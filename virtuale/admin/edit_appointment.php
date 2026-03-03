<?php
declare(strict_types=1);
session_start();
require_once __DIR__ . '/../lib/database.php';

function back_ok(int $course_id, string $msg): void {
  $_SESSION['flash'] = ['msg'=>$msg, 'type'=>'success'];
  header('Location: ../course_details.php?course_id='.$course_id.'&tab=overview'); exit;
}
function back_err(int $course_id, string $msg): void {
  $_SESSION['flash'] = ['msg'=>$msg, 'type'=>'danger'];
  header('Location: ../course_details.php?course_id='.$course_id.'&tab=overview'); exit;
}

if (!isset($_SESSION['user']) || !in_array($_SESSION['user']['role'] ?? '', ['Administrator','Instruktor'], true)) {
  header('Location: ../login.php'); exit;
}
$ROLE  = $_SESSION['user']['role'];
$ME_ID = (int)($_SESSION['user']['id'] ?? 0);

$CSRF = $_POST['csrf'] ?? '';
if ($CSRF === '' || $CSRF !== ($_SESSION['csrf_token'] ?? '')) { back_err((int)($_POST['course_id'] ?? 0), 'CSRF i pavlefshëm.'); }

$appointment_id = (int)($_POST['appointment_id'] ?? 0);
$course_id      = (int)($_POST['course_id'] ?? 0);
$title          = trim((string)($_POST['title'] ?? ''));
$desc           = trim((string)($_POST['description'] ?? ''));
$dt             = (string)($_POST['appointment_date'] ?? '');
$link           = trim((string)($_POST['link'] ?? ''));

if ($appointment_id <= 0 || $course_id <= 0 || $title === '' || $dt === '') back_err($course_id, 'Të dhëna të paplota.');

try {
  // verifiko që ky appointment i përket kursit + lejet
  $q = $pdo->prepare("SELECT a.id, c.id_creator FROM appointments a JOIN courses c ON c.id=a.course_id WHERE a.id=? AND a.course_id=?");
  $q->execute([$appointment_id, $course_id]);
  $row = $q->fetch(PDO::FETCH_ASSOC);
  if (!$row) back_err($course_id, 'Takimi nuk u gjet.');
  if ($ROLE === 'Instruktor' && (int)$row['id_creator'] !== $ME_ID) back_err($course_id, 'S’keni leje.');

  $dtObj = DateTime::createFromFormat('Y-m-d\TH:i', $dt) ?: new DateTime($dt);
  if (!$dtObj) back_err($course_id, 'Data/ora e pavlefshme.');
  $when = $dtObj->format('Y-m-d H:i:s');

  if ($link !== '' && !filter_var($link, FILTER_VALIDATE_URL)) back_err($course_id, 'Link i pavlefshëm.');

  $u = $pdo->prepare("UPDATE appointments SET title=?, description=?, appointment_date=?, link=? WHERE id=?");
  $u->execute([$title, $desc, $when, $link ?: null, $appointment_id]);

  back_ok($course_id, 'Takimi u përditësua.');
} catch (PDOException $e) {
  back_err($course_id, 'Gabim: '.$e->getMessage());
}
