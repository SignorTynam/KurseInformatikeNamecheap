<?php
// block_text_actions.php — CRUD për TEXT brenda seksioneve
declare(strict_types=1);

session_start();
require_once __DIR__ . '/lib/database.php';
require_once __DIR__ . '/lib/sections_utils.php';

/* -------------------- RBAC -------------------- */
if (!isset($_SESSION['user']) || !in_array($_SESSION['user']['role'] ?? '', ['Administrator','Instruktor'], true)) {
  http_response_code(403);
  exit('403');
}

/* -------------------- Method ------------------ */
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
  http_response_code(405);
  exit('Method Not Allowed');
}

/* -------------------- CSRF -------------------- */
if (empty($_SESSION['csrf_token'])) {
  $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

$csrf = (string)($_POST['csrf'] ?? '');
if ($csrf === '' || !hash_equals((string)$_SESSION['csrf_token'], $csrf)) {
  http_response_code(403);
  exit('CSRF');
}

/* -------------------- Helpers ----------------- */
function norm_area(string $area): string {
  $a = strtoupper(trim($area));
  return in_array($a, ['MATERIALS','LABS'], true) ? $a : 'MATERIALS';
}

function redirect_with_msg(int $courseId, string $area, bool $ok, string $msg): void {
  // Në skemën aktuale s’ka area në DB, por e mbajmë për UI/tab
  $tab = ($area === 'LABS') ? 'labs' : 'materials';
  $_SESSION['flash'] = ['msg'=>$msg, 'type'=>($ok ? 'success' : 'danger')];
  header('Location: course_details.php?course_id='.$courseId.'&tab='.$tab);
  exit;
}

function ensure_instructor_owns(PDO $pdo, int $courseId): void {
  $role = (string)($_SESSION['user']['role'] ?? '');
  if ($role !== 'Instruktor') return;

  $meId = (int)($_SESSION['user']['id'] ?? 0);
  $q = $pdo->prepare("SELECT id_creator FROM courses WHERE id=?");
  $q->execute([$courseId]);
  $creatorId = (int)($q->fetchColumn() ?: 0);

  if ($creatorId !== $meId) {
    http_response_code(403);
    exit('No access');
  }
}

/* -------------------- Inputs ------------------ */
$action   = (string)($_POST['action'] ?? '');
$courseId = (int)($_POST['course_id'] ?? 0);
$area     = norm_area((string)($_POST['area'] ?? 'MATERIALS'));

if ($courseId <= 0) {
  http_response_code(400);
  exit('Missing course_id');
}

try {
  ensure_instructor_owns($pdo, $courseId);
} catch (Throwable $e) {
  http_response_code(500);
  exit('DB');
}

/* -------------------- CREATE ------------------- */
if ($action === 'create') {
  $sectionId = (int)($_POST['section_id'] ?? 0);
  $contentMd = trim((string)($_POST['content_md'] ?? ''));

  if ($sectionId <= 0) {
    redirect_with_msg($courseId, $area, false, 'Seksioni është i detyrueshëm.');
  }
  if ($contentMd === '') {
    redirect_with_msg($courseId, $area, false, 'Përmbajtja është e detyrueshme.');
  }

  // verifiko që seksioni i përket kursit
  $st = $pdo->prepare("SELECT COUNT(*) FROM sections WHERE id=? AND course_id=?");
  $st->execute([$sectionId, $courseId]);
  if ((int)$st->fetchColumn() === 0) {
    redirect_with_msg($courseId, $area, false, 'Seksioni nuk u gjet në këtë kurs.');
  }

  try {
    // pozicioni (gap-aware) – area injorohet nëse DB s’e ka
    $pos = si_next_pos($pdo, $courseId, $sectionId, $area);

    $siHasArea = function_exists('table_has_column') ? table_has_column($pdo, 'section_items', 'area') : false;

    if ($siHasArea) {
      $ins = $pdo->prepare("
        INSERT INTO section_items (course_id, area, section_id, item_type, item_ref_id, content_md, hidden, position, created_at, updated_at)
        VALUES (?, ?, ?, 'TEXT', NULL, ?, 0, ?, NOW(), NOW())
      ");
      $ok = $ins->execute([$courseId, $area, $sectionId, $contentMd, $pos]);
    } else {
      $ins = $pdo->prepare("
        INSERT INTO section_items (course_id, section_id, item_type, item_ref_id, content_md, hidden, position, created_at, updated_at)
        VALUES (?, ?, 'TEXT', NULL, ?, 0, ?, NOW(), NOW())
      ");
      $ok = $ins->execute([$courseId, $sectionId, $contentMd, $pos]);
    }

    redirect_with_msg($courseId, $area, (bool)$ok, $ok ? 'Teksti u shtua.' : 'S’u shtua.');
  } catch (Throwable $e) {
    redirect_with_msg($courseId, $area, false, 'Gabim DB: '.$e->getMessage());
  }
}

/* -------------------- UPDATE ------------------- */
if ($action === 'update') {
  $siId      = (int)($_POST['si_id'] ?? ($_POST['section_item_id'] ?? 0));
  $contentMd = trim((string)($_POST['content_md'] ?? ''));

  if ($siId <= 0) {
    redirect_with_msg($courseId, $area, false, 'Item mungon.');
  }
  if ($contentMd === '') {
    redirect_with_msg($courseId, $area, false, 'Përmbajtja është e detyrueshme.');
  }

  try {
    $siHasArea = function_exists('table_has_column') ? table_has_column($pdo, 'section_items', 'area') : false;

    if ($siHasArea) {
      $q = $pdo->prepare("SELECT course_id, area FROM section_items WHERE id=? AND item_type='TEXT'");
      $q->execute([$siId]);
      $row = $q->fetch(PDO::FETCH_ASSOC);
      $rowArea = (string)($row['area'] ?? 'MATERIALS');
    } else {
      $q = $pdo->prepare("SELECT course_id FROM section_items WHERE id=? AND item_type='TEXT'");
      $q->execute([$siId]);
      $row = $q->fetch(PDO::FETCH_ASSOC);
      $rowArea = $area;
    }

    if (!$row) {
      redirect_with_msg($courseId, $area, false, 'Item nuk u gjet.');
    }
    if ((int)$row['course_id'] !== $courseId) {
      http_response_code(403);
      exit('No access');
    }

    $up = $pdo->prepare("UPDATE section_items SET content_md=?, updated_at=NOW() WHERE id=? AND item_type='TEXT'");
    $ok = $up->execute([$contentMd, $siId]);

    redirect_with_msg($courseId, norm_area($rowArea), (bool)$ok, $ok ? 'Teksti u përditësua.' : 'S’u përditësua.');
  } catch (Throwable $e) {
    redirect_with_msg($courseId, $area, false, 'Gabim DB: '.$e->getMessage());
  }
}

/* -------------------- DELETE ------------------- */
if ($action === 'delete') {
  $siId = (int)($_POST['si_id'] ?? ($_POST['section_item_id'] ?? 0));
  if ($siId <= 0) {
    redirect_with_msg($courseId, $area, false, 'Item mungon.');
  }

  try {
    $siHasArea = function_exists('table_has_column') ? table_has_column($pdo, 'section_items', 'area') : false;

    if ($siHasArea) {
      $q = $pdo->prepare("SELECT course_id, area FROM section_items WHERE id=? AND item_type='TEXT'");
      $q->execute([$siId]);
      $row = $q->fetch(PDO::FETCH_ASSOC);
      $rowArea = (string)($row['area'] ?? 'MATERIALS');
    } else {
      $q = $pdo->prepare("SELECT course_id FROM section_items WHERE id=? AND item_type='TEXT'");
      $q->execute([$siId]);
      $row = $q->fetch(PDO::FETCH_ASSOC);
      $rowArea = $area;
    }

    if (!$row) {
      redirect_with_msg($courseId, $area, false, 'Item nuk u gjet.');
    }
    if ((int)$row['course_id'] !== $courseId) {
      http_response_code(403);
      exit('No access');
    }

    $del = $pdo->prepare("DELETE FROM section_items WHERE id=? AND item_type='TEXT'");
    $ok  = $del->execute([$siId]);

    redirect_with_msg($courseId, norm_area($rowArea), (bool)$ok, $ok ? 'Teksti u fshi.' : 'S’u fshi.');
  } catch (Throwable $e) {
    redirect_with_msg($courseId, $area, false, 'Gabim DB: '.$e->getMessage());
  }
}

http_response_code(400);
exit('Unknown action');
