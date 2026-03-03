<?php
// Backward-compatible entrypoint.
// Some pages previously linked to /virtuale/edit_course.php, but the real page lives in /virtuale/admin/edit_course.php.
declare(strict_types=1);

$qs = $_SERVER['QUERY_STRING'] ?? '';
$target = 'admin/edit_course.php' . ($qs !== '' ? ('?' . $qs) : '');
header('Location: ' . $target, true, 302);
exit;
