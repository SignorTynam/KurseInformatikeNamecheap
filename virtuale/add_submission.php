<?php
// add_submission.php — Student Submit (schema-safe, unified student theme)
// Përmirësuar: UI më i bukur, shfaqje e përshkrimit (Markdown), skedarë resource/solution,
// path i unifikuar i dorëzimeve: /uploads/submissions/{assignment_id}/{user_id}/...
declare(strict_types=1);
session_start();
require_once __DIR__ . '/lib/database.php';
require_once __DIR__ . '/lib/Parsedown.php';

/* ------------------------------- Helpers ------------------------------- */
function h(?string $s): string { return htmlspecialchars($s ?? '', ENT_QUOTES, 'UTF-8'); }
function allow_ext(string $name, array $whitelist): bool {
  $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
  return in_array($ext, $whitelist, true);
}
function safe_filename(string $name): string {
  $base = preg_replace('/[^a-zA-Z0-9_\-\.]/', '_', basename($name));
  return $base !== '' ? $base : ('file_'.time());
}
function flash_set(string $msg, string $type='danger'): void {
  $_SESSION['flash_msg']  = $msg;
  $_SESSION['flash_type'] = $type;
}
function flash_get(): ?array {
  if (!isset($_SESSION['flash_msg'])) return null;
  $out = ['msg'=>$_SESSION['flash_msg'], 'type'=>($_SESSION['flash_type'] ?? 'danger')];
  unset($_SESSION['flash_msg'], $_SESSION['flash_type']);
  return $out;
}
function file_size_pretty(?string $abs): string {
  if (!$abs || !is_file($abs)) return '—';
  $b = filesize($abs);
  if ($b >= 1024*1024) return max(1, (int)round($b/1024/1024)) . ' MB';
  return max(1, (int)round($b/1024)) . ' KB';
}

/* ------------------------------- RBAC ---------------------------------- */
if (empty($_SESSION['user']) || (($_SESSION['user']['role'] ?? '') !== 'Student')) {
  header("Location: login.php"); exit;
}
$userId = (int)($_SESSION['user']['id'] ?? 0);
$SELF   = basename(__FILE__);

/* ----------------------------- Inputs ---------------------------------- */
$assignment_id = isset($_GET['assignment_id']) ? (int)$_GET['assignment_id'] : 0;
$course_id     = isset($_GET['course_id']) ? (int)$_GET['course_id'] : 0;
if ($assignment_id <= 0 || $course_id <= 0) {
  http_response_code(400); exit('Parametra të pavlefshëm.');
}

