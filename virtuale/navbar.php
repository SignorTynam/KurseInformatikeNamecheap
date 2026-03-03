<?php
// virtuale/navbar.php — KI v2 Slim Glass Navbar (robust URLs)
// Fix:
// - Nga /virtuale/*, "Kryefaqja" shkon te /Kurseinformatika/index.php (jo /virtuale/index.php)
// - "Hyr/Regjistrohu" shkon te /Kurseinformatika/virtuale/login.php & signup.php (jo /virtuale/virtuale/*)

declare(strict_types=1);

if (session_status() === PHP_SESSION_NONE) { session_start(); }

/* ==========================================================
   Build URL_ROOT and URL_VIRT from REQUEST_URI (most reliable)
   Examples:
   - /Kurseinformatika/virtuale/signup.php  => URL_ROOT=/Kurseinformatika, URL_VIRT=/Kurseinformatika/virtuale
   - /virtuale/login.php                    => URL_ROOT=, URL_VIRT=/virtuale
   - /Kurseinformatika/index.php            => URL_ROOT=/Kurseinformatika, URL_VIRT=/Kurseinformatika/virtuale
========================================================== */
$uriPath = (string)(parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH) ?: '');

$posVirt = stripos($uriPath, '/virtuale/');
if ($posVirt !== false) {
  $URL_ROOT = substr($uriPath, 0, $posVirt);     // before "/virtuale/"
} else {
  // If we're not inside /virtuale/, use the directory of the current request
  $dir = rtrim((string)dirname($uriPath), '/');
  $URL_ROOT = ($dir === '' || $dir === '.') ? '' : $dir;
  if ($URL_ROOT === '/') $URL_ROOT = '';
}

$URL_ROOT = rtrim($URL_ROOT, '/');               // "" or "/Kurseinformatika"
$URL_VIRT = $URL_ROOT . '/virtuale';             // "/Kurseinformatika/virtuale" or "/virtuale"

/* -------- Helpers -------- */
if (!function_exists('h')) {
  function h(?string $s): string { return htmlspecialchars($s ?? '', ENT_QUOTES, 'UTF-8'); }
}

function get_session_role(): string {
  $candidates = [
    $_SESSION['role'] ?? null,
    $_SESSION['user_role'] ?? null,
    $_SESSION['user']['role'] ?? null,
    $_SESSION['auth']['role'] ?? null,
    $_SESSION['logged_user']['role'] ?? null,
    $_SESSION['user']['role_name'] ?? null,
  ];
  foreach ($candidates as $r) {
    if (!empty($r)) return strtoupper(trim((string)$r));
  }
  return '';
}

function is_logged_in(): bool {
  $ids = [
    $_SESSION['user_id'] ?? null,
    $_SESSION['id_user'] ?? null,
    $_SESSION['id'] ?? null,
    $_SESSION['user']['id'] ?? null,
    $_SESSION['auth']['id'] ?? null,
    $_SESSION['logged_user']['id'] ?? null,
    $_SESSION['user']['user_id'] ?? null,
  ];
  foreach ($ids as $id) if (!empty($id)) return true;
  return false;
}

function get_session_name(): string {
  $candidates = [
    $_SESSION['full_name'] ?? null,
    $_SESSION['user']['full_name'] ?? null,
    $_SESSION['auth']['full_name'] ?? null,
    $_SESSION['logged_user']['full_name'] ?? null,
    $_SESSION['name'] ?? null,
    $_SESSION['user']['name'] ?? null,
  ];
  foreach ($candidates as $n) {
    $n = trim((string)$n);
    if ($n !== '') return $n;
  }
  return 'Llogaria';
}

function dashboard_href_by_role(string $role, string $URL_VIRT): string {
  $role = strtoupper($role);

  if (in_array($role, ['ADMIN', 'ADMINISTRATOR', 'ADM'], true)) {
    return $URL_VIRT . '/dashboard_admin.php';
  }

  // te projekti yt: dashboard_instruktor.php (jo dashboard_instructor.php)
  if (in_array($role, ['INSTRUCTOR', 'INSTRUKTOR', 'TEACHER', 'DOCENT'], true)) {
    return $URL_VIRT . '/dashboard_instruktor.php';
  }

  return $URL_VIRT . '/dashboard_student.php';
}

/* Active state */
$currentPath = $uriPath;
$currentFile = basename($currentPath);

/* Session-derived UI */
$isLogged    = is_logged_in();
$role        = get_session_role();
$displayName = get_session_name();
$dashHref    = dashboard_href_by_role($role, $URL_VIRT);

