<?php
session_start();
require_once __DIR__ . '/../lib/database.php';

// Siguro që përdoruesi është Administrator ose Instruktor
if (!isset($_SESSION['user']) || !in_array($_SESSION['user']['role'], ['Administrator', 'Instruktor'])) {
    header("Location: ../login.php");
    exit;
}

// Kontrollo parametrat e kërkuar
if (!isset($_GET['file_id']) || empty($_GET['file_id']) || !isset($_GET['lesson_id']) || empty($_GET['lesson_id'])) {
    $_SESSION['flash'] = ['msg'=>'Parametrat nuk janë specifikuar.', 'type'=>'danger'];
    header('Location: ../course.php');
    exit;
}

$file_id = intval($_GET['file_id']);
$lesson_id = intval($_GET['lesson_id']);

// Merr regjistrimin e skedarit nga databaza
$stmt = $pdo->prepare("SELECT * FROM lesson_files WHERE id = ? AND lesson_id = ?");
$stmt->execute([$file_id, $lesson_id]);
$file = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$file) {
    $_SESSION['flash'] = ['msg'=>'Dokumenti nuk u gjet.', 'type'=>'danger'];
    header('Location: edit_lesson.php?lesson_id=' . $lesson_id);
    exit;
}

// Fshi skedarin nga sistemi nëse ekziston
if (file_exists($file['file_path'])) {
    unlink($file['file_path']);
}

// Fshi regjistrimin nga databaza
$stmtDelete = $pdo->prepare("DELETE FROM lesson_files WHERE id = ?");
$stmtDelete->execute([$file_id]);

$_SESSION['flash'] = ['msg'=>'Dokumenti u fshi me sukses.', 'type'=>'success'];
// Ridrejto tek faqja për modifikimin e leksionit
header("Location: edit_lesson.php?lesson_id=" . $lesson_id);
exit;
?>
