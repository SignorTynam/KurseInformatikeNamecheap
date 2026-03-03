<?php
declare(strict_types=1);

if (session_status() === PHP_SESSION_NONE) { session_start(); }

function h(?string $s): string {
  return htmlspecialchars($s ?? '', ENT_QUOTES, 'UTF-8');
}

function flash(string $msg, string $type='success'): void {
  $_SESSION['flash'] = ['msg'=>$msg, 'type'=>$type];
}

function get_flash(): ?array {
  $f = $_SESSION['flash'] ?? null;
  unset($_SESSION['flash']);
  return $f;
}

if (!function_exists('set_flash')) {
  function set_flash(string $msg, string $type='success'): void { flash($msg, $type); }
}
