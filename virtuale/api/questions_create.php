<?php
declare(strict_types=1);

date_default_timezone_set('UTC');

require_once __DIR__ . '/../lib/database.php';
require_once __DIR__ . '/../lib/auth.php';
require_once __DIR__ . '/../lib/csrf.php';
require_once __DIR__ . '/../lib/json_response.php';
require_once __DIR__ . '/../lib/test_services.php';

$u = require_role_json(['Administrator','Instruktor']);
verify_csrf_json();

$input = read_json_body();
$test_id = (int)($input['test_id'] ?? $_POST['test_id'] ?? 0);
$type = (string)($input['type'] ?? $_POST['type'] ?? 'MC_SINGLE');
$text = trim((string)($input['text'] ?? $_POST['text'] ?? ''));
$points = (float)($input['points'] ?? $_POST['points'] ?? 1);
$explanation = trim((string)($input['explanation'] ?? $_POST['explanation'] ?? ''));
$difficulty = trim((string)($input['difficulty'] ?? $_POST['difficulty'] ?? ''));
$tags = trim((string)($input['tags'] ?? $_POST['tags'] ?? ''));
$short_exact = !empty($input['short_answer_exact'] ?? $_POST['short_answer_exact']) ? 1 : 0;
$options = $input['options'] ?? [];
$correct = $input['correct'] ?? null;
$correct_multi = $input['correct_multi'] ?? [];
$correct_answer = trim((string)($input['correct_answer'] ?? $_POST['correct_answer'] ?? ''));

$allowed = ['MC_SINGLE','MC_MULTI','TRUE_FALSE','SHORT'];
if ($test_id <= 0 || $text === '' || !in_array($type, $allowed, true)) {
  json_error('Invalid input');
}

try {
  $test = TestService::ensureInstructorAccess($pdo, $test_id, (int)$u['id'], is_admin($u));
  $course_id = (int)$test['course_id'];

  $pdo->beginTransaction();

  $st = $pdo->prepare(
    "INSERT INTO question_bank (course_id, created_by, type, text, points, explanation, difficulty, tags, short_answer_exact)
     VALUES (:course_id, :created_by, :type, :text, :points, :explanation, :difficulty, :tags, :short_answer_exact)"
  );
  $st->execute([
    ':course_id' => $course_id,
    ':created_by' => (int)$u['id'],
    ':type' => $type,
    ':text' => $text,
    ':points' => max(0.0, $points),
    ':explanation' => $explanation,
    ':difficulty' => $difficulty !== '' ? $difficulty : null,
    ':tags' => $tags !== '' ? $tags : null,
    ':short_answer_exact' => $short_exact,
  ]);
  $question_id = (int)$pdo->lastInsertId();

  if ($type === 'TRUE_FALSE' && empty($options)) {
    $options = [
      ['text' => 'True'],
      ['text' => 'False']
    ];
  }

  if ($type === 'SHORT') {
    if ($short_exact && $correct_answer !== '') {
      $options = [[ 'text' => $correct_answer, 'is_correct' => 1 ]];
    } else {
      $options = [];
    }
  }

  if (in_array($type, ['MC_SINGLE','MC_MULTI','TRUE_FALSE','SHORT'], true) && $options) {
    $pos = 1;
    foreach ($options as $opt) {
      $optText = trim((string)($opt['text'] ?? $opt['option_text'] ?? ''));
      if ($optText === '') continue;
      $isCorrect = 0;
      if ($type === 'MC_SINGLE' || $type === 'TRUE_FALSE') {
        $isCorrect = ((string)($correct ?? '') === (string)$pos) || (!empty($opt['is_correct']));
      } elseif ($type === 'MC_MULTI') {
        $isCorrect = in_array($pos, array_map('intval', (array)$correct_multi), true) || (!empty($opt['is_correct']));
      } elseif ($type === 'SHORT') {
        $isCorrect = !empty($opt['is_correct']);
      }
      $pdo->prepare('INSERT INTO question_options (question_id, option_text, is_correct, position) VALUES (?,?,?,?)')
          ->execute([$question_id, $optText, $isCorrect ? 1 : 0, $pos]);
      $pos++;
    }
  }

  $stPos = $pdo->prepare('SELECT COALESCE(MAX(position),0) FROM test_questions WHERE test_id=?');
  $stPos->execute([$test_id]);
  $nextPos = (int)$stPos->fetchColumn() + 1;
  $pdo->prepare('INSERT INTO test_questions (test_id, question_id, position) VALUES (?,?,?)')
      ->execute([$test_id, $question_id, $nextPos]);

  $pdo->prepare('INSERT INTO test_audit_log (test_id, user_id, action, details) VALUES (?,?,?,?)')
      ->execute([$test_id, (int)$u['id'], 'question_create', (string)$question_id]);

  $pdo->commit();
  json_ok(['question_id' => $question_id]);
} catch (Throwable $e) {
  if ($pdo->inTransaction()) $pdo->rollBack();
  json_error('Database error: ' . $e->getMessage());
}
