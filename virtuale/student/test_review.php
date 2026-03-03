<?php
declare(strict_types=1);

session_start();
require_once __DIR__ . '/../lib/database.php';
require_once __DIR__ . '/../lib/auth.php';

$u = require_role(['Student']);

function h(?string $s): string { return htmlspecialchars($s ?? '', ENT_QUOTES, 'UTF-8'); }

$attempt_id = isset($_GET['attempt_id']) ? (int)$_GET['attempt_id'] : 0;
if ($attempt_id <= 0) { die('Attempt i pavlefshëm'); }

$st = $pdo->prepare(
  "SELECT a.*, t.title AS test_title, t.show_results_mode, t.show_correct_answers_mode, t.due_at, t.results_published_at
   FROM test_attempts a
   JOIN tests t ON t.id = a.test_id
   WHERE a.id=? AND a.user_id=?"
);
$st->execute([$attempt_id, (int)$u['id']]);
$attempt = $st->fetch(PDO::FETCH_ASSOC);
if (!$attempt) { die('Nuk u gjet.'); }

$now = new DateTime('now', new DateTimeZone('UTC'));
$due_at = $attempt['due_at'] ? new DateTime((string)$attempt['due_at'], new DateTimeZone('UTC')) : null;

$canViewResults = false;
$mode = (string)$attempt['show_results_mode'];
if ($mode === 'IMMEDIATE') $canViewResults = true;
if ($mode === 'AFTER_DUE' && $due_at && $now > $due_at) $canViewResults = true;
if ($mode === 'MANUAL' && !empty($attempt['results_published_at'])) $canViewResults = true;

$canViewCorrect = false;
$cMode = (string)$attempt['show_correct_answers_mode'];
if ($cMode === 'IMMEDIATE') $canViewCorrect = true;
if ($cMode === 'AFTER_DUE' && $due_at && $now > $due_at) $canViewCorrect = true;

$questions = [];
$answers = [];
$scores = [];
if ($canViewResults) {
  $stQ = $pdo->prepare(
    "SELECT q.*, tq.points_override
     FROM test_questions tq
     JOIN question_bank q ON q.id=tq.question_id
     WHERE tq.test_id=?
     ORDER BY tq.position ASC"
  );
  $stQ->execute([(int)$attempt['test_id']]);
  $questions = $stQ->fetchAll(PDO::FETCH_ASSOC) ?: [];

  $qIds = array_map(fn($q) => (int)$q['id'], $questions);
  if ($qIds) {
    $in = implode(',', array_fill(0, count($qIds), '?'));
    $stOpt = $pdo->prepare("SELECT * FROM question_options WHERE question_id IN ($in) ORDER BY position ASC");
    $stOpt->execute($qIds);
    $opts = $stOpt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    $optsByQ = [];
    foreach ($opts as $o) { $optsByQ[(int)$o['question_id']][] = $o; }
    foreach ($questions as &$q) { $q['options'] = $optsByQ[(int)$q['id']] ?? []; }
  }

  $stA = $pdo->prepare('SELECT * FROM attempt_answers WHERE attempt_id=?');
  $stA->execute([$attempt_id]);
  $answers = $stA->fetchAll(PDO::FETCH_ASSOC) ?: [];
  $ansByQ = [];
  foreach ($answers as $a) { $ansByQ[(int)$a['question_id']][] = $a; }
  $answers = $ansByQ;

  $stS = $pdo->prepare('SELECT * FROM attempt_question_scores WHERE attempt_id=?');
  $stS->execute([$attempt_id]);
  $scores = $stS->fetchAll(PDO::FETCH_ASSOC) ?: [];
  $scoreByQ = [];
  foreach ($scores as $s) { $scoreByQ[(int)$s['question_id']] = $s; }
  $scores = $scoreByQ;
}

?>
<!DOCTYPE html>
<html lang="sq">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Review — <?= h($attempt['test_title']) ?></title>
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css">
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css">
  <link rel="stylesheet" href="../css/km-lms-forms.css">
  <link rel="stylesheet" href="../css/km-tests-forms.css">
