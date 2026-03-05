<?php
// copy_course.php — Klonim i kursit sipas skemës së re
declare(strict_types=1);
session_start();
header('Content-Type: application/json; charset=UTF-8');

require_once __DIR__ . '/lib/database.php';
require_once __DIR__ . '/lib/lesson_videos.php';

/* RBAC */
if (!isset($_SESSION['user']) || !in_array($_SESSION['user']['role'] ?? '', ['Administrator','Instruktor'], true)) {
  http_response_code(403);
  echo json_encode(['ok'=>false,'error'=>'Ndalohet.']);
  exit;
}
$ME_ID = (int)($_SESSION['user']['id'] ?? 0);

/* CSRF */
$csrf = (string)($_POST['csrf'] ?? '');
if ($csrf === '' || !hash_equals($_SESSION['csrf_token'] ?? '', $csrf)) {
  http_response_code(400);
  echo json_encode(['ok'=>false,'error'=>'CSRF i pavlefshëm.']);
  exit;
}

/* Input */
$srcCourseId = isset($_POST['course_id']) ? (int)$_POST['course_id'] : 0;
$titleNew    = trim((string)($_POST['title_new'] ?? ''));

if ($srcCourseId <= 0) {
  http_response_code(400);
  echo json_encode(['ok'=>false,'error'=>'Kursi burim i pavlefshëm.']);
  exit;
}

