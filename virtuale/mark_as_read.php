<?php
session_start();
require_once __DIR__ . '/lib/database.php';

if (!isset($_SESSION['user']) || $_SESSION['user']['role'] !== 'Administrator') {
    header("Location: login.php");
    exit;
}

if (!isset($_GET['id'])) {
    header("Location: messages.php");
    exit;
}

$id = $_GET['id'];
$stmt = $pdo->prepare("UPDATE messages SET read_status = TRUE WHERE id = :id");

try {
    $stmt->execute([':id' => $id]);
    header("Location: messages.php");
    exit;
} catch (PDOException $e) {
    die("Errore durante l'aggiornamento: " . $e->getMessage());
}
?>
