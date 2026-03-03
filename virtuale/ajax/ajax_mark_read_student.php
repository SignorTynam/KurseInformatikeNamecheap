<?php
// ajax/ajax_mark_read_student.php
declare(strict_types=1);
session_start();
require_once __DIR__ . '/../lib/database.php';

header('Content-Type: application/json; charset=utf-8');

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST' || (($_POST['action'] ?? '') !== 'mark_read')) {
  echo json_encode(['ok'=>false,'error'=>'bad_method']); exit;
}
if (!isset($_SESSION['user']) || (($_SESSION['user']['role'] ?? '') !== 'Student')) {
  echo json_encode(['ok'=>false,'error'=>'unauthorized']); exit;
}

$ME_ID = (int)($_SESSION['user']['id'] ?? 0);
$csrf  = (string)($_POST['csrf'] ?? '');
if (!hash_equals($_SESSION['csrf_token'] ?? '', $csrf)) {
  echo json_encode(['ok'=>false,'error'=>'csrf']); exit;
}

$type      = strtoupper(trim((string)($_POST['item_type'] ?? $_POST['type'] ?? '')));
$item_id   = (int)($_POST['item_id'] ?? $_POST['id'] ?? 0);
$course_id = (int)($_POST['course_id'] ?? 0);
if (!in_array($type, ['LESSON','ASSIGNMENT','QUIZ'], true) || $item_id <= 0 || $course_id <= 0) {
  echo json_encode(['ok'=>false,'error'=>'bad_input']); exit;
}

try {
  // verifiko regjistrimin
  $chk = $pdo->prepare("SELECT 1 FROM enroll WHERE course_id=? AND user_id=? LIMIT 1");
  $chk->execute([$course_id, $ME_ID]);
  if (!$chk->fetchColumn()) { echo json_encode(['ok'=>false,'error'=>'not_enrolled']); exit; }

  // verifiko që item i përket kursit
  $tbl  = $type === 'LESSON' ? 'lessons' : ($type === 'ASSIGNMENT' ? 'assignments' : 'quizzes');
  $stmt = $pdo->prepare("SELECT 1 FROM {$tbl} WHERE id=? AND course_id=? LIMIT 1");
  $stmt->execute([$item_id, $course_id]);
  if (!$stmt->fetchColumn()) { echo json_encode(['ok'=>false,'error'=>'not_found']); exit; }

  // toggle user_reads
  $exists = $pdo->prepare("SELECT 1 FROM user_reads WHERE user_id=? AND item_type=? AND item_id=?");
  $exists->execute([$ME_ID, $type, $item_id]);

  if ($exists->fetchColumn()) {
    $del = $pdo->prepare("DELETE FROM user_reads WHERE user_id=? AND item_type=? AND item_id=?");
    $del->execute([$ME_ID, $type, $item_id]);
    $now_read = false;
  } else {
    $ins = $pdo->prepare("INSERT INTO user_reads (user_id, item_type, item_id, read_at) VALUES (?,?,?,NOW())");
    $ins->execute([$ME_ID, $type, $item_id]);
    $now_read = true;
  }

  // metrika vetëm për LESSON
  $counts = null;
  if ($type === 'LESSON') {
    $tl = $pdo->prepare("SELECT COUNT(*) FROM lessons WHERE course_id=?");
    $tl->execute([$course_id]);
    $total_lessons = (int)$tl->fetchColumn();

    $rl = $pdo->prepare("SELECT COUNT(*) FROM user_reads WHERE user_id=? AND item_type='LESSON' AND item_id IN (SELECT id FROM lessons WHERE course_id=?)");
    $rl->execute([$ME_ID, $course_id]);
    $read_lessons = (int)$rl->fetchColumn();

    $unread = max(0, $total_lessons - $read_lessons);
    $pct    = $total_lessons > 0 ? (int)round(($read_lessons/$total_lessons)*100) : 0;
    $counts = ['read_lessons'=>$read_lessons, 'total_lessons'=>$total_lessons, 'unread_lessons'=>$unread, 'pct_lessons'=>$pct];
  }

  echo json_encode(['ok'=>true, 'now_read'=>$now_read, 'counts'=>$counts]); exit;
} catch (Throwable $e) {
  echo json_encode(['ok'=>false,'error'=>'server','detail'=>$e->getMessage()]); exit;
}