try {
  /* Lexo kursin burim */
  $s = $pdo->prepare("SELECT * FROM courses WHERE id=?");
  $s->execute([$srcCourseId]);
  $src = $s->fetch(PDO::FETCH_ASSOC);
  if (!$src) {
    http_response_code(404);
    echo json_encode(['ok'=>false,'error'=>'Kursi burim nuk u gjet.']);
    exit;
  }

  $pdo->beginTransaction();

  /* Përgatit titullin e ri */
  if ($titleNew === '') {
    $titleNew = rtrim((string)$src['title']);
    if ($titleNew === '') $titleNew = 'Kurs i ri';
    $titleNew .= ' (Kopje)';
  }

  /* Krijo kursin e ri (INACTIVE) */
  $insCourse = $pdo->prepare("
    INSERT INTO courses (title, description, id_lesson, id_creator, status, category, photo, AulaVirtuale, created_at, updated_at)
    VALUES (:title, :descr, NULL, :creator, 'INACTIVE', :category, :photo, NULL, NOW(), NOW())
  ");
  $insCourse->execute([
    ':title'   => $titleNew,
    ':descr'   => $src['description'] ?? null,
    ':creator' => $ME_ID,
    ':category'=> $src['category'] ?? 'TJETRA',
    ':photo'   => $src['photo'] ?? null,
  ]);
  $newCourseId = (int)$pdo->lastInsertId();

  /* =========================
     Harta ID-sh
     ========================= */
  $sectionMap    = []; // old_sec_id => new_sec_id
  $lessonMap     = []; // old_less_id => new_less_id
  $assignMap     = []; // old_asg_id  => new_asg_id
  $quizMap       = []; // old_qz_id   => new_qz_id
  $questionMap   = []; // old_qq_id   => new_qq_id

  /* =========================
     Kopjo Sections  (FIX: përfshi 'area')
     ========================= */
  $qSec = $pdo->prepare("
    SELECT id, title, description, position, hidden, highlighted
    FROM sections
    WHERE course_id = ?
    ORDER BY position ASC, id ASC
  ");
  $qSec->execute([$srcCourseId]);

  $insSec = $pdo->prepare("
    INSERT INTO sections (course_id, title, description, position, hidden, highlighted, created_at, updated_at)
    VALUES (:course_id, :title, :descr, :pos, 1, 0, NOW(), NOW())
  ");

  while ($sec = $qSec->fetch(PDO::FETCH_ASSOC)) {
    $insSec->execute([
      ':course_id' => $newCourseId,
      ':title'     => $sec['title'],
      ':descr'     => $sec['description'] ?? null,
      ':pos'       => (int)$sec['position'],
    ]);
    $sectionMap[(int)$sec['id']] = (int)$pdo->lastInsertId();
  }

  /* =========================
     Kopjo Lessons
     ========================= */
  $qLess = $pdo->prepare("SELECT * FROM lessons WHERE course_id = ? ORDER BY id ASC");
  $qLess->execute([$srcCourseId]);

  $insLess = $pdo->prepare("
    INSERT INTO lessons (course_id, section_id, title, description, URL, category, notebook_path, uploaded_at, updated_at)
    VALUES (:course_id, :section_id, :title, :descr, :url, :cat, :nb, NOW(), NOW())
  ");

  while ($l = $qLess->fetch(PDO::FETCH_ASSOC)) {
    $oldSection = $l['section_id'] ? (int)$l['section_id'] : null;
    $newSection = $oldSection && isset($sectionMap[$oldSection]) ? $sectionMap[$oldSection] : null;

    $insLess->execute([
      ':course_id'  => $newCourseId,
      ':section_id' => $newSection,
      ':title'      => $l['title'],
      ':descr'      => $l['description'] ?? null,
      ':url'        => $l['URL'] ?? null,
      ':cat'        => $l['category'] ?? 'LEKSION', // mos prek 'LAB' nëse ka
      ':nb'         => $l['notebook_path'] ?? null,
    ]);
    $newLessonId = (int)$pdo->lastInsertId();
    $lessonMap[(int)$l['id']] = $newLessonId;

    /* lesson_files */
    $lfSel = $pdo->prepare("SELECT * FROM lesson_files WHERE lesson_id=? ORDER BY id ASC");
    $lfSel->execute([(int)$l['id']]);
    $lfIns = $pdo->prepare("
      INSERT INTO lesson_files (lesson_id, file_path, file_type, uploaded_at)
      VALUES (:lesson_id, :file_path, :file_type, NOW())
    ");
    while ($lf = $lfSel->fetch(PDO::FETCH_ASSOC)) {
      $lfIns->execute([
        ':lesson_id' => $newLessonId,
        ':file_path' => $lf['file_path'],
        ':file_type' => $lf['file_type'] ?? null,
      ]);
    }

    lv_copy_lesson_videos($pdo, (int)$l['id'], $newLessonId);
  }

  /* =========================
     Kopjo Assignments (pa submissions)
     ========================= */
  $qAsg = $pdo->prepare("SELECT * FROM assignments WHERE course_id = ? ORDER BY id ASC");
  $qAsg->execute([$srcCourseId]);
  $insAsg = $pdo->prepare("
    INSERT INTO assignments (course_id, section_id, title, description, due_date, status, hidden, uploaded_at, updated_at)
    VALUES (:course_id, :section_id, :title, :descr, :due, :status, :hidden, NOW(), NOW())
  ");
  while ($a = $qAsg->fetch(PDO::FETCH_ASSOC)) {
    $oldSection = $a['section_id'] ? (int)$a['section_id'] : null;
    $newSection = $oldSection && isset($sectionMap[$oldSection]) ? $sectionMap[$oldSection] : null;

    $insAsg->execute([
      ':course_id'  => $newCourseId,
      ':section_id' => $newSection,
      ':title'      => $a['title'],
      ':descr'      => $a['description'] ?? null,
      ':due'        => $a['due_date'] ?? null,
      ':status'     => $a['status'] ?? 'PENDING',
      ':hidden'     => (int)($a['hidden'] ?? 0),
    ]);
    $newAsgId = (int)$pdo->lastInsertId();
    $assignMap[(int)$a['id']] = $newAsgId;

    /* assignments_files */
    $afSel = $pdo->prepare("SELECT * FROM assignments_files WHERE assignment_id=? ORDER BY id ASC");
    $afSel->execute([(int)$a['id']]);
    $afIns = $pdo->prepare("
      INSERT INTO assignments_files (assignment_id, file_path, uploaded_at)
      VALUES (:assignment_id, :file_path, NOW())
    ");
    while ($af = $afSel->fetch(PDO::FETCH_ASSOC)) {
      $afIns->execute([
        ':assignment_id' => $newAsgId,
        ':file_path'     => $af['file_path'],
      ]);
    }
  }

  /* =========================
     Kopjo Quizzes (pa attempts)
     ========================= */
  $qQuiz = $pdo->prepare("SELECT * FROM quizzes WHERE course_id = ? ORDER BY id ASC");
  $qQuiz->execute([$srcCourseId]);
  $insQuiz = $pdo->prepare("
    INSERT INTO quizzes
      (course_id, section_id, title, description, open_at, close_at, time_limit_sec, attempts_allowed, shuffle_questions, shuffle_answers, hidden, status, created_at, updated_at)
    VALUES
      (:course_id, :section_id, :title, :descr, :open_at, :close_at, :tls, :att, :sq, :sa, :hidden, :status, NOW(), NOW())
  ");
  while ($q = $qQuiz->fetch(PDO::FETCH_ASSOC)) {
    $oldSection = $q['section_id'] ? (int)$q['section_id'] : null;
    $newSection = $oldSection && isset($sectionMap[$oldSection]) ? $sectionMap[$oldSection] : null;

    $insQuiz->execute([
      ':course_id' => $newCourseId,
      ':section_id'=> $newSection,
      ':title'     => $q['title'],
      ':descr'     => $q['description'] ?? null,
      ':open_at'   => $q['open_at'] ?? null,
      ':close_at'  => $q['close_at'] ?? null,
      ':tls'       => $q['time_limit_sec'] ?? null,
      ':att'       => (int)($q['attempts_allowed'] ?? 1),
      ':sq'        => (int)($q['shuffle_questions'] ?? 0),
      ':sa'        => (int)($q['shuffle_answers'] ?? 0),
      ':hidden'    => (int)($q['hidden'] ?? 0),
      ':status'    => $q['status'] ?? 'DRAFT',
    ]);
    $newQuizId = (int)$pdo->lastInsertId();
    $quizMap[(int)$q['id']] = $newQuizId;

    /* quiz_questions */
    $qqSel = $pdo->prepare("SELECT * FROM quiz_questions WHERE quiz_id=? ORDER BY position ASC, id ASC");
    $qqSel->execute([(int)$q['id']]);
    $qqIns = $pdo->prepare("
      INSERT INTO quiz_questions (quiz_id, question, explanation, points, position, created_at, updated_at)
      VALUES (:quiz_id, :question, :explanation, :points, :position, NOW(), NOW())
    ");
    while ($qq = $qqSel->fetch(PDO::FETCH_ASSOC)) {
      $qqIns->execute([
        ':quiz_id'     => $newQuizId,
        ':question'    => $qq['question'],
        ':explanation' => $qq['explanation'] ?? null,
        ':points'      => (int)($qq['points'] ?? 1),
        ':position'    => (int)($qq['position'] ?? 1),
      ]);
      $newQqId = (int)$pdo->lastInsertId();
      $questionMap[(int)$qq['id']] = $newQqId;

      /* quiz_answers */
      $qaSel = $pdo->prepare("SELECT * FROM quiz_answers WHERE question_id=? ORDER BY position ASC, id ASC");
      $qaSel->execute([(int)$qq['id']]);
      $qaIns = $pdo->prepare("
        INSERT INTO quiz_answers (question_id, answer_text, is_correct, position, created_at, updated_at)
        VALUES (:question_id, :answer_text, :is_correct, :position, NOW(), NOW())
      ");
      while ($qa = $qaSel->fetch(PDO::FETCH_ASSOC)) {
        $qaIns->execute([
          ':question_id' => $newQqId,
          ':answer_text' => $qa['answer_text'],
          ':is_correct'  => (int)$qa['is_correct'],
          ':position'    => (int)$qa['position'],
        ]);
      }
    }
  }

  /* =========================
     Kopjo Section Items
     ========================= */
  $siSel = $pdo->prepare("SELECT * FROM section_items WHERE course_id=? ORDER BY section_id ASC, position ASC, id ASC");
  $siSel->execute([$srcCourseId]);
  $siIns = $pdo->prepare("
    INSERT INTO section_items (course_id, section_id, item_type, item_ref_id, content_md, hidden, position, created_at, updated_at)
    VALUES (:course_id, :section_id, :item_type, :item_ref_id, :content_md, :hidden, :position, NOW(), NULL)
  ");
  while ($si = $siSel->fetch(PDO::FETCH_ASSOC)) {
    $oldSection = (int)$si['section_id'];
    $newSection = $sectionMap[$oldSection] ?? null;
    if (!$newSection) continue;

    $type = (string)$si['item_type'];
    $newRef = null;

    if ($type === 'LESSON') {
      $old = (int)($si['item_ref_id'] ?? 0);
      $newRef = $old ? ($lessonMap[$old] ?? null) : null;
    } else if ($type === 'ASSIGNMENT') {
      $old = (int)($si['item_ref_id'] ?? 0);
      $newRef = $old ? ($assignMap[$old] ?? null) : null;
    } else if ($type === 'QUIZ') {
      $old = (int)($si['item_ref_id'] ?? 0);
      $newRef = $old ? ($quizMap[$old] ?? null) : null;
    } else if ($type === 'TEXT') {
      $newRef = null; // TEXT nuk ka ref
    } else {
      continue;
    }

    $siIns->execute([
      ':course_id'  => $newCourseId,
      ':section_id' => $newSection,
      ':item_type'  => $type,
      ':item_ref_id'=> $newRef,
      ':content_md' => $si['content_md'] ?? null,
      ':hidden'     => (int)($si['hidden'] ?? 0),
      ':position'   => (int)$si['position'],
    ]);
  }

  /* Mos kopjo: enroll, payments, appointments, events, enroll_events,
     assignments_submitted, quiz_attempts, user_reads, threads, notes, etj. */

  $pdo->commit();

  echo json_encode(['ok'=>true, 'new_course_id'=>$newCourseId]);
  exit;

} catch (Throwable $e) {
  if ($pdo->inTransaction()) { $pdo->rollBack(); }
  http_response_code(500);
  echo json_encode(['ok'=>false,'error'=>'Gabim gjatë kopjimit: '.$e->getMessage()]);
  exit;
}
