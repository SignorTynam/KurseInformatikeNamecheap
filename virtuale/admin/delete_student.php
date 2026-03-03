<?php
declare(strict_types=1);
session_start();
require_once __DIR__ . '/../lib/database.php';

if (!isset($_SESSION['user']) || !in_array($_SESSION['user']['role'] ?? '', ['Administrator','Instruktor'], true)) {
  header('Location: ../login.php'); exit;
}

$ROLE  = (string)($_SESSION['user']['role'] ?? '');
$ME_ID = (int)($_SESSION['user']['id'] ?? 0);

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
  header('Location: ../../index.php'); exit;
}

$course_id  = (int)($_POST['course_id'] ?? 0);
$student_id = (int)($_POST['student_id'] ?? 0);
$csrf       = (string)($_POST['csrf'] ?? '');

function back_ok(int $course_id, string $msg): never {
  $_SESSION['flash'] = ['msg'=>$msg, 'type'=>'success'];
  header('Location: ../course_details.php?course_id='.$course_id.'&tab=people'); exit;
}
function back_err(int $course_id, string $msg): never {
  $_SESSION['flash'] = ['msg'=>$msg, 'type'=>'danger'];
  header('Location: ../course_details.php?course_id='.$course_id.'&tab=people'); exit;
}

/* CSRF */
if (!hash_equals($_SESSION['csrf_token'] ?? '', $csrf)) {
  back_err($course_id, 'CSRF i pavlefshëm.');
}

/* Validime */
if ($course_id <= 0 || $student_id <= 0) {
  back_err($course_id, 'Kursi ose studenti nuk është specifikuar.');
}

/* Autorizim i hollësishëm: Instruktori mund vetëm në kurset që ka krijuar */
if ($ROLE === 'Instruktor') {
  try {
    $q = $pdo->prepare("SELECT id_creator FROM courses WHERE id = ?");
    $q->execute([$course_id]);
    $creator_id = (int)($q->fetchColumn() ?: 0);
    if ($creator_id !== $ME_ID) {
      back_err($course_id, 'Nuk keni leje të menaxhoni këtë kurs.');
    }
  } catch (PDOException $e) {
    back_err($course_id, 'Gabim gjatë verifikimit të aksesit.');
  }
}

try {
  $pdo->beginTransaction();

  // opsionale: verifiko nëse ekziston regjistrimi për feedback më të qartë
  $chk = $pdo->prepare("SELECT 1 FROM enroll WHERE course_id = ? AND user_id = ? LIMIT 1");
  $chk->execute([$course_id, $student_id]);
  if (!$chk->fetchColumn()) {
    $pdo->rollBack();
    back_err($course_id, 'Ky student nuk është i regjistruar në këtë kurs.');
  }

  $del = $pdo->prepare("DELETE FROM enroll WHERE course_id = ? AND user_id = ?");
  $del->execute([$course_id, $student_id]);

  $pdo->commit();

  if ($del->rowCount() > 0) {
    back_ok($course_id, 'Studenti u hoq me sukses nga kursi.');
  } else {
    back_err($course_id, 'Asnjë rresht nuk u fshi.');
  }
} catch (PDOException $e) {
  if ($pdo->inTransaction()) { $pdo->rollBack(); }
  back_err($course_id, 'Gabim gjatë heqjes së studentit.');
}
