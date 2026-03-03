<?php
declare(strict_types=1);

session_start();
require_once __DIR__ . '/../lib/database.php';
require_once __DIR__ . '/../lib/auth.php';
require_once __DIR__ . '/../lib/csrf.php';
require_once __DIR__ . '/../lib/test_services.php';

$u = require_role(['Student']);
$CSRF = csrf_token();

function h(?string $s): string { return htmlspecialchars($s ?? '', ENT_QUOTES, 'UTF-8'); }

$test_id = isset($_GET['test_id']) ? (int)$_GET['test_id'] : 0;
if ($test_id <= 0) { die('Test i pavlefshëm'); }

$test = TestService::ensureStudentAccess($pdo, $test_id, (int)$u['id']);
$questions = QuestionService::getQuestionsForTest($pdo, $test_id);

if ((int)$test['shuffle_questions'] === 1) {
  shuffle($questions);
}
if ((int)$test['shuffle_choices'] === 1) {
  foreach ($questions as $i => $question) {
    if (!empty($question['options'])) {
      shuffle($questions[$i]['options']);
    }
  }
}

?>
<!DOCTYPE html>
<html lang="sq">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title><?= h($test['title']) ?></title>
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
          <span class="km-breadcrumb-current"> / Test</span>
        </div>
        <h1 class="km-page-title">
          <i class="fa-regular fa-clipboard me-2 text-primary"></i>
          <?= h($test['title']) ?>
        </h1>
        <div class="km-page-subtitle"><?= h((string)$test['description']) ?></div>
      </div>

      <div class="d-flex align-items-center gap-2 flex-wrap">
        <span class="km-pill-meta">
          <i class="fa-regular fa-clock"></i>
          Koha: <strong><span id="timer">--:--</span></strong>
        </span>
        <span class="km-pill-meta">
          <i class="fa-solid fa-cloud"></i>
          <span id="saveState">Not saved yet</span>
        </span>
      </div>
    </div>
  </div>

  <div id="questionsWrap" class="mt-3">
    <?php foreach ($questions as $index => $q): ?>
      <div class="km-card mb-3 question-card" data-question-id="<?= (int)$q['id'] ?>" data-type="<?= h($q['type']) ?>">
        <div class="km-card-header">
          <div>
            <h2 class="km-card-title"><span class="km-step-badge"><?= (int)($index+1) ?></span> <?= h($q['text']) ?></h2>
          </div>
        </div>
        <div class="km-card-body">
        <?php if (in_array($q['type'], ['MC_SINGLE','TRUE_FALSE'], true)): ?>
          <?php foreach ($q['options'] as $opt): ?>
            <div class="form-check">
              <input class="form-check-input" type="radio" name="q_<?= (int)$q['id'] ?>" value="<?= (int)$opt['id'] ?>" id="opt_<?= (int)$opt['id'] ?>">
              <label class="form-check-label" for="opt_<?= (int)$opt['id'] ?>"><?= h($opt['option_text']) ?></label>
            </div>
          <?php endforeach; ?>
        <?php elseif ($q['type'] === 'MC_MULTI'): ?>
          <?php foreach ($q['options'] as $opt): ?>
            <div class="form-check">
              <input class="form-check-input" type="checkbox" name="q_<?= (int)$q['id'] ?>[]" value="<?= (int)$opt['id'] ?>" id="opt_<?= (int)$opt['id'] ?>">
              <label class="form-check-label" for="opt_<?= (int)$opt['id'] ?>"><?= h($opt['option_text']) ?></label>
            </div>
          <?php endforeach; ?>
        <?php else: ?>
          <textarea class="form-control" name="q_<?= (int)$q['id'] ?>" rows="3"></textarea>
        <?php endif; ?>
        </div>
      </div>
    <?php endforeach; ?>
  </div>

  <div class="km-tests-actionbar mt-3">
    <div class="km-tests-sumrow">
      <span class="km-badge km-badge-muted"><i class="fa-solid fa-shield-halved"></i> Auto-save</span>
      <span class="km-help-text">Dërgo testin kur të jesh gati.</span>
    </div>
    <div class="km-tests-actionbar-right">
      <button class="btn btn-primary km-btn-pill" id="submitBtn" type="button">
        <i class="fa-solid fa-paper-plane me-1"></i> Submit
      </button>
    </div>
  </div>
</div>

