<?php
require_once __DIR__ . '/lib/database.php';

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $email = $_POST['email'];
    $password = $_POST['password'];

    try {
        $query = "SELECT * FROM users WHERE email = :email";
        $stmt = $pdo->prepare($query);
        $stmt->bindParam(':email', $email);
        $stmt->execute();

        $user = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($user && password_verify($password, $user['password'])) {
            if ($user['status'] === 'NE SHQYRTIM') {
                $_SESSION['response'] = [
                    'type' => 'warning',
                    'message' => 'Your account is under review. Please wait for approval.'
                ];
            } elseif ($user['status'] === 'REFUZUAR') {
                $_SESSION['response'] = [
                    'type' => 'danger',
                    'message' => 'Your account has been rejected.'
                ];
            } else {
                $_SESSION['user'] = $user;

                if ($user['role'] === 'Administrator') {
                    header("Location: ADMINISTRATOR/dashboard_admin.php");
                } elseif ($user['role'] === 'Instruktor') {
                    header("Location: INSTRUKTOR/dashboard_instruktor.php");
                } elseif ($user['role'] === 'Student') {
                    header("Location: STUDENT/dashboard_student.php");
                }
                exit;
            }
        } else {
            $_SESSION['response'] = [
                'type' => 'danger',
                'message' => 'Invalid email or password.'
            ];
        }
    } catch (PDOException $e) {
        $_SESSION['response'] = [
            'type' => 'danger',
            'message' => 'Error: ' . $e->getMessage()
        ];
    }

    header("Location: login.php");
    exit;
}
?>