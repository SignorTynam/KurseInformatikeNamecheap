<?php
// dashboard_student.php (revamp: përdor CSS të njëjtë si admin + JS i ndarë)
declare(strict_types=1);
session_start();
require_once __DIR__ . '/lib/database.php';

if (empty($_SESSION['user']) || (string)($_SESSION['user']['role'] ?? '') !== 'Student') {
    header('Location: login.php'); exit;
}

$userId = (int)($_SESSION['user']['id'] ?? 0);

/* -------- Helpers -------- */
function h(?string $s): string { return htmlspecialchars($s ?? '', ENT_QUOTES, 'UTF-8'); }
function calc_percent_change($current, $previous) {
    $current  = (float)$current;
    $previous = (float)$previous;
    if ($previous == 0.0) {
        if ($current == 0.0) return ['display'=>'0%','direction'=>'same','class'=>'neutral','is_new'=>false];
        return ['display'=>'I ri','direction'=>'up','class'=>'positive','is_new'=>true];
    }
    $diff = $current - $previous;
    $percent = ($diff / $previous) * 100;
    $direction = $diff > 0 ? 'up' : ($diff < 0 ? 'down' : 'same');
    $display = ($percent > 0 ? '+' : '') . number_format(abs($percent), 0) . '%';
    $class = $diff > 0 ? 'positive' : ($diff < 0 ? 'negative' : 'neutral');
    return ['display'=>$display,'direction'=>$direction,'class'=>$class,'is_new'=>false];
}
function db_has_column(PDO $pdo, string $table, string $column): bool {
    $st = $pdo->prepare("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME=? AND COLUMN_NAME=?");
    $st->execute([$table, $column]);
    return (int)$st->fetchColumn() > 0;
}
function fill_series(array $rows, string $keyDate, string $keyVal, int $daysBack = 14): array {
    $map = [];
    foreach ($rows as $r) { $map[$r[$keyDate]] = (float)$r[$keyVal]; }
    $labels = []; $values = [];
    for ($i = $daysBack; $i >= 0; $i--) {
        $d = date('Y-m-d', strtotime("-$i days"));
        $labels[] = $d; $values[] = $map[$d] ?? 0.0;
    }
    return ['labels'=>$labels, 'values'=>$values];
}

/* -------- Init stats -------- */
$stats = [
    'course_count'=>0,'lessons_today'=>0,'unpaid_count'=>0,'pending_assignments'=>0,'my_spend'=>0.00,'avg_grade'=>null,
    'course_count_change'=>['display'=>'0%','direction'=>'same','class'=>'neutral','is_new'=>false],
    'lessons_today_change'=>['display'=>'0%','direction'=>'same','class'=>'neutral','is_new'=>false],
    'unpaid_count_change'=>['display'=>'0%','direction'=>'same','class'=>'neutral','is_new'=>false],
    'pending_assignments_change'=>['display'=>'0%','direction'=>'same','class'=>'neutral','is_new'=>false],
    'my_spend_change'=>['display'=>'0%','direction'=>'same','class'=>'neutral','is_new'=>false],
    'avg_grade_change'=>['display'=>'0%','direction'=>'same','class'=>'neutral','is_new'=>false],
    'upcoming_lessons'=>[],'recent_lessons'=>[],'payments'=>[],'recent_assignments'=>[],
    'due_soon'=>[],'open_quizzes'=>[],'recent_quiz_attempts'=>[],
];

/* -------- Time windows -------- */
$startCurrent30 = date('Y-m-d H:i:s', strtotime('-30 days'));
$endCurrent30   = date('Y-m-d H:i:s');
$startPrev30    = date('Y-m-d H:i:s', strtotime('-60 days'));
$endPrev30      = date('Y-m-d H:i:s', strtotime('-30 days'));
$todayStart     = date('Y-m-d 00:00:00');
$todayEnd       = date('Y-m-d 23:59:59');
$yStart         = date('Y-m-d 00:00:00', strtotime('-1 day'));
$yEnd           = date('Y-m-d 23:59:59', strtotime('-1 day'));

/* -------- Live window (konfigurohet) -------- */
$LIVE_DURATION_MIN = 60; // sa minuta konsiderohet “LIVE” pas nisjes
$liveWindowStart   = date('Y-m-d H:i:s', time() - $LIVE_DURATION_MIN * 60);

