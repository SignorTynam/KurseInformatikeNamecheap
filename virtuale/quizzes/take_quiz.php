<?php
// take_quiz_v4.php — NEW UI/UX (QTA-like light) + Single Question Mode + Review Panel
// © kurseinformatike.com
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

require_once $ROOT . '/lib/database.php';

function h(?string $s): string { return htmlspecialchars($s ?? '', ENT_QUOTES, 'UTF-8'); }

/* ---------- RBAC ---------- */
if (!isset($_SESSION['user']) || (($_SESSION['user']['role'] ?? '') !== 'Student')) {
  header('Location: ' . $BASE_URL . '/login.php'); exit;
}
$ME_ID = (int)($_SESSION['user']['id'] ?? 0);

/* ---------- CSRF ---------- */
if (empty($_SESSION['csrf_token'])) { $_SESSION['csrf_token'] = bin2hex(random_bytes(32)); }
$CSRF = (string)$_SESSION['csrf_token'];

/* ---------- Input ---------- */
if (!isset($_GET['quiz_id']) || !is_numeric($_GET['quiz_id'])) die('Kuizi nuk u specifikua.');
$quiz_id = (int)$_GET['quiz_id'];

/* ---------- Helper ---------- */
function hard_deadline_ts(?int $startTs, ?int $timeLimitSec, ?string $closeAt): ?int {
  $byLimit = ($startTs && $timeLimitSec && $timeLimitSec > 0) ? ($startTs + (int)$timeLimitSec) : null;
  $byClose = (!empty($closeAt) ? strtotime($closeAt) : null);
  if ($byLimit && $byClose) return min($byLimit, $byClose);
  if ($byLimit) return $byLimit;
  if ($byClose) return $byClose;
  return null;
}

