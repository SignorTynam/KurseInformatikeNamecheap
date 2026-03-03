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

$testId = (int)($_GET['test_id'] ?? 0);
$step = (string)($_GET['step'] ?? ($testId > 0 ? '2' : '1'));

$isAdmin = is_admin($u);
$meId = (int)$u['id'];

$courses = [];
$test = [
  'title' => '',
  'course_id' => 0,
  'description' => '',
  'time_limit_minutes' => 30,
  'pass_score' => 60,
  'max_attempts' => 1,
  'shuffle_questions' => 0,
  'shuffle_choices' => 0,
  'show_results_mode' => 'IMMEDIATE',
  'show_correct_answers_mode' => 'NEVER',
  'start_at' => null,
  'due_at' => null,
  'status' => 'DRAFT',
];
$questions = [];
$courseTitle = '';

try {
  if ($testId <= 0) {
    if ($isAdmin) {
      $courses = $pdo->query('SELECT id, title FROM courses ORDER BY title ASC')->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } else {
      $stC = $pdo->prepare('SELECT id, title FROM courses WHERE id_creator=? ORDER BY title ASC');
      $stC->execute([$meId]);
      $courses = $stC->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }
  } else {
    $test = TestService::ensureInstructorAccess($pdo, $testId, $meId, $isAdmin);
    $questions = QuestionService::getQuestionsForTest($pdo, $testId);
    $stT = $pdo->prepare('SELECT title FROM courses WHERE id=? LIMIT 1');
    $stT->execute([(int)$test['course_id']]);
    $courseTitle = (string)($stT->fetchColumn() ?: '');
  }
} catch (Throwable $e) {
  $courses = [];
  $test = $test ?? [];
  $questions = $questions ?? [];
}

function type_label(string $t): string {
  return match ($t) {
    'MC_SINGLE' => 'MC Single',
    'MC_MULTI' => 'MC Multi',
    'TRUE_FALSE' => 'True/False',
    'SHORT' => 'Short Answer',
    default => $t,
  };
}
?>
<!DOCTYPE html>
<html lang="sq">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Krijo/Redakto Test | kurseinformatike.com</title>
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css">
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css">
  <link rel="stylesheet" href="../css/km-lms-forms.css">
  <link rel="stylesheet" href="../css/km-tests-forms.css">
