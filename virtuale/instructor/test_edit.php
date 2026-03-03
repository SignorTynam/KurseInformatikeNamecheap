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
function utc_to_local(?string $s): string {
  if (!$s) return '';
  $dt = new DateTime($s, new DateTimeZone('UTC'));
  $dt->setTimezone(new DateTimeZone('Europe/Rome'));
  return $dt->format('Y-m-d\TH:i');
}

$test_id = isset($_GET['test_id']) ? (int)$_GET['test_id'] : 0;
if ($test_id <= 0) { die('Test i pavlefshëm'); }

$test = TestService::ensureInstructorAccess($pdo, $test_id, (int)$u['id'], is_admin($u));
$questions = [];

?>
<!DOCTYPE html>
<html lang="sq">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title><?= h($test['title']) ?> | Edit Test</title>
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
          <span class="km-breadcrumb-current"> / Edit</span>
        </div>
        <h1 class="km-page-title">
          <i class="fa-solid fa-pen-to-square me-2 text-primary"></i>
          <?= h($test['title']) ?>
        </h1>
        <div class="km-page-subtitle">Ndrysho cilësimet, pyetjet dhe publikimin e testit.</div>
      </div>

      <div class="d-flex align-items-center gap-2 flex-wrap">
        <a class="btn btn-sm btn-outline-secondary km-btn-pill" href="tests.php">
          <i class="fa-solid fa-arrow-left me-1"></i> Kthehu
        </a>
        <button id="publishBtn" class="btn btn-sm btn-success km-btn-pill">
          <i class="fa-solid fa-cloud-arrow-up me-1"></i> Publish
        </button>
        <button id="unpublishBtn" class="btn btn-sm btn-outline-secondary km-btn-pill">
          <i class="fa-solid fa-cloud-arrow-down me-1"></i> Unpublish
        </button>
      </div>
    </div>
  </div>

  <div class="km-card mt-3">
    <div class="km-card-header">
      <div>
        <h2 class="km-card-title"><span class="km-step-badge">1</span> Settings</h2>
        <div class="km-card-subtitle">Cilësimet bazë, afatet dhe opsionet e shfaqjes</div>
      </div>
    </div>
    <div class="km-card-body">
      <form id="updateTestForm" class="row g-3">
        <input type="hidden" name="test_id" value="<?= (int)$test_id ?>">
        <div class="col-md-6">
          <label class="form-label">Titulli</label>
          <input type="text" name="title" class="form-control" value="<?= h($test['title']) ?>" required>
        </div>
        <div class="col-md-6">
          <label class="form-label">Kursi</label>
          <input type="text" class="form-control" value="<?= (int)$test['course_id'] ?>" disabled>
        </div>
        <div class="col-12">
          <label class="form-label">Përshkrimi</label>
          <textarea name="description" class="form-control" rows="3"><?= h((string)$test['description']) ?></textarea>
        </div>
        <div class="col-md-3">
          <label class="form-label">Koha (min)</label>
          <input type="number" name="time_limit_minutes" class="form-control" min="0" value="<?= (int)$test['time_limit_minutes'] ?>">
        </div>
        <div class="col-md-3">
          <label class="form-label">Pass Score (%)</label>
          <input type="number" name="pass_score" class="form-control" min="0" max="100" value="<?= (float)$test['pass_score'] ?>">
        </div>
        <div class="col-md-3">
          <label class="form-label">Max Attempts</label>
          <input type="number" name="max_attempts" class="form-control" min="0" value="<?= (int)$test['max_attempts'] ?>">
        </div>
        <div class="col-md-3">
          <label class="form-label">Start</label>
          <input type="datetime-local" name="start_at" class="form-control" value="<?= h(utc_to_local((string)$test['start_at'])) ?>">
        </div>
        <div class="col-md-3">
          <label class="form-label">Due</label>
          <input type="datetime-local" name="due_at" class="form-control" value="<?= h(utc_to_local((string)$test['due_at'])) ?>">
        </div>
        <div class="col-md-3">
          <label class="form-label">Show Results</label>
          <select name="show_results_mode" class="form-select">
            <?php foreach (['IMMEDIATE','AFTER_DUE','MANUAL'] as $opt): ?>
              <option value="<?= $opt ?>" <?= $test['show_results_mode']===$opt?'selected':'' ?>><?= $opt ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="col-md-3">
          <label class="form-label">Show Correct Answers</label>
          <select name="show_correct_answers_mode" class="form-select">
            <?php foreach (['NEVER','IMMEDIATE','AFTER_DUE'] as $opt): ?>
              <option value="<?= $opt ?>" <?= $test['show_correct_answers_mode']===$opt?'selected':'' ?>><?= $opt ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="col-md-3">
          <div class="form-check mt-4">
            <input class="form-check-input" type="checkbox" name="shuffle_questions" <?= (int)$test['shuffle_questions'] ? 'checked' : '' ?> id="sq">
            <label class="form-check-label" for="sq">Përziej pyetjet</label>
          </div>
          <div class="form-check">
            <input class="form-check-input" type="checkbox" name="shuffle_choices" <?= (int)$test['shuffle_choices'] ? 'checked' : '' ?> id="sc">
            <label class="form-check-label" for="sc">Përziej alternativat</label>
          </div>
        </div>
        <div class="col-12">
          <div class="km-tests-actionbar">
            <div class="km-tests-sumrow">
              <span class="km-badge km-badge-secondary"><i class="fa-solid fa-gear"></i> Settings</span>
              <span id="saveMsg" class="km-help-text"></span>
            </div>
            <div class="km-tests-actionbar-right">
              <button class="btn btn-primary km-btn-pill" type="submit">
                <i class="fa-solid fa-floppy-disk me-1"></i> Ruaj
              </button>
            </div>
          </div>
        </div>
      </form>
    </div>
  </div>

  <div class="km-card mt-3">
    <div class="km-card-header">
      <div>
        <h2 class="km-card-title"><span class="km-step-badge">2</span> Pyetjet</h2>
        <div class="km-card-subtitle">Menaxho pyetjet te Builder</div>
      </div>
    </div>
    <div class="km-card-body">
      <div class="km-tests-actionbar">
        <div class="km-tests-sumrow">
          <span class="km-badge"><i class="fa-regular fa-circle-question"></i> Pyetjet</span>
          <span class="km-help-text">Pyetjet/alternativat menaxhohen te Builder.</span>
        </div>
        <div class="km-tests-actionbar-right">
          <a class="btn btn-primary km-btn-pill" href="test_builder.php?test_id=<?= (int)$test_id ?>&step=2">
            <i class="fa-solid fa-wand-magic-sparkles me-1"></i> Hap Builder
          </a>
        </div>
      </div>
    </div>
  </div>