try {
    $hasLessonHidden = db_has_column($pdo, 'lessons', 'hidden');

    $st = $pdo->prepare("SELECT COUNT(*) FROM enroll WHERE user_id = ?");
    $st->execute([$userId]); $stats['course_count'] = (int)$st->fetchColumn();

    $st = $pdo->prepare("
        SELECT COUNT(*)
        FROM appointments a
        JOIN enroll e ON e.course_id=a.course_id
        WHERE e.user_id=? AND a.appointment_date BETWEEN ? AND ?
    ");
    $st->execute([$userId, $todayStart, $todayEnd]); $stats['lessons_today'] = (int)$st->fetchColumn();

    $st = $pdo->prepare("SELECT COUNT(*) FROM payments WHERE user_id=? AND payment_status='FAILED'");
    $st->execute([$userId]); $stats['unpaid_count'] = (int)$st->fetchColumn();

    $st = $pdo->prepare("
        SELECT COUNT(*)
        FROM assignments a
        JOIN enroll e ON e.course_id=a.course_id
        WHERE e.user_id=? AND a.status='PENDING'
    ");
    $st->execute([$userId]); $stats['pending_assignments'] = (int)$st->fetchColumn();

    $st = $pdo->prepare("
        SELECT COALESCE(SUM(amount),0)
        FROM payments
        WHERE user_id=? AND payment_status='COMPLETED'
          AND payment_date BETWEEN ? AND ?
    ");
    $st->execute([$userId, $startCurrent30, $endCurrent30]); $stats['my_spend'] = (float)$st->fetchColumn();

    $st = $pdo->prepare("SELECT AVG(grade) FROM assignments_submitted WHERE user_id=? AND grade IS NOT NULL");
    $st->execute([$userId]); $avgAll = $st->fetchColumn();
    $stats['avg_grade'] = $avgAll !== null ? round((float)$avgAll, 1) : null;

    // changes
    $st = $pdo->prepare("SELECT COUNT(*) FROM enroll WHERE user_id=? AND enrolled_at BETWEEN ? AND ?");
    $st->execute([$userId, $startCurrent30, $endCurrent30]); $currEnroll = (int)$st->fetchColumn();
    $st->execute([$userId, $startPrev30, $endPrev30]);       $prevEnroll = (int)$st->fetchColumn();
    $stats['course_count_change'] = calc_percent_change($currEnroll, $prevEnroll);

    $st = $pdo->prepare("
        SELECT COUNT(*) FROM appointments a
        JOIN enroll e ON e.course_id=a.course_id
        WHERE e.user_id=? AND a.appointment_date BETWEEN ? AND ?
    ");
    $st->execute([$userId, $todayStart, $todayEnd]); $currLessons = (int)$st->fetchColumn();
    $st->execute([$userId, $yStart, $yEnd]);         $prevLessons = (int)$st->fetchColumn();
    $stats['lessons_today_change'] = calc_percent_change($currLessons, $prevLessons);

    $st = $pdo->prepare("
        SELECT COUNT(*) FROM payments
        WHERE user_id=? AND payment_status='FAILED' AND created_at BETWEEN ? AND ?
    ");
    $st->execute([$userId, $startCurrent30, $endCurrent30]); $currFailed = (int)$st->fetchColumn();
    $st->execute([$userId, $startPrev30, $endPrev30]);       $prevFailed = (int)$st->fetchColumn();
    $stats['unpaid_count_change'] = calc_percent_change($currFailed, $prevFailed);

    $st = $pdo->prepare("
        SELECT COUNT(*)
        FROM assignments a
        JOIN enroll e ON e.course_id=a.course_id
        WHERE e.user_id=? AND a.uploaded_at BETWEEN ? AND ?
    ");
    $st->execute([$userId, $startCurrent30, $endCurrent30]); $currAsgNew = (int)$st->fetchColumn();
    $st->execute([$userId, $startPrev30, $endPrev30]);       $prevAsgNew = (int)$st->fetchColumn();
    $stats['pending_assignments_change'] = calc_percent_change($currAsgNew, $prevAsgNew);

    $st = $pdo->prepare("
        SELECT COALESCE(SUM(amount),0)
        FROM payments
        WHERE user_id=? AND payment_status='COMPLETED'
          AND payment_date BETWEEN ? AND ?
    ");
    $st->execute([$userId, $startCurrent30, $endCurrent30]); $currSpend = (float)$st->fetchColumn();
    $st->execute([$userId, $startPrev30, $endPrev30]);       $prevSpend = (float)$st->fetchColumn();
    $stats['my_spend_change'] = calc_percent_change($currSpend, $prevSpend);

    $st = $pdo->prepare("
        SELECT AVG(grade) FROM assignments_submitted
        WHERE user_id=? AND grade IS NOT NULL AND submitted_at BETWEEN ? AND ?
    ");
    $st->execute([$userId, $startCurrent30, $endCurrent30]); $currAvg = $st->fetchColumn();
    $st->execute([$userId, $startPrev30, $endPrev30]);       $prevAvg = $st->fetchColumn();
    $stats['avg_grade_change'] = calc_percent_change((float)($currAvg ?? 0), (float)($prevAvg ?? 0));

    // lists
    $st = $pdo->prepare("
        SELECT a.id AS appointment_id, a.title AS appointment_title, a.appointment_date,
               c.id AS course_id, c.title AS course_title, COALESCE(a.link, c.AulaVirtuale) AS meeting_link
        FROM appointments a
        JOIN courses c ON c.id=a.course_id
        JOIN enroll e ON e.course_id=c.id
        WHERE e.user_id=? AND a.appointment_date > NOW()
        ORDER BY a.appointment_date ASC
        LIMIT 3
    ");
    $st->execute([$userId]); $stats['upcoming_lessons'] = $st->fetchAll(PDO::FETCH_ASSOC);

    $hiddenClause = db_has_column($pdo,'lessons','hidden') ? " AND l.hidden=0 " : "";
    $sqlRecentLessons = "
        SELECT l.id AS lesson_id, l.title AS lesson_title, l.uploaded_at,
               c.id AS course_id, c.title AS course_title, l.URL AS resource_url
        FROM lessons l
        JOIN courses c ON c.id=l.course_id
        JOIN enroll e ON e.course_id=c.id
        WHERE e.user_id=? $hiddenClause
        ORDER BY l.uploaded_at DESC
        LIMIT 3
    ";
    $st = $pdo->prepare($sqlRecentLessons);
    $st->execute([$userId]); $stats['recent_lessons'] = $st->fetchAll(PDO::FETCH_ASSOC);

    $st = $pdo->prepare("
        SELECT p.payment_date, p.amount, p.payment_status, c.title AS course_title
        FROM payments p JOIN courses c ON c.id=p.course_id
        WHERE p.user_id=? ORDER BY p.payment_date DESC LIMIT 3
    ");
    $st->execute([$userId]); $stats['payments'] = $st->fetchAll(PDO::FETCH_ASSOC);

    $st = $pdo->prepare("
        SELECT a.id AS assignment_id, a.title AS task_title, a.due_date, c.title AS course_title
        FROM assignments a JOIN courses c ON c.id=a.course_id
        JOIN enroll e ON e.course_id=c.id
        WHERE e.user_id=? ORDER BY a.uploaded_at DESC LIMIT 3
    ");
    $st->execute([$userId]); $stats['recent_assignments'] = $st->fetchAll(PDO::FETCH_ASSOC);

    $st = $pdo->prepare("
        SELECT a.id AS assignment_id, a.title AS task_title, a.due_date, c.title AS course_title
        FROM assignments a
        JOIN courses c ON c.id=a.course_id
        JOIN enroll e ON e.course_id=c.id
        LEFT JOIN assignments_submitted s ON s.assignment_id=a.id AND s.user_id=?
        WHERE e.user_id=? AND a.due_date IS NOT NULL
          AND a.due_date BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 7 DAY)
          AND s.id IS NULL
        ORDER BY a.due_date ASC LIMIT 3
    ");
    $st->execute([$userId, $userId]); $stats['due_soon'] = $st->fetchAll(PDO::FETCH_ASSOC);

    $st = $pdo->prepare("
        SELECT q.id AS quiz_id, q.title AS quiz_title, q.open_at, q.close_at,
               c.id AS course_id, c.title AS course_title, q.status, q.hidden
        FROM quizzes q
        JOIN courses c ON c.id=q.course_id
        JOIN enroll e ON e.course_id=c.id
        WHERE e.user_id=? AND q.status='PUBLISHED'
          AND (q.hidden=0 OR q.hidden IS NULL)
          AND (q.open_at IS NULL OR q.open_at <= NOW())
          AND (q.close_at IS NULL OR q.close_at >= NOW())
        ORDER BY COALESCE(q.close_at, NOW() + INTERVAL 365 DAY) ASC
        LIMIT 3
    ");
    $st->execute([$userId]); $stats['open_quizzes'] = $st->fetchAll(PDO::FETCH_ASSOC);

    $st = $pdo->prepare("
        SELECT qa.id AS attempt_id, qa.quiz_id, qa.started_at, qa.submitted_at, qa.score, qa.total_points,
               q.title AS quiz_title, c.title AS course_title
        FROM quiz_attempts qa
        JOIN quizzes q ON q.id=qa.quiz_id
        JOIN courses c ON c.id=q.course_id
        WHERE qa.user_id=? ORDER BY COALESCE(qa.submitted_at, qa.started_at) DESC LIMIT 3
    ");
    $st->execute([$userId]); $stats['recent_quiz_attempts'] = $st->fetchAll(PDO::FETCH_ASSOC);

    // charts (14d)
    $payPoints = $pdo->prepare("
        SELECT DATE(payment_date) d, COALESCE(SUM(amount),0) s
        FROM payments
        WHERE user_id=? AND payment_status='COMPLETED'
          AND payment_date >= DATE_SUB(CURDATE(), INTERVAL 14 DAY)
        GROUP BY DATE(payment_date) ORDER BY d
    ");
    $payPoints->execute([$userId]); $payRows = $payPoints->fetchAll(PDO::FETCH_ASSOC);

    $lesPoints = $pdo->prepare("
        SELECT DATE(a.appointment_date) d, COUNT(*) c
        FROM appointments a
        JOIN enroll e ON e.course_id=a.course_id
        WHERE e.user_id=? AND a.appointment_date >= DATE_SUB(CURDATE(), INTERVAL 14 DAY)
        GROUP BY DATE(a.appointment_date) ORDER BY d
    ");
    $lesPoints->execute([$userId]); $lesRows = $lesPoints->fetchAll(PDO::FETCH_ASSOC);

    $catStmt = $pdo->prepare("
        SELECT c.category, COUNT(*) cnt
        FROM enroll e JOIN courses c ON c.id=e.course_id
        WHERE e.user_id=? GROUP BY c.category ORDER BY cnt DESC
    ");
    $catStmt->execute([$userId]); $catRows = $catStmt->fetchAll(PDO::FETCH_ASSOC);

    /* -------- NEXT SESSION (me LIVE window) -------- */
    $st = $pdo->prepare("
        SELECT a.id AS appointment_id, a.title AS appointment_title, a.appointment_date,
               c.id AS course_id, c.title AS course_title, COALESCE(a.link, c.AulaVirtuale) AS meeting_link
        FROM appointments a
        JOIN courses c ON c.id=a.course_id
        JOIN enroll e ON e.course_id=c.id
        WHERE e.user_id=? 
          AND a.appointment_date >= ?
        ORDER BY a.appointment_date ASC
        LIMIT 1
    ");
    $st->execute([$userId, $liveWindowStart]);
    $nextSession = $st->fetch(PDO::FETCH_ASSOC) ?: null;

} catch (PDOException $e) {
    error_log('Student dashboard error: '.$e->getMessage());
}

/* -------- Series & pie data -------- */
$paySeries  = fill_series($payRows ?? [], 'd', 's', 14);
$lesSeries  = fill_series($lesRows ?? [], 'd', 'c', 14);
$catLabels  = array_map(fn($r)=>$r['category'], $catRows ?? []);
$catValues  = array_map(fn($r)=>(int)$r['cnt'], $catRows ?? []);

/* -------- LIVE state & link -------- */
$isLive = false;
$nextLink = null;
if (!empty($nextSession)) {
    $startTs = strtotime($nextSession['appointment_date']);
    $nowTs   = time();
    $isLive  = ($startTs <= $nowTs) && ($nowTs <= $startTs + $LIVE_DURATION_MIN * 60);
    $nextLink = $nextSession['meeting_link'] ?? null;
}
?>
<!DOCTYPE html>
<html lang="sq">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <title>Paneli i studentit — Virtuale</title>

    <!-- Bootstrap 5 & FA6 -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css" rel="stylesheet">

  <!-- CSS i përbashkët -->
  <link rel="stylesheet" href="css/style.css">
  <link rel="icon" href="image/favicon.ico" type="image/x-icon" />
</head>
<body>

<?php include __DIR__ . '/navbar_logged_student.php'; ?>

<section class="admin-hero">
  <div class="container">
    <div class="d-flex flex-column flex-lg-row align-items-start align-items-lg-center justify-content-between gap-3">
      <div class="me-3">
        <h1 class="mb-1 brand-heading">Mirë se vini, <?= h($_SESSION['user']['full_name'] ?? 'Student') ?>!</h1>
        <p class="mb-0">
          Je i regjistruar në <strong><?= (int)$stats['course_count'] ?></strong> kurse,
          ke <strong><?= (int)$stats['lessons_today'] ?></strong> leksione sot
          dhe ke shpenzuar <strong>€<?= number_format($stats['my_spend'],2) ?></strong> në 30 ditët e fundit.
        </p>
      </div>

      <!-- Toolbar e vogël në të djathtë (shkon me .hero-toolbar nga style.css) -->
      <div class="d-flex align-items-center gap-2 range-pill hero-toolbar">
        <a class="btn btn-sm btn-outline-light" href="courses_student.php"><i class="fa-solid fa-book-open-reader me-1"></i> Kurset e mia</a>
        <a class="btn btn-sm btn-outline-light" href="myAssignments.php"><i class="fa-solid fa-list-check me-1"></i> Detyrat</a>
      </div>
    </div>

    <?php if (!empty($nextSession)): ?>
      <div class="mt-3 cta-join d-flex align-items-center justify-content-between flex-wrap gap-2">
        <div class="small">
          <i class="fa-regular fa-clock me-1"></i>
          <?php if (!empty($isLive)): ?>
            <span class="badge bg-danger me-2">LIVE</span>
            <strong class="brand-heading"><?= h($nextSession['appointment_title']) ?></strong> •
            <?= date('d M Y, H:i', strtotime($nextSession['appointment_date'])) ?> •
            <span><?= h($nextSession['course_title']) ?></span>
            <span class="ms-2 text-muted">Leksioni është duke u zhvilluar LIVE.</span>
          <?php else: ?>
            Seanca e radhës: <strong class="brand-heading"><?= h($nextSession['appointment_title']) ?></strong> •
            <?= date('d M Y, H:i', strtotime($nextSession['appointment_date'])) ?> •
            <span><?= h($nextSession['course_title']) ?></span>
          <?php endif; ?>
        </div>
        <?php if ($nextLink && filter_var($nextLink, FILTER_VALIDATE_URL)): ?>
          <a class="btn btn-sm btn-light" target="_blank" rel="noopener" href="<?= h($nextLink) ?>">
            <i class="fa-solid fa-video me-1"></i>
            <?= !empty($isLive) ? 'HYR TANI' : 'LIDHU TANI' ?>
          </a>
        <?php endif; ?>
      </div>
    <?php endif; ?>
  </div>
</section>

<div class="container">

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

  <!-- KPI cards -->
  <div class="row g-3 mb-4 kpi-grid">
    <?php
      $kpis = [
        ['title'=>'Kurse aktive','value'=>$stats['course_count'],'chg'=>$stats['course_count_change'],'icon'=>'fa-book'],
        ['title'=>'Leksione sot','value'=>$stats['lessons_today'],'chg'=>$stats['lessons_today_change'],'icon'=>'fa-calendar-check'],
        ['title'=>'Detyra të padorëzuara','value'=>$stats['pending_assignments'],'chg'=>$stats['pending_assignments_change'],'icon'=>'fa-clipboard-check'],
        ['title'=>'Pagesa të dështuara','value'=>$stats['unpaid_count'],'chg'=>$stats['unpaid_count_change'],'icon'=>'fa-triangle-exclamation'],
        ['title'=>'Shpenzime (30 ditë)','value'=>'€'.number_format($stats['my_spend'],2),'chg'=>$stats['my_spend_change'],'icon'=>'fa-euro-sign'],
        ['title'=>'Mesatarja e notave','value'=>($stats['avg_grade']!==null? $stats['avg_grade'].'/10' : '—'),'chg'=>$stats['avg_grade_change'],'icon'=>'fa-star-half-stroke'],
      ];
    ?>
    <?php foreach ($kpis as $k): ?>
    <div class="col-12 col-sm-6 col-lg-4">
      <div class="card card-elev h-100">
        <div class="card-body d-flex align-items-center gap-3">
          <div class="icon text-primary-emphasis bg-primary-subtle rounded-3 p-3 fs-5">
            <i class="fa-solid <?= h($k['icon']) ?>" aria-hidden="true"></i>
          </div>
          <div class="flex-grow-1">
            <div class="text-secondary small mb-1 brand-heading"><?= h($k['title']) ?></div>
            <div class="h5 mb-0 brand-heading"><?= $k['value'] ?></div>
            <?php $ch = $k['chg']; ?>
            <div class="small mt-1 stat-change <?= h($ch['class']) ?>">
              <?php if (!empty($ch['is_new'])): ?>
                <i class="fa-solid fa-star"></i> <?= h($ch['display']) ?>
              <?php elseif ($ch['direction']==='same'): ?>
                <i class="fa-solid fa-minus"></i> <?= h($ch['display']) ?>
              <?php else: ?>
                <i class="fa-solid fa-arrow-<?= h($ch['direction']) ?>"></i> <?= h($ch['display']) ?>
              <?php endif; ?>
            </div>
          </div>
        </div>
      </div>
    </div>
    <?php endforeach; ?>
  </div>

  <!-- Quick actions -->
  <div class="quick mb-4">
    <div class="row g-3">
      <div class="col-6 col-md-4 col-lg-2">
        <a class="card text-center p-3" href="courses_student.php">
          <div class="display-6 mb-2 text-primary"><i style="color: var(--primary)" class="fa-solid fa-book-open-reader"></i></div>
          <div class="fw-semibold brand-heading">Kurset e mi</div>
          <div class="text-secondary small">Shiko listën</div>
        </a>
      </div>
      <div class="col-6 col-md-4 col-lg-2">
        <a class="card text-center p-3" href="appointments_student.php">
          <div class="display-6 mb-2 text-primary"><i style="color: var(--primary)" class="fa-solid fa-calendar-days"></i></div>
          <div class="fw-semibold brand-heading">Orari</div>
          <div class="text-secondary small">Seancat</div>
        </a>
      </div>
      <div class="col-6 col-md-4 col-lg-2">
        <a class="card text-center p-3" href="myAssignments.php">
          <div class="display-6 mb-2 text-primary"><i style="color: var(--primary)" class="fa-solid fa-list-check"></i></div>
          <div class="fw-semibold brand-heading">Detyrat</div>
          <div class="text-secondary small">Dorëzo & ndiq</div>
        </a>
      </div>
      <div class="col-6 col-md-4 col-lg-2">
        <a class="card text-center p-3" href="notes_student.php">
          <div class="display-6 mb-2 text-primary"><i style="color: var(--primary)" class="fa-solid fa-notes-medical"></i></div>
          <div class="fw-semibold brand-heading">Shënimet</div>
          <div class="text-secondary small">Organizo</div>
        </a>
      </div>
      <div class="col-6 col-md-4 col-lg-2">
        <a class="card text-center p-3" href="payments_student.php">
          <div class="display-6 mb-2 text-primary"><i style="color: var(--primary)" class="fa-solid fa-credit-card"></i></div>
          <div class="fw-semibold brand-heading">Pagesat</div>
          <div class="text-secondary small">Historik</div>
        </a>
      </div>
      <div class="col-6 col-md-4 col-lg-2">
        <a class="card text-center p-3" href="threads_student.php">
          <div class="display-6 mb-2 text-primary"><i style="color: var(--primary)" class="fa-solid fa-comments"></i></div>
          <div class="fw-semibold brand-heading">Diskutimet</div>
          <div class="text-secondary small">Pyetje & përgjigje</div>
        </a>
      </div>
    </div>
  </div>

</div>

<?php include __DIR__ . '/footer2.php'; ?>

<!-- JS vendors -->
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js"></script>
</body>
</html>
