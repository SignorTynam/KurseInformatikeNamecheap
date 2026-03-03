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
$question_id = (int)($input['question_id'] ?? $_POST['question_id'] ?? 0);
$option_ids = $input['option_ids'] ?? ($_POST['option_ids'] ?? []);
$answer_text = trim((string)($input['answer_text'] ?? $_POST['answer_text'] ?? ''));

if ($attempt_id <= 0 || $question_id <= 0) json_error('Invalid input');

$rlKey = 'rl_save_' . $attempt_id;
$nowTs = time();
if (!empty($_SESSION[$rlKey]) && ($nowTs - (int)$_SESSION[$rlKey]) < 2) {
  json_ok(['rate_limited' => true]);
}
$_SESSION[$rlKey] = $nowTs;

try {
  $st = $pdo->prepare('SELECT * FROM test_attempts WHERE id=? AND user_id=? LIMIT 1');
  $st->execute([$attempt_id, (int)$u['id']]);
  $attempt = $st->fetch(PDO::FETCH_ASSOC);
  if (!$attempt) json_error('Not found', 404);
  if (($attempt['status'] ?? '') !== 'IN_PROGRESS') json_error('Attempt closed', 409);

  $test_id = (int)$attempt['test_id'];
  $stQ = $pdo->prepare('SELECT 1 FROM test_questions WHERE test_id=? AND question_id=? LIMIT 1');
  $stQ->execute([$test_id, $question_id]);
  if (!$stQ->fetchColumn()) json_error('Question invalid', 404);
  $stT = $pdo->prepare('SELECT time_limit_minutes FROM tests WHERE id=?');
  $stT->execute([$test_id]);
  $time_limit = (int)$stT->fetchColumn();
  if ($time_limit > 0) {
    $started = strtotime((string)$attempt['started_at']);
    if ((time() - $started) > ($time_limit * 60 + 30)) {
      json_error('Time expired', 409);
    }
  }

  $pdo->beginTransaction();
  $pdo->prepare('DELETE FROM attempt_answers WHERE attempt_id=? AND question_id=?')
      ->execute([$attempt_id, $question_id]);

  if (is_array($option_ids) && $option_ids) {
    $ins = $pdo->prepare('INSERT INTO attempt_answers (attempt_id, question_id, option_id) VALUES (?,?,?)');
    foreach ($option_ids as $oid) {
      $oid = (int)$oid;
      if ($oid <= 0) continue;
      $ins->execute([$attempt_id, $question_id, $oid]);
    }
  } elseif ($answer_text !== '') {
    $pdo->prepare('INSERT INTO attempt_answers (attempt_id, question_id, answer_text) VALUES (?,?,?)')
        ->execute([$attempt_id, $question_id, $answer_text]);
  }

  $pdo->commit();
  json_ok(['saved' => true]);
} catch (Throwable $e) {
  if ($pdo->inTransaction()) $pdo->rollBack();
  json_error('Database error');
}
