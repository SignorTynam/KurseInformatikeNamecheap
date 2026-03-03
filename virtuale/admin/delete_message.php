<?php
declare(strict_types=1);
session_start();
require_once __DIR__ . '/../lib/database.php';

/* ------------------------------- RBAC ------------------------------- */
if (!isset($_SESSION['user']) || (string)($_SESSION['user']['role'] ?? '') !== 'Administrator') {
  header('Location: ../../login.php');
  exit;
}

/* ------------------------------- Method ----------------------------- */
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
  $_SESSION['flash'] = ['msg'=>'Kërkesë e pavlefshme.', 'type'=>'danger'];
  header('Location: ../messages.php');
  exit;
}

/* ------------------------------- CSRF ------------------------------- */
/* Kompatibilitet: në kodet e tua përdoren herë csrf_token, herë csrf */
$sessionCsrf = (string)($_SESSION['csrf_token'] ?? ($_SESSION['csrf'] ?? ''));
$postCsrf    = (string)($_POST['csrf'] ?? '');

if ($sessionCsrf === '' || $postCsrf === '' || !hash_equals($sessionCsrf, $postCsrf)) {
  $_SESSION['flash'] = ['msg'=>'CSRF token i pavlefshëm. Rifresko faqen dhe provo sërish.', 'type'=>'danger'];
  header('Location: ../messages.php');
  exit;
}

/* ------------------------------- Input ------------------------------ */
$idRaw = $_POST['id'] ?? '';
$id = filter_var($idRaw, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
if (!$id) {
  $_SESSION['flash'] = ['msg'=>'ID e mesazhit është e pavlefshme.', 'type'=>'danger'];
  header('Location: ../messages.php');
  exit;
}

/* ------------------------------ Delete ------------------------------ */
try {
  // (Opsionale por e dobishme) kontrollo që ekziston
  $chk = $pdo->prepare("SELECT id FROM messages WHERE id = :id LIMIT 1");
  $chk->execute([':id' => $id]);
  if (!$chk->fetchColumn()) {
    $_SESSION['flash'] = ['msg'=>'Mesazhi nuk u gjet ose është fshirë.', 'type'=>'danger'];
    header('Location: ../messages.php');
    exit;
  }

  $stmt = $pdo->prepare("DELETE FROM messages WHERE id = :id LIMIT 1");
  $stmt->execute([':id' => $id]);

  $_SESSION['flash'] = ['msg'=>'Mesazhi u fshi me sukses.', 'type'=>'success'];
  header('Location: ../messages.php');
  exit;

} catch (PDOException $e) {
  // Mos ekspozo detaje teknike në prodhim
  $_SESSION['flash'] = ['msg'=>'Gabim gjatë fshirjes. Provo sërish.', 'type'=>'danger'];
  header('Location: ../messages.php');
  exit;
}
