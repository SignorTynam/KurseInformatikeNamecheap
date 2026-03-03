<?php
declare(strict_types=1);

$host = 'localhost'; // në cPanel, përdor "localhost" (socket)
$dbname = 'kursqiyd_online_courses';
$username = 'kursqiyd_cpses_kud7js6qew';
$password = 'AlioniKida.2025';

try {
    $pdo = new PDO(
        "mysql:host=$host;dbname=$dbname;charset=utf8mb4",
        $username,
        $password,
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::MYSQL_ATTR_INIT_COMMAND => "SET NAMES utf8mb4 COLLATE utf8mb4_general_ci",
        ]
    );
} catch (PDOException $e) {
    die("Error connecting to database: " . $e->getMessage());
}
?>
