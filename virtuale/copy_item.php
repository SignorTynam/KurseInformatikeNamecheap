<?php
declare(strict_types=1);
session_start();

/* Gjatë zhvillimit – hiqi në prod */
error_reporting(E_ALL);
ini_set('display_errors', '1');

require_once __DIR__ . '/lib/database.php';
require_once __DIR__ . '/lib/copy_utils.php';   // përfshin sections_utils.php

function is_ajax(): bool {
    return (
        isset($_SERVER['HTTP_X_REQUESTED_WITH'])
        && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest'
    ) || (
        isset($_SERVER['HTTP_ACCEPT'])
        && str_contains($_SERVER['HTTP_ACCEPT'], 'application/json')
    );
}

function json_out(array $arr): void {
    header('Content-Type: application/json; charset=UTF-8');
    echo json_encode($arr);
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
if (
    !isset($_SESSION['user'])
    || !in_array($_SESSION['user']['role'] ?? '', ['Administrator','Instruktor'], true)
) {
    if (is_ajax()) {
        json_out(['ok' => false, 'error' => 'Unauthorized']);
    }
    header('Location: login.php');
    exit;
}

$ROLE  = $_SESSION['user']['role'];
$ME_ID = (int)($_SESSION['user']['id'] ?? 0);

/* ======= INPUT ======= */
$in = ($_SERVER['REQUEST_METHOD'] === 'POST') ? $_POST : $_GET;

$csrf = (string)($in['csrf'] ?? '');

/* ✅ prano të gjitha emërtimet e mundshme për llojin */
$type = strtoupper(trim((string)(
    $in['item_type'] ?? $in['type'] ?? $in['kind'] ?? ''
)));

$sourceCourseId  = (int)($in['source_course_id'] ?? 0);
$sourceItemId    = (int)($in['source_item_id']   ?? 0);   // për TEXT: section_items.id
$targetCourseId  = (int)($in['course_id']        ?? ($in['target_course_id'] ?? 0));
$targetSectionId = (int)($in['section_id']       ?? ($in['target_section_id'] ?? 0));

/* ✅ prano edhe return_to / redirect */
$redirectUrl = (string)(
    $in['return_to']
    ?? $in['redirect']
    ?? ($_SERVER['HTTP_REFERER'] ?? "course_details.php?course_id={$targetCourseId}&tab=materials")
);

/* ======= VALIDIME ======= */
if (
    !$csrf
    || !hash_equals($_SESSION['csrf_token'] ?? '', $csrf)
) {
    $msg = 'CSRF i pavlefshëm.';
    if (is_ajax()) {
        json_out(['ok' => false, 'error' => $msg]);
    }
    flash_and_redirect($redirectUrl, $msg, 'danger');
}

if (!in_array($type, ['LESSON','ASSIGNMENT','QUIZ','TEXT'], true)) {
    $msg = 'Lloj elementi i pavlefshëm.';
    if (is_ajax()) {
        json_out(['ok' => false, 'error' => $msg]);
    }
    flash_and_redirect($redirectUrl, $msg, 'danger');
}

if ($targetCourseId <= 0 || $targetSectionId < 0 || $sourceCourseId <= 0 || $sourceItemId <= 0) {
    $msg = 'Të dhëna të mangëta.';
    if (is_ajax()) {
        json_out(['ok' => false, 'error' => $msg]);
    }
    flash_and_redirect($redirectUrl, $msg, 'danger');
}

/* ======= ACCESS CHECK ======= */
try {
    $q = $pdo->prepare("SELECT id, id_creator FROM courses WHERE id IN (?, ?)");
    $q->execute([$targetCourseId, $sourceCourseId]);

    $srcOk = $tgtOk = false;
    while ($r = $q->fetch(PDO::FETCH_ASSOC)) {
        $cid       = (int)$r['id'];
        $creatorId = (int)$r['id_creator'];

        if ($cid === $targetCourseId) {
            $tgtOk = ($ROLE === 'Administrator' || $creatorId === $ME_ID);
        }
        if ($cid === $sourceCourseId) {
            $srcOk = ($ROLE === 'Administrator' || $creatorId === $ME_ID);
        }
    }

    if (!$tgtOk || !$srcOk) {
        $msg = 'Nuk keni akses për këtë veprim.';
        if (is_ajax()) {
            json_out(['ok' => false, 'error' => $msg]);
        }
        flash_and_redirect($redirectUrl, $msg, 'danger');
    }
} catch (Throwable $e) {
    $msg = 'Gabim gjatë verifikimit të aksesit: ' . $e->getMessage();
    if (is_ajax()) {
        json_out(['ok' => false, 'error' => $msg]);
    }
    flash_and_redirect($redirectUrl, $msg, 'danger');
}

/* ======= DO COPY ======= */
try {
    $res = copy_single_item(
        $pdo,
        $targetCourseId,
        $targetSectionId,
        $type,
        (string)$sourceCourseId,  // ✅ kasto në string sipas firmës së funksionit
        $sourceItemId,
        $ME_ID
    );

    if (!($res['ok'] ?? false)) {
        $msg = 'S’u krye kopjimi: ' . (string)($res['error'] ?? 'gabim i panjohur.');
        if (is_ajax()) {
            json_out(['ok' => false, 'error' => $msg]);
        }
        flash_and_redirect($redirectUrl, $msg, 'danger');
    }

    $msg = 'Elementi u kopjua dhe u fsheh automatikisht.';
    if (is_ajax()) {
        json_out(['ok' => true, 'message' => $msg, 'data' => $res]);
    } else {
        flash_and_redirect($redirectUrl, $msg, 'success');
    }

} catch (Throwable $e) {
    $msg = 'Gabim: ' . $e->getMessage();
    if (is_ajax()) {
        json_out(['ok' => false, 'error' => $msg]);
    }
    flash_and_redirect($redirectUrl, $msg, 'danger');
}
