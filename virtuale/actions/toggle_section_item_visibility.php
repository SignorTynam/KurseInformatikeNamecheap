<?php
// toggle_section_item_visibility.php — Shfaq/Fshih një element të section_items (LESSON/ASSIGNMENT/QUIZ/TEXT)
declare(strict_types=1);
session_start();

$ROOT = dirname(__DIR__);
$scriptDir = str_replace('\\', '/', dirname((string)($_SERVER['SCRIPT_NAME'] ?? '')));
$BASE_URL = $scriptDir;
foreach (['/threads', '/quizzes', '/sections', '/assignments', '/lessons', '/actions'] as $suffix) {
  if ($suffix !== '/' && str_ends_with($BASE_URL, $suffix)) {
    $BASE_URL = substr($BASE_URL, 0, -strlen($suffix));
  }
}
if ($BASE_URL === '') $BASE_URL = '/';

require_once $ROOT . '/lib/database.php';

/* ------------- RBAC ------------- */
if (!isset($_SESSION['user']) || !in_array($_SESSION['user']['role'] ?? '', ['Administrator','Instruktor'], true)) {
  header('Location: ' . $BASE_URL . '/login.php');
  exit;
}
$ROLE  = (string)($_SESSION['user']['role'] ?? '');
$ME_ID = (int)($_SESSION['user']['id'] ?? 0);

/* ------------- Helpers ---------- */
function h(?string $s): string { return htmlspecialchars($s ?? '', ENT_QUOTES, 'UTF-8'); }

function table_has_column(PDO $pdo, string $table, string $column): bool {
  static $cache = [];
  $k = strtolower($table . '.' . $column);
  if (array_key_exists($k, $cache)) return (bool)$cache[$k];
  try {
    $st = $pdo->prepare("SHOW COLUMNS FROM `{$table}` LIKE ?");
    $st->execute([$column]);
    $cache[$k] = (bool)$st->fetch(PDO::FETCH_ASSOC);
  } catch (Throwable $e) {
    $cache[$k] = false;
  }
  return (bool)$cache[$k];
}

/* ------------- Inputs ----------- */
$si_id  = isset($_GET['si_id']) ? (int)$_GET['si_id'] : 0;
$action = isset($_GET['action']) ? (string)$_GET['action'] : ''; // 'hide' | 'unhide' | ''
$return = isset($_GET['return']) ? (string)$_GET['return'] : '';
if ($si_id <= 0) { http_response_code(400); die('Parametra të pavlefshëm.'); }

try {
  // Lexo item + kursin (për RBAC)
  $stmt = $pdo->prepare("
    SELECT si.id, si.course_id, si.item_type, si.item_ref_id, si.hidden, c.id_creator
    FROM section_items si
    JOIN courses c ON c.id = si.course_id
    WHERE si.id = ?
    LIMIT 1
  ");
  $stmt->execute([$si_id]);
  $row = $stmt->fetch(PDO::FETCH_ASSOC);
  if (!$row) { http_response_code(404); die('Elementi nuk u gjet.'); }

  if ($ROLE === 'Instruktor' && (int)($row['id_creator'] ?? 0) !== $ME_ID) {
    http_response_code(403); die('Nuk keni akses.');
  }

  $courseId = (int)($row['course_id'] ?? 0);
  $type     = (string)($row['item_type'] ?? '');
  $refId    = (int)($row['item_ref_id'] ?? 0);

  $current = (int)($row['hidden'] ?? 0) === 1;
  if ($action === 'hide')       { $new = 1; }
  elseif ($action === 'unhide') { $new = 0; }
  else                          { $new = $current ? 0 : 1; }

  // section_items.hidden (source of truth for Materials listing)
  $upd = $pdo->prepare("UPDATE section_items SET hidden=?, updated_at=NOW() WHERE id=?");
  $upd->execute([$new, $si_id]);

  // Opsionale: sinkronizo hidden edhe te tabela bazë (kur ekziston)
  if ($refId > 0) {
    if ($type === 'ASSIGNMENT' && table_has_column($pdo, 'assignments', 'hidden')) {
      $pdo->prepare("UPDATE assignments SET hidden=? WHERE id=? AND course_id=?")
          ->execute([$new, $refId, $courseId]);
    } elseif ($type === 'QUIZ' && table_has_column($pdo, 'quizzes', 'hidden')) {
      $pdo->prepare("UPDATE quizzes SET hidden=? WHERE id=? AND course_id=?")
          ->execute([$new, $refId, $courseId]);
    } elseif ($type === 'LESSON' && table_has_column($pdo, 'lessons', 'hidden')) {
      $pdo->prepare("UPDATE lessons SET hidden=? WHERE id=? AND course_id=?")
          ->execute([$new, $refId, $courseId]);
    }
  }

  // Redirect: prefer "return" (relative, same-site) if provided
  $redirect = $BASE_URL . '/course_details.php?course_id=' . $courseId . '&tab=materials';
  if ($return !== '') {
    $r = str_replace('\\', '/', trim($return));
    $looksExternal = str_contains($r, '://') || str_starts_with($r, '//');
    $hasTraversal  = str_contains($r, '..');
    if (!$looksExternal && !$hasTraversal) {
      $redirect = $BASE_URL . '/' . ltrim($r, '/');
    }
  }

  header('Location: ' . $redirect);
  exit;

} catch (Throwable $e) {
  http_response_code(500);
  die('Gabim: ' . h($e->getMessage()));
}