/* Nav URLs (absolute) */
$uHome    = $URL_ROOT . '/index.php';
$uCourses = $URL_ROOT . '/courses.php';
$uEvents  = $URL_ROOT . '/events.php';
$uContact = $URL_ROOT . '/contact.php';
?>
<style>
/* ==========================================================
   Navbar Public — KI v2 Slim Glass
========================================================== */
:root{
  --ki-primary:   #2A4B7C;
  --ki-primary-2: #1d3a63;
  --ki-secondary: #F0B323;
  --ki-ink:       #0b1220;
  --ki-muted:     #6b7280;
  --ki-line:      rgba(15, 23, 42, .12);
  --ki-shadow-soft: 0 18px 44px rgba(11, 18, 32, .10);
  --ki-r: 22px;
}

.navbar-ki{
  position: sticky;
  top: 0;
  z-index: 1020;
  background: rgba(255,255,255,.55);
  border-bottom: 1px solid rgba(15,23,42,.10);
  backdrop-filter: blur(12px);
  -webkit-backdrop-filter: blur(12px);
}

.navbar-ki.navbar{
  padding-top: .45rem;
  padding-bottom: .45rem;
}

.navbar-ki .navbar-brand{
  font-weight: 900;
  letter-spacing: .25px;
  color: var(--ki-ink) !important;
  display: inline-flex;
  align-items: center;
  gap: .55rem;
}
.navbar-ki .brand-mark{
  width: 36px; height: 36px;
  border-radius: 14px;
  display: inline-flex;
  align-items: center;
  justify-content: center;
  background: radial-gradient(18px 18px at 35% 30%, rgba(240,179,35,.55), rgba(240,179,35,.0) 70%),
              rgba(42,75,124,.10);
  border: 1px solid rgba(15,23,42,.10);
  color: var(--ki-primary-2);
}
.navbar-ki .brand-text{
  font-family: Poppins, system-ui, sans-serif;
  font-weight: 900;
  letter-spacing: .35px;
}

.navbar-ki .nav-link{
  color: rgba(11,18,32,.78) !important;
  font-weight: 800;
  letter-spacing: .1px;
  padding: .45rem .7rem;
  border-radius: 999px;
  transition: background .15s ease, color .15s ease, transform .15s ease;
}
.navbar-ki .nav-link:hover{
  background: rgba(255,255,255,.45);
  color: rgba(11,18,32,.92) !important;
  transform: translateY(-1px);
}
.navbar-ki .nav-link.active{
  background: rgba(240,179,35,.22);
  color: rgba(11,18,32,.92) !important;
  border: 1px solid rgba(240,179,35,.35);
}

.navbar-ki .navbar-toggler{
  border: 1px solid rgba(15,23,42,.14);
  border-radius: 14px;
  padding: .35rem .5rem;
}
.navbar-ki .navbar-toggler:focus{
  box-shadow: 0 0 0 .22rem rgba(240,179,35,.22);
}

/* Search */
.navbar-ki .nav-search{
  border-radius: 999px;
  border: 1px solid rgba(15,23,42,.12);
  background: rgba(255,255,255,.35);
  backdrop-filter: blur(12px);
  -webkit-backdrop-filter: blur(12px);
  overflow: hidden;
}
.navbar-ki .nav-search .input-group-text{
  background: transparent;
  border: 0;
  color: rgba(11,18,32,.62);
}
.navbar-ki .nav-search .form-control{
  background: transparent;
  border: 0;
  box-shadow: none !important;
  color: rgba(11,18,32,.86);
  font-weight: 700;
}
.navbar-ki .nav-search .form-control::placeholder{
  color: rgba(11,18,32,.52);
}
.navbar-ki .nav-search .btn{
  border: 0;
  background: transparent;
  color: rgba(11,18,32,.70);
  padding-left: .8rem;
  padding-right: .8rem;
}
.navbar-ki .nav-search .btn:hover{
  background: rgba(255,255,255,.45);
}

/* CTA */
.navbar-ki .btn{
  border-radius: 14px;
  font-weight: 900;
  letter-spacing: .1px;
}
.navbar-ki .btn-ghost{
  background: rgba(255,255,255,.35);
  border: 1px solid rgba(15,23,42,.12);
  color: rgba(11,18,32,.88);
}
.navbar-ki .btn-ghost:hover{ background: rgba(255,255,255,.55); }

