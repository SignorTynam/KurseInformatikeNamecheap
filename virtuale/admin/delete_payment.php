<?php
session_start();
require_once __DIR__ . '/../lib/database.php';

// Kontrollo nëse përdoruesi është Administrator ose Instruktor
if (!isset($_SESSION['user']) || !in_array($_SESSION['user']['role'], ['Administrator', 'Instruktor'])) {
    header("Location: ../login.php");
    exit;
}

// Sigurohu që kërkesa është POST
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header("Location: ../index.php");
    exit;
}

// Merr të dhënat nga formulari
$course_id = isset($_POST['course_id']) ? intval($_POST['course_id']) : 0;
$lesson_id = isset($_POST['lesson_id']) ? intval($_POST['lesson_id']) : 0;
$user_id   = isset($_POST['user_id']) ? intval($_POST['user_id']) : 0;

// Kontrollo nëse të dhënat e domosdoshme janë dhënë
if (!$course_id || !$lesson_id || !$user_id) {
    $_SESSION['flash'] = ['msg'=>'Të dhëna të papërfshira.', 'type'=>'danger'];
    header("Location: ../course_details.php?course_id=" . (int)$course_id . "&tab=payments");
    exit;
}

try {
    // Fshi regjistrimin e pagesës nga tabela payments
    $stmtDelete = $pdo->prepare("
        DELETE FROM payments 
        WHERE course_id = ? AND lesson_id = ? AND user_id = ?
    ");
    $stmtDelete->execute([$course_id, $lesson_id, $user_id]);

    $_SESSION['flash'] = ['msg'=>'Pagesa u fshi me sukses.', 'type'=>'success'];
    // Redirekto mbrapa te faqja e detajeve të kursit
    header("Location: ../course_details.php?course_id=" . $course_id . "&tab=payments");
    exit;
} catch (PDOException $e) {
    $_SESSION['flash'] = ['msg'=>'Gabim gjatë fshirjes së pagesës.', 'type'=>'danger'];
    header("Location: ../course_details.php?course_id=" . $course_id . "&tab=payments");
    exit;
}
?>
