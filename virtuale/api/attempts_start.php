<?php
declare(strict_types=1);

date_default_timezone_set('UTC');

require_once __DIR__ . '/../lib/database.php';
require_once __DIR__ . '/../lib/auth.php';
require_once __DIR__ . '/../lib/csrf.php';
require_once __DIR__ . '/../lib/json_response.php';
require_once __DIR__ . '/../lib/test_services.php';

$u = require_role_json(['Student']);
verify_csrf_json();

$input = read_json_body();
$test_id = (int)($input['test_id'] ?? $_POST['test_id'] ?? 0);
if ($test_id <= 0) json_error('Invalid test');

try {
  $test = TestService::ensureStudentAccess($pdo, $test_id, (int)$u['id']);

  $now = new DateTime('now', new DateTimeZone('UTC'));
  $start_at = $test['start_at'] ? new DateTime((string)$test['start_at'], new DateTimeZone('UTC')) : null;
  $due_at = $test['due_at'] ? new DateTime((string)$test['due_at'], new DateTimeZone('UTC')) : null;

  if ($start_at && $now < $start_at) {
    json_error('Testi nuk është i hapur ende.', 403);
  }
  if ($due_at && $now > $due_at) {
    json_error('Testi ka përfunduar.', 403);
  }

  $open = AttemptService::getOpenAttempt($pdo, $test_id, (int)$u['id']);
  if (!$open) {
    $max_attempts = (int)($test['max_attempts'] ?? 1);
    $cnt = AttemptService::countAttempts($pdo, $test_id, (int)$u['id']);
    if ($max_attempts > 0 && $cnt >= $max_attempts) {
      json_error('Nuk keni më tentativa.', 403);
    }
    $attempt_no = $cnt + 1;
    $pdo->prepare('INSERT INTO test_attempts (test_id, user_id, attempt_no, status, started_at, time_limit_minutes) VALUES (?,?,?,?,NOW(),?)')
        ->execute([$test_id, (int)$u['id'], $attempt_no, 'IN_PROGRESS', (int)$test['time_limit_minutes']]);
    $attempt_id = (int)$pdo->lastInsertId();
    $open = $pdo->query('SELECT * FROM test_attempts WHERE id=' . (int)$attempt_id)->fetch(PDO::FETCH_ASSOC);
  }

  $attempt_id = (int)$open['id'];
  $started_at = new DateTime((string)$open['started_at'], new DateTimeZone('UTC'));
  $time_limit = (int)($open['time_limit_minutes'] ?? 0);
  $elapsed = $now->getTimestamp() - $started_at->getTimestamp();
  $remaining = $time_limit > 0 ? max(0, ($time_limit * 60) - $elapsed) : null;
  $expired = ($time_limit > 0 && $remaining !== null && $remaining <= 0);

  $questions = QuestionService::getQuestionsForTest($pdo, $test_id);

  $answers = AttemptService::getAttemptAnswers($pdo, $attempt_id);

  json_ok([
    'attempt_id' => $attempt_id,
    'started_at' => $open['started_at'],
    'time_limit_minutes' => $time_limit,
    'remaining_seconds' => $remaining,
    'expired' => $expired,
    'test' => [
      'id' => (int)$test['id'],
      'title' => (string)$test['title'],
      'description' => (string)($test['description'] ?? ''),
      'shuffle_questions' => (int)$test['shuffle_questions'],
      'shuffle_choices' => (int)$test['shuffle_choices'],
    ],
    'questions' => $questions,
    'answers' => $answers
  ]);
} catch (Throwable $e) {
  json_error('Database error');
}
