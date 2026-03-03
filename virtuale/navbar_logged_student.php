<?php
// navbar_logged_student.php — grouped nav + notifications (students)
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
    if (!headers_sent()) { header('Location: '.$url); exit; }
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
$userName= (string)($ME['full_name'] ?? 'Student');

/* CSRF token */
if (empty($_SESSION['csrf_token'])) { $_SESSION['csrf_token'] = bin2hex(random_bytes(32)); }

/* Nëse user-i nuk është Student, mos shfaq navbar-in */
if ($ME_ROLE !== 'Student') { return; }

/* -------------------------- POST: notifications (mark read) -------------------------- */
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
  $back = (string)($_SERVER['HTTP_REFERER'] ?? 'dashboard_student.php');
  safe_redirect($back);
}

/* -------------------------- Badge counts (my courses / schedule / assignments) -------------------------- */
$myCourses = 0; $upcomingAppointments = 0; $pendingAssignments = 0;
try {
  $stmt = $pdo->prepare("
    SELECT
      (SELECT COUNT(*)
         FROM enroll e
         JOIN courses c ON c.id = e.course_id
        WHERE e.user_id = :uid AND c.status='ACTIVE'
      ) AS my_courses,

      (SELECT COUNT(*)
         FROM appointments a
         JOIN enroll e2 ON e2.course_id = a.course_id
        WHERE e2.user_id = :uid AND a.appointment_date >= NOW()
      ) AS upcoming_appointments,

      (SELECT COUNT(*)
         FROM assignments a
         JOIN enroll e3 ON e3.course_id = a.course_id
        WHERE e3.user_id = :uid AND a.status='PENDING'
      ) AS pending_assignments
  ");
  $stmt->execute([':uid'=>$ME_ID]);
  $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
  $myCourses            = (int)($row['my_courses'] ?? 0);
  $upcomingAppointments = (int)($row['upcoming_appointments'] ?? 0);
  $pendingAssignments   = (int)($row['pending_assignments'] ?? 0);
} catch(Throwable $e) {}

/* -------------------------- Notifications for this student (latest 10) -------------------------- */
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

/* -------------------------- Active state helpers -------------------------- */
$currentFile = basename($_SERVER['PHP_SELF'] ?? '');
$grpStudy   = in_array($currentFile, ['courses_student.php','appointments_student.php','myAssignments.php','tests.php','test.php','test_review.php'], true);
$grpFinance = in_array($currentFile, ['payments_student.php'], true);

$BASE_HREF = virtuale_base_href();

/* -------------------------- Icons for student notification types -------------------------- */
if (!function_exists('notif_icon_student')) {
  function notif_icon_student(string $type): string {
    return [
      // Content availability
      'lesson_published'     =>'fa-solid fa-book-open',
      'lesson_available'     =>'fa-solid fa-book-open',
      'assignment_published' =>'fa-solid fa-list-check',
      'assignment_due_soon'  =>'fa-regular fa-bell',
      'quiz_published'       =>'fa-solid fa-square-poll-vertical',
      'test_published'       =>'fa-solid fa-file-circle-question',

      // Personal results
      'assignment_graded'    =>'fa-solid fa-square-check',
      'quiz_graded'          =>'fa-solid fa-clipboard-check',
      'test_graded'          =>'fa-solid fa-award',

      // Generic / shared
      'event_enrollment'     =>'fa-solid fa-ticket',
      'message_received'     =>'fa-regular fa-envelope',
      'thread_created'       =>'fa-solid fa-comments',
      'reply_posted'         =>'fa-solid fa-reply',

      // Admin-side types that might still appear
      'payment_completed'    =>'fa-solid fa-receipt',
    ][$type] ?? 'fa-regular fa-bell';
  }
}
?>

<link rel="stylesheet" href="<?= h($BASE_HREF) ?>/css/navbar.css?v=1">

<nav class="navbar navbar-expand-lg navbar-kurse navbar-dark sticky-top shadow-sm">
  <div class="container">
    <!-- Brand (teksti brenda <span> që të funksionojë rregulli mobile në CSS) -->
    <a class="navbar-brand d-flex align-items-center gap-2" href="<?= h($BASE_HREF) ?>/dashboard_student.php">
      <i class="fa-solid fa-graduation-cap"></i>
      <span>Virtuale</span>
    </a>

    <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#navStudent" aria-label="Ndrysho navigimin">
      <span class="navbar-toggler-icon"></span>
    </button>

    <div class="collapse navbar-collapse" id="navStudent">
      <ul class="navbar-nav me-auto mb-2 mb-lg-0">
        <!-- Kryefaqja -->
        <li class="nav-item">
          <a class="nav-link <?= in_array($currentFile, ['dashboard_student.php'], true)?'active':'' ?>" href="<?= h($BASE_HREF) ?>/dashboard_student.php">
            <i class="fa-solid fa-house"></i> <span>Kryefaqja</span>
          </a>
        </li>

        <!-- Studimet -->
        <li class="nav-item dropdown">
          <a class="nav-link dropdown-toggle <?= $grpStudy?'active':'' ?>" href="#" data-bs-toggle="dropdown">
            <i class="fa-solid fa-chalkboard-user"></i> <span>Studimet</span>
          </a>
          <ul class="dropdown-menu">
            <li>
              <a class="dropdown-item d-flex align-items-center <?= $currentFile==='courses_student.php'?'active':'' ?>" href="<?= h($BASE_HREF) ?>/courses_student.php">
                <i class="fa-solid fa-book me-2"></i> Kurset e mia
                <?php if ($myCourses>0): ?>
                  <span class="badge rounded-pill text-bg-info ms-auto"><?= $myCourses ?></span>
                <?php endif; ?>
              </a>
            </li>
            <li>
              <a class="dropdown-item d-flex align-items-center <?= $currentFile==='appointments_student.php'?'active':'' ?>" href="<?= h($BASE_HREF) ?>/appointments_student.php">
                <i class="fa-regular fa-calendar-days me-2"></i> Orari im
                <?php if ($upcomingAppointments>0): ?>
                  <span class="badge rounded-pill text-bg-info ms-auto"><?= $upcomingAppointments ?></span>
                <?php endif; ?>
              </a>
            </li>
            <li>
              <a class="dropdown-item d-flex align-items-center <?= $currentFile==='myAssignments.php'?'active':'' ?>" href="<?= h($BASE_HREF) ?>/myAssignments.php">
                <i class="fa-solid fa-list-check me-2"></i> Detyrat e mia
                <?php if ($pendingAssignments>0): ?>
                  <span class="badge rounded-pill text-bg-info ms-auto"><?= $pendingAssignments ?></span>
                <?php endif; ?>
              </a>
            </li>
            <li>
              <a class="dropdown-item d-flex align-items-center <?= in_array($currentFile,['tests.php','test.php','test_review.php'],true)?'active':'' ?>" href="<?= h($BASE_HREF) ?>/student/tests.php">
                <i class="fa-solid fa-file-circle-question me-2"></i> Provimet
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
              <a class="dropdown-item d-flex align-items-center <?= $currentFile==='payments_student.php'?'active':'' ?>" href="<?= h($BASE_HREF) ?>/payments_student.php">
                <i class="fa-solid fa-receipt me-2"></i> Pagesat
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
                      <div class="notif-ico"><i class="<?= notif_icon_student((string)$n['type']) ?>"></i></div>
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
                          <button class="btn btn-sm btn-outline-secondary notif-mark-btn">Marko si lexuar</button>
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

        <!-- Profile / Logout -->
        <a href="<?= h($BASE_HREF) ?>/profile.php" class="btn btn-sm border-0 text-white d-flex align-items-center gap-2">
          <span class="nav-avatar"><?= strtoupper(mb_substr($userName,0,1,'UTF-8')) ?></span>
          <span class="d-none d-sm-inline"><?= h($userName) ?></span>
        </a>
        <a href="<?= h($BASE_HREF) ?>/logout.php" class="btn btn-sm btn-warning">
          <i class="fa-solid fa-right-from-bracket me-1"></i> Dil
        </a>
      </div>
    </div>
  </div>
</nav>
