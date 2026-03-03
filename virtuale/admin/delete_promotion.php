<?php
// admin/delete_promotion.php — Fshi Promocion Kursi (Admin/Instruktor)
declare(strict_types=1);

session_start();

$ROOT = dirname(__DIR__);               // root i projektit (një nivel sipër /admin)
require_once $ROOT . '/lib/database.php';

/* ------------------------------- PDO bootstrap ------------------------------- */
if (!isset($pdo) || !($pdo instanceof PDO)) {
  if (function_exists('getPDO')) $pdo = getPDO();
}
if (!isset($pdo) || !($pdo instanceof PDO)) {
  http_response_code(500);
  die('DB connection missing ($pdo / getPDO).');
}

/* ------------------------------- RBAC ------------------------------- */
if (!isset($_SESSION['user'])) { header('Location: ../login.php'); exit; }
$ROLE = (string)($_SESSION['user']['role'] ?? '');
if (!in_array($ROLE, ['Administrator','Instruktor'], true)) {
  header('Location: ../courses_student.php'); exit;
}

/* ------------------------------ Helpers ----------------------------- */
function set_flash(string $msg, string $type='success'): void {
  $_SESSION['flash'] = ['msg'=>$msg, 'type'=>$type];
}

/**
 * Fshin foton vetëm brenda /promotions dhe vetëm me emër të sigurt.
 * Pranon si "filename.jpg" ashtu edhe "promotions/filename.jpg".
 */
function safe_unlink_photo(string $rootDir, ?string $photoFromDb): void {
  $p = trim((string)$photoFromDb);
  if ($p === '') return;

  // normalizo: nëse DB ruan vetëm emrin, vendose në promotions/
  $p = str_replace('\\', '/', $p);
  if (!str_starts_with($p, 'promotions/')) {
    $p = 'promotions/' . basename($p);
  }

  // lejo vetëm promotions/emer.ext
  if (!preg_match('#^promotions/[A-Za-z0-9._-]+\.(jpg|jpeg|png|webp)$#i', $p)) return;

  $full = rtrim($rootDir, '/\\') . DIRECTORY_SEPARATOR . $p;
  if (is_file($full)) { @unlink($full); }
}

/* ------------------------------- Guard ------------------------------ */
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
  set_flash('Kërkesë e pavlefshme.', 'warning');
  header('Location: ../promotions.php'); exit;
}

$csrf = (string)($_POST['csrf'] ?? '');
if (empty($_SESSION['csrf_token']) || !hash_equals((string)$_SESSION['csrf_token'], $csrf)) {
  set_flash('Seancë e pasigurt (CSRF). Rifresko faqen dhe provo sërish.', 'danger');
  header('Location: ../promotions.php'); exit;
}

$id = (string)($_POST['id'] ?? '');
if ($id === '' || !ctype_digit($id)) {
  set_flash('ID e promocionit është e pavlefshme.', 'danger');
  header('Location: ../promotions.php'); exit;
}
$id = (int)$id;

/* ------------------------------ Process ----------------------------- */
try {
  // Lexo promocionin (për foto & ekzistencë)
  $st = $pdo->prepare("SELECT id, name, photo FROM promoted_courses WHERE id = :id LIMIT 1");
  $st->execute([':id' => $id]);
  $promo = $st->fetch(PDO::FETCH_ASSOC);

  if (!$promo) {
    set_flash('Promocioni nuk u gjet ose është fshirë tashmë.', 'warning');
    header('Location: ../promotions.php'); exit;
  }

  // Fshi rekordin
  $pdo->beginTransaction();
  $del = $pdo->prepare("DELETE FROM promoted_courses WHERE id = :id LIMIT 1");
  $del->execute([':id' => $id]);
  $affected = $del->rowCount();
  $pdo->commit();

  if ($affected < 1) {
    set_flash('Asnjë rresht nuk u fshi (mund të ketë ndryshuar ndërkohë).', 'warning');
    header('Location: ../promotions.php'); exit;
  }

  // Fshi foton vetëm nëse nuk përdoret diku tjetër
  $photo = (string)($promo['photo'] ?? '');
  if ($photo !== '') {
    $chk = $pdo->prepare("SELECT COUNT(*) FROM promoted_courses WHERE photo = :p");
    $chk->execute([':p' => $photo]);
    $stillUsed = ((int)$chk->fetchColumn()) > 0;

    if (!$stillUsed) {
      safe_unlink_photo($ROOT, $photo);
    }
  }

  set_flash('Promocioni u fshi me sukses.', 'success');
  header('Location: ../promotions.php'); exit;

} catch (Throwable $e) {
  if ($pdo->inTransaction()) { $pdo->rollBack(); }
  set_flash('Gabim gjatë fshirjes: ' . $e->getMessage(), 'danger');
  header('Location: ../promotions.php'); exit;
}
