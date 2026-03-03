<?php
declare(strict_types=1);

if (session_status() === PHP_SESSION_NONE) {
  session_start();
}

function current_user(): ?array {
  return $_SESSION['user'] ?? null;
}

function require_login(): array {
  $u = current_user();
  if (!$u) {
    header('Location: ../login.php');
    exit;
  }
  return $u;
}

function require_role(array $roles): array {
  $u = require_login();
  $role = (string)($u['role'] ?? '');
  if (!in_array($role, $roles, true)) {
    http_response_code(403);
    exit('Forbidden');
  }
  return $u;
}

function is_admin(array $u): bool {
  return (string)($u['role'] ?? '') === 'Administrator';
}

function is_instructor(array $u): bool {
  return (string)($u['role'] ?? '') === 'Instruktor';
}

function is_student(array $u): bool {
  return (string)($u['role'] ?? '') === 'Student';
}

function require_course_owner(PDO $pdo, int $course_id, int $user_id, bool $allowAdmin = true): void {
  if ($allowAdmin && is_admin(current_user() ?? [])) {
    return;
  }
  $st = $pdo->prepare('SELECT id_creator FROM courses WHERE id = ?');
  $st->execute([$course_id]);
  $creator_id = (int)($st->fetchColumn() ?: 0);
  if ($creator_id !== $user_id) {
    http_response_code(403);
    exit('Forbidden');
  }
}

function require_enrolled(PDO $pdo, int $course_id, int $user_id): void {
  $st = $pdo->prepare('SELECT 1 FROM enroll WHERE course_id = ? AND user_id = ? LIMIT 1');
  $st->execute([$course_id, $user_id]);
  if (!$st->fetchColumn()) {
    http_response_code(403);
    exit('Forbidden');
  }
}

function require_login_json(): array {
  $u = current_user();
  if (!$u) {
    http_response_code(401);
    header('Content-Type: application/json');
    echo json_encode(['ok' => false, 'error' => 'Not authenticated']);
    exit;
  }
  return $u;
}

function require_role_json(array $roles): array {
  $u = require_login_json();
  $role = (string)($u['role'] ?? '');
  if (!in_array($role, $roles, true)) {
    http_response_code(403);
    header('Content-Type: application/json');
    echo json_encode(['ok' => false, 'error' => 'Forbidden']);
    exit;
  }
  return $u;
}
