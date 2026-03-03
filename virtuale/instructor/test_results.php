<?php
declare(strict_types=1);

session_start();
require_once __DIR__ . '/../lib/database.php';
require_once __DIR__ . '/../lib/auth.php';
require_once __DIR__ . '/../lib/csrf.php';
require_once __DIR__ . '/../lib/test_services.php';

$u = require_role(['Administrator','Instruktor']);
$CSRF = csrf_token();

function h(?string $s): string { return htmlspecialchars($s ?? '', ENT_QUOTES, 'UTF-8'); }

$test_id = isset($_GET['test_id']) ? (int)$_GET['test_id'] : 0;
if ($test_id <= 0) { die('Test i pavlefshëm'); }

$test = TestService::ensureInstructorAccess($pdo, $test_id, (int)$u['id'], is_admin($u));

$from = trim((string)($_GET['from'] ?? ''));
$to = trim((string)($_GET['to'] ?? ''));
$minScore = trim((string)($_GET['min'] ?? ''));
$maxScore = trim((string)($_GET['max'] ?? ''));

$where = ['a.test_id = :test_id'];
$params = [':test_id' => $test_id];
if ($from !== '') { $where[] = 'a.submitted_at >= :from'; $params[':from'] = $from; }
if ($to !== '') { $where[] = 'a.submitted_at <= :to'; $params[':to'] = $to; }
if ($minScore !== '') { $where[] = 'a.percentage >= :min'; $params[':min'] = (float)$minScore; }
if ($maxScore !== '') { $where[] = 'a.percentage <= :max'; $params[':max'] = (float)$maxScore; }

$sql = "SELECT a.*, u.full_name, u.email FROM test_attempts a JOIN users u ON u.id=a.user_id WHERE " . implode(' AND ', $where) . " ORDER BY a.submitted_at DESC";
$st = $pdo->prepare($sql);
$st->execute($params);
$attempts = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];

?>
<!DOCTYPE html>
<html lang="sq">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Rezultatet | <?= h($test['title']) ?></title>
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
          <a class="km-breadcrumb-link" href="tests.php">Testet</a>
          <span class="km-breadcrumb-current"> / Rezultate</span>
        </div>
        <h1 class="km-page-title">
          <i class="fa-solid fa-square-poll-vertical me-2 text-primary"></i>
          Rezultatet: <?= h($test['title']) ?>
        </h1>
        <div class="km-page-subtitle">Filtro përpjekjet dhe menaxho publikimin e rezultateve.</div>
      </div>

      <div class="d-flex align-items-center gap-2 flex-wrap">
        <a class="btn btn-sm btn-outline-secondary km-btn-pill" href="test_edit.php?test_id=<?= (int)$test_id ?>">
          <i class="fa-solid fa-pen-to-square me-1"></i> Edit
        </a>
        <?php if ((string)$test['show_results_mode'] === 'MANUAL'): ?>
          <button class="btn btn-sm btn-success km-btn-pill" id="publishResultsBtn">
            <i class="fa-solid fa-bullhorn me-1"></i> Publiko rezultatet
          </button>
        <?php endif; ?>
      </div>
    </div>
  </div>

  <div class="km-card mt-3">
    <div class="km-card-header">
      <div>
        <h2 class="km-card-title"><span class="km-step-badge">1</span> Filtrim</h2>
        <div class="km-card-subtitle">Kufizo rezultatet sipas datës dhe përqindjes</div>
      </div>
    </div>
    <div class="km-card-body">
      <form class="row g-2">
        <input type="hidden" name="test_id" value="<?= (int)$test_id ?>">
        <div class="col-md-3"><input type="datetime-local" name="from" class="form-control" value="<?= h($from) ?>"></div>
        <div class="col-md-3"><input type="datetime-local" name="to" class="form-control" value="<?= h($to) ?>"></div>
        <div class="col-md-2"><input type="number" name="min" class="form-control" placeholder="Min %" value="<?= h($minScore) ?>"></div>
        <div class="col-md-2"><input type="number" name="max" class="form-control" placeholder="Max %" value="<?= h($maxScore) ?>"></div>
        <div class="col-md-2"><button class="btn btn-primary km-btn-pill w-100">Filtro</button></div>
      </form>
    </div>
  </div>

  <div class="km-card mt-3">
    <div class="km-card-header">
      <div>
        <h2 class="km-card-title"><span class="km-step-badge">2</span> Lista e përpjekjeve</h2>
        <div class="km-card-subtitle">Shiko statusin, pikët dhe detyrat për vlerësim manual</div>
      </div>
      <div class="km-pill-meta">
        <i class="fa-solid fa-list"></i> Total: <strong><?= (int)count($attempts) ?></strong>
      </div>
    </div>
    <div class="km-card-body">
      <div class="table-responsive">
        <table class="table km-table mb-0">
      <thead>
        <tr>
          <th>Student</th>
          <th>Status</th>
          <th>Score</th>
          <th>Percent</th>
          <th>Submitted</th>
          <th>Veprime</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($attempts as $a): ?>
          <tr>
            <td><?= h($a['full_name']) ?><br><small class="text-muted"><?= h($a['email']) ?></small></td>
            <td>
              <span class="km-badge km-badge-muted">
                <i class="fa-solid fa-circle-info"></i>
                <?= h($a['status']) ?>
              </span>
            </td>
            <td><?= h((string)$a['score_points']) ?> / <?= h((string)$a['total_points']) ?></td>
            <td><?= h((string)$a['percentage']) ?>%</td>
            <td><?= h((string)$a['submitted_at']) ?></td>
            <td>
              <?php if ((string)$a['status'] === 'NEEDS_GRADING'): ?>
                <a class="btn btn-sm btn-warning km-btn-pill" href="test_grade.php?attempt_id=<?= (int)$a['id'] ?>">Grade</a>
              <?php endif; ?>
            </td>
          </tr>
        <?php endforeach; ?>
      </tbody>
        </table>
      </div>
    </div>
  </div>
</div>

<?php include __DIR__ . '/../footer2.php'; ?>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>

<script>
const CSRF = <?= json_encode($CSRF) ?>;
const testId = <?= (int)$test_id ?>;
const btn = document.getElementById('publishResultsBtn');
if (btn) {
  btn.addEventListener('click', async () => {
    const res = await fetch('../api/tests_publish_results.php', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': CSRF },
      body: JSON.stringify({ test_id: testId })
    });
    const data = await res.json();
    if (data.ok) alert('Rezultatet u publikuan.');
    else alert(data.error);
  });
}
</script>
</body>
</html>
