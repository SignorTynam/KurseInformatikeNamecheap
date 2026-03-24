<?php
declare(strict_types=1);

session_start();
require_once __DIR__ . '/../lib/database.php';

if (!isset($_SESSION['user']) || !in_array($_SESSION['user']['role'] ?? '', ['Administrator', 'Instruktor'], true)) {
  header('Location: ../login.php');
  exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
  http_response_code(405);
  exit('Method Not Allowed');
}

$csrf = (string)($_POST['csrf'] ?? '');
if ($csrf === '' || !hash_equals((string)($_SESSION['csrf_token'] ?? ''), $csrf)) {
  http_response_code(403);
  exit('CSRF');
}

$courseId = (int)($_POST['course_id'] ?? 0);
$folderId = (int)($_POST['folder_id'] ?? 0);

function df_redirect(int $courseId, bool $ok, string $msg): void {
  $_SESSION['flash'] = ['msg' => $msg, 'type' => $ok ? 'success' : 'danger'];
  header('Location: ../course_details.php?course_id=' . $courseId . '&tab=materials');
  exit;
}

function df_validate_owner(PDO $pdo, int $courseId): bool {
  $role = (string)($_SESSION['user']['role'] ?? '');
  if ($role === 'Administrator') return true;

  $meId = (int)($_SESSION['user']['id'] ?? 0);
  $q = $pdo->prepare('SELECT id_creator FROM courses WHERE id=?');
  $q->execute([$courseId]);
  return (int)$q->fetchColumn() === $meId;
}

if ($courseId <= 0 || $folderId <= 0) {
  http_response_code(400);
  exit('Bad payload');
}

if (!df_validate_owner($pdo, $courseId)) {
  http_response_code(403);
  exit('No access');
}

try {
  $pdo->beginTransaction();

  $pdo->prepare('DELETE FROM section_folder_items WHERE folder_id=?')->execute([$folderId]);
  $pdo->prepare("DELETE FROM section_items WHERE course_id=? AND item_type='FOLDER' AND item_ref_id=?")->execute([$courseId, $folderId]);
  $pdo->prepare('DELETE FROM section_folders WHERE id=? AND course_id=?')->execute([$folderId, $courseId]);

  $pdo->commit();
  df_redirect($courseId, true, 'Folder-i u fshi me sukses.');
} catch (Throwable $e) {
  if ($pdo->inTransaction()) $pdo->rollBack();
  df_redirect($courseId, false, 'Gabim: ' . $e->getMessage());
}
