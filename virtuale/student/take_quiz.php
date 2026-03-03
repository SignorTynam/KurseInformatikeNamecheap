<?php
declare(strict_types=1);

session_start();
require_once __DIR__ . '/../lib/database.php';
require_once __DIR__ . '/../lib/auth.php';

$u = require_role(['Student']);

function h(?string $s): string { return htmlspecialchars($s ?? '', ENT_QUOTES, 'UTF-8'); }

// Get quiz ID from URL
$testId = (int)($_GET['test_id'] ?? 0);
if ($testId <= 0) {
    header('Location: ../courses_student.php');
    exit;
}

// Mock data for demo
$test = [
    'id' => $testId,
    'title' => 'Quiz kapitulli 3: Variablat',
    'course_title' => 'Matematika - 1. semester',
    'time_limit_minutes' => 30
];

$questions = [
    [
        'id' => 1,
        'text' => 'Cila është formula për llogaritjen e sipërfaqes të drejtkëndëshit?',
        'type' => 'multiple_choice',
        'points' => 1,
        'options' => [
            'a' => 'Gjatësia + gjerësia',
            'b' => 'Gjatësia × gjerësia',
            'c' => '(Gjatësia + gjerësia) × 2',
            'd' => 'Gjatësia / gjerësia'
        ]
    ],
    [
        'id' => 2,
        'text' => 'Cila është rrënja katror e 144?',
        'type' => 'multiple_choice',
        'points' => 1,
        'options' => [
            'a' => '10',
            'b' => '11',
            'c' => '12',
            'd' => '13'
        ]
    ]
];

$currentQuestion = 1;
$totalQuestions = count($questions);
$answeredCount = 0;
$timeRemaining = $test['time_limit_minutes'] * 60;
?>
<!DOCTYPE html>
<html lang="sq">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Dhënie Testi | kurseinformatike.com</title>
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css">
  <link rel="stylesheet" href="../css/qc-tests.css">
</head>
<body>
<?php include __DIR__ . '/../navbar_logged_student.php'; ?>

