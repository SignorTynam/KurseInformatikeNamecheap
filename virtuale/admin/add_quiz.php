<?php
// add_quiz.php — REVAMP (2026) • Krijo quiz të ri (km-* layout) — NO AREA
// - Layout i njëjtë me addCourse/copyCourse: header + cards + sticky side + actionbar
// - CSS: km-lms-forms.css (bazë) + km-quiz-forms.css (shtesa)
// - Flow DB: INSERT quizzes + (nëse ka section) INSERT section_items
// - Redirect: quiz_builder.php?quiz_id=...

declare(strict_types=1);
session_start();
require_once __DIR__ . '/../lib/database.php';

/* -------------------- RBAC -------------------- */
if (!isset($_SESSION['user']) || !in_array($_SESSION['user']['role'] ?? '', ['Administrator','Instruktor'], true)) {
  header('Location: ../login.php'); exit;
}
$ROLE  = (string)($_SESSION['user']['role'] ?? '');
$ME_ID = (int)($_SESSION['user']['id'] ?? 0);

/* -------------------- CSRF -------------------- */
if (empty($_SESSION['csrf_token'])) { $_SESSION['csrf_token'] = bin2hex(random_bytes(32)); }
$CSRF = (string)$_SESSION['csrf_token'];

/* -------------------- Helpers ----------------- */
function h(?string $s): string { return htmlspecialchars($s ?? '', ENT_QUOTES, 'UTF-8'); }
function include_first_existing(array $paths): void {
  foreach ($paths as $p) {
    if (is_file($p)) { include $p; return; }
  }
}

/* -------------------- Inputs ------------------ */
if (!isset($_GET['course_id']) || !is_numeric($_GET['course_id'])) {
  $_SESSION['flash'] = ['msg'=>'Kursi nuk është specifikuar.', 'type'=>'danger'];
  header('Location: ../course.php'); exit;
}
$course_id  = (int)$_GET['course_id'];
$pref_section_id = (isset($_GET['section_id']) && is_numeric($_GET['section_id'])) ? (int)$_GET['section_id'] : 0;

