<?php
session_start();
require_once __DIR__ . '/../lib/database.php';

// Kontrollo nëse përdoruesi është Student
if (!isset($_SESSION['user'])) {
    header("Location: ../login.php");
    exit;
}

// Kontrollo nëse është specifikuar submission_id
if (!isset($_GET['submission_id']) || empty($_GET['submission_id'])) {
    $_SESSION['flash'] = ['msg'=>'Submission nuk është specifikuar.', 'type'=>'danger'];
    header('Location: ../course.php');
    exit;
}

$submission_id = intval($_GET['submission_id']);

// Merr submission-in dhe kontrollo nëse i përket përdoruesit
$stmt = $pdo->prepare("SELECT * FROM assignments_submitted WHERE id = ? AND user_id = ?");
$stmt->execute([$submission_id, $_SESSION['user']['id']]);
$submission = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$submission) {
    $_SESSION['flash'] = ['msg'=>'Submission nuk u gjet ose nuk keni autorizim për ta fshirë.', 'type'=>'danger'];
    header('Location: ../course.php');
    exit;
}

// Fshi skedarin nga serveri nëse ekziston
if (file_exists($submission['file_path'])) {
    unlink($submission['file_path']);
}

// Fshi regjistrimin nga baza e të dhënave
$stmtDelete = $pdo->prepare("DELETE FROM assignments_submitted WHERE id = ?");
$stmtDelete->execute([$submission_id]);

// Rikthehu në faqen e detyrës
$assignment_id = isset($_GET['assignment_id']) ? intval($_GET['assignment_id']) : 0;
$course_id = isset($_GET['course_id']) ? intval($_GET['course_id']) : 0;
$_SESSION['flash'] = ['msg'=>'Dorëzimi u fshi me sukses.', 'type'=>'success'];
if ($assignment_id > 0 && $course_id > 0) {
    header("Location: ../assignment_details.php?assignment_id=$assignment_id&course_id=$course_id");
} else {
    header("Location: ../course.php");
}
exit;
?>
