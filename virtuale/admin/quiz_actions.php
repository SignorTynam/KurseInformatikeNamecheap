<?php
// quiz_actions.php — Veprime për Quiz/Questions/Answers (përputhur me skemën e re)
declare(strict_types=1);
session_start();
require_once __DIR__ . '/../lib/database.php';

if (!isset($_SESSION['user']) || !in_array($_SESSION['user']['role'] ?? '', ['Administrator','Instruktor'], true)) {
  header('Location: login.php'); exit;
}

$ROLE  = (string)($_SESSION['user']['role'] ?? '');
$ME_ID = (int)($_SESSION['user']['id'] ?? 0);

function h(?string $s): string { return htmlspecialchars($s ?? '', ENT_QUOTES, 'UTF-8'); }

function back_url(string $fallback='course.php'): string {
  return (string)($_SERVER['HTTP_REFERER'] ?? $fallback) ?: $fallback;
}

function fail(string $msg, ?string $url=null): never {
  $_SESSION['flash'] = ['msg'=>$msg, 'type'=>'danger'];
  header('Location: ' . ($url ?? back_url()));
  exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') { fail('Metodë e pavlefshme.'); }
if (empty($_POST['csrf']) || !hash_equals($_SESSION['csrf_token'] ?? '', (string)$_POST['csrf'])) {
  fail('CSRF i pavlefshëm.');
}

$action = (string)($_POST['action'] ?? '');

/* -------------------- Helper: RBAC për kursin -------------------- */
function assertCourseAccess(PDO $pdo, string $role, int $meId, int $courseId): void {
  if ($role === 'Administrator') return;
  $st = $pdo->prepare("SELECT id_creator FROM courses WHERE id=?");
  $st->execute([$courseId]);
  $row = $st->fetch(PDO::FETCH_ASSOC);
  if (!$row || (int)$row['id_creator'] !== $meId) { fail('Nuk keni akses.'); }
}

/* -------------------- Helper: Gjej quiz_id nga question/answer ---- */
function quizIdFromQuestion(PDO $pdo, int $questionId): ?int {
  $st = $pdo->prepare("SELECT quiz_id FROM quiz_questions WHERE id=?");
  $st->execute([$questionId]);
  $r = $st->fetch(PDO::FETCH_ASSOC);
  return $r ? (int)$r['quiz_id'] : null;
}
function quizIdFromAnswer(PDO $pdo, int $answerId): ?int {
  $st = $pdo->prepare("
    SELECT qq.quiz_id
    FROM quiz_answers qa
    JOIN quiz_questions qq ON qq.id = qa.question_id
    WHERE qa.id = ?
  ");
  $st->execute([$answerId]);
  $r = $st->fetch(PDO::FETCH_ASSOC);
  return $r ? (int)$r['quiz_id'] : null;
}

/* ===================================================================
   QUIZ CRUD
   =================================================================== */

/* === CREATE QUIZ === */
if ($action === 'create_quiz') {
  $course_id  = (int)($_POST['course_id'] ?? 0);
  $section_id = isset($_POST['section_id']) && $_POST['section_id'] !== '' ? (int)$_POST['section_id'] : null;
  assertCourseAccess($pdo, $ROLE, $ME_ID, $course_id);

  $title   = trim((string)($_POST['title'] ?? ''));
  $desc    = trim((string)($_POST['description'] ?? ''));
  $open_at = (string)($_POST['open_at'] ?? '');
  $close_at= (string)($_POST['close_at'] ?? '');
  $tlm     = (string)($_POST['time_limit_minutes'] ?? ''); // do konvertohet në sekonda
  $attempts= (int)($_POST['attempts_allowed'] ?? 1);       // 0 = pa kufi (opsionale)
  $sq      = isset($_POST['shuffle_questions']) ? 1 : 0;
  $sa      = isset($_POST['shuffle_answers'])   ? 1 : 0;
  $hidden  = isset($_POST['hidden'])            ? 1 : 0;   // opsionale nga UI

  if ($title === '') fail('Titulli kërkohet.');

  // Validim i thjeshtë i afateve
  if ($open_at !== '' && $close_at !== '' && strtotime($close_at) <= strtotime($open_at)) {
    fail('“Mbyllet” duhet të jetë pas “Hapet”.');
  }

  $time_limit_sec = null;
  if ($tlm !== '') {
    $m = max(0, (int)$tlm);
    $time_limit_sec = $m > 0 ? $m * 60 : null; // 0 => pa limit
  }

  try {
    $stmt = $pdo->prepare("
      INSERT INTO quizzes
        (course_id, section_id, title, description, open_at, close_at,
         time_limit_sec, attempts_allowed, shuffle_questions, shuffle_answers,
         hidden, status)
      VALUES (?,?,?,?,?,?,?,?,?,?,?,?)
    ");
    $stmt->execute([
      $course_id,
      $section_id,
      $title,
      $desc !== '' ? $desc : null,
      $open_at !== '' ? $open_at : null,
      $close_at !== '' ? $close_at : null,
      $time_limit_sec,
      max(0, $attempts),
      $sq,
      $sa,
      $hidden,
      'DRAFT'
    ]);
    $quiz_id = (int)$pdo->lastInsertId();
    header('Location: quiz_builder.php?quiz_id=' . $quiz_id);
    exit;
  } catch (PDOException $e) {
    fail('Gabim DB: ' . h($e->getMessage()));
  }
}

/* === UPDATE QUIZ SETTINGS === */
if ($action === 'update_quiz') {
  $quiz_id = (int)($_POST['quiz_id'] ?? 0);
  $st = $pdo->prepare("SELECT course_id FROM quizzes WHERE id=?");
  $st->execute([$quiz_id]);
  $q = $st->fetch(PDO::FETCH_ASSOC);
  if (!$q) fail('Quiz nuk u gjet.');
  assertCourseAccess($pdo, $ROLE, $ME_ID, (int)$q['course_id']);

  $title   = trim((string)($_POST['title'] ?? ''));
  $description = trim((string)($_POST['description'] ?? ''));
  $open_at = (string)($_POST['open_at'] ?? '');
  $close_at= (string)($_POST['close_at'] ?? '');
  $tlm     = (string)($_POST['time_limit_minutes'] ?? '');
  $attempts= (int)($_POST['attempts_allowed'] ?? 1);
  $sq      = isset($_POST['shuffle_questions']) ? 1 : 0;
  $sa      = isset($_POST['shuffle_answers'])   ? 1 : 0;
  $section_id = isset($_POST['section_id']) && $_POST['section_id'] !== '' ? (int)$_POST['section_id'] : null;
  $hidden  = isset($_POST['hidden']) ? 1 : 0; // opsionale

  if ($title === '') fail('Titulli kërkohet.');
  if ($open_at !== '' && $close_at !== '' && strtotime($close_at) <= strtotime($open_at)) {
    fail('“Mbyllet” duhet të jetë pas “Hapet”.');
  }

  $time_limit_sec = null;
  if ($tlm !== '') {
    $m = max(0, (int)$tlm);
    $time_limit_sec = $m > 0 ? $m * 60 : null;
  }

  $stmt = $pdo->prepare("
    UPDATE quizzes SET
      title=?, description=?, open_at=?, close_at=?, time_limit_sec=?,
      attempts_allowed=?, shuffle_questions=?, shuffle_answers=?, section_id=?,
      hidden=?, updated_at=NOW()
    WHERE id=?
  ");
  $stmt->execute([
    $title,
    ($description !== '' ? $description : null),
    ($open_at !== '' ? $open_at : null),
    ($close_at !== '' ? $close_at : null),
    $time_limit_sec,
    max(0, $attempts),
    $sq,
    $sa,
    $section_id,
    $hidden,
    $quiz_id
  ]);

  $_SESSION['flash'] = ['msg'=>'Ndryshimet u ruajtën me sukses.', 'type'=>'success'];
  header('Location: quiz_builder.php?quiz_id=' . $quiz_id); exit;
}

/* === TOGGLE VISIBILITY === */
if ($action === 'toggle_quiz_visibility') {
  $quiz_id = (int)($_POST['quiz_id'] ?? 0);
  $st = $pdo->prepare("SELECT course_id, hidden FROM quizzes WHERE id=?");
  $st->execute([$quiz_id]);
  $q = $st->fetch(PDO::FETCH_ASSOC);
  if (!$q) fail('Quiz nuk u gjet.');
  assertCourseAccess($pdo, $ROLE, $ME_ID, (int)$q['course_id']);
  $to = ((int)$q['hidden'] === 1) ? 0 : 1;
  $pdo->prepare("UPDATE quizzes SET hidden=?, updated_at=NOW() WHERE id=?")->execute([$to, $quiz_id]);
  header('Location: quiz_builder.php?quiz_id=' . $quiz_id); exit;
}

/* === DELETE QUIZ === */
if ($action === 'delete_quiz') {
  $quiz_id = (int)($_POST['quiz_id'] ?? 0);
  $st = $pdo->prepare("SELECT course_id FROM quizzes WHERE id=?");
  $st->execute([$quiz_id]);
  $q = $st->fetch(PDO::FETCH_ASSOC);
  if (!$q) fail('Quiz nuk u gjet.');
  assertCourseAccess($pdo, $ROLE, $ME_ID, (int)$q['course_id']);
  $pdo->prepare("DELETE FROM quizzes WHERE id=?")->execute([$quiz_id]);
  header('Location: course_details.php?course_id=' . (int)$q['course_id'] . '&tab=sections'); exit;
}

/* ===================================================================
   QUESTIONS
   =================================================================== */

/* === ADD QUESTION === */
if ($action === 'add_question') {
  $quiz_id = (int)($_POST['quiz_id'] ?? 0);
  $st = $pdo->prepare("SELECT course_id FROM quizzes WHERE id=?");
  $st->execute([$quiz_id]);
  $q = $st->fetch(PDO::FETCH_ASSOC);
  if (!$q) fail('Quiz nuk u gjet.');
  assertCourseAccess($pdo, $ROLE, $ME_ID, (int)$q['course_id']);

  // Prano si "question" ose "question_text" për kompatibilitet
  $text = trim((string)($_POST['question'] ?? ($_POST['question_text'] ?? '')));
  if ($text === '') fail('Pyetja s’mund të jetë bosh.');

  $points = isset($_POST['points']) ? max(1, (int)$_POST['points']) : 1;
  $explanation = trim((string)($_POST['explanation'] ?? ''));
  $pos = (int)($_POST['position'] ?? 0);
  if ($pos <= 0) {
    $pstmt = $pdo->prepare("SELECT COALESCE(MAX(position),0)+1 FROM quiz_questions WHERE quiz_id=?");
    $pstmt->execute([$quiz_id]);
    $pos = (int)$pstmt->fetchColumn();
  }

  $pdo->prepare("INSERT INTO quiz_questions (quiz_id, question, explanation, points, position) VALUES (?,?,?,?,?)")
      ->execute([$quiz_id, $text, ($explanation !== '' ? $explanation : null), $points, $pos]);

  header('Location: quiz_builder.php?quiz_id=' . $quiz_id); exit;
}

/* === UPDATE QUESTION === */
if ($action === 'update_question') {
  $qid = (int)($_POST['question_id'] ?? 0);

  $st = $pdo->prepare("
    SELECT qz.course_id, qq.quiz_id
    FROM quiz_questions qq
    JOIN quizzes qz ON qz.id = qq.quiz_id
    WHERE qq.id=?
  ");
  $st->execute([$qid]);
  $row = $st->fetch(PDO::FETCH_ASSOC);
  if (!$row) fail('Pyetja nuk u gjet.');
  assertCourseAccess($pdo, $ROLE, $ME_ID, (int)$row['course_id']);

  $text = trim((string)($_POST['question'] ?? ($_POST['question_text'] ?? '')));
  if ($text === '') fail('Pyetja s’mund të jetë bosh.');
  $points = isset($_POST['points']) ? max(1, (int)$_POST['points']) : null;
  $explanation = (string)($_POST['explanation'] ?? null);
  $position = isset($_POST['position']) ? max(1, (int)$_POST['position']) : null;

  $sql = "UPDATE quiz_questions SET question=?, updated_at=NOW()";
  $args = [$text];

  if ($explanation !== null) { $sql .= ", explanation=?"; $args[] = (trim($explanation) !== '' ? $explanation : null); }
  if ($points !== null)      { $sql .= ", points=?";      $args[] = $points; }
  if ($position !== null)    { $sql .= ", position=?";    $args[] = $position; }

  $sql .= " WHERE id=?";
  $args[] = $qid;

  $pdo->prepare($sql)->execute($args);
  header('Location: quiz_builder.php?quiz_id=' . (int)$row['quiz_id']); exit;
}

/* === DELETE QUESTION === */
if ($action === 'delete_question') {
  $qid = (int)($_POST['question_id'] ?? 0);

  $st = $pdo->prepare("
    SELECT qz.course_id, qq.quiz_id
    FROM quiz_questions qq
    JOIN quizzes qz ON qz.id = qq.quiz_id
    WHERE qq.id=?
  ");
  $st->execute([$qid]);
  $row = $st->fetch(PDO::FETCH_ASSOC);
  if (!$row) fail('Pyetja nuk u gjet.');
  assertCourseAccess($pdo, $ROLE, $ME_ID, (int)$row['course_id']);

  $pdo->prepare("DELETE FROM quiz_questions WHERE id=?")->execute([$qid]);
  header('Location: quiz_builder.php?quiz_id=' . (int)$row['quiz_id']); exit;
}

/* ===================================================================
   ANSWERS
   =================================================================== */

/* === ADD ANSWER === */
if ($action === 'add_answer') {
  $question_id = (int)($_POST['question_id'] ?? 0);

  $st = $pdo->prepare("
    SELECT qz.course_id, qq.quiz_id
    FROM quiz_questions qq
    JOIN quizzes qz ON qz.id = qq.quiz_id
    WHERE qq.id=?
  ");
  $st->execute([$question_id]);
  $row = $st->fetch(PDO::FETCH_ASSOC);
  if (!$row) fail('Pyetja nuk u gjet.');
  assertCourseAccess($pdo, $ROLE, $ME_ID, (int)$row['course_id']);

  $text = trim((string)($_POST['answer_text'] ?? ''));
  if ($text === '') fail('Alternativa s’mund të jetë bosh.');

  $pos = (int)($_POST['position'] ?? 0);
  if ($pos <= 0) {
    $p = $pdo->prepare("SELECT COALESCE(MAX(position),0)+1 FROM quiz_answers WHERE question_id=?");
    $p->execute([$question_id]);
    $pos = (int)$p->fetchColumn();
  }
  $is_correct = isset($_POST['is_correct']) ? 1 : 0;

  try {
    if ($is_correct === 1) {
      $pdo->beginTransaction();
      $pdo->prepare("UPDATE quiz_answers SET is_correct=0 WHERE question_id=?")->execute([$question_id]);
      $pdo->prepare("INSERT INTO quiz_answers (question_id, answer_text, is_correct, position) VALUES (?,?,1,?)")
          ->execute([$question_id, $text, $pos]);
      $pdo->commit();
    } else {
      $pdo->prepare("INSERT INTO quiz_answers (question_id, answer_text, is_correct, position) VALUES (?,?,0,?)")
          ->execute([$question_id, $text, $pos]);
    }
  } catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    fail('Gabim: ' . h($e->getMessage()));
  }

  header('Location: quiz_builder.php?quiz_id=' . (int)$row['quiz_id']); exit;
}

/* === SET CORRECT ANSWER === */
/* (për kompatibilitet pranojmë edhe action-in e vjetër 'set_correct_option') */
if ($action === 'set_correct_answer' || $action === 'set_correct_option') {
  $answer_id = (int)($_POST['answer_id'] ?? 0);

  $st = $pdo->prepare("
    SELECT qz.course_id, qq.quiz_id, qa.question_id
    FROM quiz_answers qa
    JOIN quiz_questions qq ON qq.id = qa.question_id
    JOIN quizzes qz ON qz.id = qq.quiz_id
    WHERE qa.id=?
  ");
  $st->execute([$answer_id]);
  $row = $st->fetch(PDO::FETCH_ASSOC);
  if (!$row) fail('Alternativa nuk u gjet.');
  assertCourseAccess($pdo, $ROLE, $ME_ID, (int)$row['course_id']);

  try {
    $pdo->beginTransaction();
    $pdo->prepare("UPDATE quiz_answers SET is_correct=0 WHERE question_id=?")->execute([(int)$row['question_id']]);
    $pdo->prepare("UPDATE quiz_answers SET is_correct=1 WHERE id=?")->execute([$answer_id]);
    $pdo->commit();
  } catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    fail('Gabim: ' . h($e->getMessage()));
  }

  header('Location: quiz_builder.php?quiz_id=' . (int)$row['quiz_id']); exit;
}

/* === UPDATE ANSWER === */
if ($action === 'update_answer') {
  $answer_id = (int)($_POST['answer_id'] ?? 0);
  $text      = trim((string)($_POST['answer_text'] ?? ''));
  $position  = isset($_POST['position']) ? max(1, (int)$_POST['position']) : null;

  $st = $pdo->prepare("
    SELECT qz.course_id, qq.quiz_id
    FROM quiz_answers qa
    JOIN quiz_questions qq ON qq.id = qa.question_id
    JOIN quizzes qz ON qz.id = qq.quiz_id
    WHERE qa.id=?
  ");
  $st->execute([$answer_id]);
  $row = $st->fetch(PDO::FETCH_ASSOC);
  if (!$row) fail('Alternativa nuk u gjet.');
  assertCourseAccess($pdo, $ROLE, $ME_ID, (int)$row['course_id']);

  if ($text === '') fail('Alternativa s’mund të jetë bosh.');

  $sql = "UPDATE quiz_answers SET answer_text=?, updated_at=NOW()";
  $args = [$text];
  if ($position !== null) { $sql .= ", position=?"; $args[] = $position; }
  $sql .= " WHERE id=?";
  $args[] = $answer_id;

  $pdo->prepare($sql)->execute($args);
  header('Location: quiz_builder.php?quiz_id=' . (int)$row['quiz_id']); exit;
}

/* === DELETE ANSWER === */
if ($action === 'delete_answer') {
  $answer_id = (int)($_POST['answer_id'] ?? 0);

  $st = $pdo->prepare("
    SELECT qz.course_id, qq.quiz_id
    FROM quiz_answers qa
    JOIN quiz_questions qq ON qq.id = qa.question_id
    JOIN quizzes qz ON qz.id = qq.quiz_id
    WHERE qa.id=?
  ");
  $st->execute([$answer_id]);
  $row = $st->fetch(PDO::FETCH_ASSOC);
  if (!$row) fail('Alternativa nuk u gjet.');
  assertCourseAccess($pdo, $ROLE, $ME_ID, (int)$row['course_id']);

  $pdo->prepare("DELETE FROM quiz_answers WHERE id=?")->execute([$answer_id]);
  header('Location: quiz_builder.php?quiz_id=' . (int)$row['quiz_id']); exit;
}

/* =================================================================== */

fail('Veprim i panjohur.');
