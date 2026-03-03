<?php
/**
 * sections_reorder.php
 * Ruajtja e renditjes së seksioneve për një kurs.
 *
 * Payload (JSON):
 * {
 *   csrf,
 *   course_id,
 *   order: [ <section_id>, ... ]   // lista e seksioneve në rendin e ri
 * }
 *
 * Shënim: Në payload mund të vijë edhe fusha "area" nga frontendi i vjetër,
 * por këtu tani injorohet (seksionet janë unike vetëm me course_id, position).
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

$course_id = (int)($data['course_id'] ?? 0);
// area mund të vijë nga JS, por tani thjesht e injorojmë
$order     = $data['order'] ?? [];

if ($course_id <= 0 || !is_array($order)) {
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

/*
 * Renditje pa shkelur UNIQUE (course_id, position):
 *   1) Lista e seksioneve të këtij kursi.
 *   2) Offset i përkohshëm: position = position + 1000.
 *   3) Rend final: fillimisht $order (filtruar/unik/ekzistues), pastaj pjesa tjetër.
 *   4) position = 1..N brenda kursit.
 */
try {
  $pdo->beginTransaction();

  // 1) lista e plotë e seksioneve për kursin
  $allStmt = $pdo->prepare("
    SELECT id
    FROM sections
    WHERE course_id=?
    ORDER BY position ASC, id ASC
  ");
  $allStmt->execute([$course_id]);
  $allIds = array_map('intval', array_column($allStmt->fetchAll(PDO::FETCH_ASSOC), 'id'));

  if (!$allIds) {
    $pdo->commit();
    echo json_encode(['ok'=>true]); exit;
  }

  // 2) offset i përkohshëm për këtë kurs
  $pdo->prepare("UPDATE sections SET position = position + 1000 WHERE course_id=?")
      ->execute([$course_id]);

  // 3) ndërto rendin final
  $order = array_values(array_unique(array_map('intval', $order))); // unik & int
  $order = array_values(array_intersect($order, $allIds));          // vetëm id që ekzistojnë
  $rest  = array_values(array_diff($allIds, $order));               // pjesa tjetër
  $final = array_merge($order, $rest);

  // 4) ri-shkruaj pozicionet 1..N brenda kursit
  $upd = $pdo->prepare("
    UPDATE sections
    SET position=?
    WHERE id=? AND course_id=?
  ");
  $pos = 1;
  foreach ($final as $sid) {
    $upd->execute([$pos++, $sid, $course_id]);
  }

  $pdo->commit();
  echo json_encode(['ok'=>true]);
} catch (Throwable $e) {
  if ($pdo->inTransaction()) {
    $pdo->rollBack();
  }
  echo json_encode(['ok'=>false,'error'=>'DB update failed']);
}
