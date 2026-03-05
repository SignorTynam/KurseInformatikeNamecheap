<?php
// delete_assignment.php — fshin një detyrë dhe të gjithë materialet/refs e saj + toast te course_details
declare(strict_types=1);
session_start();
require_once __DIR__ . '/../lib/database.php';

/* ------------------------------ Helpers ------------------------------ */
function h(?string $s): string { return htmlspecialchars($s ?? '', ENT_QUOTES, 'UTF-8'); }
function redirect_course(int $course_id, string $tab, string $msg, bool $ok=false): never {
  $_SESSION['flash'] = ['msg'=>$msg, 'type'=>($ok ? 'success' : 'danger')];
  $base  = 'course_details.php?course_id=' . $course_id . '&tab=' . urlencode($tab);
  header('Location: ../'.$base);
  exit;
}
function safe_unlink(?string $rel): void {
  if (!$rel) return;
  $rel  = ltrim((string)$rel, '/');
  $abs  = realpath(__DIR__ . '/' . $rel);
  $root = realpath(__DIR__);
  if ($abs && $root && str_starts_with($abs, $root . DIRECTORY_SEPARATOR) && is_file($abs)) {
    @unlink($abs);
  }
}

/* ------------------------------- RBAC -------------------------------- */
if (!isset($_SESSION['user']) || !in_array($_SESSION['user']['role'] ?? '', ['Administrator','Instruktor'], true)) {
  header('Location: ../login.php'); exit;
}
$ROLE  = (string)($_SESSION['user']['role'] ?? '');
$ME_ID = (int)($_SESSION['user']['id'] ?? 0);

/* ------------------------------ Inputs -------------------------------- */
$method        = $_SERVER['REQUEST_METHOD'] ?? 'GET';

/* (opsionale) lexo JSON nëse vjen me fetch() */
$input = $_POST;
$ct = $_SERVER['CONTENT_TYPE'] ?? '';
if (stripos($ct, 'application/json') !== false) {
  $raw = file_get_contents('php://input');
  $json = json_decode($raw, true);
  if (is_array($json)) $input = $json + $input; // POST ka përparësi
}

$assignment_id = isset($input['assignment_id']) ? (int)$input['assignment_id'] : 0;
$course_id     = isset($input['course_id'])     ? (int)$input['course_id']     : (int)($_GET['course_id'] ?? 0);
$tab           = 'materials';

/* ------------------------------- CSRF --------------------------------- */
if (empty($_SESSION['csrf_token'])) { $_SESSION['csrf_token'] = bin2hex(random_bytes(32)); }
/* Prano të dy emrat e token-it në sesion DHE në kërkesë */
$serverTokens = array_values(array_filter([
  (string)($_SESSION['csrf_token'] ?? ''),
  (string)($_SESSION['csrf'] ?? ''),
], fn($v) => is_string($v) && $v !== ''));

$clientTokens = array_values(array_filter([
  (string)($input['csrf'] ?? ''),
  (string)($input['csrf_token'] ?? ''),
  (string)($_SERVER['HTTP_X_CSRF_TOKEN'] ?? ''),
], fn($v) => is_string($v) && $v !== ''));

$csrf_ok = false;
foreach ($serverTokens as $sv) {
  foreach ($clientTokens as $cv) {
    if ($sv !== '' && $cv !== '' && hash_equals($sv, $cv)) { $csrf_ok = true; break 2; }
  }
}

if ($method !== 'POST' || !$csrf_ok) {
  $cid = $course_id > 0 ? $course_id : (int)($input['course_id'] ?? 0);
  $msg = 'CSRF verifikimi dështoi. Rifresko faqen dhe provo përsëri.';
  if ($cid > 0) redirect_course($cid, $tab, $msg, false);
  http_response_code(403); exit($msg);
}

if ($assignment_id <= 0) {
  if ($course_id > 0) redirect_course($course_id, $tab, 'Detyra nuk është specifikuar.', false);
  exit('Detyra nuk është specifikuar.');
}

/* ------------------ Verifiko & mblidh gjurmët e skedarëve ------------- */
try {
  // 1) Ekzistenca + pronësia
  $st = $pdo->prepare("
    SELECT a.id, a.course_id, a.resource_path, a.solution_path, c.id_creator
    FROM assignments a
    JOIN courses c ON c.id = a.course_id
    WHERE a.id = ?
    LIMIT 1
  ");
  $st->execute([$assignment_id]);
  $row = $st->fetch(PDO::FETCH_ASSOC);
  if (!$row) { redirect_course($course_id ?: 0, $tab, 'Detyra nuk u gjet.', false); }

  $course_id = $course_id > 0 ? $course_id : (int)$row['course_id'];
  if ($ROLE === 'Instruktor' && (int)$row['id_creator'] !== $ME_ID) {
    redirect_course($course_id, $tab, 'Nuk keni leje për të fshirë këtë detyrë.', false);
  }

  // 2) Mblidh të gjitha rrugët për fshirje fizike
  $filesToDelete = [];
  if (!empty($row['resource_path'])) $filesToDelete[] = (string)$row['resource_path'];
  if (!empty($row['solution_path'])) $filesToDelete[] = (string)$row['solution_path'];

  // Bashkëngjitjet e detyrës
  $stF = $pdo->prepare("SELECT file_path FROM assignments_files WHERE assignment_id=?");
  $stF->execute([$assignment_id]);
  foreach ($stF->fetchAll(PDO::FETCH_COLUMN) as $p) { if ($p) $filesToDelete[] = (string)$p; }

  // Dorëzimet e studentëve
  $stS = $pdo->prepare("SELECT file_path FROM assignments_submitted WHERE assignment_id=?");
  $stS->execute([$assignment_id]);
  foreach ($stS->fetchAll(PDO::FETCH_COLUMN) as $p) { if ($p) $filesToDelete[] = (string)$p; }

  // 3) Transaksion: fshi referencat + detyrën
  $pdo->beginTransaction();

  // Heq nga section_items (të dyja zonat)
  $delSI = $pdo->prepare("DELETE FROM section_items
                          WHERE course_id=? AND item_type='ASSIGNMENT' AND item_ref_id=?");
  $delSI->execute([$course_id, $assignment_id]);

  // Heq rreshtin kryesor (FK CASCADE pastron assignments_files & assignments_submitted)
  $delA = $pdo->prepare("DELETE FROM assignments WHERE id=? LIMIT 1");
  $delA->execute([$assignment_id]);

  $pdo->commit();

  // 4) Pas suksesit DB, fshi fizikisht skedarët
  foreach ($filesToDelete as $rel) { safe_unlink($rel); }

} catch (Throwable $e) {
  if ($pdo->inTransaction()) $pdo->rollBack();
  redirect_course($course_id, $tab, 'Gabim gjatë fshirjes së detyrës: ' . $e->getMessage(), false);
}

/* ------------------------------ Redirect ------------------------------ */
redirect_course($course_id, $tab, 'Detyra u fshi me sukses (bashkë me materialet).', true);
