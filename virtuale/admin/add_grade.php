<?php
// add_grade.php — Grading screen (Admin/Instructor), unified UI
declare(strict_types=1);
session_start();
require_once __DIR__ . '/../lib/database.php';

/* ------------------------------- Helpers ------------------------------- */
function h(?string $s): string { return htmlspecialchars($s ?? '', ENT_QUOTES, 'UTF-8'); }
function flash_set(string $type, string $msg): void { $_SESSION["flash_$type"] = $msg; }
function flash_get(string $type): ?string {
  $k = "flash_$type"; if (!empty($_SESSION[$k])) { $m = $_SESSION[$k]; unset($_SESSION[$k]); return $m; }
  return null;
}
function csrf_token(): string {
  if (empty($_SESSION['csrf_grade'])) { $_SESSION['csrf_grade'] = bin2hex(random_bytes(32)); }
  return $_SESSION['csrf_grade'];
}
function ensure_csrf(): void {
  if (empty($_POST['csrf']) || empty($_SESSION['csrf_grade']) || !hash_equals($_SESSION['csrf_grade'], (string)$_POST['csrf'])) {
    http_response_code(403); exit('Seancë e pasigurt (CSRF). Ringarkoni faqen.');
  }
}

/* ------------------------------- RBAC ---------------------------------- */
if (
  !isset($_SESSION['user']) ||
  !in_array(($_SESSION['user']['role'] ?? ''), ['Administrator','Instruktor'], true)
) { header('Location: ../login.php'); exit; }

$ME          = $_SESSION['user'];
$ME_ID       = (int)($ME['id'] ?? 0);
$ME_ROLE     = (string)($ME['role'] ?? '');

/* ------------------------------ Inputs --------------------------------- */
$submission_id = (int)($_GET['submission_id'] ?? 0);
if ($submission_id <= 0) { exit('Dorëzimi nuk është specifikuar.'); }

/* --------- Load submission + assignment + course + student ------------- */
try {
  $stmt = $pdo->prepare("
    SELECT 
      s.*,
      a.id   AS assignment_id,
      a.title AS assignment_title,
      a.due_date,
      c.id   AS course_id,
      c.title AS course_title,
      c.id_creator,
      u.full_name  AS student_name,
      u.email      AS student_email
    FROM assignments_submitted s
    JOIN assignments a ON s.assignment_id = a.id
    JOIN courses    c ON a.course_id = c.id
    JOIN users      u ON u.id = s.user_id
    WHERE s.id = ?
    LIMIT 1
  ");
  $stmt->execute([$submission_id]);
  $sub = $stmt->fetch(PDO::FETCH_ASSOC);
  if (!$sub) { exit('Dorëzimi nuk u gjet.'); }
} catch (PDOException $e) { exit('Gabim DB.'); }

/* ------------------------ Ownership for Instructor --------------------- */
if ($ME_ROLE === 'Instruktor' && (int)$sub['id_creator'] !== $ME_ID) {
  http_response_code(403); exit('Nuk keni të drejta për të vlerësuar këtë dorëzim.');
}

/* ---------------------------- Form handling ---------------------------- */
$grade    = isset($sub['grade']) ? (int)$sub['grade'] : null;
$feedback = (string)($sub['feedback'] ?? '');
$errors   = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  ensure_csrf();

  $grade    = isset($_POST['grade']) ? (int)$_POST['grade'] : null;
  $feedback = trim((string)($_POST['feedback'] ?? ''));

  if ($grade === null || $grade < 1 || $grade > 10) {
    $errors[] = 'Ju lutem jepni një notë të vlefshme midis 1 dhe 10.';
  }

  if (!$errors) {
    try {
      $up = $pdo->prepare("UPDATE assignments_submitted SET grade=?, feedback=? WHERE id=?");
      $up->execute([$grade, $feedback, $submission_id]);
      flash_set('success', 'Nota u ruajt me sukses!');
      header('Location: ../assignment_details.php?assignment_id='.(int)$sub['assignment_id']);
      exit;
    } catch (PDOException $e) {
      $errors[] = 'Gabim gjatë ruajtjes së notës.';
    }
  }
}

