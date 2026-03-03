<?php
// quiz_details.php — REVAMP (2026) • Detaje quiz + Pjesëmarrësit (km-* layout)
// - Layout: header + tabs + cards + sticky side + actionbar
// - CSS: km-lms-forms.css (bazë) + km-quiz-details.css (shtesë)
// - Funksionalitet: identik (publish/unpublish, health, people filters, export csv)

declare(strict_types=1);
session_start();

$ROOT = dirname(__DIR__);
$scriptDir = str_replace('\\', '/', dirname((string)($_SERVER['SCRIPT_NAME'] ?? '')));
$BASE_URL = $scriptDir;
foreach (['/threads', '/quizzes', '/sections'] as $suffix) {
  if ($suffix !== '/' && str_ends_with($BASE_URL, $suffix)) {
    $BASE_URL = substr($BASE_URL, 0, -strlen($suffix));
  }
}
if ($BASE_URL === '') $BASE_URL = '/';

/* -------------------- DB bootstrap -------------------- */
require_once $ROOT . '/lib/database.php';

/* -------------------- RBAC -------------------- */
if (!isset($_SESSION['user']) || !in_array($_SESSION['user']['role'] ?? '', ['Administrator','Instruktor'], true)) {
  header('Location: ' . $BASE_URL . '/login.php'); exit;
}
$ROLE  = (string)($_SESSION['user']['role'] ?? '');
$ME_ID = (int)($_SESSION['user']['id'] ?? 0);

/* -------------------- CSRF -------------------- */
if (empty($_SESSION['csrf_token'])) { $_SESSION['csrf_token'] = bin2hex(random_bytes(32)); }
$CSRF = (string)$_SESSION['csrf_token'];

/* -------------------- Helpers ----------------- */
function h(?string $s): string { return htmlspecialchars($s ?? '', ENT_QUOTES, 'UTF-8'); }

/* -------------------- Input ------------------- */
if (!isset($_GET['quiz_id']) || !is_numeric($_GET['quiz_id'])) { die('Quiz nuk është specifikuar.'); }
$quiz_id   = (int)$_GET['quiz_id'];
$activeTab = (string)($_GET['tab'] ?? 'details'); // details | people
if (!in_array($activeTab, ['details','people'], true)) $activeTab = 'details';

/* -------------------- Assets base -------------------- */
$ASSET_BASE = '.';
$scriptName = str_replace('\\', '/', (string)($_SERVER['SCRIPT_NAME'] ?? ''));
if (strpos($scriptName, '/quizzes/') !== false) $ASSET_BASE = '..';

