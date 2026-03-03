<?php
declare(strict_types=1);

function json_ok(array $data = []): void {
  header('Content-Type: application/json');
  echo json_encode(['ok' => true, 'data' => $data]);
  exit;
}

function json_error(string $error, int $status = 400): void {
  http_response_code($status);
  header('Content-Type: application/json');
  echo json_encode(['ok' => false, 'error' => $error]);
  exit;
}
