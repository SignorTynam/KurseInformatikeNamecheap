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
$action = (string)($input['action'] ?? $_POST['action'] ?? 'publish');

if ($test_id <= 0) {
  json_error('Invalid test');
}

try {
  $test = TestService::ensureInstructorAccess($pdo, $test_id, (int)$u['id'], is_admin($u));

  if ($action === 'unpublish') {
    $pdo->prepare("UPDATE tests SET status='DRAFT', updated_by=?, updated_at=NOW() WHERE id=?")
        ->execute([(int)$u['id'], $test_id]);
    $pdo->prepare('INSERT INTO test_audit_log (test_id, user_id, action, details) VALUES (?,?,?,?)')
        ->execute([$test_id, (int)$u['id'], 'unpublish', null]);
    json_ok(['status' => 'DRAFT']);
  }

  // publish validations
  $stQ = $pdo->prepare(
    "SELECT q.id, q.type, q.short_answer_exact,
            (SELECT COUNT(*) FROM question_options qo WHERE qo.question_id=q.id) AS opt_count,
            (SELECT COUNT(*) FROM question_options qo WHERE qo.question_id=q.id AND qo.is_correct=1) AS correct_count
     FROM test_questions tq
     JOIN question_bank q ON q.id=tq.question_id
     WHERE tq.test_id=?"
  );
  $stQ->execute([$test_id]);
  $rows = $stQ->fetchAll(PDO::FETCH_ASSOC) ?: [];
  if (!$rows) {
    json_error('Testi nuk ka pyetje.');
  }
  $errors = [];
  foreach ($rows as $r) {
    $type = (string)$r['type'];
    $optCount = (int)$r['opt_count'];
    $correctCount = (int)$r['correct_count'];
    if (in_array($type, ['MC_SINGLE','MC_MULTI','TRUE_FALSE','SHORT'], true) && $optCount < 1 && $type !== 'SHORT') {
      $errors[] = 'Një pyetje nuk ka alternativa.';
    }
    if (in_array($type, ['MC_SINGLE','TRUE_FALSE'], true) && $correctCount !== 1) {
      $errors[] = 'Një pyetje ka numër të pasaktë përgjigjesh të sakta.';
    }
    if ($type === 'MC_MULTI' && $correctCount < 1) {
      $errors[] = 'Një pyetje multi duhet të ketë të paktën 1 përgjigje të saktë.';
    }
    if ($type === 'SHORT' && (int)($r['short_answer_exact'] ?? 1) === 1 && $correctCount < 1) {
      $errors[] = 'Një pyetje short-answer (exact) kërkon një përgjigje të saktë.';
    }
  }
  if ($errors) {
    json_error(implode(' ', array_unique($errors)));
  }

  $pdo->prepare("UPDATE tests SET status='PUBLISHED', published_at=NOW(), updated_by=?, updated_at=NOW() WHERE id=?")
      ->execute([(int)$u['id'], $test_id]);
  $pdo->prepare('INSERT INTO test_audit_log (test_id, user_id, action, details) VALUES (?,?,?,?)')
      ->execute([$test_id, (int)$u['id'], 'publish', null]);

  json_ok(['status' => 'PUBLISHED']);
} catch (Throwable $e) {
  json_error('Database error: ' . $e->getMessage());
}