/* ------------------- Load assignment + checks (schema-safe) ------------- */
try {
  $stmt = $pdo->prepare("
    SELECT 
      a.id, a.course_id, a.section_id, a.title, a.description, a.due_date, a.status, a.hidden,
      a.resource_path, a.solution_path,
      c.title AS course_title,
      s.hidden AS section_hidden
    FROM assignments a
    JOIN courses  c ON c.id = a.course_id
    LEFT JOIN sections s ON s.id = a.section_id
    WHERE a.id = ?
    LIMIT 1
  ");
  $stmt->execute([$assignment_id]);
  $assignment = $stmt->fetch(PDO::FETCH_ASSOC);
  if (!$assignment) { http_response_code(404); exit('Detyra nuk u gjet.'); }

  if ((int)$assignment['course_id'] !== $course_id) {
    http_response_code(400); exit('Detyra nuk i përket kursit të specifikuar.');
  }

  // Student must be enrolled
  $chk = $pdo->prepare("SELECT 1 FROM enroll WHERE course_id = ? AND user_id = ? LIMIT 1");
  $chk->execute([$course_id, $userId]);
  if (!$chk->fetchColumn()) { http_response_code(403); exit('Nuk jeni i regjistruar në këtë kurs.'); }

} catch (PDOException $e) {
  http_response_code(500); exit('Gabim DB.');
}

/* ---------------------------- Visibility & dates ------------------------ */
$assignHidden     = (int)($assignment['hidden'] ?? 0) === 1;
$sectionHidden    = isset($assignment['section_hidden']) && (int)$assignment['section_hidden'] === 1;
$effectiveHidden  = $assignHidden || $sectionHidden;
if ($effectiveHidden) {
  http_response_code(403); exit('Kjo detyrë nuk është e disponueshme.');
}

// Due date — end-of-day
$dueDateStr = (string)($assignment['due_date'] ?? '');
$dueTs      = $dueDateStr ? strtotime($dueDateStr . ' 23:59:59') : null;
$expired    = $dueTs !== null && time() > $dueTs;

$status           = (string)($assignment['status'] ?? 'PENDING');
$expiredByStatus  = (strcasecmp($status, 'EXPIRED') === 0);
$submittable      = !$expired && !$expiredByStatus;

/* --------------------------- Markdown (safe) ---------------------------- */
$Parsedown = new Parsedown();
if (method_exists($Parsedown, 'setSafeMode')) { $Parsedown->setSafeMode(true); }
$descHtml = $Parsedown->text((string)($assignment['description'] ?? ''));

/* ---------------------------- CSRF token -------------------------------- */
if (empty($_SESSION['csrf_submit'])) { $_SESSION['csrf_submit'] = bin2hex(random_bytes(32)); }
$csrf = $_SESSION['csrf_submit'];

/* ------------------------- Existing submission -------------------------- */
try {
  $st = $pdo->prepare("
    SELECT id, file_path, submitted_at, grade, feedback
    FROM assignments_submitted
    WHERE assignment_id = ? AND user_id = ?
    ORDER BY id DESC
    LIMIT 1
  ");
  $st->execute([$assignment_id, $userId]);
  $existing = $st->fetch(PDO::FETCH_ASSOC) ?: null;
} catch (PDOException $e) {
  $existing = null;
}

/* ------------------------------ POST: submit ---------------------------- */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'submit') {
  if (!hash_equals($csrf, (string)($_POST['csrf'] ?? ''))) {
    flash_set('Seancë e pasigurt/CSRF. Ringarkoni faqen.');
    header("Location: {$SELF}?assignment_id=$assignment_id&course_id=$course_id"); exit;
  }

  if ($effectiveHidden) {
    flash_set('Kjo detyrë është e padukshme. Dorëzimi nuk lejohet.');
    header("Location: {$SELF}?assignment_id=$assignment_id&course_id=$course_id"); exit;
  }
  if (!$submittable) {
    flash_set('Afati ka kaluar ose detyra është shënuar si EXPIRED.');
    header("Location: {$SELF}?assignment_id=$assignment_id&course_id=$course_id"); exit;
  }

  // File validations
  if (!isset($_FILES['submission_file']) || $_FILES['submission_file']['error'] === UPLOAD_ERR_NO_FILE) {
    flash_set('Ju lutem zgjidhni një skedar për dorëzim.');
    header("Location: {$SELF}?assignment_id=$assignment_id&course_id=$course_id"); exit;
  }
  $file = $_FILES['submission_file'];
  if ($file['error'] !== UPLOAD_ERR_OK) {
    flash_set('Gabim gjatë ngarkimit të skedarit.');
    header("Location: {$SELF}?assignment_id=$assignment_id&course_id=$course_id"); exit;
  }
  if ($file['size'] > 10 * 1024 * 1024) {
    flash_set('Skedari tejkalon madhësinë maksimale 10 MB.');
    header("Location: {$SELF}?assignment_id=$assignment_id&course_id=$course_id"); exit;
  }
  $allowed = ['pdf', 'doc', 'docx', 'zip', 'rar', 'txt', 'png', 'jpg', 'jpeg', 'html', 'css', 'js', 'php', 'py', 'java', 'cpp', 'c', 'cs', 'rb', 'swift', 'go', 'ts', 'sql', 'xml', 'json', 'yaml', 'md'];
  if (!allow_ext($file['name'], $allowed)) {
    flash_set('Lloji i skedarit nuk lejohet. Lejohen: pdf, doc, docx, zip, rar, txt, png, jpg, dhe skedarë programimi.');
    header("Location: {$SELF}?assignment_id=$assignment_id&course_id=$course_id"); exit;
  }

  // Save — path i unifikuar me delete_assignment.php
  $baseDir = __DIR__ . '/uploads/submissions';
  if (!is_dir($baseDir)) { @mkdir($baseDir, 0755, true); }
  $subDir = $baseDir . '/' . $assignment_id . '/' . $userId;
  if (!is_dir($subDir)) { @mkdir($subDir, 0755, true); }

  $safe    = safe_filename($file['name']);
  $newName = time() . '-' . bin2hex(random_bytes(6)) . '-' . $safe;
  $destFs  = $subDir . '/' . $newName;

  if (!is_uploaded_file($file['tmp_name']) || !move_uploaded_file($file['tmp_name'], $destFs)) {
    flash_set('Skedari nuk u ruajt në server.');
    header("Location: {$SELF}?assignment_id=$assignment_id&course_id=$course_id"); exit;
  }
  $webPath = 'uploads/submissions/' . $assignment_id . '/' . $userId . '/' . $newName;

  // Upsert (one submission per student/assignment)
  try {
    if ($existing) {
      if (!empty($existing['file_path'])) {
        $old = __DIR__ . '/' . ltrim((string)$existing['file_path'], '/');
        if (is_file($old)) { @unlink($old); }
      }
      $up = $pdo->prepare("UPDATE assignments_submitted SET file_path=?, submitted_at=NOW(), grade=NULL, feedback=NULL WHERE id=?");
      $up->execute([$webPath, (int)$existing['id']]);
    } else {
      $ins = $pdo->prepare("INSERT INTO assignments_submitted (assignment_id, user_id, file_path, submitted_at) VALUES (?,?,?,NOW())");
      $ins->execute([$assignment_id, $userId, $webPath]);
    }
    flash_set('Detyra u dorëzua me sukses.', 'success');
  } catch (PDOException $e) {
    @unlink($destFs);
    flash_set('Gabim gjatë regjistrimit të dorëzimit.');
  }

  header("Location: {$SELF}?assignment_id=$assignment_id&course_id=$course_id"); exit;
}

/* ------------------------------ Flash msg ------------------------------ */
$flash = flash_get();

/* ----------------------- Resource/Solution presence -------------------- */
$resource_path = (string)($assignment['resource_path'] ?? '');
$solution_path = (string)($assignment['solution_path'] ?? '');
$resource_abs  = $resource_path ? (__DIR__ . '/' . ltrim($resource_path,'/')) : '';
$solution_abs  = $solution_path ? (__DIR__ . '/' . ltrim($solution_path,'/')) : '';
$resource_exists = $resource_abs ? is_file($resource_abs) : false;
$solution_exists = $solution_abs ? is_file($solution_abs) : false;
$solution_visible_to_student = $solution_exists && ($expired || $expiredByStatus);
?>
<!DOCTYPE html>
<html lang="sq" data-bs-theme="light">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Dorëzo detyrën — kurseinformatike.com</title>
  <link rel="icon" href="image/favicon.ico" type="image/x-icon" />
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css">
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css">
  <link rel="stylesheet" href="css/km-lms-forms.css">
  <style>
    /* page-specific polish, aligned with km-lms-forms.css */
    .km-page-shell{ padding-top: 14px; }
    .km-breadcrumb .sep{ opacity:.55; padding:0 .45rem; }

    .km-alert-elev{
      border-radius: calc(var(--r) + 6px);
      border: 1px solid rgba(229,231,235,.95);
      box-shadow: var(--shadow-soft);
    }

    .as-pill{
      display:inline-flex;
      align-items:center;
      gap:.35rem;
      padding:.35rem .7rem;
      border-radius: 999px;
      font-weight: 900;
      font-size: .85rem;
      border: 1px solid rgba(229,231,235,.95);
      background: #ffffff;
      box-shadow: 0 6px 18px rgba(15,23,42,.04);
      color: #334155;
    }
    .as-pill.ok{ background:#ecfdf5; color:#065f46; border-color: rgba(16,185,129,.18); }
    .as-pill.bad{ background:#fef2f2; color:#991b1b; border-color: rgba(239,68,68,.20); }
    .as-pill.warn{ background:#fffbeb; color:#854d0e; border-color: rgba(245,158,11,.22); }

    .as-file-card{
      border: 1px solid rgba(229,231,235,.95);
      border-radius: var(--r-sm);
      padding: 12px 12px;
      background: #ffffff;
    }

    .as-file-drop{
      border: 2px dashed rgba(229,231,235,.95);
      border-radius: var(--r-sm);
      padding: 22px;
      text-align: center;
      background: #fbfdff;
      cursor: pointer;
      transition: .18s ease;
    }
    .as-file-drop.drag{ border-color: rgba(240,179,35,.9); background: #fff9e6; }

    .md-content{ color: #0f172a; }
    .md-content :is(h1,h2,h3){ margin:.35rem 0; color:#0f172a; }
    .md-content p{ margin-bottom:.55rem; }
    .md-content a{ color:#0d6efd; text-decoration:underline; }
    .md-content code{ background:#f1f5f9; padding:0 .25rem; border-radius:6px; }
    .md-content pre{ background:#0f172a; color:#f1f5f9; padding:12px; border-radius:12px; overflow:auto; }
    .md-content ul{ padding-left:1.1rem; margin:.2rem 0; }
    .desc-collapsed{ max-height:7.2rem; overflow:hidden; position:relative; }
    .desc-collapsed::after{
      content:""; position:absolute; left:0; right:0; bottom:0; height:2.1rem;
      background:linear-gradient(to bottom, rgba(255,255,255,0), rgba(255,255,255,.98));
    }
    .md-toggle{ color:#0d6efd; background:none; border:0; padding:0; font-weight:800; }

    /* Buttons: consistent pill sizing/alignment (page-scoped) */
    .as-btn{
      border-radius: 999px;
      font-weight: 900;
      display: inline-flex;
      align-items: center;
      gap: .42rem;
      white-space: nowrap;
      line-height: 1.1;
    }
    .as-btn.btn-sm{
      padding: .38rem .66rem;
      font-size: .82rem;
    }
    .as-btn:not(.btn-sm){
      padding: .62rem .92rem;
    }
    .as-btn i{ line-height: 1; }
    .btn-outline-secondary.as-btn,
    .btn-outline-success.as-btn{
      background: #fff;
    }
  </style>
</head>
<body class="km-body">

<?php include __DIR__ . '/navbar_logged_student.php'; ?>

<main class="km-page-shell">
  <div class="container">

    <!-- Header (consistent with other km-* pages) -->
    <header class="km-page-header">
      <div class="d-flex align-items-start justify-content-between gap-3 flex-wrap">
        <div>
          <div class="km-breadcrumb small">
            <a class="km-breadcrumb-link" href="dashboard_student.php">Kryefaqja</a>
            <span class="sep">/</span>
            <a class="km-breadcrumb-link" href="course_details_student.php?course_id=<?= (int)$assignment['course_id'] ?>"><?= h($assignment['course_title']) ?></a>
            <span class="sep">/</span>
            <span class="km-breadcrumb-current">Dorëzo detyrën</span>
          </div>
          <h1 class="km-page-title mt-2"><i class="fa-solid fa-clipboard-check me-2"></i><?= h($assignment['title']) ?></h1>
          <div class="km-page-subtitle">Kursi: <strong><?= h($assignment['course_title']) ?></strong></div>
        </div>

        <div class="d-flex align-items-center gap-2 flex-wrap justify-content-end">
          <span class="km-pill-meta">
            <i class="fa-regular fa-calendar"></i>
            <?= $dueTs ? ('Afati: ' . date('d M Y', $dueTs)) : 'Pa afat' ?>
          </span>
          <?php if ($expired || $expiredByStatus): ?>
            <span class="as-pill bad"><i class="fa-solid fa-triangle-exclamation"></i>Afati mbaroi</span>
          <?php else: ?>
            <span class="as-pill ok"><i class="fa-regular fa-circle-check"></i>Mund të dorëzohet</span>
          <?php endif; ?>
        </div>
      </div>
    </header>

  <?php if ($flash): ?>
    <div class="alert <?= ($flash['type'] ?? '')==='success' ? 'alert-success' : 'alert-danger' ?> km-alert-elev d-flex align-items-center gap-2 mt-3">
      <i class="fa-solid <?= ($flash['type'] ?? '')==='success' ? 'fa-circle-check' : 'fa-triangle-exclamation' ?>"></i>
      <div><?= h($flash['msg'] ?? '') ?></div>
    </div>
  <?php endif; ?>

  <div class="row g-3 g-lg-4 km-form-grid">
    <!-- Kolona majtas: Përshkrimi + Skedarët -->
    <div class="col-lg-7">
      <section class="km-card km-card-main mb-3">
        <div class="km-card-header">
          <div>
            <h2 class="km-card-title"><span class="km-step-badge">1</span> Detajet e detyrës</h2>
            <div class="km-card-subtitle">Lexo udhëzimet dhe kontrollo afatin.</div>
          </div>
          <?php if ($expired || $expiredByStatus): ?>
            <span class="as-pill warn"><i class="fa-regular fa-clock"></i> Afati ka përfunduar</span>
          <?php endif; ?>
        </div>
        <div class="km-card-body">
          <div id="md-box" class="md-content desc-collapsed"><?= $descHtml ?></div>
          <?php if (!empty(trim((string)$assignment['description']))): ?>
            <button id="md-toggle" class="md-toggle mt-2">Shiko më shumë</button>
          <?php endif; ?>
        </div>
      </section>

      <section class="km-card km-card-main">
        <div class="km-card-header">
          <div>
            <h2 class="km-card-title"><span class="km-step-badge">2</span>Skedarët</h2>
            <div class="km-card-subtitle">Shkarko skedarin e detyrës dhe, pas afatit, zgjidhjen.</div>
          </div>
        </div>
        <div class="km-card-body">

        <!-- Skedari i detyrës -->
        <div class="as-file-card mb-2 d-flex align-items-center justify-content-between">
          <div class="d-flex align-items-center gap-2">
            <i class="fa-regular fa-file text-primary"></i>
            <div>
              <div class="fw-semibold">Skedari i detyrës</div>
              <div class="small text-muted">
                <?php if ($resource_path && $resource_exists): ?>
                  <?= h(basename($resource_path)) ?> • <?= h(file_size_pretty($resource_abs)) ?>
                <?php else: ?>
                  Nuk ka skedar të detyrës.
                <?php endif; ?>
              </div>
            </div>
          </div>
          <?php if ($resource_path && $resource_exists): ?>
            <a class="btn btn-sm btn-primary km-btn-pill as-btn" href="<?= h($resource_path) ?>" download>
              <i class="fa-solid fa-download me-1"></i>Shkarko
            </a>
          <?php endif; ?>
        </div>

        <!-- Zgjidhja (pas afatit) -->
        <?php if ($solution_path && $solution_exists): ?>
          <div class="as-file-card d-flex align-items-center justify-content-between">
            <div class="d-flex align-items-center gap-2">
              <i class="fa-regular fa-file-lines text-success"></i>
              <div>
                <div class="fw-semibold">Zgjidhja</div>
                <div class="small text-muted">
                  <?= h(basename($solution_path)) ?> • <?= h(file_size_pretty($solution_abs)) ?>
                  <?php if (!$solution_visible_to_student): ?>
                    <span class="as-pill warn ms-1"><i class="fa-regular fa-clock"></i> E disponueshme pas afatit</span>
                  <?php endif; ?>
                </div>
              </div>
            </div>
            <?php if ($solution_visible_to_student): ?>
              <a class="btn btn-sm btn-outline-secondary as-btn" href="<?= h($solution_path) ?>" download>
                <i class="fa-solid fa-download me-1"></i>Shkarko
              </a>
            <?php endif; ?>
          </div>
        <?php endif; ?>
        </div>
      </section>
    </div>

    <!-- Kolona djathtas: Dorëzimi ekzistues + Forma -->
    <div class="col-lg-5">
      <div class="km-sticky-side">
        <section class="km-card km-card-side mb-3">
          <div class="km-card-header">
            <div>
              <h2 class="km-card-title"><span class="km-step-badge">3</span> Dorëzimi im</h2>
              <div class="km-card-subtitle">Shiko skedarin aktual, notën dhe feedback-un.</div>
            </div>
            <a class="btn btn-sm btn-outline-secondary as-btn" href="assignment_details.php?assignment_id=<?= (int)$assignment_id ?>">
              <i class="fa-solid fa-arrow-left-long me-1"></i>Te detyra
            </a>
          </div>
          <div class="km-card-body">

        <?php if ($existing): ?>
          <div class="d-flex align-items-start justify-content-between">
            <div class="d-flex align-items-center gap-2">
              <i class="fa-regular fa-file-lines"></i>
              <div>
                <div class="fw-semibold text-truncate" style="max-width: 320px;">
                  <?= h(basename((string)$existing['file_path'])) ?>
                </div>
                <div class="small text-muted">
                  Dorëzuar: <?= date('d M Y, H:i', strtotime((string)$existing['submitted_at'])) ?>
                </div>
              </div>
            </div>
            <a class="btn btn-sm btn-outline-success as-btn" href="<?= h((string)$existing['file_path']) ?>" target="_blank" rel="noopener">
              <i class="fa-solid fa-download me-1"></i>Shkarko
            </a>
          </div>

          <hr>

          <div>
            <div class="mb-1"><strong>Nota:</strong>
              <?php if ($existing['grade'] !== null): ?>
                <span class="text-success fw-bold"><?= (int)$existing['grade'] ?></span>
              <?php else: ?>
                <span class="text-muted">Në pritje</span>
              <?php endif; ?>
            </div>
            <?php if (!empty($existing['feedback'])): ?>
              <div class="small text-muted mt-2 p-2 bg-light rounded">
                <?= nl2br(h((string)$existing['feedback'])) ?>
              </div>
            <?php endif; ?>
          </div>

          <div class="mt-3 small text-muted">
            Ridorëzimi do të <strong>zëvendësojë</strong> skedarin aktual dhe do të rivendosë notën/feedback-un në “Në pritje”.
          </div>
        <?php else: ?>
          <div class="text-muted">Nuk keni dorëzuar ende një skedar.</div>
        <?php endif; ?>
          </div>
        </section>

        <section class="km-card km-card-side">
          <div class="km-card-header">
            <div>
              <h2 class="km-card-title"><span class="km-step-badge">4</span> Dorëzo skedarin</h2>
              <div class="km-card-subtitle">Maks 10 MB. Nëse dorëzon përsëri, zëvendësohet skedari.</div>
            </div>
          </div>
          <div class="km-card-body">

        <?php if (!$submittable): ?>
          <div class="alert alert-warning mb-0">
            <i class="fa-solid fa-circle-exclamation me-2"></i>
            Dorëzimi nuk lejohet sepse afati ka kaluar ose detyra është shënuar si EXPIRED.
          </div>
        <?php else: ?>
          <form method="post" enctype="multipart/form-data" onsubmit="return confirm('Të dërgohet skedari?');">
            <input type="hidden" name="action" value="submit">
            <input type="hidden" name="csrf"   value="<?= h($csrf) ?>">

            <div id="drop" class="as-file-drop mb-3">
              <input class="visually-hidden" type="file" id="fileInput" name="submission_file" required
                     accept=".pdf,.doc,.docx,.zip,.rar,.txt,.png,.jpg,.jpeg,.md,.html,.css,.js,.php,.py,.java,.cpp,.c,.cs,.go,.ts,.sql,.json,.xml,.yaml,.yml">
              <div class="p-2" id="dropInner">
                <i class="fa-solid fa-cloud-arrow-up fa-2xl text-muted d-block mb-2"></i>
                <div class="fw-semibold">Kliko për të zgjedhur skedarin</div>
                <div class="text-muted">ose tërhiqe & lësho këtu</div>
              </div>
            </div>

            <div class="d-flex gap-2">
              <button class="btn btn-primary km-btn-pill as-btn" type="submit"><i class="fa-solid fa-paper-plane me-1"></i>Dërgo</button>
              <a class="btn btn-sm btn-outline-secondary as-btn" href="assignment_details.php?assignment_id=<?= (int)$assignment_id ?>">
                <i class="fa-solid fa-arrow-left me-1"></i>Kthehu te detyra
              </a>
            </div>

            <div class="small text-muted mt-3">
              Këshillë: Emërtoni skedarin p.sh. <code>Detyra-<?= (int)$assignment_id ?>-<?= (int)$userId ?>.pdf</code> për ta gjetur më lehtë.
            </div>
          </form>
        <?php endif; ?>
          </div>
        </section>
      </div>
    </div>
  </div>

  </div>
</main>

<?php if (file_exists(__DIR__ . '/footer2.php')) include __DIR__ . '/footer2.php'; ?>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script>
  // Markdown collapse
  (function(){
    const box = document.getElementById('md-box');
    const btn = document.getElementById('md-toggle');
    if(!box || !btn) return;
    let expanded = false;
    btn.addEventListener('click', (e)=>{
      e.preventDefault();
      expanded = !expanded;
      if (expanded) {
        box.classList.remove('desc-collapsed');
        btn.textContent = 'Shiko më pak';
      } else {
        box.classList.add('desc-collapsed');
        btn.textContent = 'Shiko më shumë';
      }
    });
  })();

  // Drag & drop
  const drop = document.getElementById('drop');
  const input= document.getElementById('fileInput');
  const inner= document.getElementById('dropInner');

  drop?.addEventListener('click', ()=> input?.click());
  input?.addEventListener('change', ()=>{
    if(input.files && input.files[0]){
      inner.innerHTML = '<i class="fa-regular fa-file-lines fa-2xl text-success d-block mb-2"></i>'
                      + '<div class="fw-semibold">Skedari i zgjedhur</div>'
                      + '<div class="hint">'+ input.files[0].name +'</div>';
    }
  });
  ['dragenter','dragover'].forEach(ev=> drop?.addEventListener(ev, e=>{ e.preventDefault(); drop.classList.add('drag'); }));
  ['dragleave','drop'].forEach(ev=> drop?.addEventListener(ev, e=>{ e.preventDefault(); drop.classList.remove('drag'); }));
  drop?.addEventListener('drop', e=>{
    if(e.dataTransfer?.files?.length){
      input.files = e.dataTransfer.files;
      input.dispatchEvent(new Event('change'));
    }
  });
</script>
</body>
</html>
