<?php
// delete_course.php — fshin kursin + të gjithë skedarët e lidhur dhe pastron referencat
declare(strict_types=1);
session_start();
require_once __DIR__ . '/../lib/database.php';

/* ------------------------------ Helpers ------------------------------ */
function h(?string $s): string { return htmlspecialchars($s ?? '', ENT_QUOTES, 'UTF-8'); }
function redirect_ok(string $msg): never {
  $_SESSION['flash'] = ['msg'=>$msg, 'type'=>'success'];
  header('Location: ../course.php'); exit;
}
function redirect_err(string $msg): never {
  $_SESSION['flash'] = ['msg'=>$msg, 'type'=>'danger'];
  header('Location: ../course.php'); exit;
}
/** Fshin vetëm skedarë që bien brenda root-it të aplikacionit (siguri). */
function safe_unlink(?string $rel): void {
  if (!$rel) return;
  $rel = ltrim((string)$rel, '/');
  if ($rel === '') return;
  $abs  = realpath(__DIR__ . '/' . $rel);
  $root = realpath(__DIR__);
  if ($abs && $root && str_starts_with($abs, $root . DIRECTORY_SEPARATOR) && is_file($abs)) {
    @unlink($abs);
  }
}

/* ------------------------------- RBAC -------------------------------- */
if (!isset($_SESSION['user']) || !in_array(($_SESSION['user']['role'] ?? ''), ['Administrator','Instruktor'], true)) {
  header('Location: ../login.php'); exit;
}
$ROLE  = (string)($_SESSION['user']['role'] ?? '');
$ME_ID = (int)($_SESSION['user']['id'] ?? 0);

/* ------------------------------ Method -------------------------------- */
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
  header('Location: ../course.php'); exit;
}

/* ------------------------------- CSRF --------------------------------- */
if (empty($_SESSION['csrf_token'])) { $_SESSION['csrf_token'] = bin2hex(random_bytes(32)); }
$serverTokens = array_values(array_filter([
  (string)($_SESSION['csrf_token'] ?? ''),
  (string)($_SESSION['csrf'] ?? ''),
]));
$clientTokens = array_values(array_filter([
  (string)($_POST['csrf'] ?? ''),
  (string)($_POST['csrf_token'] ?? ''),
  (string)($_SERVER['HTTP_X_CSRF_TOKEN'] ?? ''),
]));
$csrf_ok = false;
foreach ($serverTokens as $sv) {
  foreach ($clientTokens as $cv) {
    if ($sv !== '' && $cv !== '' && hash_equals($sv, $cv)) { $csrf_ok = true; break 2; }
  }
}
if (!$csrf_ok) { redirect_err('CSRF verifikimi dështoi. Rifresko faqen dhe provo përsëri.'); }

/* ------------------------------ Inputs -------------------------------- */
$course_id = isset($_POST['course_id']) ? (int)$_POST['course_id'] : 0;
if ($course_id <= 0) { redirect_err('Kursi nuk është specifikuar.'); }

