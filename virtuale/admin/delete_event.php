<?php
session_start();
require_once 'database.php';

// Vetëm administratorët kanë të drejtë të fshijnë evente
if (!isset($_SESSION['user']) || $_SESSION['user']['role'] !== 'Administrator') {
    header("Location: ../login.php");
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $event_id = $_POST['event_id'] ?? null;
    if ($event_id) {
        // Merr të dhënat e eventit për të fshirë foton nëse ekziston
        $stmt = $pdo->prepare("SELECT photo FROM events WHERE id = ?");
        $stmt->execute([$event_id]);
        $event = $stmt->fetch(PDO::FETCH_ASSOC);
        
        try {
            $stmt = $pdo->prepare("DELETE FROM events WHERE id = ?");
            $stmt->execute([$event_id]);
            
            // Nëse fotoja ekziston, fshi atë nga serveri
            if ($event && !empty($event['photo']) && file_exists(__DIR__ . "/../uploads/events/" . $event['photo'])) {
                unlink(__DIR__ . "/../uploads/events/" . $event['photo']);
            }
            
            $_SESSION['success'] = "Eventi u fshi me sukses!";
        } catch (PDOException $e) {
            $_SESSION['error'] = "Gabim gjatë fshirjes së eventit: " . $e->getMessage();
        }
    }
}

header("Location: ../event.php");
exit;
?>
