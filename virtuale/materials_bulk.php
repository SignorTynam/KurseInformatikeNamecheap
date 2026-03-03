<?php
/**
 * materials_bulk.php
 * Bulk: move/delete për materialet (section_items) brenda një kursi.
 *
 * POST (application/json):
 * {
 *   csrf,
 *   course_id,
 *   action: "move" | "delete",
 *   si_ids: [<section_item_id>, ...],
 *   target_section_id: <int> (vetëm për action="move")
 * }
 *
 * Shënim: "area" mund të vijë ende nga frontendi i vjetër, por injorohet.
 */
declare(strict_types=1);
session_start();
header('Content-Type: application/json');
require_once __DIR__ . '/lib/database.php';

if (!isset($_SESSION['user']) || !in_array($_SESSION['user']['role'] ?? '', ['Administrator','Instruktor'], true)) {
  echo json_encode(['ok'=>false,'error'=>'Unauthenticated']); exit;
}
$ROLE  = $_SESSION['user']['role'];
$ME_ID = (int)($_SESSION['user']['id'] ?? 0);

$raw  = file_get_contents('php://input');
$data = json_decode($raw, true) ?: [];

$csrf = (string)($data['csrf'] ?? '');
if (!hash_equals($_SESSION['csrf_token'] ?? '', $csrf)) {
  echo json_encode(['ok'=>false,'error'=>'Invalid CSRF']); exit;
}

$course_id = (int)($data['course_id'] ?? 0);
$action    = (string)($data['action'] ?? '');
$si_ids    = array_values(array_unique(array_map('intval', (array)($data['si_ids'] ?? []))));
$target    = array_key_exists('target_section_id', $data) ? (int)$data['target_section_id'] : null;

if ($course_id <= 0 || !$si_ids || $action === '') {
  echo json_encode(['ok'=>false,'error'=>'Bad payload']); exit;
}

/* verify course & instructor ownership */
try {
  $q = $pdo->prepare("SELECT id_creator FROM courses WHERE id=?");
  $q->execute([$course_id]);
  $row = $q->fetch(PDO::FETCH_ASSOC);
  if (!$row) {
    echo json_encode(['ok'=>false,'error'=>'Course not found']); exit;
  }
  if ($ROLE === 'Instruktor' && (int)$row['id_creator'] !== $ME_ID) {
    echo json_encode(['ok'=>false,'error'=>'No access']); exit;
  }
} catch (PDOException $e) {
  echo json_encode(['ok'=>false,'error'=>'DB']); exit;
}

