<?php
declare(strict_types=1);

$host = 'localhost'; // në cPanel, përdor "localhost" (socket)
$dbname = 'kursqiyd_online_courses';
$username = 'kursqiyd_cpses_kud7js6qew';
$password = 'AlioniKida.2025';

$dsn = "mysql:host={$host};dbname={$dbname};charset=utf8mb4";

$options = [
  PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
  PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
  PDO::ATTR_EMULATE_PREPARES   => false,
];

try {
  $pdo = new PDO($dsn, $username, $password, $options);
} catch (PDOException $e) {
  error_log('[DB] '.$e->getMessage());
  http_response_code(500);
  exit('Probleme me databazën.'); // mos zbulon detaje në prod
}
