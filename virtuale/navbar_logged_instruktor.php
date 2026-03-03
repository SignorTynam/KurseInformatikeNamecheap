<?php
// navbar_logged_instruktor.php — minimal logged nav (path-safe)
declare(strict_types=1);

if (session_status() === PHP_SESSION_NONE) { session_start(); }

if (!function_exists('h')) {
    function h(?string $s): string { return htmlspecialchars($s ?? '', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }
}
if (!function_exists('virtuale_base_href')) {
    function virtuale_base_href(): string {
        $script = (string)($_SERVER['SCRIPT_NAME'] ?? '');
        $needle = '/virtuale/';
        $pos = strpos($script, $needle);
        if ($pos !== false) {
            $base = substr($script, 0, $pos) . '/virtuale';
            return $base !== '' ? $base : '/virtuale';
        }
        $pos2 = strpos($script, '/virtuale');
        if ($pos2 !== false) {
            $base = substr($script, 0, $pos2) . '/virtuale';
            return $base !== '' ? $base : '/virtuale';
        }
        return '/virtuale';
    }
}

$ME = $_SESSION['user'] ?? null;
$ME_ROLE = (string)($ME['role'] ?? '');
if ($ME_ROLE !== 'Instruktor') { return; }

$BASE_HREF = virtuale_base_href();
$userName = (string)($ME['full_name'] ?? 'Instruktor');
?>

<link rel="stylesheet" href="<?= h($BASE_HREF) ?>/css/navbar.css?v=1">

<nav class="navbar navbar-expand-lg navbar-kurse navbar-dark sticky-top shadow-sm"
     style="--nav-primary:#2A4B7C;--nav-primary-dark:#1d3a63;--nav-secondary:#F0B323;--nav-text:#111827">
    <div class="container">
        <a class="navbar-brand d-flex align-items-center gap-2" href="<?= h($BASE_HREF) ?>/dashboard_instruktor.php">
          <i class="fa-solid fa-graduation-cap"></i> <span>Virtuale</span>
        </a>
        <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#navbarNav" aria-controls="navbarNav" aria-expanded="false" aria-label="Toggle navigation">
            <span class="navbar-toggler-icon"></span>
        </button>
        <div class="collapse navbar-collapse" id="navbarNav">
            <ul class="navbar-nav ms-auto">
                <li class="nav-item">
                    <a class="nav-link active" href="#">Welcome, <strong><?= h($userName) ?></strong></a>
                </li>
                <li class="nav-item">
                  <a class="nav-link" href="<?= h($BASE_HREF) ?>/dashboard_instruktor.php">
                    <i class="fa-solid fa-house me-1"></i> Dashboard
                  </a>
                </li>
                <li class="nav-item">
                  <a class="nav-link" href="<?= h($BASE_HREF) ?>/instructor/tests.php">
                    <i class="fa-solid fa-file-circle-question me-1"></i> Provimet
                  </a>
                </li>
                <li class="nav-item">
                    <a class="nav-link btn btn-outline-warning ms-2" href="<?= h($BASE_HREF) ?>/logout.php">Logout</a>
                </li>
            </ul>
        </div>
    </div>
</nav>