$csrf = csrf_token();
?>
<!DOCTYPE html>
<html lang="sq" data-bs-theme="light">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Vlerësim — <?= h($sub['assignment_title']) ?></title>
  <link rel="icon" href="image/favicon.ico" type="image/x-icon" />
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css">
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css">
  <style>
    :root{
      --primary:#2A4B7C; --primary-dark:#1d3a63;
      --muted:#6b7280; --bg:#f6f8fb; --border:#e5e7eb;
      --r:16px; --shadow:0 10px 28px rgba(0,0,0,.08);
    }
    body{ background:var(--bg); }
    .hero{
      background:
        radial-gradient(700px 280px at 85% -40%, rgba(90,148,255,.18), transparent 60%),
        linear-gradient(135deg, var(--primary), var(--primary-dark));
      color:#fff; padding:42px 0 18px; margin-bottom:18px;
    }
    .crumb a{ color:#fff; opacity:.9; text-decoration:none; }
    .crumb .sep{ opacity:.65; padding:0 .4rem; }
    .cardx{ background:#fff; border:0; border-radius:var(--r); box-shadow:var(--shadow); }
    .pill{ padding:.35rem .65rem; border-radius:999px; font-weight:700; display:inline-flex; align-items:center; gap:.35rem; }
    .pill-ok{ background:#ecfdf5; color:#065f46; }
    .pill-warn{ background:#fffbeb; color:#92400e; }
    .pill-bad{ background:#fef2f2; color:#991b1b; }

    .form-control, .form-select{ border:2px solid var(--border); border-radius:12px; padding:.8rem .95rem; }
    .form-control:focus{ border-color:var(--primary); box-shadow:0 0 0 .2rem rgba(42,75,124,.12); }
    .input-group-text{ border:2px solid var(--border); border-right:0; }
    .btn-primary{ background:var(--primary); border:none; }
    .btn-primary:hover{ background:var(--primary-dark); }
    .sub-file{ background:#f8fafc; border:1px dashed #dbe2ea; border-radius:12px; padding:12px; }
    .keyinfo{ color:#e5e7eb; font-size:.95rem; }
  </style>
</head>
<body>

<?php
  if ($ME_ROLE === 'Administrator')      include __DIR__.'/../navbar_logged_administrator.php';
  elseif ($ME_ROLE === 'Instruktor')     include __DIR__.'/../navbar_logged_instruktor.php';
?>

<!-- HERO -->
<section class="hero">
  <div class="container">
    <div class="crumb small mb-2">
      <a href="<?= ($ME_ROLE==='Administrator') ? 'dashboard_admin.php' : 'courses_instructor.php' ?>">
        <i class="fa-solid fa-house me-1"></i>Kryefaqja
      </a>
      <span class="sep">/</span>
      <a href="course_details.php?course_id=<?= (int)$sub['course_id'] ?>"><?= h($sub['course_title']) ?></a>
      <span class="sep">/</span>
      <a href="assignment_details.php?assignment_id=<?= (int)$sub['assignment_id'] ?>">Detyra: <?= h($sub['assignment_title']) ?></a>
      <span class="sep">/</span>
      <span class="opacity-90">Vlerësimi</span>
    </div>

    <div class="d-flex align-items-start justify-content-between gap-3 flex-wrap">
      <div class="me-3">
        <h1 class="h3 fw-bold mb-1"><i class="fa-solid fa-star-half-stroke me-2"></i>Vlerëso dorëzimin</h1>
        <div class="keyinfo">
          <i class="fa-regular fa-user me-1"></i><?= h($sub['student_name']) ?>
          <span class="ms-2 me-2">•</span>
          <i class="fa-regular fa-envelope me-1"></i><?= h($sub['student_email']) ?>
        </div>
      </div>
      <div class="text-end">
        <div class="small opacity-75">Dorëzuar më</div>
        <div class="fw-semibold"><?= h(date('d M Y, H:i', strtotime((string)$sub['submitted_at']))) ?></div>
        <?php if (!empty($sub['due_date'])): ?>
          <div class="small opacity-75 mt-2">Afati</div>
          <div class="fw-semibold"><?= h(date('d M Y', strtotime((string)$sub['due_date']))) ?></div>
        <?php endif; ?>
      </div>
    </div>
  </div>
</section>

<main class="container pb-4">
  <?php if ($m = flash_get('success')): ?>
    <div class="alert alert-success cardx d-flex align-items-center gap-2">
      <i class="fa-regular fa-circle-check"></i><div><?= h($m) ?></div>
    </div>
  <?php endif; ?>

  <?php if ($errors): ?>
    <div class="alert alert-danger cardx">
      <div class="d-flex align-items-start gap-2">
        <i class="fa-solid fa-triangle-exclamation mt-1"></i>
        <div>
          <strong>Gabim në formular:</strong>
          <ul class="mb-0 mt-1"><?php foreach ($errors as $e): ?><li><?= h($e) ?></li><?php endforeach; ?></ul>
        </div>
      </div>
    </div>
  <?php endif; ?>

  <div class="row g-3 g-lg-4">
    <!-- Submission info (left) -->
    <div class="col-lg-5">
      <div class="cardx p-3 p-md-4 h-100">
        <h5 class="mb-3"><i class="fa-regular fa-file-lines me-2"></i>Dorëzimi</h5>

        <div class="sub-file d-flex align-items-start justify-content-between">
          <div class="d-flex align-items-start gap-2">
            <i class="fa-regular fa-file-lines mt-1"></i>
            <div>
              <div class="fw-semibold text-truncate" style="max-width:320px;">
                <?= h(basename((string)$sub['file_path'])) ?>
              </div>
              <div class="small text-muted">ID: <?= (int)$submission_id ?></div>
            </div>
          </div>
          <?php if (!empty($sub['file_path'])): ?>
            <a class="btn btn-sm btn-outline-primary" href="<?= h((string)$sub['file_path']) ?>" target="_blank" rel="noopener">
              <i class="fa-solid fa-download me-1"></i>Shkarko
            </a>
          <?php endif; ?>
        </div>

        <?php if ($sub['grade'] !== null || ($sub['feedback'] ?? '') !== ''): ?>
          <hr>
          <div class="small">
            <div class="mb-1">
              <span class="pill pill-ok"><i class="fa-regular fa-circle-check"></i> Nota aktuale: <strong><?= h((string)$sub['grade']) ?></strong></span>
            </div>
            <?php if (!empty($sub['feedback'])): ?>
              <div class="mt-2 p-2 bg-light rounded">
                <div class="fw-semibold mb-1"><i class="fa-regular fa-comment me-1"></i>Feedback ekzistues</div>
                <div class="text-muted"><?= nl2br(h((string)$sub['feedback'])) ?></div>
              </div>
            <?php endif; ?>
          </div>
        <?php endif; ?>
      </div>
    </div>

    <!-- Grading form (right) -->
    <div class="col-lg-7">
      <div class="cardx p-3 p-md-4">
        <h5 class="mb-3"><i class="fa-solid fa-pen-to-square me-2"></i>Vendos/ndrysho vlerësimin</h5>

        <form method="post" action="" class="row g-3">
          <input type="hidden" name="csrf" value="<?= h($csrf) ?>">

          <div class="col-sm-6">
            <label class="form-label">Vlerësimi (1–10)</label>
            <div class="input-group">
              <span class="input-group-text bg-white"><i class="fa-regular fa-star"></i></span>
              <input type="number" min="1" max="10" step="1" name="grade" class="form-control"
                     required value="<?= h($grade !== null ? (string)$grade : '') ?>"
                     placeholder="p.sh. 9">
            </div>
          </div>

          <div class="col-12">
            <label class="form-label">Feedback (opsional)</label>
            <textarea name="feedback" rows="5" class="form-control" placeholder="Shkruani komentet tuaja…"><?= h($feedback) ?></textarea>
            <div class="form-text text-muted">Studenti do ta shohë këtë koment pranë notës së tij/saj.</div>
          </div>

          <div class="col-12 d-grid d-sm-flex gap-2 mt-2">
            <button class="btn btn-primary"><i class="fa-regular fa-floppy-disk me-2"></i>Ruaj</button>
            <a class="btn btn-outline-secondary" href="assignment_details.php?assignment_id=<?= (int)$sub['assignment_id'] ?>">
              <i class="fa-solid fa-arrow-left-long me-2"></i>Kthehu te detyra
            </a>
          </div>
        </form>
      </div>
    </div>
  </div>
</main>

<?php if (file_exists(__DIR__.'/footer.php')) include __DIR__.'/footer.php'; ?>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
