<?php
// dashboard_administrator.php — Admin Panel (UI i ri + statistika + JS/CSS të ndara)
declare(strict_types=1);
session_start();
require_once __DIR__ . '/lib/database.php';

date_default_timezone_set('Europe/Rome');

// Guard bazik: vetëm Administrator
if (empty($_SESSION['user']) || (($_SESSION['user']['role'] ?? '') !== 'Administrator')) {
    header('Location: login.php'); exit;
}

/* ----------------------------- CSRF & Helper ----------------------------- */
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$csrf = $_SESSION['csrf_token'];
function h(?string $s): string { return htmlspecialchars($s ?? '', ENT_QUOTES, 'UTF-8'); }

/* ----------------------------- AJAX Actions (POST + CSRF) ---------------- */
// Shtuar "toggle_message_read" për shënimin e mesazhit si lexuar/pa lexuar
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && isset($_POST['action'])) {
    header('Content-Type: application/json; charset=utf-8');
    try {
        if (!hash_equals($csrf, (string)($_POST['csrf'] ?? ''))) {
            throw new RuntimeException('CSRF invalid.');
        }
        $action = (string)($_POST['action'] ?? '');
        switch ($action) {
            case 'toggle_message_read': {
                $id = (int)($_POST['id'] ?? 0);
                $stmt = $pdo->prepare("UPDATE messages SET read_status = 1 - read_status WHERE id = ?");
                $stmt->execute([$id]);
                echo json_encode(['ok'=>true]); exit;
            }
            default:
                throw new InvalidArgumentException('Veprim i panjohur.');
        }
    } catch (Throwable $e) {
        error_log('Admin action error: '.$e->getMessage());
        http_response_code(400);
        echo json_encode(['ok'=>false,'error'=>$e->getMessage()]);
    }
    exit;
}

/* ----------------------------- Parametra -------------------------- */
$rangeDays   = isset($_GET['range']) && in_array((int)$_GET['range'], [7,30,90], true) ? (int)$_GET['range'] : 30;
$nowDt       = new DateTimeImmutable('now');
$endCurrent  = $nowDt->format('Y-m-d H:i:s');
$startCurrent= $nowDt->sub(new DateInterval('P' . $rangeDays . 'D'))->format('Y-m-d H:i:s');

$endPrev     = $startCurrent;
$startPrev   = (new DateTimeImmutable($startCurrent))->sub(new DateInterval('P' . $rangeDays . 'D'))->format('Y-m-d H:i:s');

/* ----------------------------- LIVE window ------------------------ */
/* Sa minuta e konsiderojmë seancën “LIVE” pasi ka nisur (baneri mos të zhduket menjëherë) */
$LIVE_DURATION_MIN = 60;
$liveWindowStart   = $nowDt->sub(new DateInterval('PT' . $LIVE_DURATION_MIN . 'M'))->format('Y-m-d H:i:s');

/* ----------------------------- Inic. stats ------------------------ */
$stats = [
    'active_courses'      => 0,
    'total_users'         => 0,
    'lessons_today'       => 0,
    'pending_payments'    => 0,
    'unread_messages'     => 0,
    'revenue'             => 0.00,
    'pending_approvals'   => 0,
    'to_grade'            => 0,
    'quizzes_published'   => 0,

    'upcoming_lessons'    => [],
    'recent_lessons'      => [],
    'recent_assignments'  => [],
    'recent_users'        => [],
    'recent_unread_msgs'  => [],
    'recent_failed_pay'   => [],

    'active_courses_change'   => ['display' => '0%', 'direction' => 'same', 'class' => 'neutral', 'is_new' => false],
    'total_users_change'      => ['display' => '0%', 'direction' => 'same', 'class' => 'neutral', 'is_new' => false],
    'lessons_today_change'    => ['display' => '0%', 'direction' => 'same', 'class' => 'neutral', 'is_new' => false],
    'pending_payments_change' => ['display' => '0%', 'direction' => 'same', 'class' => 'neutral', 'is_new' => false],
    'unread_messages_change'  => ['display' => '0%', 'direction' => 'same', 'class' => 'neutral', 'is_new' => false],
    'revenue_change'          => ['display' => '0%', 'direction' => 'same', 'class' => 'neutral', 'is_new' => false],
    'pending_approvals_change'=> ['display' => '0%', 'direction' => 'same', 'class' => 'neutral', 'is_new' => false],
];

