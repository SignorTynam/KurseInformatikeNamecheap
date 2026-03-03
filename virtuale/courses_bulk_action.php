<?php
// courses_bulk_action.php — Veprime masive mbi kurset
declare(strict_types=1);
session_start();
require_once __DIR__ . '/lib/database.php';

function h(?string $s): string { return htmlspecialchars($s ?? '', ENT_QUOTES, 'UTF-8'); }

// Auth & RBAC
if (!isset($_SESSION['user'])) { header('Location: login.php'); exit; }
$ROLE  = $_SESSION['user']['role'] ?? '';
$ME_ID = (int)($_SESSION['user']['id'] ?? 0);
if (!in_array($ROLE, ['Administrator','Instruktor'], true)) { header('Location: courses_student.php'); exit; }

// CSRF
$csrf = $_POST['csrf'] ?? '';
if (empty($_SESSION['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $csrf)) {
  $_SESSION['flash'] = ['msg'=>'Seancë e pasigurt (CSRF). Provo sërish.', 'type'=>'danger'];
  header('Location: course.php'); exit;
}

// Inputs
$idsRaw = trim((string)($_POST['ids'] ?? ''));
$action = strtolower(trim((string)($_POST['action'] ?? '')));
$validActions = ['activate','deactivate','archive','delete'];

if ($idsRaw === '' || !in_array($action, $validActions, true)) {
  $_SESSION['flash'] = ['msg'=>'Asgjë e përzgjedhur ose veprim i pavlefshëm.', 'type'=>'warning'];
  header('Location: course.php'); exit;
}

$idList = array_values(array_filter(array_map('intval', explode(',', $idsRaw)), fn($v)=>$v>0));
$idList = array_unique($idList);
if (!$idList) {
  $_SESSION['flash'] = ['msg'=>'Lista e ID-ve bosh.', 'type'=>'warning'];
  header('Location: course.php'); exit;
}

// Ngrit placeholder-a dinamike
$placeholders = implode(',', array_fill(0, count($idList), '?'));

try {
  $pdo->beginTransaction();

  // Instruktori mund të veprojë vetëm mbi kurset e veta
  if ($ROLE === 'Instruktor') {
    $checkSql = "SELECT id FROM courses WHERE id IN ($placeholders) AND id_creator = ?";
    $checkStmt = $pdo->prepare($checkSql);
    $bind = $idList; $bind[] = $ME_ID;
    $checkStmt->execute($bind);
    $owned = $checkStmt->fetchAll(PDO::FETCH_COLUMN) ?: [];
    $owned = array_map('intval', $owned);

    // filtro idList vetëm ato që zotëron
    $idList = array_values(array_intersect($idList, $owned));
    if (!$idList) {
      $pdo->rollBack();
      $_SESSION['flash'] = ['msg'=>'Nuk keni të drejtë mbi asnjë nga kurset e përzgjedhura.', 'type'=>'danger'];
      header('Location: course.php'); exit;
    }
    $placeholders = implode(',', array_fill(0, count($idList), '?'));
  }

  if ($action === 'delete') {
    $sql = "DELETE FROM courses WHERE id IN ($placeholders)";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($idList);
    $affected = $stmt->rowCount();
    $msg = "U fshinë $affected kurs(e).";
  } else {
    $newStatus = [
      'activate'   => 'ACTIVE',
      'deactivate' => 'INACTIVE',
      'archive'    => 'ARCHIVED'
    ][$action] ?? 'INACTIVE';

    $sql = "UPDATE courses SET status = ?, updated_at = CURRENT_TIMESTAMP WHERE id IN ($placeholders)";
    $stmt = $pdo->prepare($sql);
    $params = array_merge([$newStatus], $idList);
    $stmt->execute($params);
    $affected = $stmt->rowCount();
    $label = $newStatus === 'ACTIVE' ? 'aktivizuan' : ($newStatus==='INACTIVE'?'çaktivizuan':'arkivuan');
    $msg = "U $label $affected kurs(e).";
  }

  $pdo->commit();
  $_SESSION['flash'] = ['msg'=>$msg, 'type'=>'success'];
} catch (PDOException $e) {
  if ($pdo->inTransaction()) $pdo->rollBack();
  $_SESSION['flash'] = ['msg'=>'Gabim gjatë veprimit masiv: '.h($e->getMessage()), 'type'=>'danger'];
}

header('Location: course.php');
exit;