</head>
<body class="km-body">
<?php
  if ($isAdmin) {
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
          <span class="km-breadcrumb-current"> / Builder</span>
        </div>
        <h1 class="km-page-title">
          <i class="fa-solid fa-wand-magic-sparkles me-2 text-primary"></i>
          <?= $testId ? 'Redakto' : 'Krijo' ?> Test
        </h1>
        <div class="km-page-subtitle">Hapi 1: Cilësimet → Hapi 2: Pyetjet → Hapi 3: Publikim</div>
      </div>

      <div class="d-flex align-items-center gap-2 flex-wrap">
        <span class="km-pill-meta">
          <i class="fa-solid fa-circle-info"></i>
          Status: <strong><?= h((string)($test['status'] ?? 'DRAFT')) ?></strong>
        </span>
        <div class="km-tests-tabs">
          <a class="km-tests-tab <?= $step === '1' ? 'active' : '' ?>" href="test_builder.php?test_id=<?= (int)$testId ?>&step=1">1 • Cilësimet</a>
          <a class="km-tests-tab <?= $step === '2' ? 'active' : '' ?> <?= $testId > 0 ? '' : 'disabled' ?>" <?= $testId > 0 ? 'href="test_builder.php?test_id='.(int)$testId.'&step=2"' : 'href="#" aria-disabled="true" tabindex="-1"' ?>>2 • Pyetjet</a>
          <a class="km-tests-tab <?= $step === '3' ? 'active' : '' ?> <?= $testId > 0 ? '' : 'disabled' ?>" <?= $testId > 0 ? 'href="test_builder.php?test_id='.(int)$testId.'&step=3"' : 'href="#" aria-disabled="true" tabindex="-1"' ?>>3 • Publikim</a>
        </div>
      </div>
    </div>
  </div>

  <div class="row g-3 km-form-grid mt-2">

    <!-- MAIN COLUMN -->
    <div class="col-12 col-lg-8">
      <?php if ($step === '1' || !$testId): ?>
      <!-- STEP 1: SETTINGS CARD -->
      <div class="km-card">
        <div class="km-card-header">
          <div>
            <h2 class="km-card-title"><span class="km-step-badge">1</span> Cilësimet bazë</h2>
            <div class="km-card-subtitle">Emri, përshkrimi, kursi, kohëzgjatja</div>
          </div>
        </div>
        <div class="km-card-body">
          <?php if ($testId <= 0): ?>
            <form class="row g-3" id="createTestForm">
              <div class="col-md-6">
                <label class="form-label">Titulli i testit *</label>
                <input type="text" name="title" class="form-control" placeholder="p.sh. Quiz kapitulli 3" required>
              </div>
              <div class="col-md-6">
                <label class="form-label">Kursi *</label>
                <select name="course_id" class="form-select" required>
                  <option value="">Zgjidh kursin...</option>
                  <?php foreach ($courses as $c): ?>
                    <option value="<?= (int)$c['id'] ?>"><?= h((string)$c['title']) ?></option>
                  <?php endforeach; ?>
                </select>
              </div>
              <div class="col-12">
                <label class="form-label">Përshkrimi</label>
                <textarea name="description" class="form-control" rows="3" placeholder="Shkruaj përshkrimin (opsional)"></textarea>
              </div>
              <div class="col-md-3">
                <label class="form-label">Kohëzgjatja (min)</label>
                <input type="number" name="time_limit_minutes" class="form-control" min="0" value="30">
              </div>
              <div class="col-md-3">
                <label class="form-label">Pass Score (%)</label>
                <input type="number" name="pass_score" class="form-control" min="0" max="100" value="60">
              </div>
              <div class="col-md-3">
                <label class="form-label">Max Attempts</label>
                <input type="number" name="max_attempts" class="form-control" min="0" value="1">
              </div>
              <div class="col-md-3">
                <label class="form-label">Përziej pyetjet</label>
                <div class="form-check mt-2">
                  <input class="form-check-input" type="checkbox" name="shuffle_questions" id="sqCreate">
                  <label class="form-check-label" for="sqCreate">Po</label>
                </div>
                <div class="form-check">
                  <input class="form-check-input" type="checkbox" name="shuffle_choices" id="scCreate">
                  <label class="form-check-label" for="scCreate">Përziej alternativat</label>
                </div>
              </div>

              <div class="col-12">
                <div class="km-tests-actionbar">
                  <div class="km-tests-sumrow">
                    <span class="km-badge km-badge-muted"><i class="fa-solid fa-shield-halved"></i> CSRF OK</span>
                    <span id="createMsg" class="km-help-text"></span>
                  </div>
                  <div class="km-tests-actionbar-right">
                    <button class="btn btn-primary km-btn-pill" type="submit">
                      <i class="fa-solid fa-circle-plus me-1"></i> Krijo
                    </button>
                  </div>
                </div>
              </div>
            </form>
          <?php else: ?>
            <form class="row g-3" id="settingsForm">
              <input type="hidden" name="test_id" value="<?= (int)$testId ?>">
              <input type="hidden" name="section_id" value="<?= (int)($test['section_id'] ?? 0) ?>">
              <input type="hidden" name="lesson_id" value="<?= (int)($test['lesson_id'] ?? 0) ?>">
              <input type="hidden" name="start_at" value="<?= h(utc_to_local((string)($test['start_at'] ?? ''))) ?>">
              <input type="hidden" name="due_at" value="<?= h(utc_to_local((string)($test['due_at'] ?? ''))) ?>">
              <input type="hidden" name="show_results_mode" value="<?= h((string)($test['show_results_mode'] ?? 'IMMEDIATE')) ?>">
              <input type="hidden" name="show_correct_answers_mode" value="<?= h((string)($test['show_correct_answers_mode'] ?? 'NEVER')) ?>">
              <div class="col-md-6">
                <label class="form-label">Titulli i testit *</label>
                <input type="text" name="title" class="form-control" value="<?= h((string)$test['title']) ?>" placeholder="p.sh. Quiz kapitulli 3" required>
              </div>
              <div class="col-md-6">
                <label class="form-label">Kursi</label>
                <input type="text" class="form-control" value="<?= h($courseTitle !== '' ? $courseTitle : (string)$test['course_id']) ?>" disabled>
              </div>
              <div class="col-12">
                <label class="form-label">Përshkrimi</label>
                <textarea name="description" class="form-control" rows="3" placeholder="Shkruaj përshkrimin (opsional)"><?= h((string)$test['description']) ?></textarea>
              </div>
              <div class="col-md-3">
                <label class="form-label">Kohëzgjatja (min)</label>
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
                <label class="form-label">Përzierje</label>
                <div class="form-check mt-2">
                  <input class="form-check-input" type="checkbox" name="shuffle_questions" id="sq" <?= (int)$test['shuffle_questions'] ? 'checked' : '' ?>>
                  <label class="form-check-label" for="sq">Përziej pyetjet</label>
                </div>
                <div class="form-check">
                  <input class="form-check-input" type="checkbox" name="shuffle_choices" id="sc" <?= (int)$test['shuffle_choices'] ? 'checked' : '' ?>>
                  <label class="form-check-label" for="sc">Përziej alternativat</label>
                </div>
              </div>

              <div class="col-12">
                <div class="km-tests-actionbar">
                  <div class="km-tests-sumrow">
                    <span class="km-badge km-badge-secondary"><i class="fa-solid fa-gear"></i> Settings</span>
                    <span id="settingsMsg" class="km-help-text"></span>
                  </div>
                  <div class="km-tests-actionbar-right">
                    <button class="btn btn-primary km-btn-pill" type="submit">
                      <i class="fa-solid fa-floppy-disk me-1"></i> Ruaj
                    </button>
                  </div>
                </div>
              </div>
            </form>
          <?php endif; ?>
        </div>
      </div>
      <?php endif; ?>

      <?php if ($step === '2' && $testId > 0): ?>
      <!-- STEP 2: QUESTIONS CARD -->
      <div class="km-card">
        <div class="km-card-header">
          <div>
            <h2 class="km-card-title"><span class="km-step-badge">2</span> Pyetjet</h2>
            <div class="km-card-subtitle">Shto, redakto ose fshi pyetjet</div>
          </div>
        </div>
        <div class="km-card-body">
          <div class="list-group">
            <?php if (empty($questions)): ?>
              <div class="text-center py-5">
                <div class="km-help-text">S'ka pyetje ende. Shto pyetjen e parë me butonin më poshtë.</div>
              </div>
            <?php else: ?>
              <?php foreach ($questions as $q): ?>
                <?php $qData = json_encode($q, JSON_UNESCAPED_UNICODE); ?>
                <div class="list-group-item d-flex align-items-start justify-content-between gap-2 flex-wrap">
                  <div>
                    <div class="fw-semibold">#<?= (int)$q['id'] ?> • <?= h(type_label((string)$q['type'])) ?> • <?= h((string)$q['text']) ?></div>
                    <small class="text-muted">Points: <?= h((string)($q['points_override'] ?? $q['points'])) ?></small>
                  </div>
                  <div class="d-flex gap-2 flex-wrap">
                    <button class="btn btn-sm btn-outline-secondary km-btn-pill" type="button" data-question='<?= h($qData) ?>' onclick="editQuestion(this)">Edit</button>
                    <button class="btn btn-sm btn-outline-danger km-btn-pill" type="button" onclick="if(confirm('Fshi?')) deleteQuestion(<?= (int)$q['id'] ?>)">Delete</button>
                  </div>
                </div>
              <?php endforeach; ?>
            <?php endif; ?>
          </div>

          <div class="km-tests-actionbar mt-3">
            <div class="km-tests-sumrow">
              <span class="km-badge"><i class="fa-regular fa-circle-question"></i> Pyetje</span>
              <span class="km-help-text">Shto pyetje të re me modalin.</span>
            </div>
            <div class="km-tests-actionbar-right">
              <button class="btn btn-primary km-btn-pill" type="button" data-bs-toggle="modal" data-bs-target="#questionModal">
                <i class="fa-solid fa-circle-plus me-1"></i> Shto pyetje
              </button>
            </div>
          </div>
        </div>
      </div>
      <?php endif; ?>

      <?php if ($step === '3' && $testId > 0): ?>
      <!-- STEP 3: PUBLISH CARD -->
      <div class="km-card">
        <div class="km-card-header">
          <div>
            <h2 class="km-card-title"><span class="km-step-badge">3</span> Publikim</h2>
            <div class="km-card-subtitle">Konfiguro aksesibilitetin dhe afatet</div>
          </div>
        </div>
        <div class="km-card-body">
          <form class="row g-3" id="publishForm">
            <input type="hidden" name="test_id" value="<?= (int)$testId ?>">
            <input type="hidden" name="section_id" value="<?= (int)($test['section_id'] ?? 0) ?>">
            <input type="hidden" name="lesson_id" value="<?= (int)($test['lesson_id'] ?? 0) ?>">
            <input type="hidden" name="title" value="<?= h((string)$test['title']) ?>">
            <input type="hidden" name="description" value="<?= h((string)$test['description']) ?>">
            <input type="hidden" name="time_limit_minutes" value="<?= (int)$test['time_limit_minutes'] ?>">
            <input type="hidden" name="pass_score" value="<?= (float)$test['pass_score'] ?>">
            <input type="hidden" name="max_attempts" value="<?= (int)$test['max_attempts'] ?>">
            <input type="hidden" name="shuffle_questions" value="<?= (int)$test['shuffle_questions'] ?>">
            <input type="hidden" name="shuffle_choices" value="<?= (int)$test['shuffle_choices'] ?>">
            <div class="col-md-6">
              <label class="form-label">Data/Ora e fillimit</label>
              <input type="datetime-local" name="start_at" class="form-control" value="<?= h(utc_to_local((string)($test['start_at'] ?? ''))) ?>">
            </div>
            <div class="col-md-6">
              <label class="form-label">Data/Ora e skadencës</label>
              <input type="datetime-local" name="due_at" class="form-control" value="<?= h(utc_to_local((string)($test['due_at'] ?? ''))) ?>">
            </div>
            <div class="col-md-6">
              <label class="form-label">Shfaq rezultatet</label>
              <select name="show_results_mode" class="form-select">
                <?php foreach (['IMMEDIATE' => 'Menjëherë', 'AFTER_DUE' => 'Pas afatit', 'MANUAL' => 'Manual'] as $val => $label): ?>
                  <option value="<?= $val ?>" <?= (($test['show_results_mode'] ?? 'IMMEDIATE') === $val) ? 'selected' : '' ?>><?= h($label) ?></option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="col-md-6">
              <label class="form-label">Shfaq përgjigjet e sakta</label>
              <select name="show_correct_answers_mode" class="form-select">
                <?php foreach (['NEVER' => 'Kurrë', 'IMMEDIATE' => 'Menjëherë', 'AFTER_DUE' => 'Pas afatit'] as $val => $label): ?>
                  <option value="<?= $val ?>" <?= (($test['show_correct_answers_mode'] ?? 'NEVER') === $val) ? 'selected' : '' ?>><?= h($label) ?></option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="col-12">
              <div class="km-tests-actionbar">
                <div class="km-tests-sumrow">
                  <span class="km-badge"><i class="fa-solid fa-cloud-arrow-up"></i> Publikim</span>
                  <span id="publishMsg" class="km-help-text"></span>
                </div>
                <div class="km-tests-actionbar-right">
                  <button class="btn btn-primary km-btn-pill" type="submit">
                    <i class="fa-solid fa-floppy-disk me-1"></i> Ruaj
                  </button>
                  <button class="btn btn-success km-btn-pill" type="button" id="publishBtn">
                    <i class="fa-solid fa-cloud-arrow-up me-1"></i> Publish
                  </button>
                  <button class="btn btn-outline-secondary km-btn-pill" type="button" id="unpublishBtn">
                    <i class="fa-solid fa-cloud-arrow-down me-1"></i> Unpublish
                  </button>
                </div>
              </div>
            </div>
          </form>
        </div>
      </div>
      <?php endif; ?>
    </div>

    <!-- SIDE COLUMN (STICKY PREVIEW) -->
    <div class="col-12 col-lg-4">
      <div class="km-card km-card-side km-sticky-side">
        <div class="km-card-header">
          <div>
            <div class="km-side-title"><i class="fa-solid fa-chart-simple me-2"></i> Përmbledhje</div>
            <div class="km-card-subtitle">Statistika & tips</div>
          </div>
        </div>
        <div class="km-card-body">
          <div class="km-tests-preview-box">
            <div class="km-tests-preview-row"><span>Status</span><strong><?= h((string)($test['status'] ?? 'DRAFT')) ?></strong></div>
            <div class="km-tests-preview-row"><span>Pyetje</span><strong><?= $testId > 0 ? (int)count($questions) : 0 ?></strong></div>
            <div class="km-tests-preview-row"><span>Kohëzgjatja</span><strong><?= (int)($test['time_limit_minutes'] ?? 0) ?> min</strong></div>
            <div class="km-tests-preview-row"><span>Pass Score</span><strong><?= h((string)($test['pass_score'] ?? '0')) ?>%</strong></div>
            <div class="km-tests-preview-row"><span>Max Attempts</span><strong><?= (int)($test['max_attempts'] ?? 0) ?></strong></div>
          </div>

          <div class="km-tests-divider my-3"></div>
          <div class="km-help-text"><strong>Tips</strong></div>
          <ul class="km-checklist mt-2">
            <li><i class="fa-solid fa-check"></i> Shto të paktën 1 pyetje përpara publikimit</li>
            <li><i class="fa-solid fa-check"></i> Kontrollo afatet e publikimit</li>
            <li><i class="fa-solid fa-check"></i> Testo para publikimit</li>
          </ul>

          <div class="km-tests-actionbar mt-3">
            <div class="km-tests-sumrow">
              <span class="km-badge km-badge-muted"><i class="fa-solid fa-shield-halved"></i> CSRF OK</span>
            </div>
            <div class="km-tests-actionbar-right">
              <button class="btn btn-primary km-btn-pill" type="button" onclick="saveAndPublish()">
                <i class="fa-solid fa-cloud-arrow-up me-1"></i> Ruaj & publikim
              </button>
              <button class="btn btn-outline-secondary km-btn-pill" type="button" onclick="saveDraft()">
                <i class="fa-solid fa-floppy-disk me-1"></i> Ruaj draft
              </button>
            </div>
          </div>
        </div>
      </div>
    </div>
  </div>
</div>

<!-- MODAL: Add/Edit Question -->
<div class="modal fade" id="questionModal" tabindex="-1">
  <div class="modal-dialog modal-lg">
    <div class="modal-content">
      <div class="modal-header border-bottom-0">
        <h5 class="modal-title">Shto pyetje të re</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body">
              <form class="row g-3" id="questionForm">
                <input type="hidden" name="test_id" value="<?= (int)$testId ?>">
                <input type="hidden" name="question_id" value="">
          <div class="col-12">
            <label class="form-label">Lloji i pyetjes</label>
                  <select class="form-select" name="type" id="qType" required>
                    <option value="MC_SINGLE">MC Single</option>
                    <option value="MC_MULTI">MC Multi</option>
                    <option value="TRUE_FALSE">True/False</option>
                    <option value="SHORT">Short Answer</option>
            </select>
          </div>
          <div class="col-12">
            <label class="form-label">Teksti i pyetjes *</label>
                  <textarea class="form-control" name="text" rows="4" placeholder="Shkruaj pyetjen..." required></textarea>
          </div>
          <div class="col-12">
            <label class="form-label">Pikë për këtë pyetje</label>
                  <input type="number" name="points" class="form-control" min="0" step="0.5" value="1">
                </div>
                <div class="col-12">
                  <label class="form-label">Explanation</label>
                  <textarea name="explanation" class="form-control" rows="2"></textarea>
                </div>

                <div class="col-md-4" id="shortExactWrap" style="display:none;">
                  <label class="form-label">Short Answer Exact</label>
                  <select name="short_answer_exact" class="form-select" id="shortExact">
                    <option value="1">Po</option>
                    <option value="0">Manual</option>
                  </select>
                </div>
                <div class="col-md-8" id="shortCorrectWrap" style="display:none;">
                  <label class="form-label">Correct Answer (exact)</label>
                  <input type="text" name="correct_answer" class="form-control" id="shortCorrect" placeholder="p.sh. HTTP">
                </div>

                <div class="col-12" id="optionsBlock">
                  <label class="form-label">Alternativat (MC/TF)</label>
                  <div id="optionsWrap" class="row g-2"></div>
                  <button type="button" class="btn btn-sm btn-outline-secondary mt-2" id="addOptionBtn">+ Shto alternativë</button>
                  <div class="form-text">Për MC Single / True-False, zgjidh vetëm një të saktë.</div>
          </div>
        </form>
      </div>
      <div class="modal-footer border-top-0">
        <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Anullo</button>
        <button type="button" class="btn btn-primary" onclick="saveQuestion()">Ruaj pyetjen</button>
      </div>
    </div>
  </div>
</div>

<?php include __DIR__ . '/../footer2.php'; ?>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script>
const CSRF = <?= json_encode($CSRF) ?>;

function saveAndPublish() {
  alert('Testi u publikua!');
  location.href = 'tests.php';
}

function saveDraft() {
  alert('Testi u ruajt si draft!');
}

function editQuestion(qId) {
  alert('Redakto pyetja ' + qId);
}

function deleteQuestion(qId) {
  alert('Pyetja u fshi!');
}

function saveQuestion() {
  alert('Pyetja u ruajt!');
  document.getElementById('questionForm').reset();
  bootstrap.Modal.getInstance(document.getElementById('questionModal')).hide();
}


const step = <?= json_encode($step) ?>;
const testId = <?= (int)$testId ?>;

function asInt(val, fallback = 0) {
  const n = parseInt(String(val), 10);
  return Number.isFinite(n) ? n : fallback;
}

async function postJson(url, payload) {
  const res = await fetch(url, {
    method: 'POST',
    headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': CSRF },
    body: JSON.stringify(payload)
  });
  return res.json();
}

// CREATE (step 1 when no test_id)
const createForm = document.getElementById('createTestForm');
if (createForm) {
  const createMsg = document.getElementById('createMsg');
  createForm.addEventListener('submit', async (e) => {
    e.preventDefault();
    const fd = new FormData(createForm);
    const payload = Object.fromEntries(fd.entries());
    payload.shuffle_questions = fd.get('shuffle_questions') ? 1 : 0;
    payload.shuffle_choices = fd.get('shuffle_choices') ? 1 : 0;
    const data = await postJson('../api/tests_create.php', payload);
    if (data.ok) {
      createMsg.textContent = 'Testi u krijua. Po hap builder-in...';
      location.href = `test_builder.php?test_id=${data.test_id}&step=2`;
    } else {
      createMsg.textContent = data.error || 'Gabim.';
    }
  });
}

// SETTINGS (step 1 when editing)
const settingsForm = document.getElementById('settingsForm');
const settingsMsg = document.getElementById('settingsMsg');
if (settingsForm) {
  settingsForm.addEventListener('submit', async (e) => {
    e.preventDefault();
    const fd = new FormData(settingsForm);
    const payload = Object.fromEntries(fd.entries());
    payload.shuffle_questions = fd.get('shuffle_questions') ? 1 : 0;
    payload.shuffle_choices = fd.get('shuffle_choices') ? 1 : 0;
    // keep publish defaults if not present
    payload.show_results_mode = payload.show_results_mode || 'IMMEDIATE';
    payload.show_correct_answers_mode = payload.show_correct_answers_mode || 'NEVER';
    const data = await postJson('../api/tests_update.php', payload);
    if (settingsMsg) settingsMsg.textContent = data.ok ? 'Ruajtur.' : (data.error || 'Gabim.');
  });
}

// PUBLISH SETTINGS (step 3)
const publishForm = document.getElementById('publishForm');
const publishMsg = document.getElementById('publishMsg');
if (publishForm) {
  publishForm.addEventListener('submit', async (e) => {
    e.preventDefault();
    const fd = new FormData(publishForm);
    const payload = Object.fromEntries(fd.entries());
    const data = await postJson('../api/tests_update.php', payload);
    if (publishMsg) publishMsg.textContent = data.ok ? 'Ruajtur.' : (data.error || 'Gabim.');
  });
}

async function publishAction(action) {
  const data = await postJson('../api/tests_publish.php', { test_id: testId, action });
  if (data.ok) {
    alert('OK');
    location.reload();
  } else {
    alert(data.error || 'Gabim.');
  }
}

document.getElementById('publishBtn')?.addEventListener('click', () => publishAction('publish'));
document.getElementById('unpublishBtn')?.addEventListener('click', () => publishAction('unpublish'));

function saveAndPublish() {
  if (!testId) return;
  if (step !== '3') {
    location.href = `test_builder.php?test_id=${testId}&step=3`;
    return;
  }
  // Save publish settings then publish
  (async () => {
    if (publishForm) {
      const fd = new FormData(publishForm);
      const payload = Object.fromEntries(fd.entries());
      const upd = await postJson('../api/tests_update.php', payload);
      if (!upd.ok) {
        alert(upd.error || 'Gabim në ruajtje.');
        return;
      }
    }
    await publishAction('publish');
  })();
}

function saveDraft() {
  if (!testId) return;
  if (step === '1' && settingsForm) {
    settingsForm.requestSubmit();
    return;
  }
  if (step === '3' && publishForm) {
    publishForm.requestSubmit();
    return;
  }
  alert('S’ka asgjë për ruajtje në këtë hap.');
}

// QUESTION BUILDER (step 2)
const questionModalEl = document.getElementById('questionModal');
const qForm = document.getElementById('questionForm');
const qType = document.getElementById('qType');
const optionsWrap = document.getElementById('optionsWrap');
const optionsBlock = document.getElementById('optionsBlock');
const addOptionBtn = document.getElementById('addOptionBtn');
const shortExactWrap = document.getElementById('shortExactWrap');
const shortCorrectWrap = document.getElementById('shortCorrectWrap');
const shortExact = document.getElementById('shortExact');
const shortCorrect = document.getElementById('shortCorrect');

function syncShortAnswerRequired() {
  const type = qType?.value;
  if (type !== 'SHORT') {
    shortCorrect?.removeAttribute('required');
    return;
  }
  const exact = (shortExact?.value ?? '1') === '1';
  if (exact) shortCorrect?.setAttribute('required', 'required');
  else shortCorrect?.removeAttribute('required');
}

function optionLabel(pos) {
  const letters = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ';
  return letters[(pos - 1) % letters.length] || String(pos);
}

function renderOptionRow(pos, text = '', isCorrect = false, textDisabled = false) {
  const col = document.createElement('div');
  col.className = 'col-md-6 option-row';
  col.innerHTML = `
    <div class="input-group">
      <span class="input-group-text">${optionLabel(pos)}</span>
      <input type="text" class="form-control opt-text" data-pos="${pos}" ${textDisabled ? 'disabled' : ''} placeholder="Option">
      <span class="input-group-text">
        <input type="checkbox" class="form-check-input opt-correct" data-pos="${pos}" ${isCorrect ? 'checked' : ''} title="Correct">
      </span>
    </div>
  `;
  col.querySelector('.opt-text').value = String(text ?? '');
  col.querySelector('.opt-correct').addEventListener('change', (e) => {
    const t = qType?.value;
    if (t === 'MC_SINGLE' || t === 'TRUE_FALSE') {
      if (e.target.checked) {
        optionsWrap.querySelectorAll('.opt-correct').forEach((cb) => {
          if (cb !== e.target) cb.checked = false;
        });
      }
    }
  });
  return col;
}

function setOptionsForType(type, existingOptions = []) {
  if (!optionsWrap) return;
  optionsWrap.innerHTML = '';

  if (type === 'SHORT') {
    optionsBlock.style.display = 'none';
    shortExactWrap.style.display = '';
    shortCorrectWrap.style.display = '';
    syncShortAnswerRequired();
    return;
  }

  optionsBlock.style.display = '';
  shortExactWrap.style.display = 'none';
  shortCorrectWrap.style.display = 'none';

  if (type === 'TRUE_FALSE') {
    const truthy = existingOptions?.find(o => asInt(o.position) === 1);
    const falsy = existingOptions?.find(o => asInt(o.position) === 2);
    optionsWrap.appendChild(renderOptionRow(1, truthy?.option_text ?? 'True', asInt(truthy?.is_correct) === 1, true));
    optionsWrap.appendChild(renderOptionRow(2, falsy?.option_text ?? 'False', asInt(falsy?.is_correct) === 1, true));
    return;
  }

  const opts = Array.isArray(existingOptions) ? existingOptions : [];
  if (opts.length) {
    opts.forEach((o) => {
      const pos = asInt(o.position, 1);
      optionsWrap.appendChild(renderOptionRow(pos, o.option_text ?? '', asInt(o.is_correct) === 1, false));
    });
  } else {
    for (let i = 1; i <= 4; i++) {
      optionsWrap.appendChild(renderOptionRow(i, '', false, false));
    }
  }
}

qType?.addEventListener('change', () => setOptionsForType(qType.value, []));
shortExact?.addEventListener('change', syncShortAnswerRequired);

addOptionBtn?.addEventListener('click', () => {
  const t = qType?.value;
  if (t === 'SHORT' || t === 'TRUE_FALSE') return;
  const nextPos = (optionsWrap.querySelectorAll('.option-row').length || 0) + 1;
  optionsWrap.appendChild(renderOptionRow(nextPos, '', false, false));
});

function collectOptionsPayload() {
  const type = qType?.value;
  if (type === 'SHORT') {
    return { options: [], correct_multi: [], correct: null, short_answer_exact: shortExact?.value || '1', correct_answer: shortCorrect?.value || '' };
  }

  const rows = Array.from(optionsWrap.querySelectorAll('.option-row'));
  const options = [];
  const correct = [];
  rows.forEach((row) => {
    const input = row.querySelector('.opt-text');
    const cb = row.querySelector('.opt-correct');
    const pos = asInt(input?.dataset?.pos, 1);
    const text = (input?.value ?? '').trim();
    if (text !== '') {
      options.push({ text });
      if (cb?.checked) correct.push(pos);
    }
  });

  return {
    options,
    correct_multi: correct,
    correct: correct[0] || null,
    short_answer_exact: '0',
    correct_answer: ''
  };
}

function resetQuestionForm() {
  if (!qForm) return;
  qForm.reset();
  qForm.question_id.value = '';
  setOptionsForType(qType.value, []);
}

questionModalEl?.addEventListener('show.bs.modal', () => {
  if (qForm?.question_id?.value) return;
  // New question default
  setOptionsForType(qType.value, []);
});

questionModalEl?.addEventListener('hidden.bs.modal', () => {
  resetQuestionForm();
});

function editQuestion(btnEl) {
  const raw = btnEl?.dataset?.question;
  if (!raw) return;
  const q = JSON.parse(raw);

  qForm.question_id.value = q.id;
  qType.value = q.type;
  qForm.text.value = q.text;
  qForm.points.value = q.points_override ?? q.points;
  qForm.explanation.value = q.explanation || '';

  if (q.type === 'SHORT') {
    shortExact.value = String(q.short_answer_exact ?? 1);
    shortCorrect.value = '';
  }

  setOptionsForType(q.type, q.options || []);
  syncShortAnswerRequired();
  const modal = new bootstrap.Modal(questionModalEl);
  modal.show();
}

async function deleteQuestion(qId) {
  const data = await postJson('../api/questions_delete.php', { test_id: testId, question_id: qId });
  if (data.ok) location.reload();
  else alert(data.error || 'Gabim.');
}

async function saveQuestion() {
  if (!qForm) return;
  const fd = new FormData(qForm);
  const payload = Object.fromEntries(fd.entries());
  const extra = collectOptionsPayload();
  payload.options = extra.options;
  payload.correct_multi = extra.correct_multi;
  payload.correct = extra.correct;
  payload.short_answer_exact = extra.short_answer_exact;
  payload.correct_answer = extra.correct_answer;

  const endpoint = payload.question_id ? '../api/questions_update.php' : '../api/questions_create.php';
  const data = await postJson(endpoint, payload);
  if (data.ok) {
    resetQuestionForm();
    bootstrap.Modal.getInstance(questionModalEl)?.hide();
    location.reload();
  } else {
    alert(data.error || 'Gabim.');
  }
}
</script>
</body>
