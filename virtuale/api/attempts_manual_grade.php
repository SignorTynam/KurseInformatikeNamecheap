<?php
declare(strict_types=1);

date_default_timezone_set('UTC');

require_once __DIR__ . '/../lib/database.php';
require_once __DIR__ . '/../lib/auth.php';
require_once __DIR__ . '/../lib/csrf.php';
require_once __DIR__ . '/../lib/json_response.php';

$u = require_role_json(['Administrator','Instruktor']);
verify_csrf_json();

$input = read_json_body();
$attempt_id = (int)($input['attempt_id'] ?? $_POST['attempt_id'] ?? 0);
$question_id = (int)($input['question_id'] ?? $_POST['question_id'] ?? 0);
$points_awarded = (float)($input['points_awarded'] ?? $_POST['points_awarded'] ?? 0);
$feedback = trim((string)($input['feedback'] ?? $_POST['feedback'] ?? ''));

if ($attempt_id <= 0 || $question_id <= 0) json_error('Invalid input');

try {
  $st = $pdo->prepare("\
    SELECT a.*, t.course_id, c.id_creator
    FROM test_attempts a
    JOIN tests t ON t.id = a.test_id
    JOIN courses c ON c.id = t.course_id
    WHERE a.id = ?
  ");
  $st->execute([$attempt_id]);
  $row = $st->fetch(PDO::FETCH_ASSOC);
  if (!$row) json_error('Attempt not found', 404);
  if (!is_admin($u) && (int)$row['id_creator'] !== (int)$u['id']) json_error('Forbidden', 403);

  $pdo->beginTransaction();

  $pdo->prepare("\
    INSERT INTO attempt_question_scores (attempt_id, question_id, is_correct, points_awarded, needs_manual, feedback, graded_by, graded_at)
    VALUES (?,?,?,?,0,?,?,NOW())
    ON DUPLICATE KEY UPDATE
      is_correct=VALUES(is_correct),
      points_awarded=VALUES(points_awarded),
      needs_manual=0,
      feedback=VALUES(feedback),
      graded_by=VALUES(graded_by),
      graded_at=NOW()
  ")->execute([$attempt_id, $question_id, null, max(0.0, $points_awarded), $feedback !== '' ? $feedback : null, (int)$u['id']]);

  $stCheck = $pdo->prepare('SELECT COUNT(*) FROM attempt_question_scores WHERE attempt_id=? AND needs_manual=1');
  $stCheck->execute([$attempt_id]);
  $pending = (int)$stCheck->fetchColumn();

  // Recalculate total and score
  $stTotals = $pdo->prepare("\
    SELECT SUM(aqs.points_awarded) AS score_points
    FROM attempt_question_scores aqs
    WHERE aqs.attempt_id=?
  ");
  $stTotals->execute([$attempt_id]);
  $scorePoints = (float)($stTotals->fetchColumn() ?? 0);

  $stTotalPoints = $pdo->prepare("\
    SELECT SUM(COALESCE(tq.points_override, q.points)) AS total_points
    FROM test_questions tq
    JOIN question_bank q ON q.id=tq.question_id
    WHERE tq.test_id=?
  ");
  $stTotalPoints->execute([(int)$row['test_id']]);
  $totalPoints = (float)($stTotalPoints->fetchColumn() ?? 0);

  if ($pending === 0) {
    $percentage = $totalPoints > 0 ? round(($scorePoints / $totalPoints) * 100, 2) : 0.0;
    $passScore = (float)$pdo->query('SELECT pass_score FROM tests WHERE id='.(int)$row['test_id'])->fetchColumn();
    $passed = $percentage >= $passScore ? 1 : 0;
    $pdo->prepare("\
      UPDATE test_attempts SET
        status='GRADED',
        score_points=?,
        total_points=?,
        percentage=?,
        passed=?
      WHERE id=?
    ")->execute([$scorePoints, $totalPoints, $percentage, $passed, $attempt_id]);
  } else {
    $pdo->prepare('UPDATE test_attempts SET score_points=? WHERE id=?')->execute([$scorePoints, $attempt_id]);
  }

  $pdo->commit();
  json_ok(['attempt_id' => $attempt_id, 'pending' => $pending]);
} catch (Throwable $e) {
  if ($pdo->inTransaction()) $pdo->rollBack();
  json_error('Database error');
}