<div class="qc-root">
  <!-- HEADER COMPACT -->
  <div class="qc-header qc-header-compact">
    <div class="qc-header-main">
      <h1 class="qc-title"><?= h($test['title']) ?></h1>
      <p class="qc-subtitle"><?= h($test['course_title']) ?></p>
    </div>
    <div class="qc-header-meta">
      <!-- TIMER PILL -->
      <div class="qc-timer-pill qc-timer-pill-warning">
        <span class="qc-timer-icon">⏱</span>
        <span class="qc-timer-text" id="timerDisplay">12:34</span>
      </div>
    </div>
  </div>

  <!-- MAIN LAYOUT: STUDENT -->
  <div class="qc-layout qc-layout-student">
    <!-- MAIN COLUMN: QUESTION -->
    <div class="qc-main">
      <!-- PROGRESS BAR TOP MOBILE -->
      <div class="qc-progress-bar-top d-lg-none">
        <div class="qc-progress-bar-label">Pyetja <?= $currentQuestion ?> / <?= $totalQuestions ?></div>
        <div class="progress" role="progressbar">
          <div class="progress-bar" style="width: <?= ($currentQuestion / $totalQuestions) * 100 ?>%"></div>
        </div>
      </div>

      <!-- QUESTION CARD -->
      <div class="qc-question-card">
        <div class="qc-question-head">
          <h2 class="qc-question-title"><?= $currentQuestion ?>. <?= h($questions[0]['text']) ?></h2>
          <span class="qc-question-score"><?= $questions[0]['points'] ?> pikë</span>
        </div>

        <!-- ANSWER OPTIONS -->
        <form id="answerForm" class="qc-answer-options">
          <label class="qc-answer-option">
            <input type="radio" name="question_<?= $questions[0]['id'] ?>" value="a" class="qc-answer-input">
            <div class="qc-answer-option-body">
              <span class="qc-answer-letter">A</span>
              <span class="qc-answer-text">Gjatësia + gjerësia</span>
            </div>
          </label>

          <label class="qc-answer-option">
            <input type="radio" name="question_<?= $questions[0]['id'] ?>" value="b" class="qc-answer-input">
            <div class="qc-answer-option-body">
              <span class="qc-answer-letter">B</span>
              <span class="qc-answer-text">Gjatësia × gjerësia</span>
            </div>
          </label>

          <label class="qc-answer-option">
            <input type="radio" name="question_<?= $questions[0]['id'] ?>" value="c" class="qc-answer-input">
            <div class="qc-answer-option-body">
              <span class="qc-answer-letter">C</span>
              <span class="qc-answer-text">(Gjatësia + gjerësia) × 2</span>
            </div>
          </label>

          <label class="qc-answer-option">
            <input type="radio" name="question_<?= $questions[0]['id'] ?>" value="d" class="qc-answer-input">
            <div class="qc-answer-option-body">
              <span class="qc-answer-letter">D</span>
              <span class="qc-answer-text">Gjatësia / gjerësia</span>
            </div>
          </label>
        </form>
      </div>

      <!-- FOOTER NAVIGATION -->
      <div class="qc-footer">
        <div class="d-flex justify-content-between align-items-center flex-wrap gap-2">
          <button class="btn btn-outline-secondary" disabled>← Pyetja e mëparshme</button>
          <span class="qc-question-counter"><?= $currentQuestion ?> / <?= $totalQuestions ?></span>
          <button class="btn btn-primary" onclick="nextQuestion()">Pyetja tjetër →</button>
        </div>
      </div>
    </div>

    <!-- SIDE COLUMN: NAVIGATOR + STATUS -->
    <div class="qc-side qc-side-student">
      <!-- STATUS CARD -->
      <div class="qc-side-card">
        <div style="padding: 16px 20px;">
          <h3 class="qc-side-title mb-3">Status</h3>
          <div class="qc-status-row mb-3">
            <span class="qc-status-label">Përfundim:</span>
            <span class="qc-status-value">15 min përpara</span>
          </div>
          <div class="qc-status-row mb-3">
            <span class="qc-status-label">Përgjigje:</span>
            <span class="qc-status-value"><?= $answeredCount ?> / <?= $totalQuestions ?></span>
          </div>
          <button class="btn btn-outline-danger w-100 btn-sm" onclick="if(confirm('Jeni i sigurt?')) location.href='../courses_student.php';">Përfundo testin</button>
        </div>
      </div>

      <!-- QUESTION NAVIGATOR (STICKY) -->
      <div class="qc-side-card qc-side-preview">
        <div style="padding: 16px 20px;">
          <h3 class="qc-side-title mb-3">Navigim pyetjesh</h3>
          <div class="qc-question-nav">
            <?php for ($i = 1; $i <= min(10, $totalQuestions); $i++): ?>
              <button 
                class="qc-question-nav-item <?= $i === $currentQuestion ? 'qc-question-nav-item-current' : ($i <= $answeredCount ? 'qc-question-nav-item-answered' : '') ?>" 
                title="Pyetja <?= $i ?>"
                onclick="goToQuestion(<?= $i ?>); return false;"
              >
                <?= $i ?>
              </button>
            <?php endfor; ?>
          </div>
          <div class="qc-nav-legend mt-3">
            <div class="qc-nav-legend-item mb-1">
              <span class="qc-nav-dot qc-nav-dot-answered"></span> Përgjigje
            </div>
            <div class="qc-nav-legend-item mb-1">
              <span class="qc-nav-dot qc-nav-dot-current"></span> Aktual
            </div>
            <div class="qc-nav-legend-item">
              <span class="qc-nav-dot qc-nav-dot-empty"></span> Pa përgjigje
            </div>
          </div>
        </div>
      </div>
    </div>
  </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script>
let currentQuestion = <?= $currentQuestion ?>;
let totalQuestions = <?= $totalQuestions ?>;
let timeRemaining = <?= $timeRemaining ?>;

// Timer
function updateTimer() {
  const min = Math.floor(timeRemaining / 60);
  const sec = timeRemaining % 60;
  document.getElementById('timerDisplay').textContent = `${min}:${sec.toString().padStart(2, '0')}`;
  if (timeRemaining > 0) timeRemaining--;
}
setInterval(updateTimer, 1000);

function nextQuestion() {
  if (currentQuestion < totalQuestions) {
    currentQuestion++;
    location.href = `?test_id=<?= $testId ?>&q=${currentQuestion}`;
  }
}

function goToQuestion(q) {
  location.href = `?test_id=<?= $testId ?>&q=${q}`;
}
</script>
</body>
</html>
