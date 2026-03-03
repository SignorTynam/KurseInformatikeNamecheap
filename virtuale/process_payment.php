<?php
// payment_actions.php — Create/Update/Delete payments (Admin/Instruktor)
declare(strict_types=1);
session_start();
require_once __DIR__ . '/lib/database.php';

/* ---------------- RBAC ---------------- */
if (!isset($_SESSION['user']) || !in_array($_SESSION['user']['role'] ?? '', ['Administrator','Instruktor'], true)) {
  header('Location: login.php'); exit;
}
$ROLE  = (string)($_SESSION['user']['role'] ?? '');
$ME_ID = (int)($_SESSION['user']['id'] ?? 0);

/* ---------------- Helpers ------------- */
function redirect_pay(int $course_id, string $msg, bool $err=false): never {
  $_SESSION['flash'] = ['msg'=>$msg, 'type'=>($err ? 'danger' : 'success')];
  $q = 'course_details.php?course_id=' . $course_id . '&tab=payments';
  header('Location: ' . $q); exit;
}
function clean_status(?string $s): string {
  $s = strtoupper(trim((string)$s));
  return in_array($s, ['COMPLETED','FAILED'], true) ? $s : 'FAILED';
}
function parse_dt(?string $v): ?string {
  $v = trim((string)$v);
  if ($v === '') return null;
  $ts = strtotime($v);
  return $ts ? date('Y-m-d H:i:s', $ts) : null;
}

/* -------------- Method & CSRF ---------- */
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { header('Location: index.php'); exit; }
$course_id = (int)($_POST['course_id'] ?? 0);
if ($course_id <= 0) { redirect_pay(0, 'Kurs i pavlefshëm.', true); }

if (empty($_POST['csrf']) || empty($_SESSION['csrf_token']) || !hash_equals($_SESSION['csrf_token'], (string)$_POST['csrf'])) {
  redirect_pay($course_id, 'CSRF i pavlefshëm.', true);
}

/* -------------- Course ownership (Instruktor) -------- */
try {
  $stmt = $pdo->prepare("SELECT id, id_creator FROM courses WHERE id=?");
  $stmt->execute([$course_id]);
  $course = $stmt->fetch(PDO::FETCH_ASSOC);
  if (!$course) redirect_pay($course_id, 'Kursi nuk u gjet.', true);
  if ($ROLE === 'Instruktor' && (int)$course['id_creator'] !== $ME_ID) {
    redirect_pay($course_id, 'Nuk keni akses në këtë kurs.', true);
  }
} catch (PDOException $e) {
  redirect_pay($course_id, 'Gabim DB: '.$e->getMessage(), true);
}

/* -------------- Read inputs ------------- */
$action        = (string)($_POST['action'] ?? 'create');     // create|update|delete
$payment_id    = (int)($_POST['payment_id'] ?? 0);
$user_id       = (int)($_POST['user_id'] ?? 0);
$lesson_id_raw = $_POST['lesson_id'] ?? null;                // opsional
$lesson_id     = ($lesson_id_raw === '' || $lesson_id_raw === null) ? null : (int)$lesson_id_raw;
$amount        = isset($_POST['amount']) ? (float)$_POST['amount'] : null;
$status        = clean_status($_POST['payment_status'] ?? 'FAILED');
$payment_date  = parse_dt($_POST['payment_date'] ?? '');     // null => mos e ndrysho / përdor NOW() te create

/* -------------- Validate enrollment when needed ------ */
if (in_array($action, ['create','update'], true)) {
  if ($user_id <= 0) redirect_pay($course_id, 'Zgjidh një student.', true);
  if ($amount === null || $amount < 0) redirect_pay($course_id, 'Shuma duhet të jetë ≥ 0.', true);
  try {
    $chk = $pdo->prepare("SELECT 1 FROM enroll WHERE course_id=? AND user_id=? LIMIT 1");
    $chk->execute([$course_id, $user_id]);
    if (!$chk->fetchColumn()) redirect_pay($course_id, 'Studenti nuk është i regjistruar në këtë kurs.', true);
  } catch (PDOException $e) {
    redirect_pay($course_id, 'Gabim DB: '.$e->getMessage(), true);
  }
}

/* -------------- Do action ---------------- */
try {
  if ($action === 'create') {
    // INSERT (lesson_id opsional), payment_date nëse mungon përdor NOW()
    if ($payment_date === null) {
      $stmt = $pdo->prepare("
        INSERT INTO payments (course_id, lesson_id, user_id, amount, payment_status, payment_date)
        VALUES (?, ?, ?, ?, ?, NOW())
      ");
      $stmt->execute([$course_id, $lesson_id, $user_id, $amount, $status]);
    } else {
      $stmt = $pdo->prepare("
        INSERT INTO payments (course_id, lesson_id, user_id, amount, payment_status, payment_date)
        VALUES (?, ?, ?, ?, ?, ?)
      ");
      $stmt->execute([$course_id, $lesson_id, $user_id, $amount, $status, $payment_date]);
    }
    redirect_pay($course_id, 'Pagesa u shtua.');

  } elseif ($action === 'update') {
    if ($payment_id <= 0) redirect_pay($course_id, 'ID e pagesës mungon.', true);

    // Sigurohu që payment i përket këtij kursi
    $own = $pdo->prepare("SELECT id FROM payments WHERE id=? AND course_id=?");
    $own->execute([$payment_id, $course_id]);
    if (!$own->fetchColumn()) redirect_pay($course_id, 'Pagesa nuk u gjet në këtë kurs.', true);

    // UPDATE (COALESCE për payment_date)
    $stmt = $pdo->prepare("
      UPDATE payments
      SET user_id = ?, amount = ?, payment_status = ?, lesson_id = ?, payment_date = COALESCE(?, payment_date)
      WHERE id = ? AND course_id = ?
    ");
    $stmt->execute([$user_id, $amount, $status, $lesson_id, $payment_date, $payment_id, $course_id]);

    redirect_pay($course_id, 'Pagesa u përditësua.');

  } elseif ($action === 'delete') {
    if ($payment_id <= 0) redirect_pay($course_id, 'ID e pagesës mungon.', true);
    $stmt = $pdo->prepare("DELETE FROM payments WHERE id=? AND course_id=?");
    $stmt->execute([$payment_id, $course_id]);
    redirect_pay($course_id, 'Pagesa u fshi.');

  } else {
    redirect_pay($course_id, 'Veprim i panjohur.', true);
  }

} catch (PDOException $e) {
  redirect_pay($course_id, 'Gabim DB: '.$e->getMessage(), true);
}