</div>

<?php include __DIR__ . '/../footer2.php'; ?>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>

<script>
const CSRF = <?= json_encode($CSRF) ?>;
const testId = <?= (int)$test_id ?>;

const saveMsg = document.getElementById('saveMsg');
const updateForm = document.getElementById('updateTestForm');
updateForm.addEventListener('submit', async (e) => {
  e.preventDefault();
  const formData = new FormData(updateForm);
  const payload = Object.fromEntries(formData.entries());
  payload.shuffle_questions = formData.get('shuffle_questions') ? 1 : 0;
  payload.shuffle_choices = formData.get('shuffle_choices') ? 1 : 0;
  const res = await fetch('../api/tests_update.php', {
    method: 'POST',
    headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': CSRF },
    body: JSON.stringify(payload)
  });
  const data = await res.json();
  saveMsg.textContent = data.ok ? 'Ruajtur.' : data.error;
});

async function publishAction(action) {
  const res = await fetch('../api/tests_publish.php', {
    method: 'POST',
    headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': CSRF },
    body: JSON.stringify({ test_id: testId, action })
  });
  const data = await res.json();
  if (data.ok) {
    alert('OK');
    location.reload();
  } else {
    alert(data.error);
  }
}

document.getElementById('publishBtn').addEventListener('click', () => publishAction('publish'));
document.getElementById('unpublishBtn').addEventListener('click', () => publishAction('unpublish'));
</script>
</body>
</html>
