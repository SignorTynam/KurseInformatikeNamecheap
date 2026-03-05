<?php
// copy_sections.php
declare(strict_types=1);

session_start();

require_once __DIR__ . '/lib/database.php';
require_once __DIR__ . '/lib/copy_utils.php';

function is_ajax(): bool {
  return (
    isset($_SERVER['HTTP_X_REQUESTED_WITH']) &&
    strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest'
  ) || (
    isset($_SERVER['HTTP_ACCEPT']) &&
    str_contains($_SERVER['HTTP_ACCEPT'], 'application/json')
  );
}

function json_out(array $arr): never {
  header('Content-Type: application/json; charset=UTF-8');
  echo json_encode($arr, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
  exit;
}

function strip_ok_error(string $url): string {
  $p = parse_url($url);
  $path = ($p['path'] ?? '') ?: $url;
  $qs = [];
  if (!empty($p['query'])) {
    parse_str($p['query'], $qs);
    unset($qs['ok'], $qs['error']);
  }
  $clean = $path;
  if (!empty($qs)) $clean .= '?' . http_build_query($qs);
  return $clean;
}

function flash_and_redirect(string $url, string $msg, string $type='success'): never {
  $_SESSION['flash'] = ['msg'=>$msg, 'type'=>$type];
  header('Location: ' . strip_ok_error($url));
  exit;
}

/* ======= AUTH ======= */
if (!isset($_SESSION['user']) || !in_array($_SESSION['user']['role'] ?? '', ['Administrator','Instruktor'], true)) {
  if (is_ajax()) json_out(['ok' => false, 'error' => 'Unauthorized']);
  header('Location: login.php');
  exit;
}

$ROLE  = (string)$_SESSION['user']['role'];
$ME_ID = (int)($_SESSION['user']['id'] ?? 0);

/* ======= INPUT ======= */
$in = ($_SERVER['REQUEST_METHOD'] === 'POST') ? $_POST : $_GET;

$csrf           = (string)($in['csrf'] ?? '');
$sourceCourseId = (int)($in['source_course_id'] ?? 0);
$targetCourseId = (int)($in['course_id'] ?? ($in['target_course_id'] ?? 0));
$sourceSections = $in['source_section_ids'] ?? $in['source_section_id'] ?? [];

$redirectUrl = (string)(
  $in['return_to']
  ?? ($_SERVER['HTTP_REFERER'] ?? "course_details.php?course_id={$targetCourseId}&tab=materials")
);

/* normalizo sourceSections */
if (is_string($sourceSections)) $sourceSections = [$sourceSections];
$sourceSections = array_values(array_unique(array_map('intval', (array)$sourceSections)));

/* ======= CSRF ======= */
if (!$csrf || !hash_equals((string)($_SESSION['csrf_token'] ?? ''), $csrf)) {
  $msg = 'CSRF i pavlefshëm.';
  if (is_ajax()) json_out(['ok' => false, 'error' => $msg]);
  flash_and_redirect($redirectUrl, $msg, 'danger');
}

if ($sourceCourseId <= 0 || $targetCourseId <= 0 || empty($sourceSections)) {
  $msg = 'Të dhëna të mangëta.';
  if (is_ajax()) json_out(['ok' => false, 'error' => $msg]);
  flash_and_redirect($redirectUrl, $msg, 'danger');
}

/* ======= ACCESS CHECK ======= */
try {
  $q = $pdo->prepare("SELECT id, id_creator FROM courses WHERE id IN (?, ?)");
  $q->execute([$targetCourseId, $sourceCourseId]);

  $srcOk = false; $tgtOk = false;
  while ($r = $q->fetch(PDO::FETCH_ASSOC)) {
    $cid = (int)$r['id'];
    $creatorId = (int)$r['id_creator'];

    if ($cid === $targetCourseId) $tgtOk = ($ROLE === 'Administrator' || $creatorId === $ME_ID);
    if ($cid === $sourceCourseId) $srcOk = ($ROLE === 'Administrator' || $creatorId === $ME_ID);
  }

  if (!$srcOk || !$tgtOk) {
    $msg = 'Nuk keni akses për këtë veprim.';
    if (is_ajax()) json_out(['ok' => false, 'error' => $msg]);
    flash_and_redirect($redirectUrl, $msg, 'danger');
  }
} catch (Throwable $e) {
  $msg = 'Gabim akses: ' . $e->getMessage();
  if (is_ajax()) json_out(['ok' => false, 'error' => $msg]);
  flash_and_redirect($redirectUrl, $msg, 'danger');
}

/* ======= COPY ======= */
$started = _tx_start_if_needed($pdo);

try {
  $results = [];

  foreach ($sourceSections as $sid) {
    $sid = (int)$sid;
    if ($sid <= 0) continue;

    $res = copy_section_with_items($pdo, $sourceCourseId, $sid, $targetCourseId);

    if (!($res['ok'] ?? false)) {
      throw new RuntimeException('S’u kopjua seksioni #' . $sid . ': ' . (string)($res['error'] ?? 'gabim.'));
    }

    $results[] = $res['new_section_id'] ?? null;
  }

  _tx_commit_if_started($pdo, $started);

  $msg = 'U kopjuan ' . count($results) . ' seksione (të fshehura).';

  if (is_ajax()) {
    json_out(['ok' => true, 'message' => $msg, 'new_section_ids' => $results]);
  }

  flash_and_redirect($redirectUrl, $msg, 'success');

} catch (Throwable $e) {
  _tx_rollback_if_started($pdo, $started);
  $msg = 'Gabim: ' . $e->getMessage();
  if (is_ajax()) json_out(['ok' => false, 'error' => $msg]);
  flash_and_redirect($redirectUrl, $msg, 'danger');
}
