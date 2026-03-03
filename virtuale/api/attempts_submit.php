<?php
declare(strict_types=1);

date_default_timezone_set('UTC');

require_once __DIR__ . '/../lib/database.php';
require_once __DIR__ . '/../lib/auth.php';
require_once __DIR__ . '/../lib/csrf.php';
require_once __DIR__ . '/../lib/json_response.php';

$u = require_role_json(['Student']);
verify_csrf_json();

$input = read_json_body();
$attempt_id = (int)($input['attempt_id'] ?? $_POST['attempt_id'] ?? 0);
if ($attempt_id <= 0) json_error('Invalid attempt');

try {
  $st = $pdo->prepare('SELECT * FROM test_attempts WHERE id=? AND user_id=? LIMIT 1');
  $st->execute([$attempt_id, (int)$u['id']]);
  $attempt = $st->fetch(PDO::FETCH_ASSOC);
  if (!$attempt) json_error('Not found', 404);
  if (($attempt['status'] ?? '') !== 'IN_PROGRESS') {
    json_error('Attempt already submitted', 409);
  }

  $test_id = (int)$attempt['test_id'];
  $stTest = $pdo->prepare('SELECT * FROM tests WHERE id=?');
  $stTest->execute([$test_id]);
  $test = $stTest->fetch(PDO::FETCH_ASSOC);
  if (!$test) json_error('Test not found', 404);

  $now = new DateTime('now', new DateTimeZone('UTC'));
  $started_at = new DateTime((string)$attempt['started_at'], new DateTimeZone('UTC'));
  $elapsed = $now->getTimestamp() - $started_at->getTimestamp();

  $time_limit = (int)($attempt['time_limit_minutes'] ?? 0);
  $auto_submit = false;
  if ($time_limit > 0 && $elapsed > ($time_limit * 60 + 30)) {
    $auto_submit = true;
  }
  if (!empty($test['due_at'])) {
    $due_at = new DateTime((string)$test['due_at'], new DateTimeZone('UTC'));
    if ($now > $due_at) {
      $auto_submit = true;
    }
  }

  $stQ = $pdo->prepare(
    "SELECT q.*, tq.points_override
     FROM test_questions tq
     JOIN question_bank q ON q.id = tq.question_id
     WHERE tq.test_id = ?
     ORDER BY tq.position ASC, tq.id ASC"
  );
  $stQ->execute([$test_id]);
  $questions = $stQ->fetchAll(PDO::FETCH_ASSOC) ?: [];

  if (!$questions) json_error('No questions', 409);

  $qIds = array_map(fn($q) => (int)$q['id'], $questions);
  $in = implode(',', array_fill(0, count($qIds), '?'));
  $stOpt = $pdo->prepare("SELECT * FROM question_options WHERE question_id IN ($in) ORDER BY position ASC, id ASC");
  $stOpt->execute($qIds);
  $opts = $stOpt->fetchAll(PDO::FETCH_ASSOC) ?: [];
  $optsByQ = [];
  foreach ($opts as $o) {
    $optsByQ[(int)$o['question_id']][] = $o;
  }

  $stAns = $pdo->prepare('SELECT * FROM attempt_answers WHERE attempt_id=?');
  $stAns->execute([$attempt_id]);
  $answers = $stAns->fetchAll(PDO::FETCH_ASSOC) ?: [];
  $ansByQ = [];
  foreach ($answers as $a) {
    $qid = (int)$a['question_id'];
    if (!isset($ansByQ[$qid])) $ansByQ[$qid] = [];
    $ansByQ[$qid][] = $a;
  }

  $pdo->beginTransaction();
  $pdo->prepare('DELETE FROM attempt_question_scores WHERE attempt_id=?')->execute([$attempt_id]);

  $totalPoints = 0.0;
  $scorePoints = 0.0;
  $needsManual = false;

  foreach ($questions as $q) {
    $qid = (int)$q['id'];
    $type = (string)$q['type'];
    $points = (float)($q['points_override'] ?? $q['points'] ?? 1);
    $totalPoints += $points;

    $qAns = $ansByQ[$qid] ?? [];
    $isCorrect = null;
    $pointsAwarded = 0.0;
    $needsManualThis = 0;

    if (in_array($type, ['MC_SINGLE','TRUE_FALSE'], true)) {
      $selected = null;
      foreach ($qAns as $a) {
        if (!empty($a['option_id'])) { $selected = (int)$a['option_id']; break; }
      }
      $correctId = null;
      foreach (($optsByQ[$qid] ?? []) as $opt) {
        if ((int)$opt['is_correct'] === 1) { $correctId = (int)$opt['id']; break; }
      }
      $isCorrect = ($selected !== null && $selected === $correctId);
      $pointsAwarded = $isCorrect ? $points : 0.0;
    }

    if ($type === 'MC_MULTI') {
      $selectedIds = [];
      foreach ($qAns as $a) {
        if (!empty($a['option_id'])) $selectedIds[] = (int)$a['option_id'];
      }
      sort($selectedIds);
      $correctIds = [];
      foreach (($optsByQ[$qid] ?? []) as $opt) {
        if ((int)$opt['is_correct'] === 1) $correctIds[] = (int)$opt['id'];
      }
      sort($correctIds);
      $isCorrect = ($selectedIds && $selectedIds === $correctIds);
      $pointsAwarded = $isCorrect ? $points : 0.0;
    }

    if ($type === 'SHORT') {
      $shortExact = (int)($q['short_answer_exact'] ?? 1) === 1;
      if ($shortExact) {
        $correct = '';
        foreach (($optsByQ[$qid] ?? []) as $opt) {
          if ((int)$opt['is_correct'] === 1) { $correct = (string)$opt['option_text']; break; }
        }
        $given = '';
        foreach ($qAns as $a) {
          if (!empty($a['answer_text'])) { $given = (string)$a['answer_text']; break; }
        }
        $isCorrect = (mb_strtolower(trim($given)) === mb_strtolower(trim($correct)) && $correct !== '');
        $pointsAwarded = $isCorrect ? $points : 0.0;
      } else {
        $needsManualThis = 1;
        $needsManual = true;
      }
    }

    if ($needsManualThis) {
      $pdo->prepare('INSERT INTO attempt_question_scores (attempt_id, question_id, needs_manual) VALUES (?,?,1)')
          ->execute([$attempt_id, $qid]);
    } else {
      $pdo->prepare('INSERT INTO attempt_question_scores (attempt_id, question_id, is_correct, points_awarded, needs_manual) VALUES (?,?,?,?,0)')
          ->execute([$attempt_id, $qid, $isCorrect ? 1 : 0, $pointsAwarded]);
      $scorePoints += $pointsAwarded;
    }
  }

  $status = $needsManual ? 'NEEDS_GRADING' : ($auto_submit ? 'AUTO_SUBMITTED' : 'SUBMITTED');
  $percentage = $needsManual ? null : (($totalPoints > 0) ? round(($scorePoints / $totalPoints) * 100, 2) : 0.0);
  $passed = null;
  if (!$needsManual) {
    $passScore = (float)($test['pass_score'] ?? 0);
    $passed = $percentage >= $passScore ? 1 : 0;
  }

  $pdo->prepare(
    "UPDATE test_attempts SET
      status=?,
      submitted_at=NOW(),
      duration_seconds=?,
      score_points=?,
      total_points=?,
      percentage=?,
      passed=?
     WHERE id=?"
  )->execute([
    $status,
    (int)$elapsed,
    $scorePoints,
    $totalPoints,
    $percentage,
    $passed,
    $attempt_id
  ]);

  $pdo->commit();
  json_ok(['attempt_id' => $attempt_id, 'status' => $status]);
} catch (Throwable $e) {
  if ($pdo->inTransaction()) $pdo->rollBack();
  json_error('Database error: ' . $e->getMessage());
}