/* --------------- Verifikim i aksesit & mbledhje skedarësh ------------- */
try {
  // Lexo krijuesin e kursit + foto
  $st = $pdo->prepare("SELECT id_creator, photo FROM courses WHERE id = ?");
  $st->execute([$course_id]);
  $course = $st->fetch(PDO::FETCH_ASSOC);
  if (!$course) { redirect_err('Kursi nuk u gjet.'); }
  if ($ROLE === 'Instruktor' && (int)$course['id_creator'] !== $ME_ID) {
    redirect_err('Nuk keni akses për të fshirë këtë kurs.');
  }

  // Mblidh të gjitha rrugët e skedarëve për fshirje pas DB commit
  $files = [];

  // Foto e kursit (ruaje siç është në DB; supozojmë shteg relativ)
  if (!empty($course['photo'])) $files[] = (string)$course['photo'];

  // Skedarët e leksioneve + notebook_path
  $stmtLF = $pdo->prepare("
    SELECT lf.file_path
    FROM lesson_files lf
    JOIN lessons l ON l.id = lf.lesson_id
    WHERE l.course_id = ?
  ");
  $stmtLF->execute([$course_id]);
  foreach ($stmtLF->fetchAll(PDO::FETCH_COLUMN) as $p) { if ($p) $files[] = (string)$p; }

  $stmtNB = $pdo->prepare("SELECT notebook_path FROM lessons WHERE course_id = ? AND notebook_path IS NOT NULL");
  $stmtNB->execute([$course_id]);
  foreach ($stmtNB->fetchAll(PDO::FETCH_COLUMN) as $p) { if ($p) $files[] = (string)$p; }

  // Skedarët e detyrave: resource_path & solution_path
  $stmtAR = $pdo->prepare("SELECT resource_path, solution_path FROM assignments WHERE course_id = ?");
  $stmtAR->execute([$course_id]);
  foreach ($stmtAR->fetchAll(PDO::FETCH_ASSOC) as $r) {
    if (!empty($r['resource_path'])) $files[] = (string)$r['resource_path'];
    if (!empty($r['solution_path'])) $files[] = (string)$r['solution_path'];
  }

  // Bashkëngjitjet e detyrave
  $stmtAF = $pdo->prepare("
    SELECT af.file_path
    FROM assignments_files af
    JOIN assignments a ON a.id = af.assignment_id
    WHERE a.course_id = ?
  ");
  $stmtAF->execute([$course_id]);
  foreach ($stmtAF->fetchAll(PDO::FETCH_COLUMN) as $p) { if ($p) $files[] = (string)$p; }

  // Dorëzimet e studentëve
  $stmtAS = $pdo->prepare("
    SELECT s.file_path
    FROM assignments_submitted s
    JOIN assignments a ON a.id = s.assignment_id
    WHERE a.course_id = ?
  ");
  $stmtAS->execute([$course_id]);
  foreach ($stmtAS->fetchAll(PDO::FETCH_COLUMN) as $p) { if ($p) $files[] = (string)$p; }

  // Hiq duplikatat e mundshme
  $files = array_values(array_unique(array_filter($files, fn($x) => is_string($x) && $x !== '')));

  /* ------------------------ Fshirja në DB ----------------------------- */
  $pdo->beginTransaction();

  // 1) Pastrim i section_items (nuk ka FK te courses, ndaj bëje me dorë)
  $pdo->prepare("DELETE FROM section_items WHERE course_id = ?")->execute([$course_id]);

  // 2) Pastrim i user_reads për iteme të këtij kursi (për të shmangur orphan)
  // LESSON
  $pdo->prepare("
    DELETE ur FROM user_reads ur
    WHERE ur.item_type='LESSON'
      AND ur.item_id IN (SELECT id FROM lessons WHERE course_id = ?)
  ")->execute([$course_id]);
  // ASSIGNMENT
  $pdo->prepare("
    DELETE ur FROM user_reads ur
    WHERE ur.item_type='ASSIGNMENT'
      AND ur.item_id IN (SELECT id FROM assignments WHERE course_id = ?)
  ")->execute([$course_id]);
  // QUIZ
  $pdo->prepare("
    DELETE ur FROM user_reads ur
    WHERE ur.item_type='QUIZ'
      AND ur.item_id IN (SELECT id FROM quizzes WHERE course_id = ?)
  ")->execute([$course_id]);

  // 3) Fshi vetë kursin — FK CASCADE do pastrojë lessons, lesson_files, assignments,
  //    assignments_files, assignments_submitted, quizzes, quiz_questions/answers/attempts,
  //    enroll, appointments, payments, sections, threads (me course_id), events? (jo, s'varen nga kursi)
  $pdo->prepare("DELETE FROM courses WHERE id = ? LIMIT 1")->execute([$course_id]);

  $pdo->commit();

  /* --------------------- Fshirja fizike e skedarëve ------------------- */
  foreach ($files as $rel) { safe_unlink($rel); }

  redirect_ok('Kursi dhe të gjithë materialet u fshinë me sukses.');

} catch (Throwable $e) {
  if ($pdo->inTransaction()) $pdo->rollBack();
  redirect_err('Gabim gjatë fshirjes së kursit: ' . $e->getMessage());
}
