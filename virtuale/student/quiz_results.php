<?php
declare(strict_types=1);

session_start();
require_once __DIR__ . '/../lib/database.php';
require_once __DIR__ . '/../lib/auth.php';

$u = require_role(['Student']);

function h(?string $s): string { return htmlspecialchars($s ?? '', ENT_QUOTES, 'UTF-8'); }

$testId = (int)($_GET['test_id'] ?? 0);
if ($testId <= 0) {
    header('Location: ../courses_student.php');
    exit;
}

// Mock result data
$result = [
    'score' => 85,
    'pass_score' => 60,
    'passed' => true,
    'points' => 8.5,
    'total_points' => 10,
    'time_taken' => '18 min 32 sec',
    'attempt' => 1,
    'max_attempts' => 3,
    'test_title' => 'Quiz kapitulli 3: Variablat',
    'course_title' => 'Matematika - 1. semester',
    'date' => '30 Jan 2026'
];

$reviews = [
    [
        'num' => 1,
        'question' => 'Cila është formula për llogaritjen e sipërfaqes të drejtkëndëshit?',
        'correct' => true,
        'user_answer' => 'B',
        'user_answer_text' => 'Gjatësia × gjerësia',
        'correct_answer' => 'B',
        'correct_answer_text' => 'Gjatësia × gjerësia',
        'points' => 1,
        'explanation' => 'Sipërfaqja e drejtkëndëshit llogaritet duke shumëzuar gjatësinë me gjerësinë.'
    ],
    [
        'num' => 2,
        'question' => 'Cila është rrënja katror e 144?',
        'correct' => false,
        'user_answer' => 'A',
        'user_answer_text' => '10',
        'correct_answer' => 'C',
        'correct_answer_text' => '12',
        'points' => 0,
        'max_points' => 1,
        'explanation' => 'Rrënja katror e 144 është 12 sepse 12 × 12 = 144.'
    ]
];
?>
<!DOCTYPE html>
<html lang="sq">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Rezultatet e testit | kurseinformatike.com</title>
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css">
  <link rel="stylesheet" href="../css/qc-tests.css">
</head>
<body>
<?php include __DIR__ . '/../navbar_logged_student.php'; ?>

