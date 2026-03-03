<?php
session_start();
require_once __DIR__ . '/../lib/database.php';

// Kontrollo nëse përdoruesi është i autentikuar dhe është administrator
if (!isset($_SESSION['user']) || $_SESSION['user']['role'] !== 'Administrator') {
    $_SESSION['flash'] = ['msg'=>'Aksesi i paautorizuar.', 'type'=>'danger'];
    header('Location: ../login.php');
    exit;
}

// Kontrollo nëse është dërguar ID e regjistrimit dhe eventit
if (!isset($_POST['registration_id']) || !isset($_POST['event_id'])) {
    $_SESSION['flash'] = ['msg'=>'Të dhëna të munguara.', 'type'=>'danger'];
    header('Location: ../event.php');
    exit;
}

$registration_id = intval($_POST['registration_id']);
$event_id = intval($_POST['event_id']);

try {
    // Fshi regjistrimin nga databaza
    $stmt = $pdo->prepare("DELETE FROM enroll_events WHERE id = ? AND event_id = ?");
    $stmt->execute([$registration_id, $event_id]);
    
    $_SESSION['flash'] = ['msg'=>'Regjistrimi u fshi me sukses.', 'type'=>'success'];
} catch (PDOException $e) {
    $_SESSION['flash'] = ['msg'=>'Gabim gjatë fshirjes.', 'type'=>'danger'];
}

// Kthehu te faqja e detajeve të eventit
header("Location: ../event_details.php?event_id=" . $event_id);
exit;