/** Gjen pozicionin e radhës në një seksion të caktuar të kursit. */
function next_pos(PDO $pdo, int $course_id, int $section_id): int {
  $q = $pdo->prepare("
    SELECT COALESCE(MAX(position),0) + 1
    FROM section_items
    WHERE course_id = ? AND section_id = ?
  ");
  $q->execute([$course_id, $section_id]);
  return (int)$q->fetchColumn();
}

try {
  $pdo->beginTransaction();

  // Validim i si_ids që janë të këtij kursi
  $in     = implode(',', array_fill(0, count($si_ids), '?'));
  $params = array_merge([$course_id], $si_ids);

  $stmt = $pdo->prepare("
    SELECT id, section_id, item_type, item_ref_id
    FROM section_items
    WHERE course_id = ? AND id IN ($in)
    ORDER BY id
  ");
  $stmt->execute($params);
  $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
  if (!$rows) {
    throw new RuntimeException('Nothing found');
  }

  if ($action === 'move') {
    if ($target === null) {
      throw new RuntimeException('Missing target_section_id');
    }

    // verifiko që target section ekziston në këtë kurs (ose 0 për “jashtë seksioneve”)
    if ($target !== 0) {
      $chk = $pdo->prepare("SELECT COUNT(*) FROM sections WHERE id=? AND course_id=?");
      $chk->execute([$target, $course_id]);
      if ((int)$chk->fetchColumn() === 0) {
        throw new RuntimeException('Target section not found');
      }
    }

    $upd = $pdo->prepare("
      UPDATE section_items
      SET section_id = ?, position = ?, updated_at = NOW()
      WHERE id = ? AND course_id = ?
    ");

    foreach ($rows as $r) {
      $pos = next_pos($pdo, $course_id, $target);
      $upd->execute([$target, $pos, (int)$r['id'], $course_id]);
    }

  } elseif ($action === 'delete') {

    // prepared statements (më shpejt + më e sigurt)
    $delSI = $pdo->prepare("DELETE FROM section_items WHERE id=? AND course_id=?");

    $cntOtherRef = $pdo->prepare("
      SELECT COUNT(*)
      FROM section_items
      WHERE course_id=? AND item_type=? AND item_ref_id=? AND id<>?
    ");

    // Lessons cleanup
    $delLesson      = $pdo->prepare("DELETE FROM lessons WHERE id=? AND course_id=?");
    $delLessonFiles = $pdo->prepare("DELETE FROM lesson_files WHERE lesson_id=?");
    // table e re në skemë (InnoDB + FK), por e fshijmë edhe manualisht për siguri
    $delLessonImages= $pdo->prepare("DELETE FROM lesson_images WHERE lesson_id=?");
    $delNotes       = $pdo->prepare("DELETE FROM notes WHERE lesson_id=?");
    $delThreads     = $pdo->prepare("DELETE FROM threads WHERE course_id=? AND lesson_id=?");
    $delRepliesByThreads = $pdo->prepare("
      DELETE tr
      FROM thread_replies tr
      JOIN threads t ON t.id = tr.thread_id
      WHERE t.course_id=? AND t.lesson_id=?
    ");
    $delReadsLesson = $pdo->prepare("DELETE FROM user_reads WHERE item_type='LESSON' AND item_id=?");

    // Assignments cleanup
    $delAssign      = $pdo->prepare("DELETE FROM assignments WHERE id=? AND course_id=?");
    $delAssignFiles = $pdo->prepare("DELETE FROM assignments_files WHERE assignment_id=?");
    $delAssignSub   = $pdo->prepare("DELETE FROM assignments_submitted WHERE assignment_id=?");
    $delReadsAssign = $pdo->prepare("DELETE FROM user_reads WHERE item_type='ASSIGNMENT' AND item_id=?");

    // Quizzes cleanup
    $delQuiz        = $pdo->prepare("DELETE FROM quizzes WHERE id=? AND course_id=?");
    $delReadsQuiz   = $pdo->prepare("DELETE FROM user_reads WHERE item_type='QUIZ' AND item_id=?");

    foreach ($rows as $r) {
      $si_id = (int)$r['id'];
      $typ   = (string)$r['item_type'];
      $ref   = isset($r['item_ref_id']) ? (int)$r['item_ref_id'] : 0;

      // 1) Gjithmonë fshi linkun në section_items
      $delSI->execute([$si_id, $course_id]);

      // 2) Nëse është TEXT, mbaron këtu
      if ($typ === 'TEXT') {
        continue;
      }

      // nëse s’ka ref id, s’kemi ç’të fshijmë tjetër
      if ($ref <= 0) {
        continue;
      }

      // 3) Nëse ka ende referenca të tjera të këtij objekti në section_items,
      //    MOS e fshi objektin (lesson/assignment/quiz).
      $cntOtherRef->execute([$course_id, $typ, $ref, $si_id]);
      $hasOtherRefs = ((int)$cntOtherRef->fetchColumn() > 0);
      if ($hasOtherRefs) {
        continue;
      }

      // 4) Përndryshe, fshi objektin + dependent data
      if ($typ === 'LESSON') {
        // MyISAM tables: manual cleanup
        $delLessonFiles->execute([$ref]);
        $delLessonImages->execute([$ref]); // edhe pse ka FK, s’na prish
        $delNotes->execute([$ref]);

        // threads + replies (MyISAM)
        try { $delRepliesByThreads->execute([$course_id, $ref]); } catch (Throwable $__){ /* ignore */ }
        $delThreads->execute([$course_id, $ref]);

        // user_reads (InnoDB)
        $delReadsLesson->execute([$ref]);

        // fshi lesson-in
        $delLesson->execute([$ref, $course_id]);

      } elseif ($typ === 'ASSIGNMENT') {
        $delAssignFiles->execute([$ref]);
        $delAssignSub->execute([$ref]);
        $delReadsAssign->execute([$ref]);
        $delAssign->execute([$ref, $course_id]);

      } elseif ($typ === 'QUIZ') {
        // InnoDB: quizzes ka cascade te quiz_questions/answers/attempts
        $delReadsQuiz->execute([$ref]);
        $delQuiz->execute([$ref, $course_id]);

      } else {
        // lloj tjetër i paparashikuar: vetëm linku u fshi më lart
        continue;
      }
    }

  } else {
    throw new RuntimeException('Unknown action');
  }

  $pdo->commit();
  echo json_encode(['ok'=>true]);

} catch (Throwable $e) {
  if ($pdo->inTransaction()) $pdo->rollBack();
  echo json_encode(['ok'=>false,'error'=>$e->getMessage() ?: 'DB update failed']);
}