<?php include __DIR__ . '/../footer2.php'; ?>


<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>


<script>
const CSRF = <?= json_encode($CSRF) ?>;
const testId = <?= (int)$test_id ?>;
let attemptId = null;
let remainingSeconds = null;
let timerInterval = null;

function formatTime(sec) {
  if (sec === null) return '∞';
  const m = Math.floor(sec / 60);
  const s = sec % 60;
  return String(m).padStart(2,'0') + ':' + String(s).padStart(2,'0');
}

async function startAttempt() {
  const res = await fetch('../api/attempts_start.php', {
    method: 'POST',
    headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': CSRF },
    body: JSON.stringify({ test_id: testId })
  });
  const data = await res.json();
  if (!data.ok) {
    alert(data.error);
    location.href = 'tests.php';
    return;
  }
  attemptId = data.data.attempt_id;
  remainingSeconds = data.data.remaining_seconds;
  applySavedAnswers(data.data.answers || {});
  if (data.data.expired) {
    autoSubmit();
    return;
  }
  startTimer();
}

function applySavedAnswers(answers) {
  for (const qid in answers) {
    const rows = answers[qid] || [];
    rows.forEach((a) => {
      if (a.option_id) {
        const input = document.querySelector(`[name="q_${qid}"][value="${a.option_id}"]`);
        if (input) input.checked = true;
      }
      if (a.answer_text) {
        const textarea = document.querySelector(`[name="q_${qid}"]`);
        if (textarea) textarea.value = a.answer_text;
      }
    });
  }
}

function startTimer() {
  const timerEl = document.getElementById('timer');
  if (remainingSeconds === null) {
    timerEl.textContent = '∞';
    return;
  }
  timerEl.textContent = formatTime(remainingSeconds);
  timerInterval = setInterval(() => {
    remainingSeconds--;
    timerEl.textContent = formatTime(remainingSeconds);
    if (remainingSeconds <= 0) {
      clearInterval(timerInterval);
      autoSubmit();
    }
  }, 1000);
}

function collectAnswer(questionEl) {
  const qid = questionEl.dataset.questionId;
  const type = questionEl.dataset.type;
  if (type === 'MC_SINGLE' || type === 'TRUE_FALSE') {
    const sel = questionEl.querySelector('input[type="radio"]:checked');
    return { question_id: parseInt(qid,10), option_ids: sel ? [parseInt(sel.value,10)] : [] };
  }
  if (type === 'MC_MULTI') {
    const sels = [...questionEl.querySelectorAll('input[type="checkbox"]:checked')].map(i => parseInt(i.value,10));
    return { question_id: parseInt(qid,10), option_ids: sels };
  }
  const ta = questionEl.querySelector('textarea');
  return { question_id: parseInt(qid,10), answer_text: ta ? ta.value : '' };
}

let saveTimer = null;
function queueSave(questionEl) {
  if (!attemptId) return;
  if (saveTimer) clearTimeout(saveTimer);
  saveTimer = setTimeout(async () => {
    const payload = collectAnswer(questionEl);
    payload.attempt_id = attemptId;
    const res = await fetch('../api/attempts_save_answer.php', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': CSRF },
      body: JSON.stringify(payload)
    });
    const data = await res.json();
    const saveState = document.getElementById('saveState');
    if (data.ok) {
      saveState.textContent = 'Saved';
    } else {
      saveState.textContent = data.error || 'Error saving';
    }
  }, 300);
}

document.querySelectorAll('.question-card').forEach((q) => {
  q.addEventListener('change', () => queueSave(q));
  const ta = q.querySelector('textarea');
  if (ta) ta.addEventListener('input', () => queueSave(q));
});

async function submitAttempt() {
  if (!attemptId) return;
  const res = await fetch('../api/attempts_submit.php', {
    method: 'POST',
    headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': CSRF },
    body: JSON.stringify({ attempt_id: attemptId })
  });
  const data = await res.json();
  if (data.ok) {
    location.href = `test_review.php?attempt_id=${attemptId}`;
  } else {
    alert(data.error);
  }
}

async function autoSubmit() {
  alert('Koha mbaroi. Testi do të dorëzohet.');
  await submitAttempt();
}

document.getElementById('submitBtn').addEventListener('click', () => {
  if (confirm('Dëshironi ta dorëzoni testin?')) submitAttempt();
});

startAttempt();
</script>
</body>
</html>
