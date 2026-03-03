<?php
// course_unenroll.php — Çregjistrim i studentit nga kursi (unenroll)
declare(strict_types=1);
session_start();

require_once __DIR__ . '/lib/database.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: courses_student.php');
    exit;
}

if (empty($_SESSION['user']) || (string)($_SESSION['user']['role'] ?? '') !== 'Student') {
    header('Location: login.php');
    exit;
}
$ME_ID = (int)($_SESSION['user']['id'] ?? 0);

$course_id = (int)($_POST['course_id'] ?? 0);
$csrf      = (string)($_POST['csrf'] ?? '');

function flash_msg(string $msg, string $type = 'success'): void {
    $_SESSION['flash'] = ['msg' => $msg, 'type' => $type];
}

if ($course_id <= 0) {
    flash_msg('Kursi nuk është specifikuar.', 'danger');
    header('Location: courses_student.php');
    exit;
}

if (!hash_equals((string)($_SESSION['csrf_token'] ?? ''), $csrf)) {
    flash_msg('Seancë e pavlefshme (CSRF). Ringarko faqen.', 'danger');
    header('Location: course_details_student.php?course_id=' . $course_id);
    exit;
}

try {
    // Ensure enrolled
    $chk = $pdo->prepare('SELECT 1 FROM enroll WHERE course_id=? AND user_id=? LIMIT 1');
    $chk->execute([$course_id, $ME_ID]);
    if (!$chk->fetchColumn()) {
        flash_msg('Nuk jeni i regjistruar në këtë kurs.', 'info');
        header('Location: courses_student.php?tab=available');
        exit;
    }

    $del = $pdo->prepare('DELETE FROM enroll WHERE course_id=? AND user_id=?');
    $del->execute([$course_id, $ME_ID]);

    if ($del->rowCount() > 0) {
        flash_msg('U çregjistruat me sukses nga kursi.', 'success');
    } else {
        flash_msg('Nuk u krye çregjistrimi. Provo sërish.', 'warning');
    }
} catch (Throwable $e) {
    flash_msg('Gabim gjatë çregjistrimit.', 'danger');
}

header('Location: courses_student.php?tab=available');
exit;
