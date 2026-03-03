<?php
// quiz_attempt_view.php — Shikim rezultati (Two-column, ring-score, paletë djathtas, HERO i ri, pa butona të panevojshëm)
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
require_once $ROOT . '/lib/Parsedown.php';

function h(?string $s): string { return htmlspecialchars($s ?? '', ENT_QUOTES, 'UTF-8'); }

if (!isset($_SESSION['user'])) { header('Location: ' . $BASE_URL . '/login.php'); exit; }
$ROLE  = (string)($_SESSION['user']['role'] ?? '');
$ME_ID = (int)($_SESSION['user']['id'] ?? 0);

if (!isset($_GET['attempt_id']) || !is_numeric($_GET['attempt_id'])) die('Tentativa nuk u specifikua.');
$attempt_id = (int)$_GET['attempt_id'];

try {
  $stmt = $pdo->prepare("
    SELECT a.*,
           q.title AS quiz_title, q.course_id, q.open_at, q.close_at, q.attempts_allowed,
           c.title AS course_title, c.id_creator AS course_creator
    FROM quiz_attempts a
    JOIN quizzes q ON q.id = a.quiz_id
    JOIN courses  c ON c.id = q.course_id
    WHERE a.id = ?
  ");
  $stmt->execute([$attempt_id]);
  $attempt = $stmt->fetch(PDO::FETCH_ASSOC);
  if (!$attempt) die('Tentativa nuk u gjet.');

  $isOwnerStudent = ($ROLE === 'Student' && (int)$attempt['user_id'] === $ME_ID);
  $isStaffOfCourse = in_array($ROLE, ['Administrator','Instruktor'], true) && (
    $ROLE==='Administrator' || (int)$attempt['course_creator'] === $ME_ID
  );
  if (!$isOwnerStudent && !$isStaffOfCourse) die('Nuk keni akses për këtë tentativë.');
  if ($ROLE === 'Student' && empty($attempt['submitted_at'])) {
    header('Location: ' . $BASE_URL . '/quizzes/take_quiz.php?quiz_id='.(int)$attempt['quiz_id']); exit;
  }

  $stmt = $pdo->prepare("SELECT * FROM quiz_questions WHERE quiz_id=? ORDER BY position ASC, id ASC");
  $stmt->execute([(int)$attempt['quiz_id']]);
  $questions = $stmt->fetchAll(PDO::FETCH_ASSOC);

  $answersByQ = [];
  if ($questions) {
    $qids = array_map(fn($q)=>(int)$q['id'], $questions);
    $in   = implode(',', array_fill(0, count($qids), '?'));
    $stmt = $pdo->prepare("SELECT * FROM quiz_answers WHERE question_id IN ($in) ORDER BY position ASC, id ASC");
    $stmt->execute($qids);
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $ans) {
      $answersByQ[(int)$ans['question_id']][] = $ans;
    }
  }

  $answersMap = [];
  if (!empty($attempt['answers_json'])) {
    $tmp = json_decode((string)$attempt['answers_json'], true);
    if (is_array($tmp)) {
      if (isset($tmp['answers'])) foreach ($tmp['answers'] as $qid => $aid) $answersMap[(int)$qid] = is_null($aid) ? null : (int)$aid;
      else foreach ($tmp as $qid => $aid) if (is_numeric($qid)) $answersMap[(int)$qid] = is_null($aid) ? null : (int)$aid;
    }
  }

  $totalPoints = (int)($attempt['total_points'] ?? 0);
  if ($totalPoints <= 0) { $totalPoints = 0; foreach ($questions as $q) $totalPoints += max(1,(int)($q['points']??1)); }
  $score   = (int)($attempt['score'] ?? 0);
  $percent = $totalPoints>0 ? round(($score/$totalPoints)*100) : 0;

  $correctCount=0; $incorrectCount=0; $unansweredCount=0; $statusByQ=[];
  foreach ($questions as $q) {
    $qid = (int)$q['id'];
    $selected   = $answersMap[$qid] ?? null;
    $opts       = $answersByQ[$qid] ?? [];
    $correctAid = null; foreach ($opts as $o) if ((int)$o['is_correct']===1) { $correctAid=(int)$o['id']; break; }
    if ($selected === null) { $unansweredCount++; $statusByQ[$qid]='unanswered'; }
    else if ($correctAid !== null && $selected === $correctAid) { $correctCount++; $statusByQ[$qid]='correct'; }
    else { $incorrectCount++; $statusByQ[$qid]='incorrect'; }
  }

  $allowed   = (int)($attempt['attempts_allowed'] ?? 1);
  $unlimited = ($allowed <= 0);
  $stmt = $pdo->prepare("SELECT COUNT(*) FROM quiz_attempts WHERE quiz_id=? AND user_id=? AND submitted_at IS NOT NULL");
  $stmt->execute([(int)$attempt['quiz_id'], (int)$attempt['user_id']]);
  $submittedCount = (int)$stmt->fetchColumn();

  $now = time();
  $openAt  = !empty($attempt['open_at'])  ? strtotime((string)$attempt['open_at'])  : null;
  $closeAt = !empty($attempt['close_at']) ? strtotime((string)$attempt['close_at']) : null;
  $isOpenWindow = (!$openAt || $now >= $openAt) && (!$closeAt || $now <= $closeAt);
  $mayRetake = $ROLE==='Student' && $isOpenWindow && ($unlimited || $submittedCount < $allowed);

  $backUrl = ($ROLE==='Student')
    ? ($BASE_URL . '/course_details_student.php?course_id='.(int)$attempt['course_id'])
    : ($BASE_URL . '/course_details.php?course_id='.(int)$attempt['course_id'].'&tab=sections');

  $Parsedown = new Parsedown(); if (method_exists($Parsedown,'setSafeMode')) $Parsedown->setSafeMode(true);

  $lsQ=0; $lsA=0;
  if (!empty($_SESSION['clear_ls'])) {
    if ((int)($_SESSION['clear_ls']['attempt_id'] ?? 0) === $attempt_id) {
      $lsQ = (int)($_SESSION['clear_ls']['quiz_id'] ?? 0);
      $lsA = (int)($_SESSION['clear_ls']['attempt_id'] ?? 0);
      unset($_SESSION['clear_ls']);
    }
  }
} catch (Throwable $e) { die('Gabim: ' . h($e->getMessage())); }
?>
<!DOCTYPE html>
<html lang="sq" data-bs-theme="light">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title><?= h($attempt['quiz_title']) ?> — Rezultati | kurseinformatike.com</title>

  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
  <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css" rel="stylesheet">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">
  <link rel="icon" href="<?= h($BASE_URL) ?>/image/favicon.ico" type="image/x-icon" />

  <style>
    :root{
      --primary:#2A4B7C; --primary-dark:#1d3a63; --bg:#f6f8fb; --border:#e5e7eb;
      --muted:#6b7280; --ok:#16a34a; --bad:#dc2626; --warn:#f59e0b;
      --radius:16px; --shadow:0 10px 28px rgba(0,0,0,.08);
    }
    html,body{ font-family:-apple-system,BlinkMacSystemFont,"SF Pro Text","SF Pro Display","Segoe UI",Roboto,"Helvetica Neue",Arial,"Noto Sans",sans-serif,"Apple Color Emoji","Segoe UI Emoji"; }
    body{ background:var(--bg); }

    section.hero{ 
      margin-top: 40px;
    }

    /* HERO i ri: bar gradient + kartë e bardhë */
    .hero{ padding:0 0 16px; margin-bottom:16px;}
    .hero-card{
      margin-top:-6px;
      background:#fff; border:1px solid var(--border); border-radius:20px; box-shadow:var(--shadow);
      padding:16px 18px;
    }
    .hero .crumb a{ text-decoration:none; color:var(--primary-dark); font-weight:600; }
    .chip{
      display:inline-flex; align-items:center; gap:.35rem;
      padding:.28rem .6rem; border:1px solid #e6ebf4; border-radius:999px; background:#f9fbff;
      font-size:.85rem; color:#334155;
    }

    /* Cards & metrics */
    .cardx{ background:#fff; border:0; border-radius:var(--radius); box-shadow:var(--shadow); }
    .summary{ padding:16px; }
    .ring{
      --p: 0;
      width:86px; height:86px; border-radius:50%;
      background:conic-gradient(var(--primary) calc(var(--p)*1%), #e5e7eb 0);
      display:grid; place-items:center;
    }
    .ring .inner{
      width:68px; height:68px; border-radius:50%; background:#fff; display:grid; place-items:center;
      box-shadow:inset 0 0 0 1px var(--border);
      font-weight:800; color:#1d3a63;
    }
    .metric{ border:1px solid var(--border); border-radius:14px; padding:12px; text-align:center; background:#fff; }
    .metric .val{ font-size:1.3rem; font-weight:800; color:#1d3a63; line-height:1; }
    .metric .lbl{ color:#64748b; font-size:.9rem; }

    /* Pyetjet */
    .qcard{ border:1px solid var(--border); border-radius:16px; background:#fff; box-shadow:0 6px 18px rgba(0,0,0,.05); padding:16px; }
    .ans{ border:1.5px solid var(--border); border-radius:12px; padding:10px 12px; display:flex; gap:10px; align-items:flex-start; margin-bottom: 10px;}
    .ans.correct{ border-color:#22c55e; background:#f0fff5; }
    .ans.incorrect.selected{ border-color:#ef4444; background:#fff5f5; }
    .chip-badge{ font-size:.8rem; border-radius:10px; }

    /* Sidebar / Paleta */
    .sticky-side{ position:sticky; top:86px; }
    .palette .btn{
      width:44px; height:44px; border-radius:12px; border:1px solid var(--border); background:#fff; font-weight:600;
      display:flex; align-items:center; justify-content:center; transition:transform .15s ease;
    }
    .palette .btn:hover{ transform: translateY(-1px); }
    .palette .btn.correct{ background:#e8f7ee; border-color:#cceedd; }
    .palette .btn.incorrect{ background:#fff3f3; border-color:#ffd2d2; }
    .palette .btn.unanswered{ background:#f8fafc; }
    .palette .btn.current{ outline:2px solid #bfd2ff; }

    /* Filtrat */
    .filt .btn{ border-radius:12px; }
    .filt .btn.active{ box-shadow:0 0 0 .15rem rgba(42,75,124,.15); }

    /* Shpjegimi toggle */
    .exp .exp-body{ display:block; }
    .exp.collapsed .exp-body{ display:none; }

    @media (max-width: 991.98px){
      .palette .btn{ width:40px; height:40px; }
    }
  </style>
</head>
<body>

<?php
if ($ROLE === 'Student' && is_file($ROOT.'/navbar_logged_student.php'))      include $ROOT.'/navbar_logged_student.php';
elseif ($ROLE === 'Instruktor') {
  $p1 = $ROOT.'/navbar_logged_instructor.php';
  $p2 = $ROOT.'/navbar_logged_instruktor.php';
  if (is_file($p1)) include $p1;
  elseif (is_file($p2)) include $p2;
}
elseif ($ROLE === 'Administrator' && is_file($ROOT.'/navbar_logged_administrator.php')) include $ROOT.'/navbar_logged_administrator.php';
?>

<section class="hero">
  <div class="hero-bar"></div>
  <div class="container">
    <div class="hero-card">
      <div class="d-flex flex-column flex-lg-row align-items-start align-items-lg-center justify-content-between gap-3">
        <div>
          <div class="crumb mb-1">
            <a href="<?= h($backUrl) ?>"><i class="fa-solid fa-arrow-left-long me-1"></i>Kthehu te kursi</a>
          </div>
          <h1 class="mb-1"><?= h($attempt['quiz_title']) ?></h1>
          <div class="d-flex flex-wrap gap-2">
            <span class="chip"><i class="bi bi-journal-bookmark"></i> Kursi: <strong><?= h($attempt['course_title']) ?></strong></span>
            <span class="chip"><i class="fa-regular fa-clock"></i> Nisur: <?= h(date('d M Y, H:i', strtotime((string)$attempt['started_at']))) ?></span>
            <?php if (!empty($attempt['submitted_at'])): ?>
              <span class="chip"><i class="fa-regular fa-circle-check"></i> Dorëzuar: <?= h(date('d M Y, H:i', strtotime((string)$attempt['submitted_at']))) ?></span>
            <?php endif; ?>
          </div>
        </div>
        <div class="d-flex align-items-center gap-2 flex-wrap">
          <button class="btn btn-outline-secondary btn-sm" onclick="window.print()">
            <i class="fa-regular fa-file-lines me-1"></i> Printo/PDF
          </button>
        </div>
      </div>
    </div>
  </div>
</section>

<main class="container mb-4">
  <div class="row g-3">
    <!-- MAJTAS -->
    <div class="col-12 col-lg-8">
      <div class="cardx summary mb-3">
        <div class="row g-3 align-items-center">
          <div class="col-auto">
            <div class="ring" style="--p: <?= (int)$percent ?>;">
              <div class="inner"><?= (int)$percent ?>%</div>
            </div>
          </div>
          <div class="col">
            <div class="row g-3">
              <div class="col-6 col-md-3"><div class="metric"><div class="val"><?= (int)$score ?>/<?= (int)$totalPoints ?></div><div class="lbl">Pikë</div></div></div>
              <div class="col-6 col-md-3"><div class="metric"><div class="val"><?= (int)$correctCount ?>/<?= count($questions) ?></div><div class="lbl">Të sakta</div></div></div>
              <div class="col-6 col-md-3"><div class="metric"><div class="val"><?= (int)$incorrectCount ?></div><div class="lbl">Të gabuara</div></div></div>
              <div class="col-6 col-md-3">
                <div class="metric">
                  <div class="val">
                    <?php
                      if (!empty($attempt['submitted_at'])) {
                        $secs = max(0, strtotime((string)$attempt['submitted_at']) - strtotime((string)$attempt['started_at']));
                        echo floor($secs/60).'m '.($secs%60).'s';
                      } else echo '—';
                    ?>
                  </div>
                  <div class="lbl">Kohëzgjatja</div>
                </div>
              </div>
            </div>

            <div class="d-flex justify-content-between align-items-center mt-3 flex-wrap gap-2">
              <div class="small text-muted">
                <?php if ($ROLE==='Student'): ?>
                  <?php if ($mayRetake): ?>
                    <a class="btn btn-primary btn-sm" href="<?= h($BASE_URL) ?>/quizzes/take_quiz.php?quiz_id=<?= (int)$attempt['quiz_id'] ?>"><i class="fa-solid fa-rotate-right me-1"></i>Riprovo kuizin</a>
                    <span class="ms-2"> <?= ($attempt['attempts_allowed']>0) ? ('Lejohen '.(int)$attempt['attempts_allowed'].' tentativa') : 'Tentativa të pakufizuara' ?></span>
                  <?php else: ?>
                    <?php if (!$isOpenWindow): ?>Dritarja e kuizit është jashtë orarit.
                    <?php elseif ($attempt['attempts_allowed']>0): ?>Kufiri i tentativave (<?= (int)$attempt['attempts_allowed'] ?>) është arritur.
                    <?php endif; ?>
                  <?php endif; ?>
                <?php endif; ?>
              </div>
              <div class="btn-group filt" role="group" aria-label="Filtra">
                <button type="button" class="btn btn-outline-secondary active" data-filter="all">Të gjitha</button>
                <button type="button" class="btn btn-outline-success"  data-filter="correct">Të sakta</button>
                <button type="button" class="btn btn-outline-danger"   data-filter="incorrect">Të gabuara</button>
                <button type="button" class="btn btn-outline-dark"     data-filter="unanswered">Pa përgjigje</button>
              </div>
            </div>
          </div>
        </div>
      </div>

      <?php if ($questions): ?>
        <div class="vstack gap-3" id="qWrap">
          <?php
          $n=1;
          foreach ($questions as $q):
            $qid = (int)$q['id'];
            $selectedAid = $answersMap[$qid] ?? null;

            $opts = $answersByQ[$qid] ?? [];
            $correctAid = null; foreach ($opts as $o) if ((int)$o['is_correct'] === 1) { $correctAid = (int)$o['id']; break; }

            $isCorrect    = ($selectedAid !== null && $selectedAid === $correctAid);
            $isUnanswered = ($selectedAid === null);
            $qPoints      = max(1,(int)($q['points']??1));
            $statusStr    = $isUnanswered ? 'unanswered' : ($isCorrect ? 'correct' : 'incorrect');
          ?>
          <div class="qcard exp" id="q<?= $qid ?>" data-status="<?= $statusStr ?>">
            <div class="d-flex justify-content-between align-items-start">
              <div class="fw-semibold">Pyetja <?= $n++ ?> <span class="text-muted">· <?= $qPoints ?> pikë</span></div>
              <div class="d-flex align-items-center gap-2">
                <?php if ($isUnanswered): ?>
                  <span class="badge text-bg-secondary chip-badge"><i class="fa-regular fa-circle me-1"></i>Pa përgjigje</span>
                <?php elseif ($isCorrect): ?>
                  <span class="badge text-bg-success chip-badge"><i class="fa-regular fa-circle-check me-1"></i>Saktë</span>
                <?php else: ?>
                  <span class="badge text-bg-danger chip-badge"><i class="fa-regular fa-circle-xmark me-1"></i>Gabim</span>
                <?php endif; ?>
              </div>
            </div>

            <div class="mt-1 mb-2"><?= nl2br(h((string)$q['question'])) ?></div>

            <?php if ($opts): ?>
              <div class="mt-2">
                <?php foreach ($opts as $o):
                  $aid = (int)$o['id'];
                  $rowClass = '';
                  if ($aid === $correctAid) $rowClass = 'correct';
                  if ($selectedAid === $aid && $aid !== $correctAid) $rowClass = 'incorrect selected';
                ?>
                  <div class="ans <?= $rowClass ?>">
                    <div class="form-check mt-1">
                      <input class="form-check-input" type="radio" disabled <?= $selectedAid === $aid ? 'checked' : '' ?>>
                    </div>
                    <div class="flex-grow-1">
                      <?= nl2br(h((string)$o['answer_text'])) ?>
                      <?php if ($aid === $correctAid): ?><span class="badge bg-success-subtle text-success-emphasis ms-2">Saktë</span><?php endif; ?>
                      <?php if ($selectedAid === $aid && $aid !== $correctAid): ?><span class="badge bg-danger-subtle text-danger-emphasis ms-2">Zgjedhur</span><?php endif; ?>
                    </div>
                  </div>
                <?php endforeach; ?>
              </div>
            <?php endif; ?>

            <?php if (!empty($q['explanation'])): ?>
              <div class="mt-2 exp-body">
                <div class="small text-muted mb-1"><i class="fa-regular fa-lightbulb me-1"></i>Shpjegim</div>
                <div class="p-2 border rounded bg-light">
                  <?= $Parsedown->text((string)$q['explanation']) ?>
                </div>
              </div>
            <?php endif; ?>
          </div>
          <?php endforeach; ?>
        </div>
      <?php else: ?>
        <div class="cardx p-3 text-muted">Ky kuiz nuk ka pyetje.</div>
      <?php endif; ?>

      <div class="text-end mt-3">
        <a href="<?= h($backUrl) ?>" class="btn btn-outline-secondary">
          <i class="fa-solid fa-arrow-left-long me-1"></i>Kthehu te kursi
        </a>
      </div>
    </div>

    <!-- DJATHTAS -->
    <div class="col-12 col-lg-4">
      <div class="sticky-side">
        <div class="cardx p-3 mb-3">
          <h6 class="mb-2 d-flex align-items-center gap-2">
            <i class="bi bi-grid"></i> Paleta e pyetjeve
            <span class="ms-auto small text-muted">Totale: <?= count($questions) ?></span>
          </h6>
          <div class="d-flex flex-wrap gap-2 palette" id="paletteBtns">
            <?php foreach ($questions as $i=>$q): $qid=(int)$q['id']; $cls = $statusByQ[$qid] ?? 'unanswered'; ?>
              <a href="#q<?= $qid ?>" class="btn btn-sm <?= h($cls) ?>" data-qid="<?= $qid ?>" title="Pyetja <?= $i+1 ?>"><?= $i+1 ?></a>
            <?php endforeach; ?>
          </div>
          <div class="small text-muted mt-2">
            <span class="me-2"><span class="badge bg-success-subtle text-success-emphasis"> </span> saktë</span>
            <span class="me-2"><span class="badge bg-danger-subtle text-danger-emphasis"> </span> gabim</span>
            <span><span class="badge bg-secondary-subtle text-secondary-emphasis"> </span> pa përgjigje</span>
          </div>
          <hr>
          <div class="d-grid gap-2">
            <button class="btn btn-outline-primary" id="jumpFirstIncorrect">
              <i class="bi bi-bullseye me-1"></i>Shko te e para gabim
            </button>
          </div>
        </div>

        <div class="cardx p-3">
          <h6 class="mb-2"><i class="bi bi-info-circle"></i> Përmbledhje</h6>
          <ul class="list-unstyled small mb-0">
            <li class="d-flex justify-content-between"><span>Pyetje gjithsej</span><strong><?= count($questions) ?></strong></li>
            <li class="d-flex justify-content-between"><span>Të sakta</span><strong class="text-success"><?= (int)$correctCount ?></strong></li>
            <li class="d-flex justify-content-between"><span>Të gabuara</span><strong class="text-danger"><?= (int)$incorrectCount ?></strong></li>
            <li class="d-flex justify-content-between"><span>Pa përgjigje</span><strong class="text-secondary"><?= (int)$unansweredCount ?></strong></li>
            <li class="d-flex justify-content-between"><span>Pikë</span><strong><?= (int)$score ?>/<?= (int)$totalPoints ?> (<?= (int)$percent ?>%)</strong></li>
          </ul>
        </div>
      </div>
    </div>
  </div>
</main>

<?php if (is_file($ROOT.'/footer2.php')) include $ROOT . '/footer2.php'; ?>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script>
(function(){
  // Pastrim i LocalStorage nga tentativa e sapo-dorëzuar (nëse vjen nga take_quiz)
  (function(){ var q = <?= (int)$lsQ ?>, a = <?= (int)$lsA ?>; if (q && a) { try { localStorage.removeItem('quiz_' + q + '_attempt_' + a); } catch (e) {} } })();

  // Filtrat
  const filtWrap = document.querySelector('.filt');
  const cards = Array.from(document.querySelectorAll('#qWrap .qcard'));
  function applyFilter(type){
    cards.forEach(c=>{
      const st = c.getAttribute('data-status');
      c.style.display = (type==='all' || st===type) ? '' : 'none';
    });
    markCurrentFromScroll();
  }
  if (filtWrap){
    const btns = filtWrap.querySelectorAll('[data-filter]');
    btns.forEach(b=>{
      b.addEventListener('click', ()=>{
        btns.forEach(x=>x.classList.toggle('active', x===b));
        applyFilter(b.getAttribute('data-filter')||'all');
      });
    });
  }

  // Paleta: vendos "current" gjatë scroll
  const palette = document.getElementById('paletteBtns');
  function markCurrentFromScroll(){
    let current = null, top = window.scrollY + 120;
    for (const c of cards){ if (c.offsetParent === null) continue;
      const y=c.getBoundingClientRect().top + window.scrollY;
      if (y<=top) current=c; else break;
    }
    current = current || cards.find(c=>c.offsetParent!==null) || cards[0];
    if(!current) return;
    palette?.querySelectorAll('.btn').forEach(b=>{
      b.classList.toggle('current', ('q'+b.dataset.qid) === current.id);
    });
  }
  window.addEventListener('scroll', markCurrentFromScroll, {passive:true});
  markCurrentFromScroll();

  // Shko te e para gabim (ose pa përgjigje)
  document.getElementById('jumpFirstIncorrect')?.addEventListener('click', ()=>{
    const el = document.querySelector('#qWrap .qcard[data-status="incorrect"]') ||
               document.querySelector('#qWrap .qcard[data-status="unanswered"]');
    if (el){ el.scrollIntoView({behavior:'smooth', block:'start'}); history.replaceState(null,'','#'+el.id); markCurrentFromScroll(); }
  });

  // Zgjero/Kolapso shpjegimin për kartë
  document.querySelectorAll('.toggleExp').forEach(btn=>{
    btn.addEventListener('click', ()=>{
      const card = btn.closest('.qcard'); if (card) card.classList.toggle('collapsed');
    });
  });

  // Kur klikon te paleta, shëno current menjëherë
  palette?.querySelectorAll('a.btn').forEach(btn=>{
    btn.addEventListener('click', ()=>{
      palette.querySelectorAll('a.btn').forEach(b=>b.classList.remove('current'));
      btn.classList.add('current');
    });
  });
})();
</script>
</body>
</html>
