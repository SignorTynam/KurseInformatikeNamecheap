<?php
// delete_lesson.php — fshin një leksion + skedarët + referencat (section_items, user_reads) dhe kthehet me toast
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
/** Fshin një skedar relativ vetëm brenda root-it të aplikacionit (siguri). */
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
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
  $cid = isset($_GET['course_id']) ? (int)$_GET['course_id'] : 0;
  header('Location: ../course_details.php?course_id='.$cid); exit;
}

/* (opsionale) prano JSON body nëse dërgohet me fetch() */
$input = $_POST;
$ct = $_SERVER['CONTENT_TYPE'] ?? '';
if (stripos($ct, 'application/json') !== false) {
  $raw  = file_get_contents('php://input');
  $json = json_decode($raw, true);
  if (is_array($json)) $input = $json + $input; // POST ka përparësi
}

$lesson_id = isset($input['lesson_id']) ? (int)$input['lesson_id'] : 0;
$course_id = isset($input['course_id']) ? (int)$input['course_id'] : 0;
$tab       = 'materials';

if ($lesson_id <= 0) { die('Leksioni nuk është specifikuar.'); }

/* ------------------------------- CSRF --------------------------------- */
if (empty($_SESSION['csrf_token'])) { $_SESSION['csrf_token'] = bin2hex(random_bytes(32)); }
$serverTokens = array_values(array_filter([
  (string)($_SESSION['csrf_token'] ?? ''),
  (string)($_SESSION['csrf'] ?? ''),
]));
$clientTokens = array_values(array_filter([
  (string)($input['csrf'] ?? ''),
  (string)($input['csrf_token'] ?? ''),
  (string)($_SERVER['HTTP_X_CSRF_TOKEN'] ?? ''),
]));
$csrf_ok = false;
foreach ($serverTokens as $sv) {
  foreach ($clientTokens as $cv) {
    if ($sv !== '' && $cv !== '' && hash_equals($sv, $cv)) { $csrf_ok = true; break 2; }
  }
}
if (!$csrf_ok) {
  $cid = $course_id > 0 ? $course_id : (int)($_GET['course_id'] ?? 0);
  $cid = $cid ?: 0;
  $msg = 'CSRF verifikimi dështoi. Rifresko faqen dhe provo përsëri.';
  if ($cid > 0) redirect_course($cid, $tab, $msg, false);
  http_response_code(403); exit($msg);
}

/* ------------------ Verifikim + mbledhje skedarësh -------------------- */
try {
  // Lexo kursin & pronësinë për lejen e Instruktorit + info për redirect
  $st = $pdo->prepare("
    SELECT l.id, l.course_id, l.notebook_path, l.category, c.id_creator
    FROM lessons l
    JOIN courses c ON c.id = l.course_id
    WHERE l.id = ?
    LIMIT 1
  ");
  $st->execute([$lesson_id]);
  $row = $st->fetch(PDO::FETCH_ASSOC);
  if (!$row) { die('Leksioni nuk u gjet.'); }

  if ($course_id <= 0) { $course_id = (int)$row['course_id']; }
  if ($ROLE === 'Instruktor' && (int)$row['id_creator'] !== $ME_ID) {
    die('Nuk keni akses për të fshirë këtë leksion.');
  }

  // Merre listën e skedarëve të leksionit (për fshirje fizike pas commit)
  $files = [];
  if (!empty($row['notebook_path'])) $files[] = (string)$row['notebook_path'];

  $stF = $pdo->prepare("SELECT file_path FROM lesson_files WHERE lesson_id = ?");
  $stF->execute([$lesson_id]);
  foreach ($stF->fetchAll(PDO::FETCH_COLUMN) as $p) {
    if ($p) $files[] = (string)$p;
  }

  /* ------------------------- Fshirja në DB --------------------------- */
  $pdo->beginTransaction();

  // 1) Hiq nga section_items (në MATERIALS & LABS)
  $delSI = $pdo->prepare("DELETE FROM section_items WHERE course_id=? AND item_type='LESSON' AND item_ref_id=?");
  $delSI->execute([$course_id, $lesson_id]);

  // 2) Hiq leximet e përdoruesve për këtë leksion (user_reads)
  $delUR = $pdo->prepare("DELETE FROM user_reads WHERE item_type='LESSON' AND item_id=?");
  $delUR->execute([$lesson_id]);

  // 3) Fshi vetë leksionin (lesson_files do fshihen me ON DELETE CASCADE)
  $delL  = $pdo->prepare("DELETE FROM lessons WHERE id = ? LIMIT 1");
  $delL->execute([$lesson_id]);

  $pdo->commit();

  /* --------------------- Fshirja fizike e skedarëve ------------------ */
  foreach ($files as $rel) { safe_unlink($rel); }

  redirect_course($course_id, $tab, 'Leksioni u fshi me sukses (bashkë me materialet).', true);

} catch (Throwable $e) {
  if ($pdo->inTransaction()) { $pdo->rollBack(); }
  redirect_course($course_id ?: 0, $tab, 'Gabim gjatë fshirjes së leksionit: '. $e->getMessage(), false);
}
