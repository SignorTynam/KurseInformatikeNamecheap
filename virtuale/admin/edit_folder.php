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
$mode = (string)($_POST['mode'] ?? 'replace'); // replace | append | remove_single | meta
$back = (string)($_POST['back'] ?? 'materials');

$title = trim((string)($_POST['title'] ?? ''));
$description = trim((string)($_POST['description'] ?? ''));
$lessonIds = array_values(array_unique(array_map('intval', (array)($_POST['lesson_ids'] ?? []))));
$lessonIdSingle = (int)($_POST['lesson_id'] ?? 0);

function ef_redirect(int $courseId, int $folderId, bool $ok, string $msg, string $back): void {
  $_SESSION['flash'] = ['msg' => $msg, 'type' => $ok ? 'success' : 'danger'];
  if ($back === 'folder_view' && $folderId > 0) {
    header('Location: ../folder_view.php?folder_id=' . $folderId);
  } else {
    header('Location: ../course_details.php?course_id=' . $courseId . '&tab=materials');
  }
  exit;
}

function ef_validate_owner(PDO $pdo, int $courseId): bool {
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

if (!ef_validate_owner($pdo, $courseId)) {
  http_response_code(403);
  exit('No access');
}

try {
  $chk = $pdo->prepare('SELECT id FROM section_folders WHERE id=? AND course_id=?');
  $chk->execute([$folderId, $courseId]);
  if (!$chk->fetch(PDO::FETCH_ASSOC)) {
    throw new RuntimeException('Folder-i nuk u gjet.');
  }

  $pdo->beginTransaction();

  if ($mode === 'meta' || $mode === 'replace') {
    if ($title === '') {
      throw new RuntimeException('Titulli i folder-it eshte i detyrueshem.');
    }
    $updFolder = $pdo->prepare('UPDATE section_folders SET title=?, description=?, updated_at=NOW() WHERE id=? AND course_id=?');
    $updFolder->execute([$title, ($description !== '' ? $description : null), $folderId, $courseId]);
  }

  if ($mode === 'replace' || $mode === 'append') {
    $allowedLessons = [];
    if ($lessonIds) {
      $ph = implode(',', array_fill(0, count($lessonIds), '?'));
      $qAllowed = $pdo->prepare("\n        SELECT id\n        FROM lessons\n        WHERE course_id=?\n          AND id IN ($ph)\n          AND UPPER(COALESCE(category,'')) IN ('FILE','VIDEO')\n      ");
      $qAllowed->execute(array_merge([$courseId], $lessonIds));
      $allowedLessons = array_map('intval', array_column($qAllowed->fetchAll(PDO::FETCH_ASSOC), 'id'));
    }

    if ($mode === 'replace') {
      $pdo->prepare('DELETE FROM section_folder_items WHERE folder_id=?')->execute([$folderId]);
      $pos = 1;
      if ($allowedLessons) {
        $insItem = $pdo->prepare("\n          INSERT INTO section_folder_items (folder_id, lesson_id, position, created_at)\n          VALUES (?,?,?,NOW())\n        ");
        foreach ($allowedLessons as $lessonId) {
          $insItem->execute([$folderId, $lessonId, $pos++]);
        }
      }
    } else {
      $qMax = $pdo->prepare('SELECT COALESCE(MAX(position),0) + 1 FROM section_folder_items WHERE folder_id=?');
      $qMax->execute([$folderId]);
      $pos = (int)$qMax->fetchColumn();

      $insItem = $pdo->prepare("\n        INSERT IGNORE INTO section_folder_items (folder_id, lesson_id, position, created_at)\n        VALUES (?,?,?,NOW())\n      ");
      foreach ($allowedLessons as $lessonId) {
        $insItem->execute([$folderId, $lessonId, $pos++]);
      }
    }
  } elseif ($mode === 'remove_single') {
    if ($lessonIdSingle <= 0) {
      throw new RuntimeException('Element i pavlefshem.');
    }
    $pdo->prepare('DELETE FROM section_folder_items WHERE folder_id=? AND lesson_id=?')->execute([$folderId, $lessonIdSingle]);
  } elseif ($mode !== 'meta') {
    throw new RuntimeException('Mode i panjohur.');
  }

  $pdo->commit();
  ef_redirect($courseId, $folderId, true, 'Folder-i u perditesua me sukses.', $back);
} catch (Throwable $e) {
  if ($pdo->inTransaction()) $pdo->rollBack();
  ef_redirect($courseId, $folderId, false, 'Gabim: ' . $e->getMessage(), $back);
}
