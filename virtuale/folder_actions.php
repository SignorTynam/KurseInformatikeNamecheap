<?php
declare(strict_types=1);

session_start();

require_once __DIR__ . '/lib/database.php';
require_once __DIR__ . '/lib/sections_utils.php';

if (!isset($_SESSION['user']) || !in_array($_SESSION['user']['role'] ?? '', ['Administrator', 'Instruktor'], true)) {
  http_response_code(403);
  exit('403');
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

$action   = (string)($_POST['action'] ?? '');
$courseId = (int)($_POST['course_id'] ?? 0);
$sectionId = (int)($_POST['section_id'] ?? 0);
$folderId = (int)($_POST['folder_id'] ?? 0);
$title    = trim((string)($_POST['title'] ?? ''));
$description = trim((string)($_POST['description'] ?? ''));
$lessonIds = array_values(array_unique(array_map('intval', (array)($_POST['lesson_ids'] ?? []))));

if ($courseId <= 0) {
  http_response_code(400);
  exit('Missing course_id');
}

function fa_redirect(int $courseId, bool $ok, string $msg): void {
  $_SESSION['flash'] = ['msg' => $msg, 'type' => $ok ? 'success' : 'danger'];
  header('Location: course_details.php?course_id=' . $courseId . '&tab=materials');
  exit;
}

function fa_table_has_column(PDO $pdo, string $table, string $column): bool {
  try {
    $st = $pdo->prepare("SHOW COLUMNS FROM `{$table}` LIKE ?");
    $st->execute([$column]);
    return (bool)$st->fetch(PDO::FETCH_ASSOC);
  } catch (Throwable $e) {
    return false;
  }
}

function fa_validate_owner(PDO $pdo, int $courseId): bool {
  $role = (string)($_SESSION['user']['role'] ?? '');
  if ($role === 'Administrator') return true;

  $meId = (int)($_SESSION['user']['id'] ?? 0);
  $q = $pdo->prepare('SELECT id_creator FROM courses WHERE id=?');
  $q->execute([$courseId]);
  return (int)$q->fetchColumn() === $meId;
}

if (!fa_validate_owner($pdo, $courseId)) {
  http_response_code(403);
  exit('No access');
}

if ($title === '') {
  fa_redirect($courseId, false, 'Titulli i folder-it eshte i detyrueshem.');
}

$sectionCheck = true;
if ($sectionId !== 0) {
  $qSec = $pdo->prepare('SELECT COUNT(*) FROM sections WHERE id=? AND course_id=?');
  $qSec->execute([$sectionId, $courseId]);
  $sectionCheck = ((int)$qSec->fetchColumn() > 0);
}
if (!$sectionCheck) {
  fa_redirect($courseId, false, 'Seksioni i zgjedhur nuk u gjet.');
}

try {
  $pdo->beginTransaction();

  $allowedLessons = [];
  if ($lessonIds) {
    $ph = implode(',', array_fill(0, count($lessonIds), '?'));
    $qAllowed = $pdo->prepare("\n      SELECT id\n      FROM lessons\n      WHERE course_id=?\n        AND id IN ($ph)\n        AND UPPER(COALESCE(category,'')) IN ('FILE','VIDEO')\n    ");
    $qAllowed->execute(array_merge([$courseId], $lessonIds));
    $allowedLessons = array_map('intval', array_column($qAllowed->fetchAll(PDO::FETCH_ASSOC), 'id'));
  }

  if ($action === 'create') {
    $insFolder = $pdo->prepare("\n      INSERT INTO section_folders (course_id, title, description, created_by, created_at, updated_at)\n      VALUES (?,?,?,?,NOW(),NOW())\n    ");
    $insFolder->execute([
      $courseId,
      $title,
      ($description !== '' ? $description : null),
      (int)($_SESSION['user']['id'] ?? 0),
    ]);
    $folderId = (int)$pdo->lastInsertId();

    $pos = si_next_pos($pdo, $courseId, $sectionId);
    $siHasArea = fa_table_has_column($pdo, 'section_items', 'area');

    if ($siHasArea) {
      $insSI = $pdo->prepare("\n        INSERT INTO section_items (course_id, area, section_id, item_type, item_ref_id, hidden, position, created_at, updated_at)\n        VALUES (?, 'MATERIALS', ?, 'FOLDER', ?, 0, ?, NOW(), NOW())\n      ");
      $insSI->execute([$courseId, $sectionId, $folderId, $pos]);
    } else {
      $insSI = $pdo->prepare("\n        INSERT INTO section_items (course_id, section_id, item_type, item_ref_id, hidden, position, created_at, updated_at)\n        VALUES (?, ?, 'FOLDER', ?, 0, ?, NOW(), NOW())\n      ");
      $insSI->execute([$courseId, $sectionId, $folderId, $pos]);
    }
  } elseif ($action === 'update') {
    if ($folderId <= 0) {
      throw new RuntimeException('Folder i pavlefshem.');
    }

    $chk = $pdo->prepare('SELECT id FROM section_folders WHERE id=? AND course_id=?');
    $chk->execute([$folderId, $courseId]);
    if (!$chk->fetch(PDO::FETCH_ASSOC)) {
      throw new RuntimeException('Folder-i nuk u gjet.');
    }

    $updFolder = $pdo->prepare('UPDATE section_folders SET title=?, description=?, updated_at=NOW() WHERE id=? AND course_id=?');
    $updFolder->execute([$title, ($description !== '' ? $description : null), $folderId, $courseId]);

    $pdo->prepare('DELETE FROM section_folder_items WHERE folder_id=?')->execute([$folderId]);
  } else {
    throw new RuntimeException('Veprim i panjohur.');
  }

  if ($folderId > 0 && $allowedLessons) {
    $insItem = $pdo->prepare("\n      INSERT INTO section_folder_items (folder_id, lesson_id, position, created_at)\n      VALUES (?,?,?,NOW())\n    ");
    $pos = 1;
    foreach ($allowedLessons as $lessonId) {
      $insItem->execute([$folderId, $lessonId, $pos++]);
    }
  }

  $pdo->commit();
  fa_redirect($courseId, true, $action === 'create' ? 'Folder-i u krijua me sukses.' : 'Folder-i u perditesua me sukses.');
} catch (Throwable $e) {
  if ($pdo->inTransaction()) $pdo->rollBack();
  fa_redirect($courseId, false, 'Gabim: ' . $e->getMessage());
}
