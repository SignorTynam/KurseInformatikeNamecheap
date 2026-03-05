<?php
// delete_quiz.php — fshin një quiz + referencat dhe kthehet me toast te course_details
declare(strict_types=1);
session_start();
require_once __DIR__ . '/../lib/database.php';

/* ------------------------------ Helpers ------------------------------ */
function h(?string $s): string { return htmlspecialchars($s ?? '', ENT_QUOTES, 'UTF-8'); }
function redirect_course(int $course_id, string $tab, string $msg, bool $ok=false): never {
  $_SESSION['flash'] = ['msg'=>$msg, 'type'=>($ok ? 'success' : 'danger')];
  $base  = '../course_details.php?course_id=' . $course_id . '&tab=' . urlencode($tab);
  header('Location: '.$base);
  exit;
}

/* ------------------------------- RBAC -------------------------------- */
if (!isset($_SESSION['user']) || !in_array($_SESSION['user']['role'] ?? '', ['Administrator','Instruktor'], true)) {
  header('Location: ../login.php'); exit;
}
$ROLE  = (string)($_SESSION['user']['role'] ?? '');
$ME_ID = (int)($_SESSION['user']['id'] ?? 0);

/* ------------------------------ Inputs -------------------------------- */
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

/* (opsionale) JSON body me fetch() */
$input = ($method === 'POST') ? $_POST : $_GET;
$ct = $_SERVER['CONTENT_TYPE'] ?? '';
if ($method === 'POST' && stripos($ct, 'application/json') !== false) {
  $raw  = file_get_contents('php://input');
  $json = json_decode($raw, true);
  if (is_array($json)) $input = $json + $input;
}

$quiz_id   = isset($input['quiz_id'])   ? (int)$input['quiz_id']   : 0;
$course_id = isset($input['course_id']) ? (int)$input['course_id'] : (int)($_GET['course_id'] ?? 0);
$tab       = 'materials';

if ($quiz_id <= 0) {
  $cid = $course_id ?: 0;
  if ($cid > 0) redirect_course($cid, $tab, 'Quiz nuk është specifikuar.', false);
  die('Quiz nuk është specifikuar.');
}

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
  $cid = $course_id ?: (int)($input['course_id'] ?? 0);
  $msg = 'CSRF verifikimi dështoi. Rifresko faqen dhe provo përsëri.';
  if ($cid > 0) redirect_course($cid, $tab, $msg, false);
  http_response_code(403); exit($msg);
}

/* ------------------ Verifikim + fshirje të dhënash -------------------- */
try {
  // Lexo info për akses + redirect
  $st = $pdo->prepare("
    SELECT q.course_id, c.id_creator
    FROM quizzes q
    JOIN courses c ON c.id = q.course_id
    WHERE q.id = ?
    LIMIT 1
  ");
  $st->execute([$quiz_id]);
  $q = $st->fetch(PDO::FETCH_ASSOC);
  if (!$q) { 
    if ($course_id > 0) redirect_course($course_id, $tab, 'Quiz nuk u gjet.', false);
    die('Quiz nuk u gjet.');
  }

  $course_id = $course_id > 0 ? $course_id : (int)$q['course_id'];
  if ($ROLE === 'Instruktor' && (int)$q['id_creator'] !== $ME_ID) {
    redirect_course($course_id, $tab, 'Nuk keni akses.', false);
  }

  // Transaksion: heq referencat dhe vetë quiz-in (CASCADE pastron pyetje/opsione/attempts)
  $pdo->beginTransaction();

  // 1) Hiq nga section_items (MATERIALS & LABS)
  $pdo->prepare("DELETE FROM section_items WHERE course_id=? AND item_type='QUIZ' AND item_ref_id=?")
      ->execute([$course_id, $quiz_id]);

  // 2) Fshi quiz-in
  $pdo->prepare("DELETE FROM quizzes WHERE id=? LIMIT 1")->execute([$quiz_id]);

  $pdo->commit();

  redirect_course($course_id, $tab, 'Quiz u fshi me sukses.', true);

} catch (Throwable $e) {
  if ($pdo->inTransaction()) $pdo->rollBack();
  redirect_course($course_id ?: 0, $tab, 'Gabim: '. $e->getMessage(), false);
}
