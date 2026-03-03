<?php
declare(strict_types=1);

session_start();
require_once __DIR__ . '/../lib/database.php';
require_once __DIR__ . '/../lib/auth.php';

$u = require_role(['Administrator','Instruktor']);

function h(?string $s): string { return htmlspecialchars($s ?? '', ENT_QUOTES, 'UTF-8'); }

$testId = (int)($_GET['test_id'] ?? 0);
if ($testId <= 0) {
    header('Location: tests.php');
    exit;
}

// Mock data
$test = [
    'id' => $testId,
    'title' => 'Quiz kapitulli 3: Variablat',
    'course_title' => 'Matematika',
    'total_attempts' => 45
];

$results = [
    ['id' => 1, 'student_name' => 'Arjana Duka', 'score' => 85, 'points' => 8.5, 'max_points' => 10, 'status' => 'PASSED', 'time_taken' => '18:32', 'attempt' => 1, 'date' => '30 Jan 2026'],
    ['id' => 2, 'student_name' => 'Besim Hoti', 'score' => 55, 'points' => 5.5, 'max_points' => 10, 'status' => 'FAILED', 'time_taken' => '25:14', 'attempt' => 1, 'date' => '29 Jan 2026'],
    ['id' => 3, 'student_name' => 'Clarida Rama', 'score' => 90, 'points' => 9, 'max_points' => 10, 'status' => 'PASSED', 'time_taken' => '15:47', 'attempt' => 2, 'date' => '30 Jan 2026'],
    ['id' => 4, 'student_name' => 'Denis Tërmaku', 'score' => 70, 'points' => 7, 'max_points' => 10, 'status' => 'PASSED', 'time_taken' => '28:55', 'attempt' => 1, 'date' => '28 Jan 2026'],
    ['id' => 5, 'student_name' => 'Elsa Guri', 'score' => 95, 'points' => 9.5, 'max_points' => 10, 'status' => 'PASSED', 'time_taken' => '12:33', 'attempt' => 1, 'date' => '30 Jan 2026'],
];

$passCount = count(array_filter($results, fn($r) => $r['status'] === 'PASSED'));
$avgScore = array_reduce($results, fn($c, $r) => $c + $r['score'], 0) / count($results);
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
<?php include __DIR__ . '/../navbar_logged_instruktor.php'; ?>