/* ------------------------ Ndryshimet % helper --------------------- */
function calc_percent_change($current, $previous) {
    $current  = (float)$current;
    $previous = (float)$previous;
    if ($previous == 0.0) {
        if ($current == 0.0) {
            return ['display' => '0%', 'direction' => 'same', 'class' => 'neutral', 'is_new' => false];
        }
        return ['display' => 'I ri', 'direction' => 'up', 'class' => 'positive', 'is_new' => true];
    }
    $diff = $current - $previous;
    $percent = ($diff / $previous) * 100;
    $direction = $diff > 0 ? 'up' : ($diff < 0 ? 'down' : 'same');
    $display = ($percent > 0 ? '+' : '') . number_format(abs($percent), 0) . '%';
    $class = $diff > 0 ? 'positive' : ($diff < 0 ? 'negative' : 'neutral');
    return ['display' => $display, 'direction' => $direction, 'class' => $class, 'is_new' => false];
}

/* ---------------------- Defaults për koleksionet ------------------ */
$revPoints  = [];
$userPoints = [];
$catRows    = [];
$topCourses = [];
$currFailed = 0;

/* ---------------------- Next session (për BANER) ------------------ */
$nextSession = null;
$isLiveNext  = false;
$nextLink    = null;

try {
    /* ====================== Snapshot totals ====================== */
    $stats['active_courses']    = (int)$pdo->query("SELECT COUNT(*) FROM courses WHERE status='ACTIVE'")->fetchColumn();
    $stats['total_users']       = (int)$pdo->query("SELECT COUNT(*) FROM users")->fetchColumn();
    $stats['lessons_today']     = (int)$pdo->query("SELECT COUNT(*) FROM appointments WHERE DATE(appointment_date)=CURDATE()")->fetchColumn();
    $stats['pending_payments']  = (int)$pdo->query("SELECT COUNT(*) FROM payments WHERE payment_status='FAILED'")->fetchColumn();
    $stats['unread_messages']   = (int)$pdo->query("SELECT COUNT(*) FROM messages WHERE read_status=FALSE")->fetchColumn();
    $stats['pending_approvals'] = (int)$pdo->query("SELECT COUNT(*) FROM users WHERE status='NE SHQYRTIM'")->fetchColumn();
    $stats['to_grade']          = (int)$pdo->query("SELECT COUNT(*) FROM assignments_submitted WHERE grade IS NULL")->fetchColumn();
    $stats['quizzes_published'] = (int)$pdo->query("SELECT COUNT(*) FROM quizzes WHERE status='PUBLISHED' AND hidden=0")->fetchColumn();

    $stmt = $pdo->prepare("
        SELECT COALESCE(SUM(amount),0)
        FROM payments
        WHERE payment_status='COMPLETED'
          AND payment_date BETWEEN ? AND ?
    ");
    $stmt->execute([$startCurrent, $endCurrent]);
    $stats['revenue'] = (float)$stmt->fetchColumn();

    /* ====================== Period comparisons ====================== */
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM courses WHERE status='ACTIVE' AND created_at BETWEEN ? AND ?");
    $stmt->execute([$startCurrent, $endCurrent]); $currActiveCreated = (int)$stmt->fetchColumn();
    $stmt->execute([$startPrev,    $endPrev   ]); $prevActiveCreated = (int)$stmt->fetchColumn();
    $stats['active_courses_change'] = calc_percent_change($currActiveCreated, $prevActiveCreated);

    $stmt = $pdo->prepare("SELECT COUNT(*) FROM users WHERE created_at BETWEEN ? AND ?");
    $stmt->execute([$startCurrent, $endCurrent]); $currUsersNew = (int)$stmt->fetchColumn();
    $stmt->execute([$startPrev,    $endPrev   ]); $prevUsersNew = (int)$stmt->fetchColumn();
    $stats['total_users_change'] = calc_percent_change($currUsersNew, $prevUsersNew);

    $todayStart = date('Y-m-d 00:00:00');
    $todayEnd   = date('Y-m-d 23:59:59');
    $yStart     = date('Y-m-d 00:00:00', strtotime('-1 day'));
    $yEnd       = date('Y-m-d 23:59:59', strtotime('-1 day'));
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM appointments WHERE appointment_date BETWEEN ? AND ?");
    $stmt->execute([$todayStart, $todayEnd]); $currLessonsToday = (int)$stmt->fetchColumn();
    $stmt->execute([$yStart,     $yEnd     ]); $prevLessonsYday = (int)$stmt->fetchColumn();
    $stats['lessons_today_change'] = calc_percent_change($currLessonsToday, $prevLessonsYday);

    $stmt = $pdo->prepare("SELECT COUNT(*) FROM payments WHERE payment_status='FAILED' AND created_at BETWEEN ? AND ?");
    $stmt->execute([$startCurrent, $endCurrent]); $currFailed = (int)$stmt->fetchColumn();
    $stmt->execute([$startPrev,    $endPrev   ]); $prevFailed = (int)$stmt->fetchColumn();
    $stats['pending_payments_change'] = calc_percent_change($currFailed, $prevFailed);

    $stmt = $pdo->prepare("SELECT COUNT(*) FROM messages WHERE read_status=FALSE AND created_at BETWEEN ? AND ?");
    $stmt->execute([$startCurrent, $endCurrent]); $currUnread = (int)$stmt->fetchColumn();
    $stmt->execute([$startPrev,    $endPrev   ]); $prevUnread = (int)$stmt->fetchColumn();
    $stats['unread_messages_change'] = calc_percent_change($currUnread, $prevUnread);

    $stmt = $pdo->prepare("SELECT COUNT(*) FROM users WHERE status='NE SHQYRTIM' AND created_at BETWEEN ? AND ?");
    $stmt->execute([$startCurrent, $endCurrent]); $currPendingAppr = (int)$stmt->fetchColumn();
    $stmt->execute([$startPrev,    $endPrev   ]); $prevPendingAppr = (int)$stmt->fetchColumn();
    $stats['pending_approvals_change'] = calc_percent_change($currPendingAppr, $prevPendingAppr);

    $stmt = $pdo->prepare("
        SELECT COALESCE(SUM(amount),0)
        FROM payments
        WHERE payment_status='COMPLETED'
          AND payment_date BETWEEN ? AND ?
    ");
    $stmt->execute([$startCurrent, $endCurrent]); $currRevenue = (float)$stmt->fetchColumn();
    $stmt->execute([$startPrev,    $endPrev   ]); $prevRevenue = (float)$stmt->fetchColumn();
    $stats['revenue_change'] = calc_percent_change($currRevenue, $prevRevenue);

    /* ====================== NEXT SESSION for banner ====================== */
    $stmt = $pdo->prepare("
        SELECT a.id AS appointment_id, a.title AS appointment_title, a.appointment_date,
               c.id AS course_id, c.title AS course_title,
               COALESCE(a.link, c.AulaVirtuale) AS meeting_link
        FROM appointments a
        JOIN courses c ON a.course_id = c.id
        WHERE a.appointment_date >= ?
        ORDER BY a.appointment_date ASC
        LIMIT 1
    ");
    $stmt->execute([$liveWindowStart]);
    $nextSession = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;

    if ($nextSession) {
        $startTs   = strtotime($nextSession['appointment_date']);
        $nowTs     = time();
        $isLiveNext= ($startTs <= $nowTs) && ($nowTs <= $startTs + $LIVE_DURATION_MIN * 60);
        $nextLink  = $nextSession['meeting_link'] ?? null;
    }

    /* ====================== Lists ====================== */
    // (UPCOMING: përfshijmë edhe seancat që kanë nisur brenda dritares LIVE)
    $stmt = $pdo->prepare("
        SELECT a.id AS appointment_id, a.title AS appointment_title, a.appointment_date,
               c.id AS course_id, c.title AS course_title, c.AulaVirtuale AS meeting_link, a.link AS custom_link
        FROM appointments a
        JOIN courses c ON a.course_id = c.id
        WHERE a.appointment_date >= ?
        ORDER BY a.appointment_date ASC
    ");
    $stmt->execute([$liveWindowStart]);
    $stats['upcoming_lessons'] = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

    $stats['recent_lessons'] = $pdo->query("
        SELECT l.id AS lesson_id, l.title AS lesson_title, l.uploaded_at,
               c.id AS course_id, c.title AS course_title, l.URL AS resource_url
        FROM lessons l
        JOIN courses c  ON l.course_id = c.id
        LEFT JOIN sections s ON s.id = l.section_id
        WHERE COALESCE(s.hidden,0)=0
        ORDER BY l.uploaded_at DESC
        LIMIT 3
    ")->fetchAll(PDO::FETCH_ASSOC);

    $stats['recent_assignments'] = $pdo->query("
        SELECT a.id AS assignment_id, a.title AS assignment_title, a.uploaded_at,
               c.id AS course_id, c.title AS course_title
        FROM assignments a
        JOIN courses c ON a.course_id = c.id
        ORDER BY a.uploaded_at DESC
        LIMIT 3
    ")->fetchAll(PDO::FETCH_ASSOC);

    $stats['recent_users'] = $pdo->query("
        SELECT id, full_name, role, status, created_at, email
        FROM users
        ORDER BY created_at DESC
        LIMIT 5
    ")->fetchAll(PDO::FETCH_ASSOC);

    $stats['recent_unread_msgs'] = $pdo->query("
        SELECT id, name, email, subject, created_at
        FROM messages
        WHERE read_status=FALSE
        ORDER BY created_at DESC
        LIMIT 5
    ")->fetchAll(PDO::FETCH_ASSOC);

    $stmt = $pdo->prepare("
        SELECT p.id, p.amount, p.created_at, u.full_name, c.title AS course_title
        FROM payments p
        JOIN users u ON u.id = p.user_id
        JOIN courses c ON c.id = p.course_id
        WHERE p.payment_status='FAILED'
          AND p.created_at BETWEEN ? AND ?
        ORDER BY p.created_at DESC
        LIMIT 5
    ");
    $stmt->execute([$startCurrent, $endCurrent]);
    $stats['recent_failed_pay'] = $stmt->fetchAll(PDO::FETCH_ASSOC);

    /* ====================== Extra për grafikë ====================== */
    $stmt = $pdo->prepare("
        SELECT DATE(payment_date) d, COALESCE(SUM(amount),0) s
        FROM payments
        WHERE payment_status='COMPLETED'
          AND payment_date BETWEEN ? AND ?
        GROUP BY DATE(payment_date)
        ORDER BY d
    ");
    $stmt->execute([$startCurrent, $endCurrent]);
    $revPoints = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

    $stmt = $pdo->prepare("
        SELECT DATE(created_at) d, COUNT(*) c
        FROM users
        WHERE created_at BETWEEN ? AND ?
        GROUP BY DATE(created_at)
        ORDER BY d
    ");
    $stmt->execute([$startCurrent, $endCurrent]);
    $userPoints = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

    $catRows = $pdo->query("
        SELECT category, COUNT(*) cnt
        FROM courses
        WHERE status='ACTIVE'
        GROUP BY category
        ORDER BY cnt DESC
    ")->fetchAll(PDO::FETCH_ASSOC) ?: [];

    $stmt = $pdo->prepare("
        SELECT c.title, COALESCE(SUM(p.amount),0) total
        FROM payments p
        JOIN courses c ON c.id = p.course_id
        WHERE p.payment_status='COMPLETED'
          AND p.payment_date BETWEEN ? AND ?
        GROUP BY c.id
        ORDER BY total DESC
        LIMIT 5
    ");
    $stmt->execute([$startCurrent, $endCurrent]);
    $topCourses = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

} catch (PDOException $e) {
    error_log('Dashboard stats error: ' . $e->getMessage());
}

/* ---------------- Helper: seri me ditë të plota ------------------- */
function fill_series($rows, string $keyDate, string $keyVal, int $daysBack): array {
    if (!is_array($rows)) { $rows = []; }
    $map = [];
    foreach ($rows as $r) {
        $d = isset($r[$keyDate]) ? substr((string)$r[$keyDate], 0, 10) : null;
        if ($d !== null) { $map[$d] = (float)($r[$keyVal] ?? 0); }
    }
    $labels = []; $values = [];
    for ($i = $daysBack; $i >= 0; $i--) {
        $d = date('Y-m-d', strtotime("-$i days"));
        $labels[] = $d; $values[] = $map[$d] ?? 0.0;
    }
    return ['labels'=>$labels, 'values'=>$values];
}

/* ---------------------- Përgatitja e serive ----------------------- */
$revSeries  = fill_series($revPoints,  'd', 's', $rangeDays);
$userSeries = fill_series($userPoints, 'd', 'c', $rangeDays);

$catLabels  = array_map(fn($r)=>$r['category'], $catRows);
$catValues  = array_map(fn($r)=>(int)$r['cnt'], $catRows);

$topLabels  = array_map(fn($r)=>$r['title'], $topCourses);
$topValues  = array_map(fn($r)=>(float)$r['total'], $topCourses);

// Paketim dataset për JS (do lexohet nga administrator/admin.js)
$JS_DATA = [
    'csrf'       => $csrf,
    'revSeries'  => $revSeries,
    'userSeries' => $userSeries,
    'catLabels'  => $catLabels,
    'catValues'  => $catValues,
    'topLabels'  => $topLabels,
    'topValues'  => $topValues,
];
?>
<!DOCTYPE html>
<html lang="sq">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Paneli i administratorit — Virtuale</title>

    <!-- Bootstrap 5 & FA6 -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css" rel="stylesheet">

    <!-- CSS i dedikuar i administratorit -->
    <link rel="stylesheet" href="css/style.css">
    <link rel="icon" href="image/favicon.ico" type="image/x-icon" />
</head>
<body>

<?php if (is_file(__DIR__.'/navbar_logged_administrator.php')) include __DIR__ . '/navbar_logged_administrator.php'; ?>

<section class="admin-hero">
  <div class="container">
    <div class="d-flex flex-column flex-lg-row align-items-start align-items-lg-center justify-content-between gap-3">
        <div class="me-3">
            <h1 class="mb-1 brand-heading">Mirë se vini, <?= h($_SESSION['user']['full_name'] ?? 'Administrator') ?>!</h1>
            <p class="mb-0">Po menaxhoni <strong><?= (int)$stats['total_users'] ?></strong> përdorues,
                <strong><?= (int)$stats['active_courses'] ?></strong> kurse aktive,
                dhe <strong>€<?= number_format($stats['revenue'],2) ?></strong> të ardhura në <?= $rangeDays ?> ditë.</p>
        </div>
        <div class="d-flex align-items-center gap-2 range-pill hero-toolbar">
            <?php foreach ([7,30,90] as $d): ?>
              <a class="btn btn-sm <?= $rangeDays===$d?'active btn-light':'btn-outline-light' ?>" href="?range=<?= $d ?>" aria-label="Shfaq <?= $d ?> ditë"><?= $d ?> ditë</a>
            <?php endforeach; ?>
            <a class="btn btn-sm btn-outline-light" href="payments.php?status=COMPLETED&range=<?= $rangeDays ?>"><i class="fa-solid fa-file-export me-1"></i> Raport</a>
            <a class="btn btn-sm btn-outline-light" href="help.php"><i class="fa-regular fa-circle-question me-1"></i> Ndihmë</a>
        </div>
    </div>

    <!-- BANERI i seancës (me LIVE window) -->
    <?php if (!empty($nextSession)): ?>
      <div class="mt-3 cta-join d-flex align-items-center justify-content-between flex-wrap gap-2">
                <div class="small">
          <i class="fa-regular fa-clock me-1"></i>
          <?php if (!empty($isLiveNext)): ?>
            <span class="badge bg-danger me-2">LIVE</span>
            <strong class="brand-heading"><?= h($nextSession['appointment_title']) ?></strong> •
            <?= date('d M Y, H:i', strtotime($nextSession['appointment_date'])) ?> •
                                                <a href="course_details.php?course_id=<?= (int)$nextSession['course_id'] ?>" class="fw-semibold text-reset text-decoration-underline"><?= h($nextSession['course_title']) ?></a>
                        <span class="ms-2 text-muted">Leksioni është duke u zhvilluar LIVE.</span>
          <?php else: ?>
            Seanca e radhës: <strong class="brand-heading"><?= h($nextSession['appointment_title']) ?></strong> •
            <?= date('d M Y, H:i', strtotime($nextSession['appointment_date'])) ?> •
                                                <a href="course_details.php?course_id=<?= (int)$nextSession['course_id'] ?>" class="fw-semibold text-reset text-decoration-underline"><?= h($nextSession['course_title']) ?></a>
          <?php endif; ?>
        </div>
        <?php if ($nextLink && filter_var($nextLink, FILTER_VALIDATE_URL)): ?>
          <a class="btn btn-sm btn-light" target="_blank" rel="noopener" href="<?= h($nextLink) ?>">
            <i class="fa-solid fa-video me-1"></i><?= !empty($isLiveNext) ? 'HYR TANI' : 'LIDHU TANI' ?>
          </a>
        <?php endif; ?>
      </div>
    <?php endif; ?>
  </div>
</section>

<div class="container">
    <div class="row g-3 mb-4 upcoming-lessons-wrap">
        <div class="col-12">
            <div class="card card-elev h-100">
                <div class="card-body">
                    <div class="d-flex align-items-center justify-content-between mb-3 upcoming-lessons-head">
                        <h2 class="h5 mb-0 brand-heading">Të gjitha leksionet e ardhshme</h2>
                        <span class="badge upcoming-lessons-count"><?= count($stats['upcoming_lessons']) ?></span>
                    </div>

                    <?php if (!empty($stats['upcoming_lessons'])): ?>
                        <div class="upcoming-lessons-list">
                            <?php foreach ($stats['upcoming_lessons'] as $idx => $lesson): ?>
                                <?php $isNextLesson = ($idx === 0); ?>
                                <div class="upcoming-lesson-item <?= $isNextLesson ? 'is-next' : '' ?>">
                                    <div class="d-flex flex-column flex-lg-row align-items-start align-items-lg-center justify-content-between gap-2 upcoming-lesson-row">
                                        <div class="upcoming-lesson-meta">
                                            <?php if ($isNextLesson): ?>
                                                <span class="badge upcoming-lesson-next-badge mb-1">LEKSIONI I RADHËS</span>
                                            <?php endif; ?>
                                            <div class="fw-semibold brand-heading upcoming-lesson-title"><?= h($lesson['appointment_title']) ?></div>
                                            <div class="small text-muted upcoming-lesson-date"><?= date('d M Y, H:i', strtotime($lesson['appointment_date'])) ?></div>
                                        </div>
                                        <a href="course_details.php?course_id=<?= (int)$lesson['course_id'] ?>" class="fw-semibold upcoming-lesson-course"><?= h($lesson['course_title']) ?></a>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php else: ?>
                        <p class="text-muted mb-0">Nuk ka leksione të planifikuara për momentin.</p>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>

    <div class="row g-3 mb-4">
        <div class="col-12 col-lg-12">
            <div class="card card-elev h-100">
                <div class="card-body">
                    <form class="d-flex gap-2" method="get" action="search.php" role="search">
                        <input type="hidden" name="scope" value="all">
                        <input class="form-control" type="search" name="q" placeholder="Kërko në kurse, përdorues, pagesa..." aria-label="Kërko">
                        <button class="btn btn-dark" type="submit"><i class="fa-solid fa-magnifying-glass me-1"></i>Kërko</button>
                    </form>
                    <div class="small text-secondary mt-2">Sugjerim: “kurs web”, “pagesa FAILED”.</div>
                </div>
            </div>
        </div>
    </div>

    <!-- Quick actions -->
    <div class="row g-3 align-items-stretch mb-4">
        <div class="col-12 col-lg-12">
            <div class="quick">
                <div class="row g-3">
                    <div class="col-6 col-md-4 col-xl-2">
                        <a class="card text-center p-3" href="add_course.php">
                            <div class="display-6 mb-2 text-primary"><i style="color: var(--primary)" class="fa-solid fa-circle-plus"></i></div>
                            <div class="fw-semibold brand-heading">Kurs i ri</div>
                            <div class="text-secondary small">Krijo kurs</div>
                        </a>
                    </div>
                    <div class="col-6 col-md-4 col-xl-2">
                        <a class="card text-center p-3" href="add_user.php">
                            <div class="display-6 mb-2 text-primary"><i style="color: var(--primary)" class="fa-solid fa-user-plus"></i></div>
                            <div class="fw-semibold brand-heading">Shto përdorues</div>
                            <div class="text-secondary small">Krijo llogari</div>
                        </a>
                    </div>
                    <div class="col-6 col-md-4 col-xl-2">
                        <a class="card text-center p-3" href="users.php">
                            <div class="display-6 mb-2 text-primary"><i style="color: var(--primary)" class="fa-solid fa-users-gear"></i></div>
                            <div class="fw-semibold brand-heading">Menaxho përdorues</div>
                            <div class="text-secondary small">Listo & filtro</div>
                        </a>
                    </div>
                    <div class="col-6 col-md-4 col-xl-2">
                        <a class="card text-center p-3" href="course.php">
                            <div class="display-6 mb-2 text-primary"><i style="color: var(--primary)" class="fa-solid fa-book-open"></i></div>
                            <div class="fw-semibold brand-heading">Menaxho kurse</div>
                            <div class="text-secondary small">Shiko/ndrysho</div>
                        </a>
                    </div>
                    <div class="col-6 col-md-4 col-xl-2">
                        <a class="card text-center p-3" href="payments.php">
                            <div class="display-6 mb-2 text-primary"><i style="color: var(--primary)" class="fa-solid fa-credit-card"></i></div>
                            <div class="fw-semibold brand-heading">Pagesat</div>
                            <div class="text-secondary small">Transaksione</div>
                        </a>
                    </div>
                    <div class="col-6 col-md-4 col-xl-2">
                        <a class="card text-center p-3" href="messages.php">
                            <div class="display-6 mb-2 text-primary"><i style="color: var(--primary)" class="fa-solid fa-comments"></i></div>
                            <div class="fw-semibold brand-heading">Mesazhet</div>
                            <div class="text-secondary small">Inbox & përgjigje</div>
                        </a>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- KPI cards -->
    <div class="row g-3 mb-4 kpi-grid">
        <?php
          $kpis = [
            ['title'=>'Kurset aktive','value'=>$stats['active_courses'],'chg'=>$stats['active_courses_change'],'icon'=>'fa-book'],
            ['title'=>'Përdorues total','value'=>$stats['total_users'],'chg'=>$stats['total_users_change'],'icon'=>'fa-users'],
            ['title'=>'Leksione sot','value'=>$stats['lessons_today'],'chg'=>$stats['lessons_today_change'],'icon'=>'fa-calendar-check'],
            ['title'=>'Pagesa të dështuara','value'=>$stats['pending_payments'],'chg'=>$stats['pending_payments_change'],'icon'=>'fa-triangle-exclamation'],
            ['title'=>'Mesazhe të palexuara','value'=>$stats['unread_messages'],'chg'=>$stats['unread_messages_change'],'icon'=>'fa-envelope'],
            ['title'=>'Të ardhurat ('.$rangeDays.'d)','value'=>'€'.number_format($stats['revenue'],2),'chg'=>$stats['revenue_change'],'icon'=>'fa-euro-sign'],
            ['title'=>'Aprovime të pritura','value'=>$stats['pending_approvals'],'chg'=>$stats['pending_approvals_change'],'icon'=>'fa-user-check'],
            ['title'=>'Për t’u vlerësuar','value'=>$stats['to_grade'],'chg'=>['display'=>'','direction'=>'same','class'=>'neutral','is_new'=>false],'icon'=>'fa-clipboard-check'],
            ['title'=>'Quiz-e publikuara','value'=>$stats['quizzes_published'],'chg'=>['display'=>'','direction'=>'same','class'=>'neutral','is_new'=>false],'icon'=>'fa-circle-question'],
          ];
        ?>
        <?php foreach ($kpis as $k): ?>
        <div class="col-12 col-sm-6 col-lg-4 col-xxl-3">
            <div class="card card-elev h-100">
                <div class="card-body d-flex align-items-center gap-3">
                    <div class="icon text-primary-emphasis bg-primary-subtle rounded-3 p-3 fs-5">
                        <i class="fa-solid <?= $k['icon'] ?>" aria-hidden="true"></i>
                    </div>
                    <div class="flex-grow-1">
                        <div class="text-secondary small mb-1 brand-heading"><?= h($k['title']) ?></div>
                        <div class="h5 mb-0 brand-heading"><?= $k['value'] ?></div>
                        <?php $ch = $k['chg']; ?>
                        <?php if (!empty($ch['display'])): ?>
                        <div class="small mt-1 stat-change <?= h($ch['class']) ?>">
                          <?php if (!empty($ch['is_new'])): ?>
                            <i class="fa-solid fa-star"></i> <?= h($ch['display']) ?>
                          <?php elseif ($ch['direction']==='same'): ?>
                            <i class="fa-solid fa-minus"></i> <?= h($ch['display']) ?>
                          <?php else: ?>
                            <i class="fa-solid fa-arrow-<?= h($ch['direction']) ?>"></i> <?= h($ch['display']) ?>
                          <?php endif; ?>
                        </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
        <?php endforeach; ?>
    </div>
</div>

<?php if (is_file(__DIR__.'/footer2.php')) include __DIR__ . '/footer2.php'; ?>

<!-- JS vendorët -->
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<!-- Chart.js -->
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js"></script>
</body>
</html>
