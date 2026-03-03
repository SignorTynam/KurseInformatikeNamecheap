<?php
declare(strict_types=1);

session_start();
require_once __DIR__ . '/../lib/database.php';
require_once __DIR__ . '/../lib/auth.php';
require_once __DIR__ . '/../lib/csrf.php';

$u = require_role(['Administrator','Instruktor']);
$CSRF = csrf_token();

function h(?string $s): string { return htmlspecialchars($s ?? '', ENT_QUOTES, 'UTF-8'); }

$attempt_id = isset($_GET['attempt_id']) ? (int)$_GET['attempt_id'] : 0;
if ($attempt_id <= 0) { die('Attempt i pavlefshëm'); }

$st = $pdo->prepare("\
  SELECT a.*, t.title AS test_title, t.id AS test_id, c.id_creator
  FROM test_attempts a
  JOIN tests t ON t.id=a.test_id
  JOIN courses c ON c.id=t.course_id
  WHERE a.id=?
");
$st->execute([$attempt_id]);
$attempt = $st->fetch(PDO::FETCH_ASSOC);
if (!$attempt) die('Attempt nuk u gjet');
if (!is_admin($u) && (int)$attempt['id_creator'] !== (int)$u['id']) die('Forbidden');

$stQ = $pdo->prepare("\
  SELECT q.*, tq.points_override, aqs.needs_manual
  FROM test_questions tq
  JOIN question_bank q ON q.id=tq.question_id
  LEFT JOIN attempt_question_scores aqs ON aqs.question_id=q.id AND aqs.attempt_id=?
  WHERE tq.test_id=?
  ORDER BY tq.position ASC
");
$stQ->execute([$attempt_id, (int)$attempt['test_id']]);
$questions = $stQ->fetchAll(PDO::FETCH_ASSOC) ?: [];

$stAns = $pdo->prepare('SELECT * FROM attempt_answers WHERE attempt_id=?');
$stAns->execute([$attempt_id]);
$answers = $stAns->fetchAll(PDO::FETCH_ASSOC) ?: [];
$ansByQ = [];
foreach ($answers as $a) {
  $qid = (int)$a['question_id'];
  if (!isset($ansByQ[$qid])) $ansByQ[$qid] = [];
  $ansByQ[$qid][] = $a;
}

?>
<!DOCTYPE html>
<html lang="sq">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Manual Grade</title>
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css">
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css">
  <link rel="stylesheet" href="../css/km-lms-forms.css">
  <link rel="stylesheet" href="../css/km-tests-forms.css">
</head>
<body class="km-body">
<?php
  if (is_admin($u)) {
    include __DIR__ . '/../navbar_logged_administrator.php';
  } else {
    include __DIR__ . '/../navbar_logged_instruktor.php';
  }
?>
<div class="container km-page-shell">

  <div class="km-page-header">
    <div class="d-flex align-items-start align-items-lg-center justify-content-between gap-3 flex-wrap">
      <div class="flex-grow-1">
        <div class="km-breadcrumb small">
          <a class="km-breadcrumb-link" href="test_results.php?test_id=<?= (int)$attempt['test_id'] ?>">Rezultate</a>
          <span class="km-breadcrumb-current"> / Manual grade</span>
        </div>
        <h1 class="km-page-title">
          <i class="fa-solid fa-marker me-2 text-primary"></i>
          Manual Grade — <?= h($attempt['test_title']) ?>
        </h1>
        <div class="km-page-subtitle">Vlerëso vetëm pyetjet që kërkojnë ndërhyrje manuale.</div>
      </div>

      <div class="d-flex align-items-center gap-2 flex-wrap">
        <a class="btn btn-sm btn-outline-secondary km-btn-pill" href="test_results.php?test_id=<?= (int)$attempt['test_id'] ?>">
          <i class="fa-solid fa-arrow-left me-1"></i> Kthehu
        </a>
      </div>
    </div>
  </div>

  <?php foreach ($questions as $q): ?>
    <?php if ((int)($q['needs_manual'] ?? 0) !== 1) continue; ?>
    <?php $qid = (int)$q['id']; $pts = (float)($q['points_override'] ?? $q['points']); ?>
    <div class="km-card mt-3">
      <div class="km-card-header">
        <div>
          <h2 class="km-card-title"><span class="km-step-badge">Q</span> #<?= $qid ?> — <?= h($q['text']) ?></h2>
          <div class="km-card-subtitle">Max: <?= h((string)$pts) ?> points</div>
        </div>
      </div>
      <div class="km-card-body">
        <p><strong>Përgjigja e studentit:</strong> <?= h((string)($ansByQ[$qid][0]['answer_text'] ?? '')) ?></p>
        <div class="row g-2">
          <div class="col-md-3">
            <label class="form-label">Points (0 - <?= h((string)$pts) ?>)</label>
            <input type="number" class="form-control" step="0.5" min="0" max="<?= h((string)$pts) ?>" id="points-<?= $qid ?>">
          </div>
          <div class="col-md-9">
            <label class="form-label">Feedback</label>
            <input type="text" class="form-control" id="feedback-<?= $qid ?>">
          </div>
        </div>
        <div class="km-tests-actionbar mt-3">
          <div class="km-tests-sumrow">
            <span class="km-badge km-badge-muted"><i class="fa-solid fa-check"></i> Manual</span>
            <span id="msg-<?= $qid ?>" class="km-help-text"></span>
          </div>
          <div class="km-tests-actionbar-right">
            <button class="btn btn-primary km-btn-pill" onclick="grade(<?= $qid ?>, <?= (float)$pts ?>)">
              <i class="fa-solid fa-floppy-disk me-1"></i> Save
            </button>
          </div>
        </div>
      </div>
    </div>
  <?php endforeach; ?>
</div>

<?php include __DIR__ . '/../footer2.php'; ?>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>

<script>
const CSRF = <?= json_encode($CSRF) ?>;
const attemptId = <?= (int)$attempt_id ?>;

async function grade(questionId, maxPoints) {
  const points = parseFloat(document.getElementById('points-' + questionId).value || '0');
  const feedback = document.getElementById('feedback-' + questionId).value || '';
  if (points < 0 || points > maxPoints) { alert('Invalid points'); return; }
  const res = await fetch('../api/attempts_manual_grade.php', {
    method: 'POST',
    headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': CSRF },
    body: JSON.stringify({ attempt_id: attemptId, question_id: questionId, points_awarded: points, feedback })
  });
  const data = await res.json();
  const msg = document.getElementById('msg-' + questionId);
  msg.textContent = data.ok ? 'Saved' : data.error;
}
</script>
</body>
</html>