<div class="qc-root">
  <!-- HEADER -->
  <div class="qc-header">
    <div class="qc-header-main">
      <div class="qc-badge">
        <span class="qc-badge-dot"></span>
        <span>Analytics</span>
      </div>
      <h1 class="qc-title">Rezultatet e studentëve</h1>
      <p class="qc-subtitle"><?= h($test['title']) ?></p>
    </div>
  </div>

  <!-- MAIN LAYOUT -->
  <div class="qc-layout">
    <!-- MAIN COLUMN -->
    <div class="qc-main">
      <!-- STATS CARDS -->
      <div class="row g-3 mb-4">
        <div class="col-md-4">
          <div class="qc-card">
            <div style="padding: 20px; text-align: center;">
              <div class="qc-score-stat-label">Total përpjekje</div>
              <div class="qc-score-stat-value" style="font-size: 2rem;"><?= count($results) ?></div>
            </div>
          </div>
        </div>
        <div class="col-md-4">
          <div class="qc-card">
            <div style="padding: 20px; text-align: center;">
              <div class="qc-score-stat-label">Kalime</div>
              <div class="qc-score-stat-value" style="font-size: 2rem; color: var(--success);"><?= $passCount ?></div>
            </div>
          </div>
        </div>
        <div class="col-md-4">
          <div class="qc-card">
            <div style="padding: 20px; text-align: center;">
              <div class="qc-score-stat-label">Mesatar score</div>
              <div class="qc-score-stat-value" style="font-size: 2rem;"><?= round($avgScore, 1) ?>%</div>
            </div>
          </div>
        </div>
      </div>

      <!-- RESULTS TABLE CARD -->
      <div class="qc-card">
        <div class="qc-card-header">
          <h2 class="qc-card-title">Rezultatet sipas studentit</h2>
          <p class="qc-card-subtitle">Shiko detajet e secilit përpjekje</p>
        </div>
        <div class="qc-card-body">
          <div class="table-responsive">
            <table class="table mb-0">
              <thead>
                <tr>
                  <th style="font-weight: 700; color: #111827;">Emri i studentit</th>
                  <th style="font-weight: 700; color: #111827; text-align: center;">Score</th>
                  <th style="font-weight: 700; color: #111827; text-align: center;">Pikë</th>
                  <th style="font-weight: 700; color: #111827; text-align: center;">Status</th>
                  <th style="font-weight: 700; color: #111827; text-align: center;">Koha</th>
                  <th style="font-weight: 700; color: #111827; text-align: center;">Përpjekje</th>
                  <th style="font-weight: 700; color: #111827; text-align: center;">Data</th>
                  <th style="font-weight: 700; color: #111827; text-align: center;">Aksion</th>
                </tr>
              </thead>
              <tbody>
                <?php foreach ($results as $r): ?>
                  <tr style="border-bottom: 1px solid var(--border);">
                    <td style="padding: 12px 0; color: #111827; font-weight: 500;">
                      <?= h($r['student_name']) ?>
                    </td>
                    <td style="padding: 12px 0; text-align: center;">
                      <strong style="color: var(--primary);"><?= $r['score'] ?>%</strong>
                    </td>
                    <td style="padding: 12px 0; text-align: center;">
                      <span><?= $r['points'] ?> / <?= $r['max_points'] ?></span>
                    </td>
                    <td style="padding: 12px 0; text-align: center;">
                      <?php if ($r['status'] === 'PASSED'): ?>
                        <span class="qc-badge-success" style="display: inline-block;">✓ Kaloi</span>
                      <?php else: ?>
                        <span class="qc-badge-danger" style="display: inline-block;">✗ Dështoi</span>
                      <?php endif; ?>
                    </td>
                    <td style="padding: 12px 0; text-align: center;">
                      <span style="font-family: 'Monaco', monospace;"><?= $r['time_taken'] ?></span>
                    </td>
                    <td style="padding: 12px 0; text-align: center;">
                      <?= $r['attempt'] ?>
                    </td>
                    <td style="padding: 12px 0; text-align: center;">
                      <span style="font-size: .85rem; color: var(--muted);"><?= $r['date'] ?></span>
                    </td>
                    <td style="padding: 12px 0; text-align: center;">
                      <button class="btn btn-sm btn-outline-secondary" onclick="viewDetails(<?= $r['id'] ?>)">Detaje</button>
                    </td>
                  </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
          </div>
        </div>
      </div>
    </div>

    <!-- SIDE COLUMN -->
    <div class="qc-side">
      <!-- FILTERS CARD -->
      <div class="qc-side-card qc-side-preview">
        <div style="padding: 16px 20px;">
          <h3 class="qc-side-title mb-3">Filtrat</h3>
          
          <div class="mb-3">
            <label class="form-label" style="font-size: .85rem;">Status</label>
            <div class="form-check">
              <input class="form-check-input" type="checkbox" id="fPassed" checked>
              <label class="form-check-label" for="fPassed" style="font-size: .9rem;">
                Kaloi
              </label>
            </div>
            <div class="form-check">
              <input class="form-check-input" type="checkbox" id="fFailed" checked>
              <label class="form-check-label" for="fFailed" style="font-size: .9rem;">
                Dështoi
              </label>
            </div>
          </div>

          <div class="mb-3">
            <label class="form-label" style="font-size: .85rem;">Sorto sipas</label>
            <select class="form-select form-select-sm">
              <option>Score (zbritës)</option>
              <option>Score (rritës)</option>
              <option>Data (më të reja)</option>
              <option>Data (më të vjetra)</option>
            </select>
          </div>

          <button class="btn btn-primary btn-sm w-100">Zbato filtrat</button>

          <h4 class="qc-side-title mb-2 mt-4">Export</h4>
          <div class="d-flex flex-column gap-2">
            <button class="btn btn-sm btn-outline-secondary w-100">Shkarko CSV</button>
            <button class="btn btn-sm btn-outline-secondary w-100">Shkarko PDF</button>
          </div>
        </div>
      </div>
    </div>
  </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script>
function viewDetails(resultId) {
  alert('Shikimi i detajeve për rezultatin ' + resultId);
  // location.href = `result_detail.php?result_id=${resultId}`;
}
</script>
</body>
</html>
