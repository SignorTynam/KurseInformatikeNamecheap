<?php
declare(strict_types=1);
session_start();
require_once __DIR__ . '/../lib/database.php';

function h(?string $s): string { return htmlspecialchars($s ?? '', ENT_QUOTES, 'UTF-8'); }
function back_ok(int $course_id, string $msg): void {
  $_SESSION['flash'] = ['msg'=>$msg, 'type'=>'success'];
  header('Location: ../course_details.php?course_id='.$course_id.'&tab=overview'); exit;
}
function back_err(int $course_id, string $msg): void {
  $_SESSION['flash'] = ['msg'=>$msg, 'type'=>'danger'];
  header('Location: ../course_details.php?course_id='.$course_id.'&tab=overview'); exit;
}

/* RBAC */
if (!isset($_SESSION['user']) || !in_array($_SESSION['user']['role'] ?? '', ['Administrator','Instruktor'], true)) {
  header('Location: ../login.php'); exit;
}
$ROLE  = $_SESSION['user']['role'];
$ME_ID = (int)($_SESSION['user']['id'] ?? 0);

/* CSRF */
$CSRF = $_POST['csrf'] ?? '';
if ($CSRF === '' || $CSRF !== ($_SESSION['csrf_token'] ?? '')) { back_err((int)($_POST['course_id'] ?? 0), 'CSRF i pavlefshëm.'); }

/* Inputs */
$course_id = (int)($_POST['course_id'] ?? 0);
$title     = trim((string)($_POST['title'] ?? ''));
$desc      = trim((string)($_POST['description'] ?? ''));
$dt        = (string)($_POST['appointment_date'] ?? '');
$link      = trim((string)($_POST['link'] ?? ''));

if ($course_id <= 0 || $title === '' || $dt === '') back_err($course_id, 'Ploteso titullin dhe daten/orën.');

try {
  // 1) Verifiko kursin + pronësinë për Instruktor
  $stmt = $pdo->prepare("SELECT id_creator FROM courses WHERE id=?");
  $stmt->execute([$course_id]);
  $row = $stmt->fetch(PDO::FETCH_ASSOC);
  if (!$row) back_err($course_id, 'Kursi nuk u gjet.');
  if ($ROLE === 'Instruktor' && (int)$row['id_creator'] !== $ME_ID) back_err($course_id, 'S’keni leje për këtë kurs.');

  // 2) Normalizo datetime nga input type=datetime-local
  $dtObj = DateTime::createFromFormat('Y-m-d\TH:i', $dt) ?: new DateTime($dt);
  if (!$dtObj) back_err($course_id, 'Data/ora e pavlefshme.');
  $when = $dtObj->format('Y-m-d H:i:s');

  // 3) (Opsionale) validim link
  if ($link !== '' && !filter_var($link, FILTER_VALIDATE_URL)) back_err($course_id, 'Link i pavlefshëm.');

  // 4) Insert
  $ins = $pdo->prepare("INSERT INTO appointments (course_id, title, description, appointment_date, link) VALUES (?,?,?,?,?)");
  $ins->execute([$course_id, $title, $desc, $when, $link ?: null]);

  back_ok($course_id, 'Takimi u planifikua me sukses.');
} catch (PDOException $e) {
  back_err($course_id, 'Gabim: '. $e->getMessage());
}