/* -------------------- Load quiz --------------- */
try {
  $st = $pdo->prepare("
    SELECT q.*, c.id AS course_id, c.id_creator, c.title AS course_title
    FROM quizzes q
    JOIN courses c ON c.id=q.course_id
    WHERE q.id=?
    LIMIT 1
  ");
  $st->execute([$quiz_id]);
  $quiz = $st->fetch(PDO::FETCH_ASSOC);
  if (!$quiz) die('Quiz nuk u gjet.');
  if ($ROLE === 'Instruktor' && (int)$quiz['id_creator'] !== $ME_ID) die('Nuk keni akses.');

  $course_id   = (int)$quiz['course_id'];
  $courseTitle = (string)$quiz['course_title'];
} catch (PDOException $e) {
  die('Gabim: ' . h($e->getMessage()));
}

/* -------------------- Publish/Unpublish ---------- */
$msg = null; $err = null;
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
  if (!hash_equals($CSRF, (string)($_POST['csrf'] ?? ''))) {
    $err = 'CSRF i pavlefshëm.';
  } else {
    $action = (string)($_POST['action'] ?? '');
    try {
      if ($action === 'publish') {
        // Validim: çdo pyetje ka >=1 alternativë dhe saktësisht 1 e saktë
        $qs = $pdo->prepare("SELECT id FROM quiz_questions WHERE quiz_id=? ORDER BY position,id");
        $qs->execute([$quiz_id]);
        $qids = $qs->fetchAll(PDO::FETCH_COLUMN);

        $reasons = [];
        if (!$qids) {
          $reasons[] = 'Quiz nuk ka asnjë pyetje.';
        } else {
          $stCnt = $pdo->prepare("
            SELECT
              qq.id AS qid,
              (SELECT COUNT(*) FROM quiz_answers qa WHERE qa.question_id = qq.id) AS cnt_all,
              (SELECT COUNT(*) FROM quiz_answers qa WHERE qa.question_id = qq.id AND qa.is_correct=1) AS cnt_ok
            FROM quiz_questions qq
            WHERE qq.quiz_id=?
          ");
          $stCnt->execute([$quiz_id]);
          foreach ($stCnt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $qid = (int)($row['qid'] ?? 0);
            $cntAll = (int)($row['cnt_all'] ?? 0);
            $cntOK  = (int)($row['cnt_ok'] ?? 0);
            if ($cntAll < 1) $reasons[] = "Pyetja #$qid nuk ka asnjë alternativë.";
            if ($cntOK !== 1) $reasons[] = "Pyetja #$qid duhet të ketë saktësisht 1 të saktë.";
          }
        }

        if ($reasons) {
          throw new Exception("S’mund të publikohet: " . implode(' ', $reasons));
        }

        $pdo->prepare("UPDATE quizzes SET status='PUBLISHED', updated_at=NOW() WHERE id=?")->execute([$quiz_id]);
        $msg = 'Quiz u publikua.';
      }

      if ($action === 'unpublish') {
        $pdo->prepare("UPDATE quizzes SET status='DRAFT', updated_at=NOW() WHERE id=?")->execute([$quiz_id]);
        $msg = 'Quiz kaloi në Draft.';
      }

      // refresh
      $st->execute([$quiz_id]);
      $quiz = $st->fetch(PDO::FETCH_ASSOC);

    } catch (Throwable $e) {
      $err = $e->getMessage();
    }
  }
}

/* -------------------- Stats & Health ----------- */
try {
  $qCount = (int)$pdo->prepare("SELECT COUNT(*) FROM quiz_questions WHERE quiz_id=?")
                     ->execute([$quiz_id]) ?: 0;
} catch (Throwable $e) {}

$stQCount = $pdo->prepare("SELECT COUNT(*) FROM quiz_questions WHERE quiz_id=?");
$stQCount->execute([$quiz_id]);
$qCount = (int)$stQCount->fetchColumn();

$stACount = $pdo->prepare("
  SELECT COUNT(*)
  FROM quiz_answers
  WHERE question_id IN (SELECT id FROM quiz_questions WHERE quiz_id=?)
");
$stACount->execute([$quiz_id]);
$aCount = (int)$stACount->fetchColumn();

$stHealth = $pdo->prepare("
  SELECT
    qq.id AS qid,
    (SELECT COUNT(*) FROM quiz_answers qa WHERE qa.question_id=qq.id) AS cnt_all,
    (SELECT COUNT(*) FROM quiz_answers qa WHERE qa.question_id=qq.id AND qa.is_correct=1) AS cnt_ok
  FROM quiz_questions qq
  WHERE qq.quiz_id=?
");
$stHealth->execute([$quiz_id]);
$noAnswerQs = 0; $badCorrectQs = 0;
foreach ($stHealth->fetchAll(PDO::FETCH_ASSOC) as $r) {
  $cntAll = (int)($r['cnt_all'] ?? 0);
  $cntOK  = (int)($r['cnt_ok'] ?? 0);
  if ($cntAll < 1) $noAnswerQs++;
  if ($cntOK !== 1) $badCorrectQs++;
}
$canPublish = ($qCount > 0 && $noAnswerQs === 0 && $badCorrectQs === 0);

/* -------------------- Derivatives -------------- */
$timeMin    = !empty($quiz['time_limit_sec']) ? (int)$quiz['time_limit_sec'] / 60 : 0;
$timeLabel  = $timeMin ? ((int)$timeMin . ' min') : 'Pa limit';

$attempts   = (int)($quiz['attempts_allowed'] ?? 1); // 0 = ∞ (nëse e përdor)
$attemptsUi = $attempts === 0 ? '∞' : (string)$attempts;

$shuffleFlags = [];
if ((int)($quiz['shuffle_questions'] ?? 0)) $shuffleFlags[] = 'Përziej pyetjet';
if ((int)($quiz['shuffle_answers'] ?? 0))  $shuffleFlags[] = 'Përziej përgjigjet';
$shuffleLabel = $shuffleFlags ? implode(' • ', $shuffleFlags) : '—';

$status = (string)($quiz['status'] ?? 'DRAFT');
$statusPillClass = ($status === 'PUBLISHED') ? 'km-pill-success' : (($status === 'ARCHIVED') ? 'km-pill-muted' : 'km-pill-warn');

/* -------------------- People: who did it / not -------------- */
$students = [];
try {
  $stS = $pdo->prepare("
    SELECT u.id, u.full_name, u.email, e.enrolled_at
    FROM enroll e
    JOIN users u ON u.id=e.user_id
    WHERE e.course_id=?
    ORDER BY u.full_name ASC
  ");
  $stS->execute([$course_id]);
  $students = $stS->fetchAll(PDO::FETCH_ASSOC) ?: [];
} catch (PDOException $e) { $students = []; }

$attByUser = [];
$lastByUser = [];

if ($students) {
  try {
    $stA = $pdo->prepare("
      SELECT user_id,
             SUM(CASE WHEN submitted_at IS NULL THEN 1 ELSE 0 END) AS in_progress,
             SUM(CASE WHEN submitted_at IS NOT NULL THEN 1 ELSE 0 END) AS submitted,
             COUNT(*) AS total
      FROM quiz_attempts
      WHERE quiz_id=?
      GROUP BY user_id
    ");
    $stA->execute([$quiz_id]);
    foreach ($stA->fetchAll(PDO::FETCH_ASSOC) as $r) {
      $attByUser[(int)$r['user_id']] = [
        'in_progress' => (int)$r['in_progress'],
        'submitted'   => (int)$r['submitted'],
        'total'       => (int)$r['total'],
      ];
    }

    $stL = $pdo->prepare("
      SELECT a.user_id, a.id, a.score, a.total_points, a.submitted_at
      FROM quiz_attempts a
      JOIN (
        SELECT user_id, MAX(submitted_at) AS max_sub
        FROM quiz_attempts
        WHERE quiz_id=? AND submitted_at IS NOT NULL
        GROUP BY user_id
      ) m ON m.user_id=a.user_id AND a.submitted_at=m.max_sub
      WHERE a.quiz_id=?
    ");
    $stL->execute([$quiz_id, $quiz_id]);
    foreach ($stL->fetchAll(PDO::FETCH_ASSOC) as $r) {
      $lastByUser[(int)$r['user_id']] = [
        'id'           => (int)$r['id'],
        'score'        => isset($r['score']) ? (int)$r['score'] : null,
        'total_points' => isset($r['total_points']) ? (int)$r['total_points'] : null,
        'submitted_at' => (string)$r['submitted_at'],
      ];
    }
  } catch (PDOException $e) {}
}

$doneCnt=0; $inProgCnt=0; $notStartedCnt=0;
foreach ($students as $s) {
  $uid  = (int)$s['id'];
  $meta = $attByUser[$uid] ?? ['in_progress'=>0,'submitted'=>0,'total'=>0];
  if (($meta['submitted'] ?? 0) > 0)        $doneCnt++;
  elseif (($meta['in_progress'] ?? 0) > 0)  $inProgCnt++;
  else                                      $notStartedCnt++;
}
$totalStudents = count($students);

/* -------------------- Export CSV ----------------- */
if (isset($_GET['export']) && $_GET['export'] === 'csv') {
  $statusFilter = (string)($_GET['status'] ?? ''); // '', done, in_progress, not_started
  header('Content-Type: text/csv; charset=utf-8');
  header('Content-Disposition: attachment; filename="quiz_'.$quiz_id.'_participants.csv"');

  $out = fopen('php://output', 'w');
  fputcsv($out, ['user_id','full_name','email','status','submitted','in_progress','last_score','last_total','last_percent','last_submitted_at']);

  foreach ($students as $s) {
    $uid  = (int)$s['id'];
    $meta = $attByUser[$uid] ?? ['in_progress'=>0,'submitted'=>0,'total'=>0];
    $last = $lastByUser[$uid] ?? null;

    $st = ($meta['submitted'] ?? 0) > 0 ? 'done' : (($meta['in_progress'] ?? 0) > 0 ? 'in_progress' : 'not_started');
    if ($statusFilter && $st !== $statusFilter) continue;

    $sc  = $last['score'] ?? null;
    $tp  = $last['total_points'] ?? null;
    $pct = ($sc !== null && $tp) ? round(($sc / $tp) * 100) : null;

    fputcsv($out, [
      $uid,
      (string)($s['full_name'] ?? ''),
      (string)($s['email'] ?? ''),
      $st,
      (int)($meta['submitted'] ?? 0),
      (int)($meta['in_progress'] ?? 0),
      $sc,
      $tp,
      $pct !== null ? $pct.'%' : '',
      $last['submitted_at'] ?? ''
    ]);
  }
  fclose($out);
  exit;
}

/* -------------------- URLs ----------------- */
$backToCourseHref = $BASE_URL . '/course_details.php?course_id='.(int)$course_id.'&tab=materials';
$editHref         = $BASE_URL . '/admin/quiz_builder.php?quiz_id='.(int)$quiz_id;
$peopleHref       = $BASE_URL . '/quizzes/quiz_details.php?quiz_id='.(int)$quiz_id.'&tab=people';
$detailsHref      = $BASE_URL . '/quizzes/quiz_details.php?quiz_id='.(int)$quiz_id.'&tab=details';
$deleteHref       = $BASE_URL . '/admin/delete_quiz.php?quiz_id='.(int)$quiz_id.'&csrf='.urlencode($CSRF);
?>
<!DOCTYPE html>
<html lang="sq" data-bs-theme="light">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <title>Quiz — <?= h((string)$quiz['title']) ?></title>

  <!-- Vendor -->
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
  <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css" rel="stylesheet">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">

  <link rel="icon" href="<?= h($ASSET_BASE) ?>/image/favicon.ico" type="image/x-icon" />

  <!-- Base theme -->
  <link rel="stylesheet" href="<?= h($ASSET_BASE) ?>/css/km-lms-forms.css">
  <!-- Page specific -->
  <link rel="stylesheet" href="<?= h($ASSET_BASE) ?>/css/km_quiz_details.css">
</head>

<body class="km-body">

<?php
  // Navbar (fallbacks)
  if ($ROLE === 'Administrator') {
    $p = $ROOT . '/navbar_logged_administrator.php';
    if (is_file($p)) include $p;
  } else {
    $p1 = $ROOT . '/navbar_logged_instructor.php';
    $p2 = $ROOT . '/navbar_logged_instruktor.php';
    if (is_file($p1)) include $p1;
    elseif (is_file($p2)) include $p2;
  }
?>

<div class="container km-page-shell">

  <!-- Header / Hero -->
  <div class="km-page-header km-quizd-header">
    <div class="d-flex align-items-start align-items-lg-center justify-content-between gap-3 flex-wrap">
      <div class="flex-grow-1">
        <div class="km-breadcrumb small">
          <a class="km-breadcrumb-link" href="<?= h($backToCourseHref) ?>">
            <?= h($courseTitle) ?>
          </a>
          <span class="mx-1">/</span>
          <span class="km-breadcrumb-current">Quiz</span>
        </div>

        <h1 class="km-page-title">
          <i class="fa-regular fa-circle-question me-2 text-primary"></i>
          <?= h((string)$quiz['title']) ?>
        </h1>

        <div class="km-page-subtitle d-flex flex-wrap gap-2">
          <span class="km-pill-meta <?= h($statusPillClass) ?>">
            <i class="fa-solid fa-flag"></i>
            Status: <strong><?= h($status) ?></strong>
          </span>

          <span class="km-pill-meta">
            <i class="fa-regular fa-hourglass-half"></i>
            <?= h($timeLabel) ?>
          </span>

          <span class="km-pill-meta">
            <i class="fa-solid fa-repeat"></i>
            Tentativa: <strong><?= h($attemptsUi) ?></strong>
          </span>

          <?php if (!empty($quiz['open_at'])): ?>
            <span class="km-pill-meta">
              <i class="fa-regular fa-clock"></i>
              Hapet: <?= h(date('d.m.Y H:i', strtotime((string)$quiz['open_at']))) ?>
            </span>
          <?php endif; ?>

          <?php if (!empty($quiz['close_at'])): ?>
            <span class="km-pill-meta">
              <i class="fa-regular fa-clock"></i>
              Mbyllet: <?= h(date('d.m.Y H:i', strtotime((string)$quiz['close_at']))) ?>
            </span>
          <?php endif; ?>
        </div>
      </div>

      <div class="d-flex align-items-center gap-2 flex-wrap">
        <a class="btn btn-outline-secondary km-btn-pill" href="<?= h($backToCourseHref) ?>">
          <i class="fa-solid fa-arrow-left-long me-1"></i> Kthehu te kursi
        </a>
        <a class="btn btn-outline-primary km-btn-pill" href="<?= h($editHref) ?>">
          <i class="fa-solid fa-pen-to-square me-1"></i> Builder
        </a>
      </div>
    </div>

    <!-- Tabs -->
    <div class="km-quizd-tabs mt-3" role="tablist" aria-label="Quiz tabs">
      <a class="km-quizd-tab <?= $activeTab==='details'?'is-active':'' ?>" href="<?= h($detailsHref) ?>">
        <i class="fa-regular fa-rectangle-list me-1"></i> Detaje
      </a>
      <a class="km-quizd-tab <?= $activeTab==='people'?'is-active':'' ?>" href="<?= h($peopleHref) ?>">
        <i class="fa-regular fa-user me-1"></i> Pjesëmarrësit
      </a>
    </div>
  </div>

  <?php if ($err): ?>
    <div class="mt-3 km-alert km-alert-danger">
      <div class="d-flex gap-2 align-items-start">
        <i class="fa-solid fa-triangle-exclamation mt-1"></i>
        <div><strong>Gabim:</strong> <?= h($err) ?></div>
      </div>
    </div>
  <?php endif; ?>

  <?php if ($msg): ?>
    <div class="mt-3 km-alert km-alert-success">
      <div class="d-flex gap-2 align-items-start">
        <i class="fa-regular fa-circle-check mt-1"></i>
        <div><?= h($msg) ?></div>
      </div>
    </div>
  <?php endif; ?>

  <?php if ($activeTab === 'people'): ?>
    <!-- ================= PEOPLE ================= -->
    <div class="row g-3 mt-2">

      <!-- MAIN -->
      <div class="col-12 col-lg-8">

        <div class="km-card km-card-main">
          <div class="km-card-header">
            <div>
              <div class="km-card-title">
                <i class="fa-regular fa-user me-2 text-primary"></i>
                Pjesëmarrësit
              </div>
              <div class="km-card-subtitle">
                Filtra të shpejtë + kërkim live. Export CSV sipas statusit.
              </div>
            </div>

            <div class="km-quizd-toolbar">
              <div class="km-quizd-search">
                <i class="fa-solid fa-magnifying-glass"></i>
                <input id="searchBox" class="form-control" placeholder="Kërko emër ose email…">
              </div>

              <div class="btn-group" role="group" aria-label="Filters">
                <button class="btn btn-outline-secondary km-btn-pill-sm btn-filter is-active" data-filter="all">Të gjithë</button>
                <button class="btn btn-outline-success   km-btn-pill-sm btn-filter" data-filter="done">Bërë</button>
                <button class="btn btn-outline-warning   km-btn-pill-sm btn-filter" data-filter="in_progress">Në progres</button>
                <button class="btn btn-outline-danger    km-btn-pill-sm btn-filter" data-filter="not_started">Pa nisur</button>
              </div>

              <div class="dropdown">
                <button class="btn btn-outline-dark km-btn-pill-sm dropdown-toggle" data-bs-toggle="dropdown">
                  <i class="fa-solid fa-file-export me-1"></i> Export
                </button>
                <ul class="dropdown-menu dropdown-menu-end">
                  <li><a class="dropdown-item" href="<?= h($peopleHref) ?>&export=csv">CSV — Të gjithë</a></li>
                  <li><a class="dropdown-item" href="<?= h($peopleHref) ?>&export=csv&status=done">CSV — Bërë</a></li>
                  <li><a class="dropdown-item" href="<?= h($peopleHref) ?>&export=csv&status=in_progress">CSV — Në progres</a></li>
                  <li><a class="dropdown-item" href="<?= h($peopleHref) ?>&export=csv&status=not_started">CSV — Pa nisur</a></li>
                </ul>
              </div>
            </div>
          </div>

          <div class="km-card-body">
            <div class="table-responsive km-quizd-tablewrap">
              <table class="table table-hover align-middle" id="peopleTable">
                <thead>
                  <tr>
                    <th>Studenti</th>
                    <th>Email</th>
                    <th>Statusi</th>
                    <th class="text-center">Tentativa</th>
                    <th>Rezultati i fundit</th>
                    <th>Data</th>
                    <th class="text-end"></th>
                  </tr>
                </thead>
                <tbody>
                <?php if (!$students): ?>
                  <tr><td colspan="7" class="text-center text-muted py-4">S’ka pjesëmarrës në këtë kurs.</td></tr>
                <?php else: ?>
                  <?php foreach ($students as $s):
                    $uid  = (int)$s['id'];
                    $meta = $attByUser[$uid] ?? ['in_progress'=>0,'submitted'=>0,'total'=>0];
                    $last = $lastByUser[$uid] ?? null;

                    $st = ($meta['submitted'] ?? 0) > 0 ? 'done' : (($meta['in_progress'] ?? 0) > 0 ? 'in_progress' : 'not_started');

                    $badge = $st === 'done'
                      ? '<span class="km-quizd-dot is-done"></span><span class="km-quizd-st">Bërë</span>'
                      : ($st === 'in_progress'
                        ? '<span class="km-quizd-dot is-prog"></span><span class="km-quizd-st">Në progres</span>'
                        : '<span class="km-quizd-dot is-none"></span><span class="km-quizd-st">Pa nisur</span>');

                    $sc  = $last['score'] ?? null;
                    $tp  = $last['total_points'] ?? null;
                    $pct = ($sc !== null && $tp) ? round(($sc / $tp) * 100) : null;

                    $lastTxt = ($sc !== null && $tp !== null) ? ($sc . ' / ' . $tp . ($pct !== null ? ' (' . $pct . '%)' : '')) : '—';
                    $dateTxt = !empty($last['submitted_at']) ? date('d.m.Y H:i', strtotime((string)$last['submitted_at'])) : '—';
                    $attemptsTxt = (int)($meta['submitted'] ?? 0) . ' / ' . (int)($meta['total'] ?? 0);

                    $name = (string)($s['full_name'] ?? '');
                    $email = (string)($s['email'] ?? '');
                  ?>
                    <tr data-status="<?= h($st) ?>" data-search="<?= h(mb_strtolower($name.' '.$email)) ?>">
                      <td><strong><?= h($name) ?></strong></td>
                      <td class="text-muted"><?= h($email) ?></td>
                      <td><?= $badge ?></td>
                      <td class="text-center"><?= h($attemptsTxt) ?></td>
                      <td><?= h($lastTxt) ?></td>
                      <td class="text-muted"><?= h($dateTxt) ?></td>
                      <td class="text-end">
                        <?php if ($last && !empty($last['id'])): ?>
                          <a class="btn btn-sm btn-outline-secondary km-btn-icon"
                             title="Shiko rezultatin e fundit"
                             href="<?= h($BASE_URL) ?>/quizzes/quiz_attempt_view.php?attempt_id=<?= (int)$last['id'] ?>">
                            <i class="fa-solid fa-chart-line"></i>
                          </a>
                        <?php else: ?>
                          <button class="btn btn-sm btn-outline-secondary km-btn-icon" disabled title="S’ka rezultat">
                            <i class="fa-solid fa-chart-line"></i>
                          </button>
                        <?php endif; ?>
                      </td>
                    </tr>
                  <?php endforeach; ?>
                <?php endif; ?>
                </tbody>
              </table>
            </div>

            <div class="km-help-text mt-2">
              Shënim: “Tentativa” = dorëzime të përfunduara / tentativa gjithsej (përfshi edhe në progres).
            </div>
          </div>
        </div>

        <!-- Actionbar (sticky) -->
        <div class="km-quizd-actionbar mt-3">
          <div class="km-quizd-actionbar-left">
            <span class="km-pill-meta"><i class="fa-solid fa-users"></i> Totali: <strong><?= (int)$totalStudents ?></strong></span>
            <span class="km-pill-meta"><i class="fa-solid fa-circle-check"></i> Bërë: <strong><?= (int)$doneCnt ?></strong></span>
            <span class="km-pill-meta"><i class="fa-solid fa-clock"></i> Në progres: <strong><?= (int)$inProgCnt ?></strong></span>
            <span class="km-pill-meta"><i class="fa-solid fa-circle-xmark"></i> Pa nisur: <strong><?= (int)$notStartedCnt ?></strong></span>
          </div>
          <div class="km-quizd-actionbar-right">
            <a class="btn btn-outline-primary km-btn-pill" href="<?= h($editHref) ?>">
              <i class="fa-solid fa-pen-to-square me-1"></i> Hap Builder
            </a>
            <a class="btn btn-outline-secondary km-btn-pill" href="<?= h($detailsHref) ?>">
              <i class="fa-regular fa-rectangle-list me-1"></i> Detajet
            </a>
          </div>
        </div>

      </div>

      <!-- SIDE -->
      <div class="col-12 col-lg-4">
        <div class="km-card km-card-side km-sticky-side">
          <div class="km-card-header">
            <div>
              <div class="km-card-title"><span class="km-step-badge">1</span> KPI</div>
              <div class="km-card-subtitle">Status i shpejtë i pjesëmarrësve.</div>
            </div>
            <span class="km-pill-meta"><i class="fa-regular fa-chart-bar"></i> Live</span>
          </div>
          <div class="km-card-body">
            <div class="km-quizd-kpis">
              <div class="km-quizd-kpi">
                <div class="km-quizd-kpi-label">Të regjistruar</div>
                <div class="km-quizd-kpi-val"><?= (int)$totalStudents ?></div>
              </div>
              <div class="km-quizd-kpi">
                <div class="km-quizd-kpi-label">Bërë</div>
                <div class="km-quizd-kpi-val"><?= (int)$doneCnt ?></div>
              </div>
              <div class="km-quizd-kpi">
                <div class="km-quizd-kpi-label">Në progres</div>
                <div class="km-quizd-kpi-val"><?= (int)$inProgCnt ?></div>
              </div>
              <div class="km-quizd-kpi">
                <div class="km-quizd-kpi-label">Pa nisur</div>
                <div class="km-quizd-kpi-val"><?= (int)$notStartedCnt ?></div>
              </div>
            </div>

            <div class="km-quiz-divider my-3"></div>

            <div class="km-side-title mb-2">
              <i class="fa-regular fa-lightbulb me-2 text-primary"></i> Këshilla
            </div>
            <ul class="km-checklist">
              <li><i class="fa-solid fa-check"></i> Përdor filtrin “Pa nisur” për follow-up.</li>
              <li><i class="fa-solid fa-check"></i> Export CSV për raportim.</li>
              <li><i class="fa-solid fa-check"></i> “Tentativa” përfshin edhe në progres.</li>
            </ul>
          </div>
        </div>
      </div>

    </div>

  <?php else: ?>
    <!-- ================= DETAILS ================= -->
    <form method="POST" class="row g-3 mt-2">
      <input type="hidden" name="csrf" value="<?= h($CSRF) ?>">

      <!-- MAIN -->
      <div class="col-12 col-lg-8">

        <div class="km-card km-card-main">
          <div class="km-card-header">
            <div>
              <div class="km-card-title">
                <i class="fa-regular fa-rectangle-list me-2 text-primary"></i>
                Detaje & konfigurim
              </div>
              <div class="km-card-subtitle">
                Kontrollo metrikat, opsionet, përshkrimin dhe veprimet (Publish/Draft).
              </div>
            </div>
          </div>

          <div class="km-card-body">

            <div class="row g-2">
              <div class="col-6 col-md-3">
                <div class="km-quizd-metric">
                  <div class="km-quizd-metric-label">Pyetje</div>
                  <div class="km-quizd-metric-val"><?= (int)$qCount ?></div>
                </div>
              </div>
              <div class="col-6 col-md-3">
                <div class="km-quizd-metric">
                  <div class="km-quizd-metric-label">Alternativa</div>
                  <div class="km-quizd-metric-val"><?= (int)$aCount ?></div>
                </div>
              </div>
              <div class="col-6 col-md-3">
                <div class="km-quizd-metric">
                  <div class="km-quizd-metric-label">Kohëzgjatja</div>
                  <div class="km-quizd-metric-val"><?= h($timeLabel) ?></div>
                </div>
              </div>
              <div class="col-6 col-md-3">
                <div class="km-quizd-metric">
                  <div class="km-quizd-metric-label">Tentativa</div>
                  <div class="km-quizd-metric-val"><?= h($attemptsUi) ?></div>
                </div>
              </div>
            </div>

            <div class="km-quiz-divider my-3"></div>

            <div class="d-flex gap-2 flex-wrap">
              <span class="km-pill-meta"><i class="fa-solid fa-shuffle"></i> <?= h($shuffleLabel) ?></span>
              <?php if (!empty($quiz['open_at'])): ?>
                <span class="km-pill-meta"><i class="fa-regular fa-clock"></i> Hapet: <?= h(date('d.m.Y H:i', strtotime((string)$quiz['open_at']))) ?></span>
              <?php endif; ?>
              <?php if (!empty($quiz['close_at'])): ?>
                <span class="km-pill-meta"><i class="fa-regular fa-clock"></i> Mbyllet: <?= h(date('d.m.Y H:i', strtotime((string)$quiz['close_at']))) ?></span>
              <?php endif; ?>
            </div>

            <div class="km-quiz-divider my-3"></div>

            <div class="km-quizd-block">
              <div class="km-quizd-block-title">Përshkrimi</div>
              <?php if (!empty($quiz['description'])): ?>
                <div class="km-quizd-desc"><?= nl2br(h((string)$quiz['description'])) ?></div>
              <?php else: ?>
                <div class="km-quizd-empty">
                  <i class="fa-regular fa-note-sticky mb-2"></i>
                  <div>S’ka përshkrim për këtë quiz.</div>
                </div>
              <?php endif; ?>
            </div>

          </div>
        </div>

        <!-- Actionbar (sticky) -->
        <div class="km-quizd-actionbar mt-3">
          <div class="km-quizd-actionbar-left">
            <span class="km-pill-meta <?= h($statusPillClass) ?>"><i class="fa-solid fa-flag"></i> <?= h($status) ?></span>
            <span class="km-pill-meta"><i class="fa-solid fa-list-check"></i> Health: <strong><?= $canPublish ? 'OK' : 'Problem' ?></strong></span>
          </div>

          <div class="km-quizd-actionbar-right">
            <?php if ($status === 'PUBLISHED'): ?>
              <button class="btn btn-outline-secondary km-btn-pill" type="submit" name="action" value="unpublish">
                <i class="fa-regular fa-eye-slash me-1"></i> Kalo në Draft
              </button>
            <?php else: ?>
              <button class="btn btn-success km-btn-pill" type="submit" name="action" value="publish"
                      onclick="return <?= json_encode($canPublish) ?> ? true : confirm('Disa kontrolle dështuan. Gjithsesi të publikohet?');">
                <i class="fa-solid fa-upload me-1"></i> Publiko
              </button>
            <?php endif; ?>

            <a class="btn btn-outline-danger km-btn-pill" href="<?= h($deleteHref) ?>"
               onclick="return confirm('Fshi quiz-in?');">
              <i class="fa-regular fa-trash-can me-1"></i> Fshi
            </a>

            <a class="btn btn-outline-primary km-btn-pill" href="<?= h($editHref) ?>">
              <i class="fa-solid fa-pen-to-square me-1"></i> Builder
            </a>
          </div>
        </div>

      </div>

      <!-- SIDE -->
      <div class="col-12 col-lg-4">

        <div class="km-card km-card-side km-sticky-side">
          <div class="km-card-header">
            <div>
              <div class="km-card-title"><span class="km-step-badge">1</span> Quiz Health</div>
              <div class="km-card-subtitle">Kontrolle minimale për publikim.</div>
            </div>
            <span class="km-pill-meta"><i class="fa-regular fa-shield-halved"></i> Guard</span>
          </div>

          <div class="km-card-body">

            <div class="km-quizd-health">
              <div class="km-quizd-health-row">
                <span>Pyetje gjithsej</span>
                <span class="km-quizd-badge"><?= (int)$qCount ?></span>
              </div>
              <div class="km-quizd-health-row">
                <span>Pa alternativa</span>
                <span class="km-quizd-badge <?= $noAnswerQs ? 'is-bad' : 'is-ok' ?>"><?= (int)$noAnswerQs ?></span>
              </div>
              <div class="km-quizd-health-row">
                <span>Pa 1 të saktë</span>
                <span class="km-quizd-badge <?= $badCorrectQs ? 'is-warn' : 'is-ok' ?>"><?= (int)$badCorrectQs ?></span>
              </div>
            </div>

            <div class="km-quizd-health-state <?= $canPublish ? 'is-ok' : 'is-bad' ?>">
              <i class="fa-regular fa-circle-<?= $canPublish ? 'check' : 'xmark' ?> me-1"></i>
              <?= $canPublish ? 'Gati për publikim.' : 'Duhet të rregullohen pikat e mësipërme.' ?>
            </div>

            <div class="km-quiz-divider my-3"></div>

            <div class="km-side-title mb-2">
              <i class="fa-regular fa-lightbulb me-2 text-primary"></i> Shënime
            </div>
            <ul class="km-checklist">
              <li><i class="fa-solid fa-check"></i> Publikimi bllokohet kur pyetjet s’kanë 1 të saktë.</li>
              <li><i class="fa-solid fa-check"></i> Për “pa limit”, kohëzgjatjen lëre bosh / 0 në konfigurim.</li>
              <li><i class="fa-solid fa-check"></i> Përshkrimi duhet të ketë udhëzime të qarta për studentët.</li>
            </ul>

          </div>
        </div>

      </div>

    </form>
  <?php endif; ?>

  <div class="mt-3"></div>
</div>

<?php
  // Footer fallback
  $f2 = $ROOT . '/footer2.php';
  $f1 = $ROOT . '/footer.php';
  if (is_file($f2)) include $f2;
  elseif (is_file($f1)) include $f1;
?>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>

<?php if ($activeTab === 'people'): ?>
<script>
(function(){
  const searchBox = document.getElementById('searchBox');
  const table = document.getElementById('peopleTable');
  const rows = Array.from(table.querySelectorAll('tbody tr'));
  let currentFilter = 'all';

  function apply(){
    const q = (searchBox.value || '').toLowerCase().trim();
    rows.forEach(tr => {
      const st = tr.getAttribute('data-status') || '';
      const hay = tr.getAttribute('data-search') || tr.innerText.toLowerCase();
      const okFilter = (currentFilter === 'all') || (st === currentFilter);
      const okSearch = !q || hay.includes(q);
      tr.style.display = (okFilter && okSearch) ? '' : 'none';
    });
  }

  document.querySelectorAll('.btn-filter').forEach(btn => {
    btn.addEventListener('click', (e) => {
      e.preventDefault();
      document.querySelectorAll('.btn-filter').forEach(b => b.classList.remove('is-active'));
      btn.classList.add('is-active');
      currentFilter = btn.getAttribute('data-filter') || 'all';
      apply();
    });
  });

  searchBox.addEventListener('input', apply);
  apply();
})();
</script>
<?php endif; ?>

</body>
</html>