<div class="qc-root">
  <!-- HEADER -->
  <div class="qc-header">
    <div class="qc-header-main">
      <div class="qc-badge" style="background: #dbeafe; border-color: #bfdbfe; color: #1e40af;">
        <span class="qc-badge-dot" style="background: #3b82f6;"></span>
        <span>Përfunduar</span>
      </div>
      <h1 class="qc-title">Rezultatet e testit</h1>
      <p class="qc-subtitle"><?= h($result['test_title']) ?></p>
    </div>
  </div>

  <!-- MAIN LAYOUT -->
  <div class="qc-layout">
    <!-- MAIN COLUMN -->
    <div class="qc-main">
      <!-- SCORE CARD (BIG) -->
      <div class="qc-card qc-score-card">
        <div style="padding: 32px 24px; text-align: center;">
          <h2 class="qc-score-label">Rezultati juaj</h2>
          <div class="qc-score-big"><?= $result['score'] ?>%</div>
          <p class="qc-score-status qc-score-status-<?= $result['passed'] ? 'pass' : 'fail' ?>">
            ✓ Testimi u <?= $result['passed'] ? 'kalua' : 'dështoi' ?> (Pass Score: <?= $result['pass_score'] ?>%)
          </p>
          <div class="row g-3 mt-4">
            <div class="col-md-4">
              <div class="qc-score-stat">
                <div class="qc-score-stat-label">Pikë</div>
                <div class="qc-score-stat-value"><?= $result['points'] ?> / <?= $result['total_points'] ?></div>
              </div>
            </div>
            <div class="col-md-4">
              <div class="qc-score-stat">
                <div class="qc-score-stat-label">Koha</div>
                <div class="qc-score-stat-value"><?= $result['time_taken'] ?></div>
              </div>
            </div>
            <div class="col-md-4">
              <div class="qc-score-stat">
                <div class="qc-score-stat-label">Përpjekje</div>
                <div class="qc-score-stat-value"><?= $result['attempt'] ?> / <?= $result['max_attempts'] ?></div>
              </div>
            </div>
          </div>
          <div class="mt-4">
            <a href="../courses_student.php" class="btn btn-primary">Kthehu në kurs</a>
            <button class="btn btn-outline-secondary" onclick="window.print();">Përmbledh rezultatin</button>
          </div>
        </div>
      </div>

      <!-- REVIEW SECTION -->
      <div class="qc-card">
        <div class="qc-card-header">
          <h2 class="qc-card-title">Rishikim përgjigjesh</h2>
          <p class="qc-card-subtitle">Shiko përgjigjet dhe shpjegimet</p>
        </div>
        <div class="qc-card-body">
          <?php foreach ($reviews as $review): ?>
            <div class="qc-review-item <?= !$review['correct'] ? 'mt-4' : '' ?>">
              <div class="qc-review-head">
                <h4 class="qc-review-question"><?= $review['num'] ?>. <?= h($review['question']) ?></h4>
                <div class="qc-review-badges">
                  <?php if ($review['correct']): ?>
                    <span class="qc-badge-success">✓ Saktë</span>
                  <?php else: ?>
                    <span class="qc-badge-danger">✗ Gabim</span>
                  <?php endif; ?>
                  <span class="qc-badge-points"><?= $review['points'] ?> / <?= $review['max_points'] ?? 1 ?> pikë</span>
                </div>
              </div>
              <div class="qc-review-body">
                <p class="qc-review-label">Përgjigjja juaj:</p>
                <div class="qc-review-answer <?= $review['correct'] ? 'qc-review-answer-correct' : 'qc-review-answer-incorrect' ?>">
                  <span class="qc-answer-letter"><?= $review['user_answer'] ?></span>
                  <span><?= h($review['user_answer_text']) ?></span>
                </div>

                <?php if (!$review['correct']): ?>
                  <p class="qc-review-label mt-3">Përgjigja e saktë:</p>
                  <div class="qc-review-answer qc-review-answer-correct">
                    <span class="qc-answer-letter"><?= $review['correct_answer'] ?></span>
                    <span><?= h($review['correct_answer_text']) ?></span>
                  </div>
                <?php endif; ?>

                <p class="qc-review-label mt-3">Shpjegim:</p>
                <p class="qc-review-explanation">
                  <?= h($review['explanation']) ?>
                </p>
              </div>
            </div>
          <?php endforeach; ?>
        </div>
      </div>
    </div>

    <!-- SIDE COLUMN -->
    <div class="qc-side">
      <!-- SUMMARY CARD -->
      <div class="qc-side-card qc-side-preview">
        <div style="padding: 16px 20px;">
          <h3 class="qc-side-title mb-3">Përmbledhje</h3>
          <div class="qc-preview-row">
            <span class="qc-preview-label">Total pyetje:</span>
            <span class="qc-preview-value">10</span>
          </div>
          <div class="qc-preview-row">
            <span class="qc-preview-label">Saktë:</span>
            <span class="qc-preview-value">8</span>
          </div>
          <div class="qc-preview-row">
            <span class="qc-preview-label">Gabim:</span>
            <span class="qc-preview-value">2</span>
          </div>
          <div class="qc-preview-row">
            <span class="qc-preview-label">Pass Score:</span>
            <span class="qc-preview-value"><?= $result['pass_score'] ?>%</span>
          </div>
          <div class="qc-preview-row">
            <span class="qc-preview-label">Kohëzgjatja:</span>
            <span class="qc-preview-value"><?= $result['time_taken'] ?></span>
          </div>
          <div class="qc-preview-row">
            <span class="qc-preview-label">Data:</span>
            <span class="qc-preview-value"><?= $result['date'] ?></span>
          </div>
        </div>
      </div>

      <!-- ACTIONS CARD -->
      <div class="qc-side-card">
        <div style="padding: 16px 20px;">
          <h4 class="qc-side-title mb-3">Më shumë veprime</h4>
          <div class="d-flex flex-column gap-2">
            <button class="btn btn-sm btn-outline-secondary w-100" onclick="window.print();">Shkarko PDF</button>
            <button class="btn btn-sm btn-outline-secondary w-100" onclick="window.print();">Çdo nga printi</button>
            <?php if ($result['attempt'] < $result['max_attempts']): ?>
              <button class="btn btn-sm btn-outline-secondary w-100" onclick="location.href='take_quiz.php?test_id=<?= $testId ?>';">Ribëje testin</button>
            <?php endif; ?>
          </div>
        </div>
      </div>
    </div>
  </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
