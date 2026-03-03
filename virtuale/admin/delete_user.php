<?php
declare(strict_types=1);

session_start();

/**
 * Ky file pritet të jetë te: /admin/delete_user.php
 * dhe database.php pritet te: /database.php
 */
require_once __DIR__ . '/../lib/database.php';

/* ------------------------------- Helpers ------------------------------- */
function redirect_with(string $url): void {
  header('Location: ' . $url);
  exit;
}

function flash_and_redirect(string $url, string $msg, string $type='success'): void {
  $_SESSION['flash'] = ['msg'=>$msg, 'type'=>$type];
  redirect_with($url);
}

function safe_return_url(): string {
  // 1) prefero return (POST)
  $ret = (string)($_POST['return'] ?? '');
  if ($ret !== '') return $ret;

  // 2) fallback: referer nëse duket users.php
  $ref = (string)($_SERVER['HTTP_REFERER'] ?? '');
  if ($ref !== '') {
    $p = parse_url($ref);
    $path = (string)($p['path'] ?? '');
    if ($path !== '' && str_ends_with($path, '/users.php')) {
      $qs = [];
      if (!empty($p['query'])) {
        parse_str($p['query'], $qs);
        unset($qs['ok'], $qs['error']);
      }
      $clean = $path;
      if (!empty($qs)) { $clean .= '?' . http_build_query($qs); }
      return $clean;
    }
  }

  // 3) fallback final
  return '../users.php';
}

/* -------------------------------- RBAC -------------------------------- */
if (!isset($_SESSION['user']) || (string)($_SESSION['user']['role'] ?? '') !== 'Administrator') {
  header('Location: ../login.php');
  exit;
}

$meId = (int)($_SESSION['user']['id'] ?? 0);

/* ------------------------------- Method -------------------------------- */
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
  flash_and_redirect('../users.php', 'Kërkesë e pavlefshme.', 'danger');
}

/* -------------------------------- CSRF --------------------------------- */
$csrfPost = (string)($_POST['csrf'] ?? '');
$csrfSess = (string)($_SESSION['csrf_token'] ?? '');

if ($csrfSess === '' || $csrfPost === '' || !hash_equals($csrfSess, $csrfPost)) {
  flash_and_redirect(safe_return_url(), 'CSRF i pavlefshëm. Rifresko faqen dhe provo sërish.', 'danger');
}

/* -------------------------------- Input -------------------------------- */
$id = (int)($_POST['id'] ?? 0);
if ($id <= 0) {
  flash_and_redirect(safe_return_url(), 'ID i pavlefshëm.', 'danger');
}

// Mos lejo të fshish veten (opsionale por shumë e këshillueshme)
if ($meId > 0 && $id === $meId) {
  flash_and_redirect(safe_return_url(), 'Nuk mund të fshish llogarinë tënde.', 'danger');
}

/* ------------------------------- Delete -------------------------------- */
try {
  // (Opsionale) verifiko ekzistencën para delete
  $chk = $pdo->prepare("SELECT id FROM users WHERE id = :id LIMIT 1");
  $chk->execute([':id' => $id]);
  if (!$chk->fetchColumn()) {
    flash_and_redirect(safe_return_url(), 'Përdoruesi nuk u gjet.', 'danger');
  }

  // Delete
  $stmt = $pdo->prepare("DELETE FROM users WHERE id = :id LIMIT 1");
  $stmt->execute([':id' => $id]);

  if ($stmt->rowCount() < 1) {
    flash_and_redirect(safe_return_url(), 'Fshirja dështoi (asnjë rresht nuk u prek).', 'danger');
  }

  flash_and_redirect(safe_return_url(), 'Përdoruesi u fshi me sukses.', 'success');
} catch (PDOException $e) {
  // Shumë shpesh këtu bie për FK constraints (user i lidhur me enroll/lessons/etc.)
  $msg = 'Gabim gjatë fshirjes. Mund të jetë i lidhur me të dhëna të tjera (FK).';
  flash_and_redirect(safe_return_url(), $msg, 'danger');
}
