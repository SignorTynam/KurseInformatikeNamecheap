<?php
declare(strict_types=1);

// Legacy route compatibility.
$target = 'virtuale/signup.php';
$qs = (string)($_SERVER['QUERY_STRING'] ?? '');
if ($qs !== '') {
  $target .= '?' . $qs;
}

header('Location: ' . $target, true, 302);
exit;