/* -------------------- Access check ------------- */
try {
  $stmt = $pdo->prepare("
    SELECT c.*, u.id AS creator_id, u.full_name AS creator_name
    FROM courses c
    LEFT JOIN users u ON c.id_creator = u.id
    WHERE c.id = ?
    LIMIT 1
  ");
  $stmt->execute([$course_id]);
  $course = $stmt->fetch(PDO::FETCH_ASSOC);
  if (!$course) {
    $_SESSION['flash'] = ['msg'=>'Kursi nuk u gjet.', 'type'=>'danger'];
    header('Location: ../course.php'); exit;
  }
  if ($ROLE === 'Instruktor' && (int)($course['id_creator'] ?? 0) !== $ME_ID) {
    $_SESSION['flash'] = ['msg'=>'Nuk keni akses në këtë kurs.', 'type'=>'danger'];
    header('Location: ../course.php'); exit;
  }
} catch (PDOException $e) {
  $_SESSION['flash'] = ['msg'=>'Gabim: ' . h($e->getMessage()), 'type'=>'danger'];
  header('Location: ../course.php'); exit;
}

/* -------------------- Sections list (NO AREA) -- */
$sections = [];
try {
  $st = $pdo->prepare("SELECT id, title FROM sections WHERE course_id = ? ORDER BY position ASC, id ASC");
  $st->execute([$course_id]);
  $sections = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
} catch (PDOException $e) { /* ignore */ }

/* -------------------- Defaults (form) ---------- */
$err = null;

$titleVal          = '';
$descriptionVal    = '';
$openAtVal         = '';
$closeAtVal        = '';
$timeLimitMinVal   = '';
$attemptsVal       = 1;
$shuffleQChecked   = false;
$shuffleAChecked   = false;
$sectionSelected   = ($pref_section_id > 0) ? $pref_section_id : 0;

/* -------------------- POST --------------------- */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  if (!hash_equals($CSRF, (string)($_POST['csrf'] ?? ''))) {
    $err = 'Seancë e pavlefshme (CSRF). Ringarko faqen.';
  } else {
    $titleVal        = trim((string)($_POST['title'] ?? ''));
    $descriptionVal  = trim((string)($_POST['description'] ?? ''));
    $openAtVal       = (string)($_POST['open_at'] ?? '');
    $closeAtVal      = (string)($_POST['close_at'] ?? '');
    $timeLimitMinVal = (string)($_POST['time_limit_min'] ?? '');
    $attemptsVal     = max(1, (int)($_POST['attempts_allowed'] ?? 1));
    $shuffleQChecked = isset($_POST['shuffle_questions']);
    $shuffleAChecked = isset($_POST['shuffle_answers']);
    $sectionSelected = ((string)($_POST['section_id'] ?? '') !== '') ? (int)$_POST['section_id'] : 0;

    if ($titleVal === '') {
      $err = 'Titulli është i detyrueshëm.';
    } elseif ($openAtVal && $closeAtVal && strtotime($closeAtVal) <= strtotime($openAtVal)) {
      $err = 'Data e mbylljes duhet të jetë pas datës së hapjes.';
    } else {
      try {
        // INSERT quizzes
        $stmtI = $pdo->prepare("
          INSERT INTO quizzes (
            course_id, section_id, title, description, open_at, close_at, time_limit_sec,
            attempts_allowed, shuffle_questions, shuffle_answers, hidden, status
          )
          VALUES (?,?,?,?,?,?,?,?,?,?,0,'DRAFT')
        ");
        $stmtI->execute([
          $course_id,
          ($sectionSelected > 0 ? $sectionSelected : null),
          $titleVal,
          ($descriptionVal !== '' ? $descriptionVal : null),
          ($openAtVal !== '' ? $openAtVal : null),
          ($closeAtVal !== '' ? $closeAtVal : null),
          ($timeLimitMinVal !== '' && (int)$timeLimitMinVal > 0) ? ((int)$timeLimitMinVal * 60) : null,
          $attemptsVal,
          $shuffleQChecked ? 1 : 0,
          $shuffleAChecked ? 1 : 0
        ]);

        $qid = (int)$pdo->lastInsertId();

        // AUTO-LINK te section_items (NO AREA) — vetëm nëse ka section
        if ($sectionSelected > 0) {
          try {
            // pozicioni i radhës brenda atij section
            $stmtPos = $pdo->prepare("
              SELECT COALESCE(MAX(position),0) + 1
              FROM section_items
              WHERE course_id = ? AND section_id = ?
            ");
            $stmtPos->execute([$course_id, $sectionSelected]);
            $nextPos = (int)$stmtPos->fetchColumn();

            $pdo->prepare("
              INSERT INTO section_items (course_id, section_id, item_type, item_ref_id, position)
              VALUES (?,?,?,?,?)
            ")->execute([$course_id, $sectionSelected, 'QUIZ', $qid, $nextPos]);

          } catch (PDOException $e) {
            // e injorojmë: quiz-i u krijua, vetëm link-u dështoi
          }
        }

        $_SESSION['flash'] = ['msg'=>'Quiz u krijua me sukses.', 'type'=>'success'];
        header('Location: quiz_builder.php?quiz_id=' . $qid);
        exit;

      } catch (PDOException $e) {
        $err = 'Gabim DB: ' . h($e->getMessage());
      }
    }
  }
}

/* -------------------- Paths / assets -------------------- */
$ASSET_BASE = is_file(__DIR__ . '/../css/km-lms-forms.css') ? '..' : '.';

$courseTitle = (string)($course['title'] ?? 'Kursi');
$backToCourseHref = $ASSET_BASE . '/course_details.php?course_id=' . (int)$course_id;
?>
<!DOCTYPE html>
<html lang="sq" data-bs-theme="light">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <title>Shto Quiz — <?= h($courseTitle) ?></title>

  <!-- Vendor -->
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
  <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css" rel="stylesheet">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/simplemde/latest/simplemde.min.css">

  <link rel="icon" href="<?= h($ASSET_BASE) ?>/image/favicon.ico" type="image/x-icon" />

  <!-- Base theme -->
  <link rel="stylesheet" href="<?= h($ASSET_BASE) ?>/css/km-lms-forms.css">
  <!-- Quiz-specific -->
  <link rel="stylesheet" href="<?= h($ASSET_BASE) ?>/css/km-quiz-forms.css">
</head>

<body class="km-body">

<?php
  // Navbar (robust paths)
  if ($ROLE === 'Administrator') {
    include_first_existing([
      __DIR__ . '/../navbar_logged_administrator.php',
    ]);
  } else {
    include_first_existing([
      __DIR__ . '/../navbar_logged_instruktor.php',
      __DIR__ . '/../navbar_logged_instruktor.php',
    ]);
  }
?>

<div class="container km-page-shell">

  <!-- Header -->
  <div class="km-page-header">
    <div class="d-flex align-items-start align-items-lg-center justify-content-between gap-3 flex-wrap">
      <div class="flex-grow-1">
        <div class="km-breadcrumb small">
          <a class="km-breadcrumb-link" href="<?= h($backToCourseHref) ?>">
            <?= h($courseTitle) ?>
          </a>
          <span class="mx-1">/</span>
          <span class="km-breadcrumb-current">Shto quiz</span>
        </div>

        <h1 class="km-page-title">
          <i class="fa-regular fa-circle-question me-2 text-primary"></i>
          Shto quiz
        </h1>

        <div class="km-page-subtitle">
          Krijo një quiz të ri në status <strong>DRAFT</strong> dhe vazhdo menjëherë te <strong>Quiz Builder</strong>.
        </div>
      </div>

      <div class="d-flex align-items-center gap-2 flex-wrap">
        <a class="btn btn-outline-secondary km-btn-pill" href="<?= h($backToCourseHref) ?>">
          <i class="fa-solid fa-arrow-left-long me-1"></i> Kthehu te kursi
        </a>
      </div>
    </div>
  </div>

  <?php if ($err): ?>
    <div class="mt-3 km-alert km-alert-danger">
      <div class="d-flex gap-2 align-items-start">
        <i class="fa-solid fa-triangle-exclamation mt-1"></i>
        <div><strong>Gabim:</strong> <?= h($err) ?></div>
      </div>
    </div>
  <?php endif; ?>

  <form method="POST" class="row g-3 km-form-grid mt-2">
    <input type="hidden" name="csrf" value="<?= h($CSRF) ?>">

    <!-- MAIN -->
    <div class="col-12 col-lg-8">

      <div class="km-card km-card-main">
        <div class="km-card-header">
          <div>
            <div class="km-card-title">
              <span class="km-step-badge">1</span>
              Konfigurimi i quiz-it
            </div>
            <div class="km-card-subtitle">
              Plotëso detajet bazë, afatet, kohën dhe opsionet e integritetit.
            </div>
          </div>

          <!-- Tabs (km style) -->
          <div class="km-quiz-tabs" role="tablist" aria-label="Quiz tabs">
            <button class="km-quiz-tab active" type="button" data-km-tab="details">
              <i class="fa-regular fa-rectangle-list me-1"></i> Detajet
            </button>
            <button class="km-quiz-tab" type="button" data-km-tab="timing">
              <i class="fa-regular fa-clock me-1"></i> Koha & integriteti
            </button>
          </div>
        </div>

        <div class="km-card-body">

          <!-- TAB: DETAILS -->
          <section class="km-quiz-pane is-active" data-km-pane="details">
            <div class="mb-3">
              <label class="form-label">Titulli <span class="text-danger">*</span></label>
              <input class="form-control"
                     name="title"
                     required
                     placeholder="p.sh. Quiz 1 — Variablat & kushtet"
                     value="<?= h($titleVal) ?>">
              <div class="km-help-text mt-1">Titull i shkurtër, i qartë dhe specifik për temën.</div>
            </div>

            <div class="row g-3">
              <div class="col-md-6">
                <label class="form-label">Seksioni (opsional)</label>
                <select class="form-select" name="section_id" id="sectionSelect">
                  <option value="">— Pa seksion —</option>
                  <?php foreach ($sections as $s): ?>
                    <option value="<?= (int)$s['id'] ?>" <?= ($sectionSelected===(int)$s['id'])?'selected':'' ?>>
                      <?= h((string)$s['title']) ?>
                    </option>
                  <?php endforeach; ?>
                </select>
                <div class="km-help-text mt-1">
                  Nëse lë “Pa seksion”, quiz-i krijohet, por <strong>nuk lidhet</strong> automatikisht te <code>section_items</code>.
                </div>
              </div>

              <div class="col-md-6">
                <label class="form-label">Tentativa të lejuara</label>
                <div class="d-flex gap-2 align-items-center flex-wrap">
                  <input type="number"
                         class="form-control"
                         style="max-width: 160px;"
                         name="attempts_allowed"
                         min="1"
                         value="<?= (int)$attemptsVal ?>"
                         id="triesInput"
                         aria-describedby="triesHelp">

                  <div class="d-flex gap-2 flex-wrap">
                    <button class="km-type-pill" type="button" data-tries="1">1</button>
                    <button class="km-type-pill" type="button" data-tries="2">2</button>
                    <button class="km-type-pill" type="button" data-tries="3">3</button>
                    <button class="km-type-pill" type="button" data-tries="5">5</button>
                  </div>
                </div>
                <div id="triesHelp" class="km-help-text mt-1">Mund të ndryshohet edhe te “Quiz Builder”.</div>
              </div>
            </div>

            <div class="mt-3">
              <label class="form-label">Përshkrimi (opsional, Markdown)</label>
              <textarea id="description" name="description"><?= h($descriptionVal) ?></textarea>
              <div class="km-help-text mt-1">
                Përdor Markdown për lista, kode, linke. Për studentët shfaqet si hyrje e quiz-it.
              </div>
            </div>

            <div class="km-quiz-divider my-3"></div>

            <div class="d-flex gap-2 flex-wrap">
              <span class="km-pill-meta">
                <i class="fa-solid fa-circle-info"></i>
                Statusi fillestar: DRAFT
              </span>
              <span class="km-pill-meta">
                <i class="fa-solid fa-wand-magic-sparkles"></i>
                Vazhdon te Builder pas krijimit
              </span>
            </div>
          </section>

          <!-- TAB: TIMING -->
          <section class="km-quiz-pane" data-km-pane="timing">
            <div class="row g-3 align-items-end">
              <div class="col-md-4">
                <label class="form-label">Hapet më</label>
                <input type="datetime-local" class="form-control" name="open_at" value="<?= h($openAtVal) ?>" id="openAt">
                <div class="km-help-text mt-1">Bosh = i hapur pas publikimit.</div>
              </div>

              <div class="col-md-4">
                <label class="form-label">Mbyllet më</label>
                <input type="datetime-local" class="form-control" name="close_at" value="<?= h($closeAtVal) ?>" id="closeAt">
                <div class="km-help-text mt-1">Duhet të jetë pas datës së hapjes.</div>
                <div class="invalid-feedback">Mbyllja duhet të jetë pas hapjes.</div>
              </div>

              <div class="col-md-4">
                <label class="form-label">Kohëzgjatja (min)</label>
                <input type="number" class="form-control" name="time_limit_min" min="0"
                       placeholder="0 = pa limit" value="<?= h($timeLimitMinVal) ?>" id="limitInput">
                <div class="km-help-text mt-1">0 / bosh = pa limit. Mund të ndryshohet te Builder.</div>
              </div>
            </div>

            <div class="mt-3">
              <div class="km-help-text mb-2">
                Preset të shpejta (aplikohen te fusha aktive: Hapet/Mbyllet):
              </div>
              <div class="d-flex flex-wrap gap-2">
                <button class="km-type-pill" type="button" data-dtpreset="now">Tani</button>
                <button class="km-type-pill" type="button" data-dtpreset="in1h">+1 orë</button>
                <button class="km-type-pill" type="button" data-dtpreset="t23">Sot • 23:00</button>
                <button class="km-type-pill" type="button" data-dtpreset="tom9">Nesër • 09:00</button>
                <button class="km-type-pill" type="button" data-dtpreset="tom23">Nesër • 23:59</button>
              </div>
            </div>

            <div class="km-quiz-divider my-3"></div>

            <div class="row g-3">
              <div class="col-md-6">
                <div class="form-check form-switch">
                  <input class="form-check-input" type="checkbox" name="shuffle_questions" id="sq" <?= $shuffleQChecked?'checked':'' ?>>
                  <label for="sq" class="form-check-label">
                    Përziej pyetjet
                    <span class="km-help-text d-block">Rrit integritetin, por e bën më pak “të standardizuar”.</span>
                  </label>
                </div>
              </div>

              <div class="col-md-6">
                <div class="form-check form-switch">
                  <input class="form-check-input" type="checkbox" name="shuffle_answers" id="sa" <?= $shuffleAChecked?'checked':'' ?>>
                  <label for="sa" class="form-check-label">
                    Përziej përgjigjet
                    <span class="km-help-text d-block">E dobishme sidomos te MCQ.</span>
                  </label>
                </div>
              </div>
            </div>

            <div class="km-quiz-divider my-3"></div>

            <div class="d-flex gap-2 flex-wrap">
              <button class="km-type-pill" type="button" data-limit="15">15 min</button>
              <button class="km-type-pill" type="button" data-limit="30">30 min</button>
              <button class="km-type-pill" type="button" data-limit="60">60 min</button>
              <button class="km-type-pill" type="button" data-limit="0">Pa limit</button>
            </div>

            <div class="km-help-text mt-2">
              Këto janë vetëm preset; fusha “Kohëzgjatja” mbetet e editueshme.
            </div>
          </section>

        </div>
      </div>

      <!-- Actionbar -->
      <div class="km-quiz-actionbar mt-3">
        <div class="km-quiz-actionbar-left">
          <div class="km-quiz-sumrow">
            <span><i class="fa-regular fa-clock me-1"></i><span id="sum-open">—</span> → <span id="sum-close">—</span></span>
            <span><i class="fa-regular fa-circle-dot me-1"></i>Tentativa: <strong id="sum-tries"><?= (int)$attemptsVal ?></strong></span>
            <span><i class="fa-regular fa-hourglass-half me-1"></i>Kohëzgjatja: <strong id="sum-limit">Pa limit</strong></span>
          </div>
        </div>

        <div class="km-quiz-actionbar-right">
          <button class="btn btn-primary km-btn-pill" type="submit">
            <i class="fa-solid fa-plus me-1"></i> Krijo dhe vazhdo
          </button>

          <button class="btn btn-outline-secondary km-btn-pill" type="button" data-bs-toggle="offcanvas" data-bs-target="#previewCanvas">
            <i class="fa-regular fa-eye me-1"></i> Parapamje
          </button>

          <a class="btn btn-outline-secondary km-btn-pill" href="<?= h($backToCourseHref) ?>">
            <i class="fa-solid fa-arrow-left-long me-1"></i> Kthehu
          </a>
        </div>
      </div>

    </div>

    <!-- SIDE -->
    <div class="col-12 col-lg-4">

      <div class="km-card km-card-side km-sticky-side">
        <div class="km-card-header">
          <div>
            <div class="km-card-title">
              <span class="km-step-badge">2</span>
              Parapamje & këshilla
            </div>
            <div class="km-card-subtitle">Kontroll i shpejtë para krijimit.</div>
          </div>
          <span class="km-pill-meta"><i class="fa-regular fa-eye"></i> Live</span>
        </div>

        <div class="km-card-body">
          <div class="km-quiz-preview-box">
            <div class="km-quiz-preview-row">
              <span class="text-secondary">Titulli</span>
              <strong id="pv-title">—</strong>
            </div>
            <div class="km-quiz-preview-row">
              <span class="text-secondary">Kursi</span>
              <span><?= h($courseTitle) ?></span>
            </div>
            <div class="km-quiz-preview-row">
              <span class="text-secondary">Seksioni</span>
              <span id="pv-section">—</span>
            </div>
            <div class="km-quiz-preview-row">
              <span class="text-secondary">Hapet</span>
              <span id="pv-open">—</span>
            </div>
            <div class="km-quiz-preview-row">
              <span class="text-secondary">Mbyllet</span>
              <span id="pv-close">—</span>
            </div>
            <div class="km-quiz-preview-row">
              <span class="text-secondary">Kohëzgjatja</span>
              <span id="pv-limit">Pa limit</span>
            </div>
            <div class="km-quiz-preview-row">
              <span class="text-secondary">Tentativa</span>
              <span id="pv-tries"><?= (int)$attemptsVal ?></span>
            </div>
            <div class="km-quiz-preview-row">
              <span class="text-secondary">Përziej</span>
              <span id="pv-shuffle">—</span>
            </div>
          </div>

          <div class="km-quiz-divider my-3"></div>

          <div class="km-side-title mb-2">
            <i class="fa-regular fa-lightbulb me-2 text-primary"></i> Këshilla
          </div>
          <ul class="km-checklist">
            <li><i class="fa-solid fa-check"></i> Vendos 0 minuta për “pa limit”.</li>
            <li><i class="fa-solid fa-check"></i> Afatet: Mbyllja duhet të jetë pas hapjes.</li>
            <li><i class="fa-solid fa-check"></i> “Përziej” rrit integritetin (sidomos te MCQ).</li>
            <li><i class="fa-solid fa-check"></i> Zgjidh seksion nëse do ta lidhësh te <code>section_items</code>.</li>
          </ul>

          <div class="km-help-text mt-3">
            Quiz-i krijohet si <strong>DRAFT</strong> dhe detajet e pyetjeve i shton te <strong>Quiz Builder</strong>.
          </div>
        </div>
      </div>

    </div>
  </form>

  <!-- Offcanvas Preview (mobile-friendly) -->
  <div class="offcanvas offcanvas-end km-quiz-offcanvas" tabindex="-1" id="previewCanvas" aria-labelledby="previewCanvasLabel">
    <div class="offcanvas-header">
      <h5 class="offcanvas-title" id="previewCanvasLabel">
        <i class="fa-regular fa-eye me-2"></i> Parapamje
      </h5>
      <button type="button" class="btn-close" data-bs-dismiss="offcanvas" aria-label="Mbyll"></button>
    </div>
    <div class="offcanvas-body">
      <div class="km-quiz-preview-box">
        <div class="km-quiz-preview-row"><span class="text-secondary">Titulli</span><strong id="pv2-title">—</strong></div>
        <div class="km-quiz-preview-row"><span class="text-secondary">Kursi</span><span><?= h($courseTitle) ?></span></div>
        <div class="km-quiz-preview-row"><span class="text-secondary">Seksioni</span><span id="pv2-section">—</span></div>
        <div class="km-quiz-preview-row"><span class="text-secondary">Hapet</span><span id="pv2-open">—</span></div>
        <div class="km-quiz-preview-row"><span class="text-secondary">Mbyllet</span><span id="pv2-close">—</span></div>
        <div class="km-quiz-preview-row"><span class="text-secondary">Kohëzgjatja</span><span id="pv2-limit">Pa limit</span></div>
        <div class="km-quiz-preview-row"><span class="text-secondary">Tentativa</span><span id="pv2-tries">1</span></div>
        <div class="km-quiz-preview-row"><span class="text-secondary">Përziej</span><span id="pv2-shuffle">—</span></div>
      </div>

      <div class="km-help-text mt-3">
        Kjo është parapamje e shpejtë; detajet e plota konfigurohen te “Quiz Builder”.
      </div>
    </div>
  </div>

  <div class="mt-3"></div>
</div>

<?php
  include_first_existing([
    __DIR__ . '/../footer2.php',
    __DIR__ . '/../footer.php',
    __DIR__ . '/footer2.php',
    __DIR__ . '/footer.php',
  ]);
?>

<!-- JS vendor -->
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script src="https://cdn.jsdelivr.net/simplemde/latest/simplemde.min.js"></script>

<script>
/* ---------------- Markdown editor ---------------- */
const simplemde = new SimpleMDE({
  element: document.getElementById('description'),
  toolbar: ['bold','italic','heading','|','code','quote','unordered-list','ordered-list','|','link','image','table','|','preview','guide'],
  spellChecker: false,
  placeholder: "Përshkrim i shkurtër i quiz-it (opsional)..."
});

/* ---------------- Tabs (km) ---------------- */
document.querySelectorAll('.km-quiz-tab').forEach(btn => {
  btn.addEventListener('click', () => {
    const key = btn.getAttribute('data-km-tab');
    document.querySelectorAll('.km-quiz-tab').forEach(b => b.classList.toggle('active', b === btn));
    document.querySelectorAll('.km-quiz-pane').forEach(p => p.classList.toggle('is-active', p.getAttribute('data-km-pane') === key));
  });
});

/* ---------------- Elements ---------------- */
const elTitle   = document.querySelector('input[name="title"]');
const elSection = document.querySelector('select[name="section_id"]');
const openEl    = document.getElementById('openAt');
const closeEl   = document.getElementById('closeAt');
const triesEl   = document.getElementById('triesInput');
const limitEl   = document.getElementById('limitInput');
const sqEl      = document.getElementById('sq');
const saEl      = document.getElementById('sa');

/* ---------------- Helpers ---------------- */
function fmtDT(v){ return v ? v.replace('T',' · ') : '—'; }
function fmtLimit(v){
  const raw = String(v || '').trim();
  const n = parseInt(raw, 10);
  if (!raw || !isFinite(n) || n <= 0) return 'Pa limit';
  return n + ' min';
}
function shuffleText(){
  const q = !!sqEl?.checked;
  const a = !!saEl?.checked;
  if (!q && !a) return 'Jo';
  if (q && a) return 'Pyetje + Përgjigje';
  if (q) return 'Pyetje';
  return 'Përgjigje';
}

/* ---------------- Chips: attempts ---------------- */
const triesChips = document.querySelectorAll('[data-tries]');
function syncTriesChips(){
  const v = String(triesEl.value || '1');
  triesChips.forEach(c => c.classList.toggle('active', c.getAttribute('data-tries') === v));
}
triesChips.forEach(ch => {
  ch.addEventListener('click', () => {
    triesEl.value = ch.getAttribute('data-tries') || '1';
    syncTriesChips();
    refreshAll();
  });
});

/* ---------------- Chips: time limit presets ---------------- */
const limitChips = document.querySelectorAll('[data-limit]');
function syncLimitChips(){
  const raw = String(limitEl.value || '').trim();
  const v = (raw === '' || parseInt(raw,10) <= 0) ? '0' : raw;
  limitChips.forEach(c => c.classList.toggle('active', c.getAttribute('data-limit') === v));
}
limitChips.forEach(ch => {
  ch.addEventListener('click', () => {
    const v = ch.getAttribute('data-limit') || '0';
    limitEl.value = (v === '0') ? '' : v;
    syncLimitChips();
    refreshAll();
  });
});

/* ---------------- Presets datetime ---------------- */
document.querySelectorAll('[data-dtpreset]').forEach(ch => {
  ch.addEventListener('click', () => {
    const p = ch.getAttribute('data-dtpreset');
    const now = new Date();
    const pad = n => String(n).padStart(2,'0');
    const toLocal = d => `${d.getFullYear()}-${pad(d.getMonth()+1)}-${pad(d.getDate())}T${pad(d.getHours())}:${pad(d.getMinutes())}`;

    let d1 = new Date(now);
    if (p==='now') d1 = new Date(now);
    if (p==='in1h') d1 = new Date(now.getTime()+3600*1000);
    if (p==='t23') { d1 = new Date(now); d1.setHours(23,0,0,0); }
    if (p==='tom9') { d1 = new Date(now); d1.setDate(d1.getDate()+1); d1.setHours(9,0,0,0); }
    if (p==='tom23'){ d1 = new Date(now); d1.setDate(d1.getDate()+1); d1.setHours(23,59,0,0); }

    const activeIsClose = (document.activeElement === closeEl);
    (activeIsClose ? closeEl : openEl).value = toLocal(d1);

    validateDates();
    refreshAll();
  });
});

/* ---------------- Validate dates ---------------- */
function validateDates(){
  const o = openEl.value ? new Date(openEl.value) : null;
  const c = closeEl.value ? new Date(closeEl.value) : null;
  closeEl.classList.remove('is-invalid');
  if (o && c && c <= o) closeEl.classList.add('is-invalid');
}

/* ---------------- Live summary + preview ---------------- */
function refreshAll(){
  document.getElementById('sum-open').textContent  = fmtDT(openEl.value);
  document.getElementById('sum-close').textContent = fmtDT(closeEl.value);
  document.getElementById('sum-tries').textContent = triesEl.value || '1';
  document.getElementById('sum-limit').textContent = fmtLimit(limitEl.value);

  const title = elTitle.value || '—';
  const secTxt = elSection?.selectedOptions?.[0]?.textContent?.trim() || '—';

  // Side preview
  document.getElementById('pv-title').textContent = title;
  document.getElementById('pv-section').textContent = secTxt;
  document.getElementById('pv-open').textContent  = fmtDT(openEl.value);
  document.getElementById('pv-close').textContent = fmtDT(closeEl.value);
  document.getElementById('pv-limit').textContent = fmtLimit(limitEl.value);
  document.getElementById('pv-tries').textContent = triesEl.value || '1';
  document.getElementById('pv-shuffle').textContent = shuffleText();

  // Offcanvas preview
  document.getElementById('pv2-title').textContent = title;
  document.getElementById('pv2-section').textContent = secTxt;
  document.getElementById('pv2-open').textContent  = fmtDT(openEl.value);
  document.getElementById('pv2-close').textContent = fmtDT(closeEl.value);
  document.getElementById('pv2-limit').textContent = fmtLimit(limitEl.value);
  document.getElementById('pv2-tries').textContent = triesEl.value || '1';
  document.getElementById('pv2-shuffle').textContent = shuffleText();
}

[elTitle, elSection, openEl, closeEl, triesEl, limitEl, sqEl, saEl].forEach(el => {
  el?.addEventListener('input', () => { validateDates(); refreshAll(); });
  el?.addEventListener('change', () => { validateDates(); refreshAll(); });
});

syncTriesChips();
syncLimitChips();
validateDates();
refreshAll();
</script>
</body>
</html>
