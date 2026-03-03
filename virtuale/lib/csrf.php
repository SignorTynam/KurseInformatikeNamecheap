<?php
declare(strict_types=1);

if (session_status() === PHP_SESSION_NONE) {
  session_start();
}

function csrf_token(): string {
  if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
  }
  return (string)$_SESSION['csrf_token'];
}

function get_request_headers(): array {
  $headers = [];
  foreach (getallheaders() ?: [] as $k => $v) {
    $headers[strtolower($k)] = $v;
  }
  return $headers;
}

function read_json_body(): array {
  if (isset($GLOBALS['__json_body']) && is_array($GLOBALS['__json_body'])) {
    return $GLOBALS['__json_body'];
  }
  $raw = file_get_contents('php://input');
  if (!$raw) {
    $GLOBALS['__json_body'] = [];
    return [];
  }
  $data = json_decode($raw, true);
  $GLOBALS['__json_body'] = is_array($data) ? $data : [];
  return $GLOBALS['__json_body'];
}

function get_csrf_from_request(): ?string {
  $headers = get_request_headers();
  if (!empty($headers['x-csrf-token'])) {
    return (string)$headers['x-csrf-token'];
  }
  if (isset($_POST['csrf'])) {
    return (string)$_POST['csrf'];
  }
  $json = read_json_body();
  if (isset($json['csrf'])) {
    return (string)$json['csrf'];
  }
  return null;
}

function verify_csrf_or_die(): void {
  $token = get_csrf_from_request();
  $valid = !empty($_SESSION['csrf_token']) && $token && hash_equals((string)$_SESSION['csrf_token'], (string)$token);
  if (!$valid) {
    http_response_code(403);
    exit('CSRF verifikimi dështoi.');
  }
}

function verify_csrf_json(): void {
  $token = get_csrf_from_request();
  $valid = !empty($_SESSION['csrf_token']) && $token && hash_equals((string)$_SESSION['csrf_token'], (string)$token);
  if (!$valid) {
    http_response_code(403);
    header('Content-Type: application/json');
    echo json_encode(['ok' => false, 'error' => 'CSRF invalid']);
    exit;
  }
}
