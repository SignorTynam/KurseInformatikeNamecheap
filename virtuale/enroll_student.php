<?php
declare(strict_types=1);
session_start();
require_once __DIR__ . '/lib/database.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
  header('Location: course.php'); exit;
}

$course_id  = (int)($_POST['course_id'] ?? 0);
$student_id = (int)($_POST['student_id'] ?? 0);
$csrf       = $_POST['csrf'] ?? '';

function back_ok(string $msg): never {
  global $course_id;
  $_SESSION['flash'] = ['msg'=>$msg, 'type'=>'success'];
  header('Location: course_details.php?course_id='.$course_id.'&tab=people'); exit;
}
function back_err(string $msg): never {
  global $course_id;
  $_SESSION['flash'] = ['msg'=>$msg, 'type'=>'danger'];
  header('Location: course_details.php?course_id='.$course_id.'&tab=people'); exit;
}

/* CSRF */
if (!hash_equals($_SESSION['csrf_token'] ?? '', $csrf)) {
  back_err('CSRF i pavlefshëm.');
}

/* Validime bazë */
if ($course_id <= 0 || $student_id <= 0) {
  back_err('Të dhënat e nevojshme nuk u gjetën.');
}

/* Kontrollo që është Student */
try {
  $stmt = $pdo->prepare("SELECT id FROM users WHERE id = ? AND role = 'Student'");
  $stmt->execute([$student_id]);
  if (!$stmt->fetchColumn()) back_err('Përdoruesi i përzgjedhur nuk është student.');
} catch (PDOException $e) {
  back_err('Gabim gjatë kontrollit të përdoruesit.');
}

/* Regjistro */
try {
  // nëse ke kolonë enrolled_at, përdor varianten me NOW():
  $stmt = $pdo->prepare("INSERT INTO enroll (course_id, user_id, enrolled_at) VALUES (?, ?, NOW())");
  $stmt->execute([$course_id, $student_id]);
  back_ok('Studenti u shtua me sukses në kurs.');
} catch (PDOException $e) {
  // 23000 = duplicate key (p.sh. ekziston)
  if ($e->getCode() === '23000') {
    back_err('Ky student është tashmë i regjistruar në këtë kurs.');
  }
  back_err('Gabim gjatë regjistrimit.');
}
