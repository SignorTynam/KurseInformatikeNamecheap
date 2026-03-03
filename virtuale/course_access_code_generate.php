<?php
declare(strict_types=1);
session_start();

require_once __DIR__ . '/lib/database.php';
require_once __DIR__ . '/lib/lib_access_code.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: course.php');
    exit;
}

if (!isset($_SESSION['user']) || !in_array($_SESSION['user']['role'] ?? '', ['Administrator','Instruktor'], true)) {
    header('Location: login.php');
    exit;
}
$ROLE  = (string)($_SESSION['user']['role'] ?? '');
$ME_ID = (int)($_SESSION['user']['id'] ?? 0);

$course_id = (int)($_POST['course_id'] ?? 0);
$csrf      = (string)($_POST['csrf'] ?? '');

function flash_msg(string $msg, string $type = 'success'): void {
    $_SESSION['flash'] = ['msg' => $msg, 'type' => $type];
}

if ($course_id <= 0) {
    flash_msg('Kursi nuk është specifikuar.', 'danger');
    header('Location: course.php');
    exit;
}

if (!hash_equals($_SESSION['csrf_token'] ?? '', $csrf)) {
    flash_msg('CSRF i pavlefshëm.', 'danger');
    header('Location: course_details.php?course_id=' . $course_id);
    exit;
}

// Ensure schema exists
if (!ki_table_has_column($pdo, 'courses', 'access_code')) {
    // Best-effort: try to add column + unique key automatically.
    ki_ensure_courses_access_code_schema($pdo);
}

if (!ki_table_has_column($pdo, 'courses', 'access_code')) {
    flash_msg(
        'Skema e DB nuk është përditësuar (mungon kolona access_code). Ekzekuto SQL-në te virtuale/sql_update_2026_02_03_course_access_code.sql në databazën e saktë.',
        'danger'
    );
    header('Location: course_details.php?course_id=' . $course_id);
    exit;
}

// Check ownership for instructor
try {
    $st = $pdo->prepare('SELECT id_creator, access_code FROM courses WHERE id=? LIMIT 1');
    $st->execute([$course_id]);
    $row = $st->fetch(PDO::FETCH_ASSOC);
    if (!$row) {
        flash_msg('Kursi nuk u gjet.', 'danger');
        header('Location: course.php');
        exit;
    }
    if ($ROLE === 'Instruktor' && (int)$row['id_creator'] !== $ME_ID) {
        flash_msg('Nuk keni akses në këtë kurs.', 'danger');
        header('Location: course.php');
        exit;
    }

    $existing = is_string($row['access_code'] ?? null) ? trim((string)$row['access_code']) : '';
    if ($existing !== '') {
        flash_msg('Ky kurs ka tashmë access code: ' . $existing, 'info');
        header('Location: course_details.php?course_id=' . $course_id);
        exit;
    }

    $pdo->beginTransaction();
    $code = ki_set_course_access_code_if_empty($pdo, $course_id);
    $pdo->commit();

    if (is_string($code) && $code !== '') {
        flash_msg('Access code u gjenerua: ' . $code, 'success');
    } else {
        flash_msg('Access code nuk u gjenerua (mund të jetë krijuar ndërkohë).', 'info');
    }
} catch (Throwable $e) {
    if ($pdo->inTransaction()) { $pdo->rollBack(); }
    flash_msg('Gabim gjatë gjenerimit të access code.', 'danger');
}

header('Location: course_details.php?course_id=' . $course_id);
exit;
