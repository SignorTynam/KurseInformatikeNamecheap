<?php
// section_actions.php — CRUD + hide/unhide + highlight/unhighlight për seksionet
declare(strict_types=1);

session_start();

$ROOT = dirname(__DIR__);
$scriptDir = str_replace('\\', '/', dirname((string)($_SERVER['SCRIPT_NAME'] ?? '')));
$BASE_URL = $scriptDir;
foreach (['/threads', '/quizzes', '/sections'] as $suffix) {
  if ($suffix !== '/' && str_ends_with($BASE_URL, $suffix)) {
    $BASE_URL = substr($BASE_URL, 0, -strlen($suffix));
  }
}
if ($BASE_URL === '') $BASE_URL = '/';

require_once $ROOT . '/lib/database.php';

/* ---------------- RBAC ---------------- */
if (!isset($_SESSION['user']) || !in_array($_SESSION['user']['role'] ?? '', ['Administrator','Instruktor'], true)) {
  header('Location: ' . $BASE_URL . '/login.php');
  exit;
}
$ROLE  = (string)$_SESSION['user']['role'];
$ME_ID = (int)($_SESSION['user']['id'] ?? 0);

/* ---------------- Helpers ------------ */
function redirect_sections(int $course_id, ?string $ok = null, ?string $error = null): void {
  if ($ok) {
    $_SESSION['flash'] = ['msg'=>$ok, 'type'=>'success'];
  } elseif ($error) {
    $_SESSION['flash'] = ['msg'=>$error, 'type'=>'danger'];
  }
  global $BASE_URL;
  header('Location: ' . $BASE_URL . '/course_details.php?' . http_build_query(['course_id'=>$course_id, 'tab'=>'materials']));
  exit;
}

function fetch_course(PDO $pdo, int $course_id): ?array {
  $q = $pdo->prepare("
    SELECT c.*, u.id AS creator_id
    FROM courses c
    LEFT JOIN users u ON u.id = c.id_creator
    WHERE c.id = ?
  ");
  $q->execute([$course_id]);
  return $q->fetch(PDO::FETCH_ASSOC) ?: null;
}

function assert_instructor_owns(PDO $pdo, string $role, int $me, int $course_id): void {
  if ($role !== 'Instruktor') return;
  $q = $pdo->prepare("SELECT id_creator FROM courses WHERE id=?");
  $q->execute([$course_id]);
  $owner = (int)$q->fetchColumn();
  if ($owner !== $me) {
    redirect_sections($course_id, null, 'Nuk keni akses për këtë kurs.');
  }
}

/* ---------------- Method & CSRF ------- */
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
  http_response_code(405);
  echo 'Metodë e pavlefshme.';
  exit;
}

if (
  empty($_POST['csrf']) ||
  empty($_SESSION['csrf_token']) ||
  !hash_equals((string)$_SESSION['csrf_token'], (string)$_POST['csrf'])
) {
  $cid = (int)($_POST['course_id'] ?? 0);
  if ($cid > 0) redirect_sections($cid, null, 'Sesioni ka skaduar. Rifresko faqen dhe provo sërish.');
  http_response_code(403);
  echo 'CSRF i pavlefshëm.';
  exit;
}

/* ---------------- Inputs -------------- */
$action    = (string)($_POST['action'] ?? '');
$course_id = (int)($_POST['course_id'] ?? 0);

if ($course_id <= 0) {
  http_response_code(400);
  echo 'Kursi mungon.';
  exit;
}

$course = fetch_course($pdo, $course_id);
if (!$course) redirect_sections($course_id, null, 'Kursi nuk u gjet.');
assert_instructor_owns($pdo, $ROLE, $ME_ID, $course_id);

/* -------------- Utilities ------------- */
function next_position(PDO $pdo, int $course_id): int {
  $stmt = $pdo->prepare("SELECT COALESCE(MAX(position),0) FROM sections WHERE course_id=?");
  $stmt->execute([$course_id]);
  return ((int)$stmt->fetchColumn()) + 1;
}