</head>
<body class="km-body">
<?php include __DIR__ . '/../navbar_logged_student.php'; ?>
<div class="container km-page-shell">

  <div class="km-page-header">
    <div class="d-flex align-items-start align-items-lg-center justify-content-between gap-3 flex-wrap">
      <div class="flex-grow-1">
        <div class="km-breadcrumb small">
          <a class="km-breadcrumb-link" href="tests.php">Student / Testet</a>
          <span class="km-breadcrumb-current"> / Review</span>
        </div>
        <h1 class="km-page-title">
          <i class="fa-solid fa-clipboard-check me-2 text-primary"></i>
          Review: <?= h($attempt['test_title']) ?>
        </h1>
        <div class="km-page-subtitle">Shiko rezultatin dhe (nëse lejohet) përgjigjet e sakta.</div>
      </div>
      <a class="btn btn-sm btn-outline-secondary km-btn-pill" href="tests.php">
        <i class="fa-solid fa-arrow-left me-1"></i> Kthehu
      </a>
    </div>
  </div>

  <?php if (!$canViewResults): ?>
    <div class="alert alert-info">Rezultatet nuk janë të disponueshme ende.</div>
  <?php else: ?>
    <div class="km-card mt-3">
      <div class="km-card-header">
        <div>
          <h2 class="km-card-title"><span class="km-step-badge">1</span> Përmbledhje</h2>
          <div class="km-card-subtitle">Statusi dhe rezultati</div>
        </div>
      </div>
      <div class="km-card-body">
        <div class="d-flex gap-2 flex-wrap">
          <span class="km-badge km-badge-muted"><i class="fa-solid fa-circle-info"></i> <?= h($attempt['status']) ?></span>
          <span class="km-badge km-badge-secondary"><i class="fa-solid fa-star"></i> <?= h((string)$attempt['score_points']) ?> / <?= h((string)$attempt['total_points']) ?></span>
          <span class="km-badge"><i class="fa-solid fa-percent"></i> <?= h((string)$attempt['percentage']) ?>%</span>
        </div>
      </div>
    </div>

    <?php foreach ($questions as $q): ?>
      <?php $qid = (int)$q['id']; $ans = $answers[$qid] ?? []; $score = $scores[$qid] ?? null; ?>
      <div class="km-card mt-3">
        <div class="km-card-header">
          <div>
            <h2 class="km-card-title"><span class="km-step-badge">Q</span> #<?= $qid ?> — <?= h($q['text']) ?></h2>
          </div>
        </div>
        <div class="km-card-body">
          <?php if (in_array($q['type'], ['MC_SINGLE','MC_MULTI','TRUE_FALSE'], true)): ?>
            <ul class="list-group">
              <?php foreach ($q['options'] as $opt): ?>
                <?php
                  $selected = false;
                  foreach ($ans as $a) { if ((int)$a['option_id'] === (int)$opt['id']) { $selected = true; break; } }
                  $isCorrect = (int)($opt['is_correct'] ?? 0) === 1;
                ?>
                <li class="list-group-item <?= $selected ? 'active' : '' ?>">
                  <?= h($opt['option_text']) ?>
                  <?php if ($canViewCorrect && $isCorrect): ?>
                    <span class="badge bg-success ms-2">Correct</span>
                  <?php endif; ?>
                </li>
              <?php endforeach; ?>
            </ul>
          <?php else: ?>
            <div><strong>Answer:</strong> <?= h((string)($ans[0]['answer_text'] ?? '')) ?></div>
            <?php if ($canViewCorrect): ?>
              <?php
                $correct = '';
                foreach (($q['options'] ?? []) as $opt) { if ((int)$opt['is_correct'] === 1) { $correct = (string)$opt['option_text']; break; } }
              ?>
              <div class="text-muted">Correct: <?= h($correct) ?></div>
            <?php endif; ?>
          <?php endif; ?>
          <?php if ($score && isset($score['points_awarded'])): ?>
            <div class="mt-2 text-muted">Points: <?= h((string)$score['points_awarded']) ?></div>
          <?php endif; ?>
        </div>
      </div>
    <?php endforeach; ?>
  <?php endif; ?>
</div>

<?php include __DIR__ . '/../footer2.php'; ?>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
