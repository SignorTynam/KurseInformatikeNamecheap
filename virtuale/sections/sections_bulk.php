<?php
/**
 * sections_bulk.php
 * Bulk: hide/unhide/highlight/unhighlight/delete për seksione të një kursi.
 *
 * POST (application/json):
 * {
 *   csrf,
 *   course_id,
 *   // area mund të vijë ende nga frontendi i vjetër, por injorohet
 *   action: "hide" | "unhide" | "highlight" | "unhighlight" | "delete",
 *   section_ids: [<id>, ...]
 * }
 */
declare(strict_types=1);
session_start();
header('Content-Type: application/json');

$ROOT = dirname(__DIR__);
require_once $ROOT . '/lib/database.php';

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

$course_id   = (int)($data['course_id'] ?? 0);
// $area lexohet për kompatibilitet mbrapa, por nuk përdoret më
$action      = (string)($data['action'] ?? '');
$section_ids = array_values(array_unique(array_map('intval', (array)($data['section_ids'] ?? []))));

if ($course_id <= 0 || !$section_ids || $action === '') {
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

/**
 * Fshin një seksion dhe të gjitha materialet e lidhura me të.
 */
function delete_section_and_items(PDO $pdo, int $course_id, int $section_id): void {
  // Gjej materialet e seksionit
  $q = $pdo->prepare("
    SELECT id, item_type, item_ref_id
    FROM section_items
    WHERE course_id = ? AND section_id = ?
  ");
  $q->execute([$course_id, $section_id]);
  $items = $q->fetchAll(PDO::FETCH_ASSOC);

  // Fshi çdo material (përfshi entitetet kryesore)
  foreach ($items as $it) {
    $si_id = (int)$it['id'];
    $typ   = (string)$it['item_type'];
    $ref   = (int)$it['item_ref_id'];

    try {
      if ($typ === 'TEXT') {
        $pdo->prepare("DELETE FROM section_items WHERE id=?")->execute([$si_id]);

      } elseif ($typ === 'LESSON') {
        $pdo->prepare("DELETE FROM lesson_files WHERE lesson_id=?")->execute([$ref]);
        $pdo->prepare("DELETE FROM section_items WHERE id=?")->execute([$si_id]);
        $pdo->prepare("DELETE FROM lessons WHERE id=? AND course_id=?")->execute([$ref, $course_id]);

      } elseif ($typ === 'ASSIGNMENT') {
        $pdo->prepare("DELETE FROM assignments_submitted WHERE assignment_id=?")->execute([$ref]);
        $pdo->prepare("DELETE FROM section_items WHERE id=?")->execute([$si_id]);
        $pdo->prepare("DELETE FROM assignments WHERE id=? AND course_id=?")->execute([$ref, $course_id]);

      } elseif ($typ === 'QUIZ') {
        try {
          $pdo->prepare("DELETE FROM quiz_questions WHERE quiz_id=?")->execute([$ref]);
        } catch (Throwable $__) {}
        $pdo->prepare("DELETE FROM section_items WHERE id=?")->execute([$si_id]);
        $pdo->prepare("DELETE FROM quizzes WHERE id=? AND course_id=?")->execute([$ref, $course_id]);

      } else {
        // fallback: vetëm hiq lidhjen në section_items
        $pdo->prepare("DELETE FROM section_items WHERE id=?")->execute([$si_id]);
      }
    } catch (Throwable $__) {
      // vazhdo me tjetrin
    }
  }

  // Çliro çdo entitet legacy që ka section_id = ky seksion (për siguri)
  $pdo->prepare("UPDATE lessons     SET section_id=NULL WHERE course_id=? AND section_id=?")
      ->execute([$course_id, $section_id]);
  $pdo->prepare("UPDATE assignments SET section_id=NULL WHERE course_id=? AND section_id=?")
      ->execute([$course_id, $section_id]);
  try {
    $pdo->prepare("UPDATE quizzes SET section_id=NULL WHERE course_id=? AND section_id=?")
        ->execute([$course_id, $section_id]);
  } catch (Throwable $__) {}

  // Së fundi fshi vetë seksionin
  $pdo->prepare("DELETE FROM sections WHERE id=? AND course_id=?")
      ->execute([$section_id, $course_id]);
}

try {
  $pdo->beginTransaction();

  switch ($action) {
    case 'hide':
    case 'unhide': {
      $hidden = $action === 'hide' ? 1 : 0;
      $in = implode(',', array_fill(0, count($section_ids), '?'));
      $sql = "UPDATE sections SET hidden=? WHERE course_id=? AND id IN ($in)";
      $params = array_merge([$hidden, $course_id], $section_ids);
      $pdo->prepare($sql)->execute($params);
      break;
    }

    case 'highlight':
    case 'unhighlight': {
      $val = $action === 'highlight' ? 1 : 0;
      $in = implode(',', array_fill(0, count($section_ids), '?'));
      $sql = "UPDATE sections SET highlighted=? WHERE course_id=? AND id IN ($in)";
      $params = array_merge([$val, $course_id], $section_ids);
      $pdo->prepare($sql)->execute($params);
      break;
    }

    case 'delete': {
      foreach ($section_ids as $sid) {
        delete_section_and_items($pdo, $course_id, (int)$sid);
      }
      break;
    }

    default:
      throw new RuntimeException('Unknown action');
  }

  $pdo->commit();
  echo json_encode(['ok'=>true]);
} catch (Throwable $e) {
  if ($pdo->inTransaction()) {
    $pdo->rollBack();
  }
  echo json_encode(['ok'=>false,'error'=>'DB update failed']);
}
