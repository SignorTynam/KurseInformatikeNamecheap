<?php
// navbar_logged_administrator.php — grouped nav + beautiful mobile-friendly notifications (headers-safe)
declare(strict_types=1);

if (session_status() === PHP_SESSION_NONE) { session_start(); }
require_once __DIR__ . '/lib/database.php';

/* -------------------------- Helpers (avoid re-declare) -------------------------- */
if (!function_exists('h')) {
  function h(?string $s): string { return htmlspecialchars($s ?? '', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }
}
if (!function_exists('csrf_ok')) {
  function csrf_ok(string $t): bool { return isset($_SESSION['csrf_token']) && hash_equals($_SESSION['csrf_token'], $t); }
}
if (!function_exists('time_ago')) {
  function time_ago(string $dt): string {
    $ts = strtotime($dt); if (!$ts) return '';
    $diff = time() - $ts;
    if ($diff < 60) return 'sapo';
    $m = intdiv($diff, 60); if ($m < 60) return "para {$m} min";
    $h = intdiv($m, 60); if ($h < 24) return "para {$h} orë";
    $d = intdiv($h, 24); if ($d === 1) return 'dje';
    if ($d < 7) return "para {$d} ditë";
    return date('d.m.Y H:i', $ts);
  }
}
if (!function_exists('safe_redirect')) {
  function safe_redirect(string $url): void {
    if (!headers_sent()) {
      header('Location: '.$url);
      exit;
    }
    echo '<!doctype html><meta charset="utf-8"><script>location.replace('.json_encode($url).');</script>';
    exit;
  }
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
/* -------------------------------------------------------------------------------- */

$ME      = $_SESSION['user'] ?? null;
$ME_ID   = (int)($ME['id'] ?? 0);
$ME_ROLE = (string)($ME['role'] ?? '');
$userName= (string)($ME['full_name'] ?? 'Administrator');

/* CSRF token */
if (empty($_SESSION['csrf_token'])) { $_SESSION['csrf_token'] = bin2hex(random_bytes(32)); }

/* -------------------------- IMPORTANT --------------------------
   Mos bëj redirect nga navbar.
   Nëse user-i nuk është Administrator, thjesht kthehu (nav-i nuk shfaqet).
   Guard-in e roleve bëje te faqet përpara çdo output-i.
----------------------------------------------------------------- */
if ($ME_ROLE !== 'Administrator') { return; }

/* -------------------------- POST: notifications -------------------------- */
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && isset($_POST['notif_action'])) {
  $csrf = (string)($_POST['csrf_token'] ?? '');
  if (!csrf_ok($csrf)) { http_response_code(400); echo 'Invalid CSRF'; exit; }
  try {
    if ($_POST['notif_action']==='mark_one') {
      $nu_id = (int)($_POST['nu_id'] ?? 0);
      if ($nu_id>0) {
        $st=$pdo->prepare("UPDATE notification_users SET is_read=1, read_at=NOW() WHERE id=? AND user_id=?");
        $st->execute([$nu_id,$ME_ID]);
      }
    } elseif ($_POST['notif_action']==='mark_all') {
      $st=$pdo->prepare("UPDATE notification_users SET is_read=1, read_at=NOW() WHERE user_id=? AND is_read=0");
      $st->execute([$ME_ID]);
    }
  } catch(Throwable $e) { /* optional: error_log($e->getMessage()); */ }
  $back = (string)($_SERVER['HTTP_REFERER'] ?? 'dashboard_admin.php');
  safe_redirect($back); // works even if headers already sent
}

/* -------------------------- Badge counts -------------------------- */
$activeCourses = $futureEvents = $totalApplications = $upcomingAppointments = $totalMessages = $unreadMessages = 0;
$totalAssignments = $pendingGrades = 0;
try {
  $row=$pdo->query("
    SELECT
      (SELECT COUNT(*) FROM courses  WHERE status='ACTIVE') AS active_courses,
      (SELECT COUNT(*) FROM events   WHERE status='ACTIVE' AND event_datetime >= NOW()) AS future_events,
      (SELECT COUNT(*) FROM promoted_course_enrollments) AS total_applications,
      (SELECT COUNT(*) FROM appointments WHERE appointment_date >= NOW()) AS upcoming_appointments,
      (SELECT COUNT(*) FROM messages) AS total_messages,
      (SELECT COUNT(*) FROM messages WHERE read_status=0) AS unread_messages,
      (SELECT COUNT(*) FROM assignments) AS total_assignments,
      (SELECT COUNT(*) FROM assignments_submitted WHERE grade IS NULL) AS pending_grades
  ")->fetch(PDO::FETCH_ASSOC) ?: [];
  $activeCourses        = (int)($row['active_courses'] ?? 0);
  $futureEvents         = (int)($row['future_events'] ?? 0);
  $totalApplications    = (int)($row['total_applications'] ?? 0);
  $upcomingAppointments = (int)($row['upcoming_appointments'] ?? 0);
  $totalMessages        = (int)($row['total_messages'] ?? 0);
  $unreadMessages       = (int)($row['unread_messages'] ?? 0);
  $totalAssignments     = (int)($row['total_assignments'] ?? 0);
  $pendingGrades        = (int)($row['pending_grades'] ?? 0);
} catch(Throwable $e){}

/* -------------------------- Notifications (latest 10) -------------------------- */
$unreadNotifs=0; $recentNotifs=[];
try{
  $stc=$pdo->prepare("SELECT COUNT(*) FROM notification_users WHERE user_id=? AND is_read=0");
  $stc->execute([$ME_ID]); $unreadNotifs=(int)$stc->fetchColumn();

  $st=$pdo->prepare("
    SELECT nu.id AS nu_id, nu.is_read,
           n.type, n.title, n.body, n.target_url, n.created_at
    FROM notification_users nu
    JOIN notifications n ON n.id=nu.notification_id
    WHERE nu.user_id=?
    ORDER BY n.created_at DESC
    LIMIT 10
  ");
  $st->execute([$ME_ID]); $recentNotifs=$st->fetchAll(PDO::FETCH_ASSOC) ?: [];
}catch(Throwable $e){}

$currentFile = basename($_SERVER['PHP_SELF'] ?? '');
$grpAcademic = in_array($currentFile, ['course.php','appointment.php','event.php','promotions.php','assignments.php','tests.php','test_edit.php','test_builder.php','test_results.php','test_grade.php'], true);
$grpFinance  = in_array($currentFile, ['payments.php'], true);
$grpComm     = in_array($currentFile, ['messages.php'], true);
$grpAdmin    = in_array($currentFile, ['users.php'], true);

$BASE_HREF = virtuale_base_href();

function notif_icon(string $type): string {
  return [
    'assignment_submitted'=>'fa-solid fa-paperclip',
    'assignment_graded'   =>'fa-solid fa-square-check',
    'quiz_submitted'      =>'fa-solid fa-square-poll-vertical',
    'quiz_graded'         =>'fa-solid fa-clipboard-check',
    'test_submitted'      =>'fa-solid fa-file-circle-check',
    'test_graded'         =>'fa-solid fa-award',
    'payment_completed'   =>'fa-solid fa-receipt',
    'course_enrolled'     =>'fa-solid fa-user-plus',
    'user_registered'     =>'fa-solid fa-id-card',
    'event_enrollment'    =>'fa-solid fa-ticket',
    'message_received'    =>'fa-regular fa-envelope',
    'thread_created'      =>'fa-solid fa-comments',
    'reply_posted'        =>'fa-solid fa-reply',
    'promotion_application'=>'fa-solid fa-bullhorn',
  ][$type] ?? 'fa-regular fa-bell';
}
?>

<link rel="stylesheet" href="<?= h($BASE_HREF) ?>/css/navbar.css">

<nav class="navbar navbar-expand-lg navbar-kurse navbar-dark sticky-top shadow-sm"
     style="--nav-primary:#2A4B7C;--nav-primary-dark:#1d3a63;--nav-secondary:#F0B323;--nav-text:#111827">
  <div class="container">
    <a class="navbar-brand d-flex align-items-center gap-2" href="<?= h($BASE_HREF) ?>/dashboard_admin.php">
      <i class="fa-solid fa-graduation-cap"></i> <span>Virtuale</span>
    </a>

    <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#navMain" aria-label="Ndrysho navigimin">
      <span class="navbar-toggler-icon"></span>
    </button>

    <div class="collapse navbar-collapse" id="navMain">
      <ul class="navbar-nav me-auto mb-2 mb-lg-0">
        <!-- Kryefaqja -->
        <li class="nav-item">
          <a class="nav-link <?= in_array($currentFile, ['dashboard_admin.php','dashboard_administrator.php'], true)?'active':'' ?>" href="<?= h($BASE_HREF) ?>/dashboard_admin.php">
            <i class="fa-solid fa-house"></i> <span>Kryefaqja</span>
          </a>
        </li>

        <!-- Akademia -->
        <li class="nav-item dropdown">
          <a class="nav-link dropdown-toggle <?= $grpAcademic?'active':'' ?>" href="#" data-bs-toggle="dropdown">
            <i class="fa-solid fa-chalkboard-user"></i> <span>Akademia</span>
          </a>
          <ul class="dropdown-menu">
            <li>
              <a class="dropdown-item <?= $currentFile==='course.php'?'active':'' ?> d-flex align-items-center" href="<?= h($BASE_HREF) ?>/course.php">
                <i class="fa-solid fa-book me-2"></i> Kurset
                <?php if ($activeCourses>0): ?>
                  <span class="badge rounded-pill text-bg-info ms-auto"><?= $activeCourses ?></span>
                <?php endif; ?>
              </a>
            </li>
            <li>
              <a class="dropdown-item <?= $currentFile==='assignments.php'?'active':'' ?> d-flex align-items-center" href="<?= h($BASE_HREF) ?>/assignments.php">
                <i class="fa-solid fa-clipboard-check me-2"></i> Detyrat
                <?php if ($pendingGrades>0): ?>
                  <span class="badge rounded-pill text-bg-info ms-auto"><?= $pendingGrades ?></span>
                <?php endif; ?>
              </a>
            </li>
            <li>
              <a class="dropdown-item <?= in_array($currentFile,['tests.php','test_edit.php','test_builder.php','test_results.php','test_grade.php'],true)?'active':'' ?> d-flex align-items-center" href="<?= h($BASE_HREF) ?>/instructor/tests.php">
                <i class="fa-solid fa-file-circle-question me-2"></i> Provimet
              </a>
            </li>
            <li>
              <a class="dropdown-item <?= $currentFile==='appointment.php'?'active':'' ?> d-flex align-items-center" href="<?= h($BASE_HREF) ?>/appointment.php">
                <i class="fa-regular fa-calendar-check me-2"></i> Takimet
                <?php if ($upcomingAppointments>0): ?>
                  <span class="badge rounded-pill text-bg-info ms-auto"><?= $upcomingAppointments ?></span>
                <?php endif; ?>
              </a>
            </li>
            <li>
              <a class="dropdown-item <?= $currentFile==='event.php'?'active':'' ?> d-flex align-items-center" href="<?= h($BASE_HREF) ?>/event.php">
                <i class="fa-regular fa-calendar-days me-2"></i> Eventet
                <?php if ($futureEvents>0): ?>
                  <span class="badge rounded-pill text-bg-info ms-auto"><?= $futureEvents ?></span>
                <?php endif; ?>
              </a>
            </li>
            <li>
              <a class="dropdown-item <?= $currentFile==='promotions.php'?'active':'' ?> d-flex align-items-center" href="<?= h($BASE_HREF) ?>/promotions.php">
                <i class="fa-solid fa-bullhorn me-2"></i> Reklamat
                <?php if ($totalApplications>0): ?>
                  <span class="badge rounded-pill text-bg-info ms-auto"><?= $totalApplications ?></span>
                <?php endif; ?>
              </a>
            </li>
          </ul>
        </li>

        <!-- Financa -->
        <li class="nav-item dropdown">
          <a class="nav-link dropdown-toggle <?= $grpFinance?'active':'' ?>" href="#" data-bs-toggle="dropdown">
            <i class="fa-solid fa-money-bill"></i> <span>Financa</span>
          </a>
          <ul class="dropdown-menu">
            <li>
              <a class="dropdown-item <?= $currentFile==='payments.php'?'active':'' ?> d-flex align-items-center" href="<?= h($BASE_HREF) ?>/payments.php">
                <i class="fa-solid fa-receipt me-2"></i> Pagesat
              </a>
            </li>
          </ul>
        </li>

        <!-- Komunikim -->
        <li class="nav-item dropdown">
          <a class="nav-link dropdown-toggle <?= $grpComm?'active':'' ?>" href="#" data-bs-toggle="dropdown">
            <i class="fa-regular fa-message"></i> <span>Komunikim</span>
          </a>
          <ul class="dropdown-menu">
            <li>
              <a class="dropdown-item <?= $currentFile==='messages.php'?'active':'' ?> d-flex align-items-center" href="<?= h($BASE_HREF) ?>/messages.php">
                <i class="fa-regular fa-envelope me-2"></i> Mesazhet
                <?php if ($totalMessages>0): ?>
                  <span class="badge rounded-pill text-bg-info ms-auto"><?= $totalMessages ?></span>
                <?php endif; ?>
              </a>
            </li>
          </ul>
        </li>

        <!-- Administrim -->
        <li class="nav-item dropdown">
          <a class="nav-link dropdown-toggle <?= $grpAdmin?'active':'' ?>" href="#" data-bs-toggle="dropdown">
            <i class="fa-solid fa-gear"></i> <span>Administrim</span>
          </a>
          <ul class="dropdown-menu">
            <li>
              <a class="dropdown-item <?= $currentFile==='users.php'?'active':'' ?> d-flex align-items-center" href="<?= h($BASE_HREF) ?>/users.php">
                <i class="fa-solid fa-users me-2"></i> Përdoruesit
              </a>
            </li>
          </ul>
        </li>
      </ul>

      <div class="d-flex align-items-center gap-2">
        <!-- Bell / Notifications -->
        <div class="nav-item dropdown">
          <a class="btn btn-sm btn-bell position-relative" href="#" role="button" data-bs-toggle="dropdown" aria-expanded="false" title="Njoftime">
            <i class="fa-regular fa-bell"></i>
            <?php if ($unreadNotifs>0): ?>
              <span class="position-absolute top-0 start-100 translate-middle badge rounded-pill bg-danger"><?= $unreadNotifs ?></span>
            <?php endif; ?>
          </a>

          <div class="dropdown-menu dropdown-menu-end dropdown-menu-notif">
            <div class="notif-header">
              <div class="fw-semibold">Njoftime</div>
              <form method="post" class="m-0">
                <input type="hidden" name="csrf_token" value="<?= h($_SESSION['csrf_token']) ?>">
                <input type="hidden" name="notif_action" value="mark_all">
                <button class="btn btn-link btn-sm text-decoration-none">Marko të gjitha si lexuar</button>
              </form>
            </div>

            <?php if (!$recentNotifs): ?>
              <div class="notif-empty">S’ka njoftime.</div>
            <?php else: ?>
              <div class="notif-list">
                <?php foreach ($recentNotifs as $n): ?>
                  <div>
                    <a class="notif-item" href="<?= h($n['target_url'] ?: '#') ?>">
                      <div class="notif-ico"><i class="<?= notif_icon((string)$n['type']) ?>"></i></div>
                      <div class="flex-grow-1">
                        <div class="notif-title"><?= h((string)$n['title']) ?></div>
                        <?php if (!empty($n['body'])): ?>
                          <div class="notif-body"><?= h((string)$n['body']) ?></div>
                        <?php endif; ?>
                        <div class="notif-meta"><?= h(time_ago((string)$n['created_at'])) ?></div>
                      </div>
                      <?php if ((int)$n['is_read']===0): ?>
                        <span class="notif-unread-dot"></span>
                      <?php endif; ?>
                    </a>
                    <?php if ((int)$n['is_read']===0): ?>
                      <div class="notif-actions">
                        <form method="post" class="m-0">
                          <input type="hidden" name="csrf_token" value="<?= h($_SESSION['csrf_token']) ?>">
                          <input type="hidden" name="notif_action" value="mark_one">
                          <input type="hidden" name="nu_id" value="<?= (int)$n['nu_id'] ?>">
                          <button class="btn btn-outline-secondary notif-mark-btn">Marko si lexuar</button>
                        </form>
                      </div>
                    <?php endif; ?>
                    <div style="height:1px;background:#f1f5f9;"></div>
                  </div>
                <?php endforeach; ?>
              </div>
            <?php endif; ?>
          </div>
        </div>

        <!-- Profile -->
        <a href="<?= h($BASE_HREF) ?>/profile.php" class="btn btn-sm border-0 text-dark d-flex align-items-center gap-2">
          <span class="nav-avatar"><?= strtoupper(mb_substr($userName,0,1,'UTF-8')) ?></span>
          <span class="text-white d-none d-sm-inline"><?= h($userName) ?></span>
        </a>
        <a href="<?= h($BASE_HREF) ?>/logout.php" class="btn btn-sm btn-warning">
          <i class="fa-solid fa-right-from-bracket me-1"></i> Dil
        </a>
      </div>
    </div>
  </div>
</nav>
