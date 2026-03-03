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
$question_id = (int)($input['question_id'] ?? $_POST['question_id'] ?? 0);
if ($test_id <= 0 || $question_id <= 0) json_error('Invalid input');

try {
  TestService::ensureInstructorAccess($pdo, $test_id, (int)$u['id'], is_admin($u));

  $pdo->beginTransaction();
  $pdo->prepare('DELETE FROM test_questions WHERE test_id=? AND question_id=?')
      ->execute([$test_id, $question_id]);

  $st = $pdo->prepare('SELECT COUNT(*) FROM test_questions WHERE question_id=?');
  $st->execute([$question_id]);
  $cnt = (int)$st->fetchColumn();
  if ($cnt === 0) {
    $pdo->prepare('DELETE FROM question_options WHERE question_id=?')->execute([$question_id]);
    $pdo->prepare('DELETE FROM question_bank WHERE id=?')->execute([$question_id]);
  }

  $pdo->prepare('INSERT INTO test_audit_log (test_id, user_id, action, details) VALUES (?,?,?,?)')
      ->execute([$test_id, (int)$u['id'], 'question_delete', (string)$question_id]);

  $pdo->commit();
  json_ok(['question_id' => $question_id]);
} catch (Throwable $e) {
  if ($pdo->inTransaction()) $pdo->rollBack();
  json_error('Database error');
}
