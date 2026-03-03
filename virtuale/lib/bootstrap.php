<?php
// Common initialization: session, database, shared helpers
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/database.php';

// Flash message helpers (guarded to avoid redeclaration)
if (!function_exists('set_flash')) {
    function set_flash($msg, $type = 'info') {
        $_SESSION['flash'] = ['msg' => $msg, 'type' => $type];
    }
}

if (!function_exists('get_flash')) {
    function get_flash() {
        if (!empty($_SESSION['flash'])) {
            $f = $_SESSION['flash'];
            unset($_SESSION['flash']);
            return $f;
        }
        return null;
    }
}