function position_exists(PDO $pdo, int $course_id, int $position, ?int $exclude_id = null): bool {
  if ($exclude_id !== null) {
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM sections WHERE course_id=? AND position=? AND id<>?");
    $stmt->execute([$course_id, $position, $exclude_id]);
  } else {
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM sections WHERE course_id=? AND position=?");
    $stmt->execute([$course_id, $position]);
  }
  return ((int)$stmt->fetchColumn()) > 0;
}

/**
 * Fshin një seksion dhe të gjitha materialet e lidhura me të.
 * (Përmirësim: fshin edhe assignments_files).
 */
function delete_section_and_items(PDO $pdo, int $course_id, int $section_id): void {
  $q = $pdo->prepare("
    SELECT id, item_type, item_ref_id
    FROM section_items
    WHERE course_id=? AND section_id=?
  ");
  $q->execute([$course_id, $section_id]);
  $items = $q->fetchAll(PDO::FETCH_ASSOC);

  foreach ($items as $it) {
    $si_id = (int)$it['id'];
    $typ   = (string)$it['item_type'];
    $ref   = (int)$it['item_ref_id'];

    if ($typ === 'TEXT') {
      $pdo->prepare("DELETE FROM section_items WHERE id=?")->execute([$si_id]);
      continue;
    }

    if ($typ === 'LESSON') {
      // cleanup files (MyISAM)
      try { $pdo->prepare("DELETE FROM lesson_files WHERE lesson_id=?")->execute([$ref]); } catch (Throwable $__) {}
      // heq lidhjen + fshi leksionin
      $pdo->prepare("DELETE FROM section_items WHERE id=?")->execute([$si_id]);
      $pdo->prepare("DELETE FROM lessons WHERE id=? AND course_id=?")->execute([$ref, $course_id]);
      continue;
    }

    if ($typ === 'ASSIGNMENT') {
      try { $pdo->prepare("DELETE FROM assignments_submitted WHERE assignment_id=?")->execute([$ref]); } catch (Throwable $__) {}
      try { $pdo->prepare("DELETE FROM assignments_files WHERE assignment_id=?")->execute([$ref]); } catch (Throwable $__) {}
      $pdo->prepare("DELETE FROM section_items WHERE id=?")->execute([$si_id]);
      $pdo->prepare("DELETE FROM assignments WHERE id=? AND course_id=?")->execute([$ref, $course_id]);
      continue;
    }

    if ($typ === 'QUIZ') {
      // me FK CASCADE mjafton delete quizzes; por heqim lidhjen dhe pastaj quiz
      $pdo->prepare("DELETE FROM section_items WHERE id=?")->execute([$si_id]);
      $pdo->prepare("DELETE FROM quizzes WHERE id=? AND course_id=?")->execute([$ref, $course_id]);
      continue;
    }

    // fallback: vetëm hiq lidhjen
    $pdo->prepare("DELETE FROM section_items WHERE id=?")->execute([$si_id]);
  }

  // Fshi vetë seksionin
  $pdo->prepare("DELETE FROM sections WHERE id=? AND course_id=?")->execute([$section_id, $course_id]);
}

/* ---------------- Do action ---------- */
try {
  switch ($action) {
    case 'create': {
      $title = trim((string)($_POST['title'] ?? ''));
      $description = (string)($_POST['description_md'] ?? $_POST['description'] ?? '');
      $posIn = isset($_POST['position']) && $_POST['position'] !== '' ? (int)$_POST['position'] : 0;

      if ($title === '') redirect_sections($course_id, null, 'Titulli i seksionit është i detyrueshëm.');

      if ($posIn < 1) $posIn = next_position($pdo, $course_id);
      elseif (position_exists($pdo, $course_id, $posIn)) {
        redirect_sections($course_id, null, 'Pozicioni i zgjedhur ekziston tashmë për këtë kurs.');
      }

      $stmt = $pdo->prepare("
        INSERT INTO sections (course_id, title, description, position, hidden, highlighted)
        VALUES (?,?,?,?,0,0)
      ");
      $stmt->execute([$course_id, $title, $description !== '' ? $description : null, $posIn]);

      redirect_sections($course_id, 'Seksioni u shtua me sukses.');
    }

    case 'update': {
      $section_id = (int)($_POST['section_id'] ?? 0);
      if ($section_id <= 0) redirect_sections($course_id, null, 'Seksioni mungon.');

      $chk = $pdo->prepare("SELECT id FROM sections WHERE id=? AND course_id=?");
      $chk->execute([$section_id, $course_id]);
      if (!$chk->fetch(PDO::FETCH_ASSOC)) redirect_sections($course_id, null, 'Seksioni nuk u gjet për këtë kurs.');

      $title = trim((string)($_POST['title'] ?? ''));
      $description = (string)($_POST['description_md'] ?? $_POST['description'] ?? '');
      $posIn = (int)($_POST['position'] ?? 0);

      if ($title === '' || $posIn < 1) {
        redirect_sections($course_id, null, 'Titulli dhe pozicioni janë të detyrueshëm (pozicioni ≥ 1).');
      }

      if (position_exists($pdo, $course_id, $posIn, $section_id)) {
        redirect_sections($course_id, null, 'Ky pozicion është i zënë nga një seksion tjetër në këtë kurs.');
      }

      $stmt = $pdo->prepare("
        UPDATE sections
        SET title=?, description=?, position=?
        WHERE id=? AND course_id=?
      ");
      $stmt->execute([$title, $description !== '' ? $description : null, $posIn, $section_id, $course_id]);

      redirect_sections($course_id, 'Seksioni u përditësua.');
    }

    case 'hide':
    case 'unhide': {
      $section_id = (int)($_POST['section_id'] ?? 0);
      if ($section_id <= 0) redirect_sections($course_id, null, 'Seksioni mungon.');

      $chk = $pdo->prepare("SELECT id FROM sections WHERE id=? AND course_id=?");
      $chk->execute([$section_id, $course_id]);
      if (!$chk->fetch(PDO::FETCH_ASSOC)) redirect_sections($course_id, null, 'Seksioni nuk u gjet për këtë kurs.');

      $hidden = $action === 'hide' ? 1 : 0;
      $pdo->prepare("UPDATE sections SET hidden=? WHERE id=? AND course_id=?")->execute([$hidden, $section_id, $course_id]);

      redirect_sections($course_id, $hidden ? 'Seksioni u fsheh.' : 'Seksioni u bë publik.');
    }

    case 'delete': {
      $section_id = (int)($_POST['section_id'] ?? 0);
      if ($section_id <= 0) redirect_sections($course_id, null, 'Seksioni mungon.');

      $chk = $pdo->prepare("SELECT id FROM sections WHERE id=? AND course_id=?");
      $chk->execute([$section_id, $course_id]);
      if (!$chk->fetch(PDO::FETCH_ASSOC)) redirect_sections($course_id, null, 'Seksioni nuk u gjet për këtë kurs.');

      $pdo->beginTransaction();
      delete_section_and_items($pdo, $course_id, $section_id);
      $pdo->commit();

      redirect_sections($course_id, 'Seksioni u fshi bashkë me materialet e tij.');
    }

    case 'highlight':
    case 'unhighlight': {
      $sid = (int)($_POST['section_id'] ?? 0);
      if ($sid <= 0) redirect_sections($course_id, null, 'Seksioni mungon.');

      $chk = $pdo->prepare("SELECT id FROM sections WHERE id=? AND course_id=?");
      $chk->execute([$sid, $course_id]);
      if (!$chk->fetch(PDO::FETCH_ASSOC)) redirect_sections($course_id, null, 'Seksioni nuk u gjet për këtë kurs.');

      $val = ($action === 'highlight') ? 1 : 0;
      $pdo->prepare("UPDATE sections SET highlighted=? WHERE id=? AND course_id=?")->execute([$val, $sid, $course_id]);

      redirect_sections($course_id, $val ? 'U vendos highlight.' : 'U hoq highlight.');
    }

    default:
      redirect_sections($course_id, null, 'Veprim i panjohur.');
  }
} catch (Throwable $e) {
  if ($pdo->inTransaction()) $pdo->rollBack();
  redirect_sections($course_id, null, 'Gabim: ' . $e->getMessage());
}