try {
  /* ---------- Lexo quiz + kurs ---------- */
  $Q = $pdo->prepare("
    SELECT q.*, c.title AS course_title, c.id AS course_id
    FROM quizzes q
    JOIN courses c ON c.id = q.course_id
    WHERE q.id = ?
    LIMIT 1
  ");
  $Q->execute([$quiz_id]);
  $quiz = $Q->fetch(PDO::FETCH_ASSOC);
  if (!$quiz) die('Kuizi nuk u gjet.');

  /* ---------- Kontrollo enrollment ---------- */
  $chk = $pdo->prepare("SELECT 1 FROM enroll WHERE course_id=? AND user_id=? LIMIT 1");
  $chk->execute([(int)$quiz['course_id'], $ME_ID]);
  if (!$chk->fetchColumn()) die('Nuk jeni i regjistruar në këtë kurs.');

  /* ---------- Statusi kohor ---------- */
  $now     = time();
  $openAt  = !empty($quiz['open_at'])  ? strtotime((string)$quiz['open_at'])  : null;
  $closeAt = !empty($quiz['close_at']) ? strtotime((string)$quiz['close_at']) : null;
  $isOpen      = (!$openAt || $now >= $openAt) && (!$closeAt || $now <= $closeAt);
  $isClosed    = ($closeAt && $now > $closeAt);
  $isUpcoming  = ($openAt  && $now < $openAt);

  /* ---------- Attempts ---------- */
  $A = $pdo->prepare("SELECT * FROM quiz_attempts WHERE quiz_id=? AND user_id=? ORDER BY id DESC");
  $A->execute([$quiz_id, $ME_ID]);
  $attempts = $A->fetchAll(PDO::FETCH_ASSOC);

  $inProgress = null;
  foreach ($attempts as $a) { if (empty($a['submitted_at'])) { $inProgress = $a; break; } }
  $submittedCount = 0; foreach ($attempts as $a) if (!empty($a['submitted_at'])) $submittedCount++;

  $allowed   = (int)($quiz['attempts_allowed'] ?? 1);
  $unlimited = ($allowed <= 0);
  $canStart  = $isOpen && !$inProgress && ($unlimited || $submittedCount < $allowed);

  if (!$inProgress && $canStart) {
    $C = $pdo->prepare("INSERT INTO quiz_attempts (quiz_id, user_id, started_at) VALUES (?,?,NOW())");
    $C->execute([$quiz_id, $ME_ID]);
    $aid = (int)$pdo->lastInsertId();
    $A = $pdo->prepare("SELECT * FROM quiz_attempts WHERE id=?");
    $A->execute([$aid]);
    $inProgress = $A->fetch(PDO::FETCH_ASSOC);
  }

  if (!$inProgress && !$canStart) {
    $L = $pdo->prepare("SELECT * FROM quiz_attempts WHERE quiz_id=? AND user_id=? AND submitted_at IS NOT NULL ORDER BY submitted_at DESC, id DESC LIMIT 1");
    $L->execute([$quiz_id, $ME_ID]);
    $last = $L->fetch(PDO::FETCH_ASSOC);
    if ($last) { header('Location: ' . $BASE_URL . '/quizzes/quiz_attempt_view.php?attempt_id='.(int)$last['id']); exit; }
  }

  /* ---------- Pyetjet & alternativat ---------- */
  $Qq = $pdo->prepare("SELECT * FROM quiz_questions WHERE quiz_id=? ORDER BY position ASC, id ASC");
  $Qq->execute([$quiz_id]);
  $questions = $Qq->fetchAll(PDO::FETCH_ASSOC);

  $answersByQ = [];
  if ($questions) {
    $ids = array_map(fn($r)=>(int)$r['id'], $questions);
    $in  = implode(',', array_fill(0, count($ids), '?'));
    $Qa  = $pdo->prepare("SELECT * FROM quiz_answers WHERE question_id IN ($in) ORDER BY position ASC, id ASC");
    $Qa->execute($ids);
    foreach ($Qa->fetchAll(PDO::FETCH_ASSOC) as $row) {
      $answersByQ[(int)$row['question_id']][] = $row;
    }
  }

  /* ---------- Start time nga DB ---------- */
  $startTs = null;
  if ($inProgress) {
    $ts = $pdo->prepare("SELECT UNIX_TIMESTAMP(started_at) FROM quiz_attempts WHERE id=?");
    $ts->execute([(int)$inProgress['id']]);
    $startTs = (int)$ts->fetchColumn();
  }

  $timeLimitSec   = isset($quiz['time_limit_sec']) ? (int)$quiz['time_limit_sec'] : 0;
  $hasTimeLimit   = $timeLimitSec > 0;

  $timerDeadlineTs = ($inProgress && $hasTimeLimit && $startTs)
    ? ($startTs + $timeLimitSec)
    : 0;

  $hardDeadlineTs = hard_deadline_ts($startTs, $timeLimitSec ?: null, $quiz['close_at'] ?? null);

  /* ---------- SUBMIT (POST) ---------- */
  if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && (isset($_POST['submit_quiz']) || isset($_POST['attempt_id']))) {
    if (!hash_equals($CSRF, (string)($_POST['csrf'] ?? ''))) throw new RuntimeException('CSRF i pavlefshëm.');
    $attempt_id = (int)($_POST['attempt_id'] ?? 0);
    if ($attempt_id <= 0) throw new RuntimeException('Tentativa mungon.');

    $AR = $pdo->prepare("SELECT * FROM quiz_attempts WHERE id=? AND quiz_id=? AND user_id=? LIMIT 1");
    $AR->execute([$attempt_id, $quiz_id, $ME_ID]);
    $attempt = $AR->fetch(PDO::FETCH_ASSOC);
    if (!$attempt) throw new RuntimeException('Tentativa nuk u gjet.');
    if (!empty($attempt['submitted_at'])) throw new RuntimeException('Tentativa tashmë e dorëzuar.');

    $rawAnswers = [];
    foreach ($questions as $q) {
      $qid = (int)$q['id'];
      $key = 'q_' . $qid;
      $val = $_POST[$key] ?? null;
      if ($val !== null && $val !== '') {
        $aid = (int)$val;
        $ok = false;
        foreach ($answersByQ[$qid] ?? [] as $A) { if ((int)$A['id'] === $aid) { $ok = true; break; } }
        if ($ok) $rawAnswers[$qid] = $aid;
      }
    }

    // Llogarit pikët
    $score = 0; $totalPoints = 0;
    foreach ($questions as $q) {
      $qid = (int)$q['id'];
      $qPts = (int)($q['points'] ?? 1); if ($qPts <= 0) $qPts = 1;
      $totalPoints += $qPts;
      $correct = null;
      foreach ($answersByQ[$qid] ?? [] as $A) if ((int)$A['is_correct'] === 1) { $correct = (int)$A['id']; break; }
      if ($correct !== null && isset($rawAnswers[$qid]) && (int)$rawAnswers[$qid] === $correct) $score += $qPts;
    }

    $answersJson = json_encode(['v'=>1, 'answers'=>$rawAnswers, 'ua'=>($_SERVER['HTTP_USER_AGENT'] ?? '')], JSON_UNESCAPED_UNICODE);

    $UP = $pdo->prepare("
      UPDATE quiz_attempts
      SET submitted_at = NOW(), score = ?, total_points = ?, answers_json = ?
      WHERE id = ? AND user_id = ? AND submitted_at IS NULL
    ");
    $UP->execute([$score, $totalPoints, $answersJson, $attempt_id, $ME_ID]);

    $_SESSION['clear_ls'] = ['quiz_id' => (int)$quiz_id, 'attempt_id' => (int)$attempt_id];

    header('Location: ' . $BASE_URL . '/quizzes/quiz_attempt_view.php?attempt_id=' . $attempt_id);
    exit;
  }

  /* ---------- Prefill nga attempt ---------- */
  $prefill = [];
  if ($inProgress && !empty($inProgress['answers_json'])) {
    $dec = json_decode((string)$inProgress['answers_json'], true);
    if (is_array($dec) && isset($dec['answers']) && is_array($dec['answers'])) $prefill = $dec['answers'];
  }

  /* ---------- Shuffling (vizual) ---------- */
  $renderQuestions = $questions;
  if ((int)($quiz['shuffle_questions'] ?? 0) === 1) shuffle($renderQuestions);
  $renderAnswersByQ = [];
  foreach ($renderQuestions as $q) {
    $qid = (int)$q['id'];
    $opts = $answersByQ[$qid] ?? [];
    if ((int)($quiz['shuffle_answers'] ?? 0) === 1) shuffle($opts);
    $renderAnswersByQ[$qid] = $opts;
  }

} catch (Throwable $e) {
  die('Gabim: ' . h($e->getMessage()));
}
?>
<!DOCTYPE html>
<html lang="sq" data-bs-theme="light">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title><?= h((string)($quiz['title'] ?? 'Kuiz')) ?> — Kuiz | kurseinformatike.com</title>

    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
  <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css" rel="stylesheet">

  <!-- Vendor CSS -->
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">
  <link rel="icon" href="<?= h($BASE_URL) ?>/image/favicon.ico" type="image/x-icon" />

  <style>
    /* ==========================================================
      Take Quiz v4 — Scoped UI (QTA-like light)
      Everything under .tq-page to avoid collisions
    ========================================================== */
    .tq-page{
      --tq-bg:#f6f8fc;
      --tq-surface:#ffffff;
      --tq-surface-2:#f1f5f9;
      --tq-text:#0f172a;
      --tq-muted:#64748b;
      --tq-muted-2:#94a3b8;
      --tq-border:#e5e7eb;
      --tq-primary:#4f46e5;
      --tq-primary-2:#1d4ed8;
      --tq-success:#16a34a;
      --tq-warning:#f59e0b;
      --tq-danger:#dc2626;
      --tq-radius:16px;
      --tq-shadow:0 10px 28px rgba(0,0,0,.08);
      --tq-shadow-sm:0 6px 18px rgba(0,0,0,.06);
      background:var(--tq-bg);
      color:var(--tq-text);
      min-height:100vh;
      font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, "Helvetica Neue", Arial, "Noto Sans", "Liberation Sans", sans-serif;
    }
    .tq-wrap{ padding: 14px 0 80px; } /* leave space for mobile bar */

    /* Header (sticky, clean) */
    .tq-header{
      position: sticky;
      top: 56px; /* matches your navbar height */
      z-index: 1020;
      background: rgba(246,248,252,.78);
      backdrop-filter: blur(10px) saturate(170%);
      border-bottom: 1px solid var(--tq-border);
    }
    .tq-header .inner{
      padding: 12px 0 10px;
      display:flex;
      flex-wrap:wrap;
      align-items:center;
      justify-content:space-between;
      gap:10px;
    }
    .tq-title{
      font-weight: 800;
      letter-spacing: .2px;
      margin: 0;
      line-height: 1.15;
      font-size: 1.05rem;
    }
    .tq-sub{
      color: var(--tq-muted);
      font-size: .92rem;
      margin: 2px 0 0;
    }
    .tq-pill{
      display:inline-flex;
      align-items:center;
      gap:.45rem;
      border:1px solid var(--tq-border);
      background:#fff;
      border-radius: 999px;
      padding: .35rem .7rem;
      font-weight: 700;
      font-size: .86rem;
      color: var(--tq-text);
      box-shadow: var(--tq-shadow-sm);
    }
    .tq-pill.muted{ color: var(--tq-muted); font-weight: 700; }
    .tq-pill.timer{ border-color: rgba(79,70,229,.25); }
    .tq-pill.saved{ border-color: rgba(22,163,74,.25); color:#14532d; background:#f0fdf4; }

    .tq-progress{
      height: 10px;
      border-radius: 999px;
      background: #e9edf6;
      overflow: hidden;
      border: 1px solid var(--tq-border);
    }
    .tq-progress > div{
      height: 100%;
      width: 0%;
      background: linear-gradient(90deg, rgba(79,70,229,.95), rgba(29,78,216,.85));
      transition: width .3s ease;
    }

    /* Cards */
    .tq-card{
      background: var(--tq-surface);
      border: 1px solid var(--tq-border);
      border-radius: var(--tq-radius);
      box-shadow: var(--tq-shadow-sm);
    }
    .tq-card-h{
      padding: 14px 16px;
      border-bottom: 1px solid var(--tq-border);
      display:flex;
      align-items:center;
      justify-content:space-between;
      gap:10px;
    }
    .tq-card-b{ padding: 14px 16px; }

    /* Question viewport */
    .tq-qwrap{
      border: 1px solid var(--tq-border);
      border-radius: 20px;
      background: linear-gradient(180deg, #ffffff 0%, #fbfcff 100%);
      padding: 18px;
    }
    .tq-qmeta{
      display:flex;
      flex-wrap:wrap;
      align-items:center;
      justify-content:space-between;
      gap:10px;
      margin-bottom: 10px;
      color: var(--tq-muted);
      font-size: .92rem;
    }
    .tq-qmeta strong{ color: var(--tq-text); }

    .tq-qtitle{
      font-weight: 800;
      margin: 0 0 8px;
      font-size: 1.08rem;
    }
    .tq-qtext{
      font-size: 1rem;
      color: var(--tq-text);
      line-height: 1.45;
      margin-bottom: 12px;
    }

    /* Flag button */
    .qflag{
      border: 1px solid var(--tq-border);
      border-radius: 999px;
      padding: 6px 10px;
      background: #fff;
      cursor: pointer;
      font-weight: 700;
      color: var(--tq-muted);
      transition: .15s ease;
      user-select:none;
    }
    .qflag:hover{ background: #f8fafc; color: var(--tq-text); }
    .qflag.active{
      background: #fff7ed;
      border-color: #fed7aa;
      color: #b45309;
    }

    /* Answers */
    .ans{
      border: 1px solid var(--tq-border);
      border-radius: 14px;
      padding: 12px 14px;
      cursor: pointer;
      display:flex;
      gap:10px;
      align-items:flex-start;
      transition: border-color .15s ease, background-color .15s ease, box-shadow .15s ease;
      background:#fff;
    }
    .ans:hover{
      background: #f8fafc;
      border-color: rgba(79,70,229,.22);
    }
    label.ans:has(input:checked){
      border-color: rgba(79,70,229,.55);
      background: rgba(79,70,229,.06);
      box-shadow: 0 0 0 .18rem rgba(79,70,229,.10);
    }
    .ans input{ transform: translateY(3px); }

    /* Sidebar palette */
    .sticky-side{ position: sticky; top: 128px; }
    .palette{
      display:grid;
      grid-template-columns: repeat(6, minmax(0, 1fr));
      gap: 10px;
    }
    @media (max-width: 1199.98px){
      .palette{ grid-template-columns: repeat(8, minmax(0, 1fr)); }
    }
    @media (max-width: 575.98px){
      .palette{ grid-template-columns: repeat(6, minmax(0, 1fr)); }
    }
    .palette .qbtn{
      width: 100%;
      aspect-ratio: 1/1;
      border-radius: 14px;
      border: 1px solid var(--tq-border);
      background:#fff;
      font-weight: 800;
      color: #334155;
      transition: transform .12s ease, box-shadow .12s ease, border-color .12s ease;
    }
    .palette .qbtn:hover{ transform: translateY(-1px); box-shadow: var(--tq-shadow-sm); }
    .palette .qbtn.current{ outline: 2px solid rgba(79,70,229,.28); }
    .palette .qbtn.answered{ background:#f0fdf4; border-color: rgba(22,163,74,.28); color:#14532d; }
    .palette .qbtn.flagged{ background:#fff7ed; border-color: rgba(245,158,11,.35); color:#92400e; }

    /* Mobile bottom bar */
    .bottomnav{
      position: fixed;
      bottom: 0; left: 0; right: 0;
      z-index: 1100;
      border-top: 1px solid var(--tq-border);
      background: rgba(255,255,255,.85);
      backdrop-filter: blur(10px) saturate(180%);
      padding: 10px 14px;
      display: none;
    }
    @media (max-width: 991.98px){ .bottomnav{ display:block; } }

    /* Description box */
    .desc-box{
      border:1px solid var(--tq-border);
      background:#fafafa;
      border-radius:14px;
      padding:12px;
      white-space:pre-wrap;
    }

    /* Small helpers */
    .tq-muted{ color: var(--tq-muted); }
    .tq-kbd{
      font-family: ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, "Liberation Mono", "Courier New", monospace;
      font-size: .82rem;
      padding: .08rem .35rem;
      border: 1px solid var(--tq-border);
      border-bottom-width: 2px;
      border-radius: 8px;
      background: #fff;
      color: #0f172a;
    }
  </style>
</head>

<body class="tq-page">

<?php
  $navStudent = $ROOT . '/navbar_logged_student.php';
  if (is_file($navStudent)) include $navStudent;
?>

<!-- HEADER -->
<div class="tq-header">
  <div class="container">
    <div class="inner">
      <div class="d-flex align-items-start gap-3 flex-wrap">
        <a class="btn btn-outline-secondary btn-sm"
            href="<?= h($BASE_URL) ?>/course_details_student.php?course_id=<?= (int)$quiz['course_id'] ?>">
          <i class="bi bi-arrow-left"></i>
        </a>

        <div>
          <div class="tq-title"><?= h((string)($quiz['title'] ?? 'Kuiz')) ?></div>
          <div class="tq-sub">
            Kursi: <strong><?= h((string)($quiz['course_title'] ?? '')) ?></strong>
            <span class="tq-muted">•</span>
            Tentativa: <strong><?= (int)$submittedCount ?></strong> / <strong><?= $unlimited ? 'Pa limit' : (int)($quiz['attempts_allowed'] ?? 1) ?></strong>
          </div>
        </div>

        <?php if ($timerDeadlineTs && $timerDeadlineTs > time()): ?>
          <span class="tq-pill timer"><i class="bi bi-hourglass-split"></i><span id="timerTop">—:—</span></span>
        <?php else: ?>
          <span class="tq-pill muted"><i class="bi bi-clock-history"></i>Pa limit kohe</span>
        <?php endif; ?>

        <span class="tq-pill saved" id="autosavePill" hidden><i class="bi bi-check2-circle"></i>Ruajtur</span>
      </div>

      <div class="d-flex align-items-center gap-2">
        <button class="btn btn-success btn-sm" id="openSummaryBtn">
          <i class="bi bi-check2-circle me-1"></i>Dorëzo
        </button>
      </div>
    </div>

    <div class="tq-progress mb-2">
      <div id="progressBarTop"></div>
    </div>
  </div>
</div>

<div class="container tq-wrap">

  <?php if (!empty($quiz['description'])): ?>
    <div class="tq-card mb-3">
      <div class="tq-card-h">
        <div class="d-flex align-items-center gap-2">
          <i class="bi bi-info-circle"></i>
          <strong>Udhëzime</strong>
        </div>
      </div>
      <div class="tq-card-b">
        <div class="desc-box"><?= nl2br(h((string)$quiz['description'])) ?></div>
      </div>
    </div>
  <?php endif; ?>

  <?php if (!$renderQuestions): ?>
    <div class="tq-card p-3">Ky kuiz nuk ka ende pyetje.</div>

  <?php elseif (!$inProgress && !$canStart): ?>
    <div class="tq-card p-3">
      <?php if ($isUpcoming): ?>
        <div class="d-flex align-items-center gap-2">
          <i class="bi bi-clock"></i><strong>Ky kuiz ende nuk është hapur.</strong>
        </div>
        <div class="tq-muted mt-1">Hapet: <?= $openAt ? date('d/m/Y H:i', $openAt) : '—' ?></div>

      <?php elseif ($isClosed): ?>
        <div class="d-flex align-items-center gap-2">
          <i class="bi bi-lock"></i><strong>Ky kuiz është mbyllur.</strong>
        </div>
        <div class="tq-muted mt-1">Mbyllet: <?= $closeAt ? date('d/m/Y H:i', $closeAt) : '—' ?></div>

      <?php else: ?>
        <div class="d-flex align-items-center gap-2">
          <i class="bi bi-shield-lock"></i><strong>Nuk mund të nisni një tentativë të re për momentin.</strong>
        </div>
      <?php endif; ?>
    </div>

  <?php else: ?>

  <form method="POST" action="<?= h($BASE_URL) ?>/quizzes/take_quiz.php?quiz_id=<?= (int)$quiz_id ?>" id="quizForm">
    <input type="hidden" name="csrf" value="<?= h($CSRF) ?>">
    <input type="hidden" name="attempt_id" id="attempt_id" value="<?= (int)$inProgress['id'] ?>">
    <input type="hidden" id="deadlineTs" value="<?= ($timerDeadlineTs && $timerDeadlineTs > time()) ? (int)$timerDeadlineTs : 0 ?>">

    <div class="row g-3">

      <!-- Left: Workspace / single question -->
      <div class="col-12 col-lg-8">
        <div class="tq-qwrap" id="viewport">
          <?php
            $totalQ = count($renderQuestions);
            $totPts = 0; foreach ($renderQuestions as $qq){ $p=(int)($qq['points']??1); $totPts += max(1,$p); }
          ?>

          <div class="tq-qmeta">
            <div>
              Pyetje: <strong id="totalCount"><?= (int)$totalQ ?></strong>
              <span class="tq-muted">•</span>
              Pikë totale: <strong><?= (int)$totPts ?></strong>
            </div>

            <?php if ($timerDeadlineTs && $timerDeadlineTs > time()): ?>
              <span class="tq-pill timer"><i class="bi bi-stopwatch"></i><span id="bigTimer">—:—</span></span>
            <?php endif; ?>
          </div>

          <?php foreach ($renderQuestions as $idx => $q):
            $qid = (int)$q['id'];
            $opts = $renderAnswersByQ[$qid] ?? [];
            $qPoints = (int)($q['points'] ?? 1); if ($qPoints <= 0) $qPoints = 1;
            $pref = isset($prefill[$qid]) ? (int)$prefill[$qid] : null;
          ?>
            <section class="question" data-index="<?= $idx ?>" data-qid="<?= $qid ?>" id="q<?= $qid ?>" style="<?= $idx===0 ? '' : 'display:none' ?>">
              <div class="d-flex align-items-start justify-content-between gap-2 mb-2">
                <div>
                  <h2 class="tq-qtitle mb-1">
                    Pyetja <span class="curIndex"><?= $idx+1 ?></span> / <span class="totIndex"><?= (int)$totalQ ?></span>
                  </h2>
                  <div class="tq-muted small">
                    Vlera: <strong><?= (int)$qPoints ?></strong> pikë
                  </div>
                </div>

                <div class="d-flex align-items-center gap-2">
                  <button type="button" class="qflag" data-flag="<?= $qid ?>" title="Shëno për ta parë më vonë (F)">
                    <i class="bi bi-flag me-1"></i>Shëno
                  </button>
                </div>
              </div>

              <div class="tq-qtext"><?= nl2br(h((string)$q['question'])) ?></div>

              <?php if (!$opts): ?>
                <div class="tq-card p-3">
                  <div class="d-flex align-items-center gap-2">
                    <i class="bi bi-exclamation-triangle"></i>
                    <strong>Kjo pyetje nuk ka alternativa.</strong>
                  </div>
                </div>
              <?php else: ?>
                <div class="vstack gap-2">
                  <?php foreach ($opts as $A): $aid=(int)$A['id']; ?>
                    <label class="ans">
                      <input type="radio" class="form-check-input mt-0 q-radio"
                             name="q_<?= $qid ?>" value="<?= $aid ?>" data-qid="<?= $qid ?>"
                             <?= ($pref && $pref===$aid) ? 'checked' : '' ?>>
                      <span><?= nl2br(h((string)$A['answer_text'])) ?></span>
                    </label>
                  <?php endforeach; ?>
                </div>
              <?php endif; ?>
            </section>
          <?php endforeach; ?>

          <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mt-3">
            <button type="button" class="btn btn-outline-secondary" id="prevBtn">
              <i class="bi bi-arrow-left"></i> Mbrapa
            </button>

            <div class="tq-muted small">
              Shigjetat <span class="tq-kbd">←</span> <span class="tq-kbd">→</span> • Shëno <span class="tq-kbd">F</span>
            </div>

            <button type="button" class="btn btn-primary" id="nextBtn">
              Tjetra <i class="bi bi-arrow-right"></i>
            </button>
          </div>
        </div>
      </div>

      <!-- Right: Review panel -->
      <div class="col-12 col-lg-4">
        <div class="sticky-side">

          <div class="tq-card mb-3">
            <div class="tq-card-h">
              <div class="d-flex align-items-center gap-2">
                <i class="bi bi-gauge"></i><strong>Ecuria</strong>
              </div>
              <?php if ($timerDeadlineTs && $timerDeadlineTs > time()): ?>
                <span class="tq-pill timer"><i class="bi bi-hourglass"></i><span id="sideTimer">—:—</span></span>
              <?php endif; ?>
            </div>
            <div class="tq-card-b">
              <div class="d-flex justify-content-between small mb-2">
                <span class="tq-muted">U përgjigj</span>
                <span><strong id="answeredCountSide">0</strong>/<strong><?= (int)$totalQ ?></strong></span>
              </div>
              <div class="progress" style="height:10px;">
                <div class="progress-bar" id="progressBarSide" style="width:0%"></div>
              </div>
              <div class="tq-muted small mt-2">
                Jeshile = u përgjigj • Portokalli = e shënuar
              </div>
            </div>
          </div>

          <div class="tq-card mb-3">
            <div class="tq-card-h">
              <div class="d-flex align-items-center gap-2">
                <i class="bi bi-grid"></i><strong>Navigo pyetjet</strong>
              </div>
              <button type="button" class="btn btn-outline-primary btn-sm" id="openSummaryBtnSide">
                <i class="bi bi-check2-circle me-1"></i>Dorëzo
              </button>
            </div>
            <div class="tq-card-b">
              <div class="palette" id="paletteBtns">
                <?php foreach ($renderQuestions as $i=>$q): $qid=(int)$q['id']; ?>
                  <button type="button" class="qbtn" data-jump="<?= $i ?>" data-qid="<?= $qid ?>" title="Pyetja <?= $i+1 ?>"><?= $i+1 ?></button>
                <?php endforeach; ?>
              </div>
            </div>
          </div>

          <div class="tq-card">
            <div class="tq-card-b">
              <button type="button" class="btn btn-success w-100 mb-2" id="openSummaryBtnBottomFake" style="display:none;"></button>
              <button type="button" class="btn btn-success w-100 mb-2" id="openSummaryBtnSide2">
                <i class="bi bi-check2-circle me-1"></i>Dorëzo tani
              </button>
              <div class="tq-muted small">
                <ul class="mb-0 ps-3">
                  <li>Shëno me <strong>F</strong> për ta parë më vonë.</li>
                  <li>Nëse ka limit kohe, dorëzohet automatikisht.</li>
                  <li>Mund të kthehesh te pyetjet pa përgjigje nga përmbledhja.</li>
                </ul>
              </div>
            </div>
          </div>

        </div>
      </div>

    </div>

    <!-- Hidden submit trigger -->
    <button class="visually-hidden" type="submit" name="submit_quiz" id="submitBtn">Dorëzo</button>

    <!-- Mobile bottom bar -->
    <div class="bottomnav">
      <div class="d-flex align-items-center justify-content-between gap-2">
        <div class="small">
          U përgjigj: <strong id="answeredCountBottom">0</strong>/<span><?= (int)$totalQ ?></span>
          <?php if ($timerDeadlineTs && $timerDeadlineTs > time()): ?>
            <span class="tq-muted">•</span> <i class="bi bi-clock me-1"></i><span id="miniTimer">—:—</span>
          <?php endif; ?>
        </div>
        <div class="d-flex gap-2">
          <button type="button" class="btn btn-outline-secondary" id="prevBtnM"><i class="bi bi-arrow-left"></i></button>
          <button type="button" class="btn btn-outline-primary" id="nextBtnM"><i class="bi bi-arrow-right"></i></button>
          <button type="button" class="btn btn-success" id="openSummaryBtnBottom"><i class="bi bi-check2-circle"></i></button>
        </div>
      </div>
    </div>
  </form>
  <?php endif; ?>
</div>

<!-- Modal: Summary -->
<div class="modal fade" id="summaryModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title"><i class="bi bi-clipboard-check me-2"></i>Përmbledhje para dorëzimit</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Mbyll"></button>
      </div>
      <div class="modal-body">
        <ul class="list-unstyled mb-0">
          <li class="d-flex justify-content-between py-1"><span>Pyetje gjithsej</span><strong id="sumTotal">0</strong></li>
          <li class="d-flex justify-content-between py-1"><span>U përgjigj</span><strong id="sumAnswered">0</strong></li>
          <li class="d-flex justify-content-between py-1"><span>Pa përgjigje</span><strong id="sumUnanswered">0</strong></li>
          <li class="d-flex justify-content-between py-1"><span>Të shënuara</span><strong id="sumFlagged">0</strong></li>
        </ul>
        <div class="alert alert-warning small mt-3 mb-0" id="sumWarn" style="display:none;">
          Ka pyetje pa përgjigje ose të shënuara. Mund të kthehesh ose të dorëzosh.
        </div>
      </div>
      <div class="modal-footer">
        <button id="goFirstUnanswered" type="button" class="btn btn-outline-secondary">Shko te e para pa përgjigje</button>
        <button id="confirmSubmit" type="button" class="btn btn-success"><i class="bi bi-check2-circle me-1"></i>Dorëzo</button>
      </div>
    </div>
  </div>
</div>

<?php include $ROOT . '/footer.php'; ?>

<!-- Vendor JS -->
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>

<script>
(function(){
  const byId = id => document.getElementById(id);

  const form = byId('quizForm');
  const submitBtn = byId('submitBtn');
  const attemptId = byId('attempt_id')?.value || '0';
  const quizId = <?= (int)$quiz_id ?>;

  /* ===== Timer ===== */
  const deadline = parseInt(byId('deadlineTs')?.value || '0', 10);
  const pad = n => (n<10 ? '0'+n : ''+n);
  function setTimer(out){
    ['timerTop','bigTimer','miniTimer','sideTimer'].forEach(i=>{ const el=byId(i); if(el) el.textContent = out; });
  }
  function tick(){
    if(!deadline) return;
    const now = Math.floor(Date.now()/1000);
    let diff = deadline - now, out='00:00';
    if (diff>0){
      const m=Math.floor(diff/60), s=diff%60;
      out=pad(m)+':'+pad(s);
    } else {
      if (form && !form.dataset.autosubmitted) {
        form.dataset.autosubmitted='1';
        const hid = document.createElement('input');
        hid.type='hidden'; hid.name='submit_quiz'; hid.value='1';
        form.appendChild(hid); form.submit();
      }
    }
    setTimer(out);
  }
  tick(); if (deadline) setInterval(tick, 1000);

  /* ===== State ===== */
  const questions = Array.from(document.querySelectorAll('.question'));
  const qCount = questions.length;
  let cur = 0;

  const radios = Array.from(document.querySelectorAll('.q-radio'));
  const answeredSet = new Set(radios.filter(r => r.checked).map(r => r.dataset.qid));

  const autosavePill = byId('autosavePill');
  let saveTO=null;
  function flashSaved(){
    if(!autosavePill) return;
    autosavePill.hidden=false;
    clearTimeout(saveTO);
    saveTO=setTimeout(()=> autosavePill.hidden=true, 1000);
  }

  const LS_KEY = `quiz_${<?= (int)$quiz_id ?>}_attempt_${attemptId}`;
  const LS_FLAG = `${LS_KEY}_flags`;
  const loadSaved = () => { try{ return JSON.parse(localStorage.getItem(LS_KEY)||'{}')||{}; }catch(e){ return {}; } };
  const saveMap   = map => { try{ localStorage.setItem(LS_KEY, JSON.stringify(map)); }catch(e){} };
  const loadFlags = () => { try{ return JSON.parse(localStorage.getItem(LS_FLAG)||'{}')||{}; }catch(e){ return {}; } };
  const saveFlags = f   => { try{ localStorage.setItem(LS_FLAG, JSON.stringify(f)); }catch(e){} };

  const flags = loadFlags();

  // Prefill nga LS kur serveri s’ka përgjigje
  const saved = loadSaved();
  Object.entries(saved).forEach(([qid, aid])=>{
    const hasChecked = document.querySelector(`input.q-radio[name="q_${qid}"]:checked`);
    if(!hasChecked){
      const sel = document.querySelector(`input.q-radio[name="q_${qid}"][value="${aid}"]`);
      if(sel){ sel.checked=true; answeredSet.add(qid); }
    }
  });

  // Flags e ruajtura
  Object.keys(flags).forEach(qid=>{
    if(flags[qid]){
      document.querySelector(`.qflag[data-flag="${qid}"]`)?.classList.add('active');
    }
  });

  /* ===== Progress ===== */
  const progressTopEl  = byId('progressBarTop');
  const progressSideEl = byId('progressBarSide');
  const ansBottom      = byId('answeredCountBottom');
  const ansSide        = byId('answeredCountSide');

  function renderProgress(){
    const n = answeredSet.size;
    const pct = qCount ? Math.round((n/qCount)*100) : 0;

    if (progressTopEl)  progressTopEl.style.width  = pct + '%';
    if (progressSideEl) progressSideEl.style.width = pct + '%';
    if (ansBottom) ansBottom.textContent = n;
    if (ansSide)   ansSide.textContent   = n;

    document.querySelectorAll('#paletteBtns .qbtn').forEach(btn=>{
      const qid = btn.dataset.qid;
      btn.classList.toggle('answered', answeredSet.has(qid));
      btn.classList.toggle('flagged', !!flags[qid]);
      btn.classList.toggle('current', Number(btn.dataset.jump)===cur);
    });
  }
  renderProgress();

  function currentMap(){
    const map={};
    const groups = new Set(radios.map(r=>r.name));
    groups.forEach(name=>{
      const ch = document.querySelector(`input.q-radio[name="${name}"]:checked`);
      if (ch) map[ch.dataset.qid] = ch.value;
    });
    return map;
  }

  radios.forEach(r=>{
    r.addEventListener('change', ()=>{
      if(r.checked){
        answeredSet.add(r.dataset.qid);
        saveMap(currentMap());
        flashSaved();
        renderProgress();
      }
    });
  });

  /* ===== Navigation ===== */
  function show(idx){
    if (idx<0 || idx>=qCount) return;
    questions[cur].style.display='none';
    cur = idx;
    questions[cur].style.display='';
    renderProgress();
  }
  function next(){ if (cur<qCount-1) show(cur+1); }
  function prev(){ if (cur>0) show(cur-1); }

  byId('nextBtn')?.addEventListener('click', next);
  byId('prevBtn')?.addEventListener('click', prev);
  byId('nextBtnM')?.addEventListener('click', next);
  byId('prevBtnM')?.addEventListener('click', prev);

  document.querySelectorAll('#paletteBtns .qbtn').forEach(btn=>{
    btn.addEventListener('click', ()=>{
      show(Number(btn.dataset.jump)||0);
      const vp = byId('viewport');
      if (vp) window.scrollTo({top: vp.offsetTop-70, behavior:'smooth'});
    });
  });

  // Keyboard: arrows + F
  window.addEventListener('keydown', (e)=>{
    if (e.target && /input|textarea|select/i.test(e.target.tagName)) return;
    if (e.key==='ArrowRight') next();
    else if (e.key==='ArrowLeft') prev();
    else if (e.key.toLowerCase()==='f'){
      const qid = questions[cur]?.dataset?.qid;
      if (!qid) return;
      const btn = document.querySelector(`.qflag[data-flag="${qid}"]`);
      if (btn){
        const act = btn.classList.toggle('active');
        flags[qid] = act ? 1 : 0;
        saveFlags(flags); renderProgress();
      }
    }
  });

  // Flag click
  document.querySelectorAll('.qflag').forEach(b=>{
    b.addEventListener('click', ()=>{
      const qid = b.getAttribute('data-flag');
      const act = b.classList.toggle('active');
      flags[qid] = act ? 1 : 0;
      saveFlags(flags);
      renderProgress();
    });
  });

  /* ===== Summary Modal ===== */
  const summaryModal = new bootstrap.Modal(document.getElementById('summaryModal'));
  function openSummary(){
    const ans = answeredSet.size;
    const totalQ = qCount;
    const unanswered = totalQ - ans;
    const flagged = Object.values(flags).filter(Boolean).length;
    byId('sumTotal').textContent = totalQ;
    byId('sumAnswered').textContent = ans;
    byId('sumUnanswered').textContent = unanswered;
    byId('sumFlagged').textContent = flagged;
    byId('sumWarn').style.display = (unanswered>0 || flagged>0) ? 'block' : 'none';
    summaryModal.show();
  }

  byId('openSummaryBtn')?.addEventListener('click', openSummary);
  byId('openSummaryBtnSide')?.addEventListener('click', openSummary);
  byId('openSummaryBtnSide2')?.addEventListener('click', openSummary);
  byId('openSummaryBtnBottom')?.addEventListener('click', openSummary);

  byId('goFirstUnanswered')?.addEventListener('click', ()=>{
    for (let i=0;i<qCount;i++){
      const qid = questions[i].dataset.qid;
      if (!answeredSet.has(qid)){ show(i); break; }
    }
    summaryModal.hide();
  });

  byId('confirmSubmit')?.addEventListener('click', ()=>{
    try{
      localStorage.removeItem(LS_KEY);
      localStorage.removeItem(LS_FLAG);
    }catch(e){}
    const hid = document.createElement('input');
    hid.type='hidden'; hid.name='submit_quiz'; hid.value='1';
    form.appendChild(hid);
    form.submit();
  });

  // Before unload notice
  window.addEventListener('beforeunload', (e)=>{
    if (!form || form.dataset.autosubmitted==='1') return;
    if (answeredSet.size>0){ e.preventDefault(); e.returnValue=''; }
  });

  // Tooltips (optional)
  const tooltipTriggerList = [].slice.call(document.querySelectorAll('[title]'));
  tooltipTriggerList.forEach(el => { try{ new bootstrap.Tooltip(el, {trigger:'hover'}); }catch(e){} });

  // Initial
  show(0);
  if (Object.keys(saved).length>0){
    if (autosavePill){ autosavePill.hidden = false; setTimeout(()=> autosavePill.hidden=true, 1200); }
  }
})();
</script>
</body>
</html>
