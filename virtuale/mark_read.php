<?php
// mark_read.php — Toggle "Mark as read" për LESSON/ASSIGNMENT/QUIZ (STUDENT)
declare(strict_types=1);
session_start();
require_once __DIR__ . '/lib/database.php';

header('Content-Type: application/json');

if (!isset($_SESSION['user']) || (($_SESSION['user']['role'] ?? '') !== 'Student')) {
  http_response_code(403);
  echo json_encode(['ok'=>false,'msg'=>'Joautorizuar']); exit;
}

$ME_ID = (int)($_SESSION['user']['id'] ?? 0);

$csrf   = (string)($_POST['csrf'] ?? '');
$type   = strtoupper(trim((string)($_POST['type'] ?? '')));
$id     = (int)($_POST['id'] ?? 0);
$course = (int)($_POST['course_id'] ?? 0);
$action = (string)($_POST['action'] ?? 'mark'); // mark | unmark

if (!$csrf || $csrf !== ($_SESSION['csrf_token'] ?? '')) {
  http_response_code(400);
  echo json_encode(['ok'=>false,'msg'=>'CSRF']); exit;
}
if (!in_array($type, ['LESSON','ASSIGNMENT','QUIZ'], true) || $id<=0 || $course<=0) {
  http_response_code(400);
  echo json_encode(['ok'=>false,'msg'=>'Input i pavlefshëm']); exit;
}

try {
  // duhet të jetë i regjistruar në kurs
  $chk = $pdo->prepare("SELECT 1 FROM enroll WHERE course_id=? AND user_id=? LIMIT 1");
  $chk->execute([$course, $ME_ID]);
  if (!$chk->fetchColumn()) { throw new RuntimeException('Nuk jeni i regjistruar në këtë kurs.'); }

  // verifiko që objekti i përket kursit
  if ($type === 'LESSON') {
    $q = $pdo->prepare("SELECT 1 FROM lessons WHERE id=? AND course_id=?");
  } elseif ($type === 'ASSIGNMENT') {
    $q = $pdo->prepare("SELECT 1 FROM assignments WHERE id=? AND course_id=?");
  } else { // QUIZ
    $q = $pdo->prepare("SELECT 1 FROM quizzes WHERE id=? AND course_id=?");
  }
  $q->execute([$id, $course]);
  if (!$q->fetchColumn()) { throw new RuntimeException('Objekti nuk i përket këtij kursi.'); }

  if ($action === 'unmark') {
    $d = $pdo->prepare("DELETE FROM user_reads WHERE user_id=? AND item_type=? AND item_id=?");
    $d->execute([$ME_ID, $type, $id]);
    echo json_encode(['ok'=>true,'read'=>false]); exit;
  } else {
    $i = $pdo->prepare("INSERT IGNORE INTO user_reads (user_id,item_type,item_id) VALUES (?,?,?)");
    $i->execute([$ME_ID, $type, $id]);
    echo json_encode(['ok'=>true,'read'=>true]); exit;
  }
} catch (Throwable $e) {
  http_response_code(500);
  echo json_encode(['ok'=>false,'msg'=>$e->getMessage()]); exit;
}
