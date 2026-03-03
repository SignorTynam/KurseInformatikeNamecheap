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
if ($test_id <= 0) json_error('Invalid test');

try {
  TestService::ensureInstructorAccess($pdo, $test_id, (int)$u['id'], is_admin($u));
  $pdo->prepare("UPDATE tests SET status='ARCHIVED', updated_by=?, updated_at=NOW() WHERE id=?")
      ->execute([(int)$u['id'], $test_id]);
  $pdo->prepare('INSERT INTO test_audit_log (test_id, user_id, action, details) VALUES (?,?,?,?)')
      ->execute([$test_id, (int)$u['id'], 'archive', null]);
  json_ok(['status' => 'ARCHIVED']);
} catch (Throwable $e) {
  json_error('Database error');
}