.navbar-ki .btn-primary-ki{
  background: linear-gradient(135deg, var(--ki-secondary), #ffd36a);
  border: 1px solid rgba(240,179,35,.55);
  color: #111827;
}
.navbar-ki .btn-primary-ki:hover{ filter: brightness(.98); }

/* Dropdown */
.navbar-ki .dropdown-menu{
  border: 1px solid rgba(15,23,42,.10);
  border-radius: 16px;
  padding: .5rem;
  box-shadow: var(--ki-shadow-soft);
  background: rgba(255,255,255,.92);
  backdrop-filter: blur(10px);
  -webkit-backdrop-filter: blur(10px);
}
.navbar-ki .dropdown-item{
  border-radius: 12px;
  padding: .6rem .75rem;
  font-weight: 800;
  color: rgba(11,18,32,.86);
}
.navbar-ki .dropdown-item:hover{ background: rgba(42,75,124,.08); }
.navbar-ki .dropdown-divider{ border-top-color: rgba(15,23,42,.10); }

@media (max-width: 991.98px){
  .navbar-ki .navbar-collapse{ margin-top: .6rem; }
  .navbar-ki .nav-link{ border-radius: 14px; padding: .55rem .7rem; }
  .navbar-ki .nav-actions{ margin-top: .65rem; }
  .navbar-ki .nav-search{ margin-top: .6rem; border-radius: 16px; }
}
</style>

<nav class="navbar navbar-expand-lg navbar-ki navbar-light">
  <div class="container">

    <a class="navbar-brand" href="<?= h($uHome) ?>" aria-label="Kurse Informatike - Kryefaqja">
      <span class="brand-mark"><i class="fa-solid fa-graduation-cap"></i></span>
      <span class="brand-text">KURSEINFORMATIKE</span>
    </a>

    <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#navPublic"
            aria-controls="navPublic" aria-expanded="false" aria-label="Hap menunë">
      <span class="navbar-toggler-icon"></span>
    </button>

    <div class="collapse navbar-collapse" id="navPublic">

      <ul class="navbar-nav mx-auto mb-2 mb-lg-0">
        <li class="nav-item">
          <a class="nav-link <?= $currentFile==='index.php' ? 'active' : '' ?>" href="<?= h($uHome) ?>">
            Kryefaqja
          </a>
        </li>
        <li class="nav-item">
          <a class="nav-link <?= $currentFile==='courses.php' ? 'active' : '' ?>" href="<?= h($uCourses) ?>">
            Kurset
          </a>
        </li>
        <li class="nav-item">
          <a class="nav-link <?= $currentFile==='events.php' ? 'active' : '' ?>" href="<?= h($uEvents) ?>">
            Eventet
          </a>
        </li>
        <li class="nav-item">
          <a class="nav-link <?= $currentFile==='contact.php' ? 'active' : '' ?>" href="<?= h($uContact) ?>">
            Kontakt
          </a>
        </li>
      </ul>

      <form class="nav-search input-group input-group-sm me-lg-2" action="<?= h($uCourses) ?>" method="get" role="search">
        <span class="input-group-text"><i class="fa-solid fa-magnifying-glass"></i></span>
        <input class="form-control" type="search" name="q" placeholder="Kërko kurse..." aria-label="Kërko">
        <button class="btn" type="submit" aria-label="Kërko"><i class="fa-solid fa-arrow-right"></i></button>
      </form>

      <div class="d-flex gap-2 nav-actions">
        <?php if ($isLogged): ?>
          <div class="dropdown">
            <a class="btn btn-ghost dropdown-toggle" href="#" role="button" data-bs-toggle="dropdown" aria-expanded="false">
              <i class="fa-regular fa-user me-1"></i> <?= h($displayName) ?>
            </a>
            <ul class="dropdown-menu dropdown-menu-end">
              <li><a class="dropdown-item" href="<?= h($dashHref) ?>"><i class="fa-solid fa-gauge-high me-2"></i> Dashboard</a></li>
              <li><a class="dropdown-item" href="<?= h($URL_VIRT) ?>/profile.php"><i class="fa-regular fa-id-badge me-2"></i> Profili</a></li>
              <li><hr class="dropdown-divider"></li>
              <li><a class="dropdown-item" href="<?= h($URL_VIRT) ?>/logout.php"><i class="fa-solid fa-right-from-bracket me-2"></i> Dalje</a></li>
            </ul>
          </div>
        <?php else: ?>
          <a href="<?= h($URL_VIRT) ?>/login.php" class="btn btn-ghost">
            <i class="fa-solid fa-right-to-bracket me-1"></i> Hyr
          </a>
          <a href="<?= h($URL_VIRT) ?>/signup.php" class="btn btn-primary-ki">
            <i class="fa-solid fa-user-plus me-1"></i> Regjistrohu
          </a>
        <?php endif; ?>
      </div>

    </div>
  </div>
</nav>
