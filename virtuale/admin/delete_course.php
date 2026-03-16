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

  // Imazhet e leksioneve (lesson_images)
  $stmtLI = $pdo->prepare("
    SELECT li.file_path
    FROM lesson_images li
    JOIN lessons l ON l.id = li.lesson_id
    WHERE l.course_id = ?
  ");
  $stmtLI->execute([$course_id]);
  foreach ($stmtLI->fetchAll(PDO::FETCH_COLUMN) as $p) { if ($p) $files[] = (string)$p; }

  // Imazhet e pyetjeve të bankës (question_bank)
  $stmtQBI = $pdo->prepare("SELECT image_path FROM question_bank WHERE course_id = ? AND image_path IS NOT NULL");
  $stmtQBI->execute([$course_id]);
  foreach ($stmtQBI->fetchAll(PDO::FETCH_COLUMN) as $p) { if ($p) $files[] = (string)$p; }

  // Skedarët e ngarkuar nga studentët në course_test_attempt_answers
  $stmtCTAA = $pdo->prepare("
    SELECT ctaa.file_path
    FROM course_test_attempt_answers ctaa
    JOIN course_test_attempts cta ON cta.id = ctaa.attempt_id
    JOIN course_tests ct ON ct.id = cta.test_id
    WHERE ct.course_id = ? AND ctaa.file_path IS NOT NULL AND ctaa.file_path <> ''
  ");
  $stmtCTAA->execute([$course_id]);
  foreach ($stmtCTAA->fetchAll(PDO::FETCH_COLUMN) as $p) { if ($p) $files[] = (string)$p; }

  // Hiq duplikatat e mundshme
  $files = array_values(array_unique(array_filter($files, fn($x) => is_string($x) && $x !== '')));

  /* ------------------------ Fshirja në DB ----------------------------- */
  $pdo->beginTransaction();

  // — Pastrim i user_reads para se të fshihen leksionet/detyrat/kuizet
  $pdo->prepare("
    DELETE ur FROM user_reads ur
    WHERE ur.item_type='LESSON'
      AND ur.item_id IN (SELECT id FROM lessons WHERE course_id = ?)
  ")->execute([$course_id]);
  $pdo->prepare("
    DELETE ur FROM user_reads ur
    WHERE ur.item_type='ASSIGNMENT'
      AND ur.item_id IN (SELECT id FROM assignments WHERE course_id = ?)
  ")->execute([$course_id]);
  $pdo->prepare("
    DELETE ur FROM user_reads ur
    WHERE ur.item_type='QUIZ'
      AND ur.item_id IN (SELECT id FROM quizzes WHERE course_id = ?)
  ")->execute([$course_id]);

  // — Diskutime & përgjigjet e tyre (MyISAM, pa FK)
  $pdo->prepare("
    DELETE tr FROM thread_replies tr
    JOIN threads t ON t.id = tr.thread_id
    WHERE t.course_id = ?
  ")->execute([$course_id]);
  $pdo->prepare("DELETE FROM threads WHERE course_id = ?")->execute([$course_id]);

  // — Shënime studentësh mbi leksione (MyISAM, pa FK)
  $pdo->prepare("
    DELETE n FROM notes n
    JOIN lessons l ON l.id = n.lesson_id
    WHERE l.course_id = ?
  ")->execute([$course_id]);

  // — Materialet e leksioneve: video, imazhe, skedarë (pa FK CASCADE)
  $pdo->prepare("
    DELETE lv FROM lesson_videos lv
    JOIN lessons l ON l.id = lv.lesson_id
    WHERE l.course_id = ?
  ")->execute([$course_id]);
  $pdo->prepare("
    DELETE li FROM lesson_images li
    JOIN lessons l ON l.id = li.lesson_id
    WHERE l.course_id = ?
  ")->execute([$course_id]);
  $pdo->prepare("
    DELETE lf FROM lesson_files lf
    JOIN lessons l ON l.id = lf.lesson_id
    WHERE l.course_id = ?
  ")->execute([$course_id]);
  $pdo->prepare("DELETE FROM lessons WHERE course_id = ?")->execute([$course_id]);

  // — course_tests + zinxhiri i tyre (MyISAM, pa FK)
  $pdo->prepare("
    DELETE ctqo FROM course_test_question_options ctqo
    JOIN course_test_questions ctq ON ctq.id = ctqo.question_id
    JOIN course_tests ct ON ct.id = ctq.test_id
    WHERE ct.course_id = ?
  ")->execute([$course_id]);
  $pdo->prepare("
    DELETE ctq FROM course_test_questions ctq
    JOIN course_tests ct ON ct.id = ctq.test_id
    WHERE ct.course_id = ?
  ")->execute([$course_id]);
  $pdo->prepare("
    DELETE ctaa FROM course_test_attempt_answers ctaa
    JOIN course_test_attempts cta ON cta.id = ctaa.attempt_id
    JOIN course_tests ct ON ct.id = cta.test_id
    WHERE ct.course_id = ?
  ")->execute([$course_id]);
  $pdo->prepare("
    DELETE cta FROM course_test_attempts cta
    JOIN course_tests ct ON ct.id = cta.test_id
    WHERE ct.course_id = ?
  ")->execute([$course_id]);
  $pdo->prepare("DELETE FROM course_tests WHERE course_id = ?")->execute([$course_id]);

  // — tests (InnoDB) + zinxhiri i tyre (pa FK CASCADE te tests)
  //   attempt_answers dhe attempt_question_scores fshihen via FK CASCADE nga test_attempts
  $pdo->prepare("
    DELETE tq FROM test_questions tq
    JOIN tests t ON t.id = tq.test_id
    WHERE t.course_id = ?
  ")->execute([$course_id]);
  $pdo->prepare("
    DELETE tal FROM test_audit_log tal
    JOIN tests t ON t.id = tal.test_id
    WHERE t.course_id = ?
  ")->execute([$course_id]);
  $pdo->prepare("
    DELETE ta FROM test_attempts ta
    JOIN tests t ON t.id = ta.test_id
    WHERE t.course_id = ?
  ")->execute([$course_id]);
  $pdo->prepare("DELETE FROM tests WHERE course_id = ?")->execute([$course_id]);

  // — Detyra: dorëzime, bashkëngjitje, vetë detyrat (MyISAM / pa FK)
  $pdo->prepare("
    DELETE s FROM assignments_submitted s
    JOIN assignments a ON a.id = s.assignment_id
    WHERE a.course_id = ?
  ")->execute([$course_id]);
  $pdo->prepare("
    DELETE af FROM assignments_files af
    JOIN assignments a ON a.id = af.assignment_id
    WHERE a.course_id = ?
  ")->execute([$course_id]);
  $pdo->prepare("DELETE FROM assignments WHERE course_id = ?")->execute([$course_id]);

  // — Takimet & regjistrimet (MyISAM, pa FK)
  $pdo->prepare("DELETE FROM appointments WHERE course_id = ?")->execute([$course_id]);
  $pdo->prepare("DELETE FROM enroll WHERE course_id = ?")->execute([$course_id]);

  // — section_items dhe sections
  $pdo->prepare("DELETE FROM section_items WHERE course_id = ?")->execute([$course_id]);
  $pdo->prepare("DELETE FROM sections WHERE course_id = ?")->execute([$course_id]);

  // — Fshi vetë kursin
  //   FK CASCADE (InnoDB) do pastrojë automatikisht:
  //   quizzes → quiz_questions → quiz_answers
  //             quiz_attempts
  //   question_bank → question_options
  //   notifications.course_id → SET NULL (mbahen si histori)
  $pdo->prepare("DELETE FROM courses WHERE id = ? LIMIT 1")->execute([$course_id]);

  $pdo->commit();

  /* --------------------- Fshirja fizike e skedarëve ------------------- */
  foreach ($files as $rel) { safe_unlink($rel); }

  redirect_ok('Kursi dhe të gjithë materialet u fshinë me sukses.');

} catch (Throwable $e) {
  if ($pdo->inTransaction()) $pdo->rollBack();
  redirect_err('Gabim gjatë fshirjes së kursit: ' . $e->getMessage());
}
