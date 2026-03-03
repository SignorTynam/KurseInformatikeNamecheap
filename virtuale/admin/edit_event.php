<?php
// edit_event.php — Revamp (Administrator)
declare(strict_types=1);
session_start();
require_once __DIR__ . '/../lib/database.php';

/* ------------------------------ Helpers ------------------------------ */
function h(?string $s): string { return htmlspecialchars($s ?? '', ENT_QUOTES, 'UTF-8'); }
function ensure_dir(string $path): void { if (!is_dir($path)) { @mkdir($path, 0775, true); } }

/* ------------------------------ AuthZ ------------------------------- */
if (!isset($_SESSION['user']) || ($_SESSION['user']['role'] ?? '') !== 'Administrator') {
  header('Location: ../login.php'); exit;
}

/* ------------------------------ CSRF -------------------------------- */
if (empty($_SESSION['csrf'])) { $_SESSION['csrf'] = bin2hex(random_bytes(16)); }
$csrf = $_SESSION['csrf'];

/* ---------------------------- Input: id ----------------------------- */
$event_id = isset($_GET['event_id']) && ctype_digit((string)$_GET['event_id']) ? (int)$_GET['event_id'] : 0;
if ($event_id <= 0) { header('Location: ../event.php'); exit; }

try {
  $stmt = $pdo->prepare("SELECT * FROM events WHERE id = ?");
  $stmt->execute([$event_id]);
  $event = $stmt->fetch(PDO::FETCH_ASSOC);
  if (!$event) {
    $_SESSION['flash'] = ['msg'=>'Eventi nuk u gjend.', 'type'=>'danger'];
    header('Location: ../event.php'); exit;
  }
} catch (PDOException $e) {
  $_SESSION['flash'] = ['msg'=>'Gabim DB: ' . h($e->getMessage()), 'type'=>'danger'];
  header('Location: ../event.php'); exit;
}

/* ---------------------------- Defaults ----------------------------- */
$title           = (string)($event['title'] ?? '');
$description     = (string)($event['description'] ?? '');
$status          = (string)($event['status'] ?? 'ACTIVE');
$category        = (string)($event['category'] ?? 'TJETRA');
$current_photo   = (string)($event['photo'] ?? '');
$event_datetime  = (string)($event['event_datetime'] ?? date('Y-m-d H:i:s'));
$datetime_local  = date('Y-m-d\TH:i', strtotime($event_datetime));

$allowed_categories = ['KONFERENCA','SEMINAR','WORKSHOP','TJETRA'];
$allowed_statuses   = ['ACTIVE','INACTIVE','ARCHIVED'];

$errors = [];

/* ------------------------ Upload constraints ----------------------- */
const EVENTS_DIR = __DIR__ . '/events/';
$public_events_prefix = 'events/'; // për src në <img> / link
$img_max_bytes  = 5 * 1024 * 1024; // 5MB
$img_ext_allow  = ['jpg','jpeg','png','gif'];
$img_mime_allow = ['image/jpeg','image/png','image/gif'];

/* ------------------------------ POST -------------------------------- */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  // CSRF
  if (!hash_equals($csrf, (string)($_POST['csrf'] ?? ''))) {
    $errors[] = 'Seancë e pasigurt. Ringarkoni faqen dhe provoni sërish.';
  }

  $title          = trim((string)($_POST['title'] ?? ''));
  $description    = trim((string)($_POST['description'] ?? ''));
  $status         = (string)($_POST['status'] ?? 'ACTIVE');
  $category       = (string)($_POST['category'] ?? '');
  $event_dt_input = trim((string)($_POST['event_datetime'] ?? ''));

  // Validime fushash
  if ($title === '') { $errors[] = 'Titulli i eventit është i detyrueshëm.'; }

  if (!in_array($category, $allowed_categories, true)) {
    $errors[] = 'Kategoria e eventit është e detyrueshme dhe duhet të jetë e vlefshme.';
  }

  if (!in_array($status, $allowed_statuses, true)) {
    $errors[] = 'Status i pavlefshëm.';
  }

  if ($event_dt_input === '') {
    $errors[] = 'Data dhe ora e eventit janë të detyrueshme.';
  } else {
    // Presim format HTML datetime-local: Y-m-d\TH:i
    $dt = DateTime::createFromFormat('Y-m-d\TH:i', $event_dt_input);
    $ok = $dt && $dt->format('Y-m-d\TH:i') === $event_dt_input;
    if (!$ok) {
      $errors[] = 'Formati i datës/ores nuk është i vlefshëm.';
    } else {
      // Ruaj si Y-m-d H:i:s për DB
      $event_datetime = $dt->format('Y-m-d H:i:s');
      $datetime_local = $dt->format('Y-m-d\TH:i'); // për redisplay
    }
  }

  // Ngarkim imazhi (opsional)
  if (isset($_FILES['event_photo']) && $_FILES['event_photo']['error'] !== UPLOAD_ERR_NO_FILE) {
    if ($_FILES['event_photo']['error'] === UPLOAD_ERR_OK) {
      $tmp  = $_FILES['event_photo']['tmp_name'];
      $name = (string)$_FILES['event_photo']['name'];
      $size = (int)$_FILES['event_photo']['size'];
      $ext  = strtolower(pathinfo($name, PATHINFO_EXTENSION));

      // MIME real nga finfo
      $finfo = new finfo(FILEINFO_MIME_TYPE);
      $mime  = $finfo->file($tmp) ?: '';

      if ($size > $img_max_bytes) {
        $errors[] = 'Fotoja është shumë e madhe (maks 5MB).';
      }
      if (!in_array($ext, $img_ext_allow, true)) {
        $errors[] = 'Formati i fotos nuk lejohet. Lejohen: ' . implode(', ', $img_ext_allow) . '.';
      }
      if (!in_array($mime, $img_mime_allow, true)) {
        $errors[] = 'Lloji MIME i fotos nuk lejohet.';
      }

      if (!$errors) {
        ensure_dir(EVENTS_DIR);
        $new_photo = uniqid('event_', true) . '.' . $ext;
        $dest_path = EVENTS_DIR . $new_photo;

        if (!move_uploaded_file($tmp, $dest_path)) {
          $errors[] = 'Ngarkimi i imazhit dështoi.';
        } else {
          // Fshi foton e vjetër vetëm nëse është emër i thjeshtë (parandalim traversal)
          if ($current_photo !== '' && basename($current_photo) === $current_photo) {
            $old = EVENTS_DIR . $current_photo;
            if (is_file($old)) { @unlink($old); }
          }
          $current_photo = $new_photo; // ruaj të renë
        }
      }
    } else {
      $errors[] = 'Gabim gjatë ngarkimit të fotos.';
    }
  }

  // Nëse s’ka gabime -> update
  if (!$errors) {
    try {
      $stmtU = $pdo->prepare("
        UPDATE events
           SET title = ?, description = ?, status = ?, category = ?, photo = ?, event_datetime = ?, updated_at = NOW()
         WHERE id = ?
      ");
      $stmtU->execute([
        $title,
        $description,
        $status,
        $category,
        $current_photo !== '' ? $current_photo : null,
        $event_datetime,
        $event_id
      ]);

      $_SESSION['success'] = 'Eventi u modifikua me sukses!';
      header('Location: event_details.php?event_id=' . (int)$event_id);
      exit;
    } catch (PDOException $e) {
      $errors[] = 'Gabim gjatë modifikimit të eventit: ' . h($e->getMessage());
    }
  }
}
?>
<!DOCTYPE html>
<html lang="sq" data-bs-theme="light">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Modifiko Eventin — Paneli i Administratorit</title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/simplemde/latest/simplemde.min.css">
  <link rel="icon" href="image/favicon.ico" type="image/x-icon" />
  <style>
    :root{ --primary:#2A4B7C; --secondary:#5B7BA3; --light:#F8F9FC; --shadow:0 10px 28px rgba(0,0,0,.08); --r:16px; }
    body{ background:#f6f8fb; }
    .hero{ background:linear-gradient(135deg,var(--primary),#1d3a63); color:#fff; padding:28px 0 18px; }
    .card-elev{ background:#fff; border:0; border-radius:var(--r); box-shadow:var(--shadow); }
    .input-group-text{ background-color:var(--light); border:2px solid #e9ecef; border-right:none; }
    .form-control{ border:2px solid #e9ecef; }
    .form-control:focus{ border-color: var(--primary); box-shadow: 0 0 0 3px rgba(42,75,124,.1); }
    .file-upload{ border:2px dashed #e5e7eb; border-radius:12px; padding:24px; background:#fafbff; cursor:pointer; }
    .btn-primary{ background:linear-gradient(135deg,var(--primary),var(--secondary)); border:none; }
    .editor-toolbar{ border-radius:12px 12px 0 0; background:#f8f9fc; border:2px solid #e9ecef; }
    .CodeMirror{ border:2px solid #e9ecef; border-top:0; border-radius:0 0 12px 12px; min-height:150px; }
    .current-image img{ max-width:100%; border-radius:12px; border:2px solid #e9ecef; }
  </style>
</head>
<body>

<?php include __DIR__ . '/../navbar_logged_administrator.php'; ?>

<section class="hero">
  <div class="container">
    <div class="d-flex align-items-center justify-content-between">
      <a href="event_details.php?event_id=<?= (int)$event_id ?>" class="btn btn-sm btn-light">
        <i class="bi bi-arrow-left"></i> Detajet e eventit
      </a>
      <small class="opacity-75">Event ID: <strong><?= (int)$event_id ?></strong></small>
    </div>
    <h1 class="h3 mt-2 mb-0">Modifiko Eventin</h1>
    <div class="opacity-75">Përditëso titullin, përshkrimin, datën/orën, kategorinë, foton etj.</div>
  </div>
</section>

<main class="container py-4">
  <?php if ($errors): ?>
    <div class="alert alert-danger card-elev">
      <div class="d-flex align-items-start gap-2">
        <i class="bi bi-exclamation-circle fs-4"></i>
        <div>
          <div class="fw-bold mb-1">Gabime në formular</div>
          <ul class="mb-0"><?php foreach ($errors as $er): ?><li><?= h($er) ?></li><?php endforeach; ?></ul>
        </div>
      </div>
    </div>
  <?php endif; ?>

  <div class="card-elev p-3 p-md-4">
    <form method="POST" action="" enctype="multipart/form-data">
      <input type="hidden" name="csrf" value="<?= h($csrf) ?>">

      <!-- Titulli -->
      <div class="mb-4">
        <label for="title" class="form-label">Titulli i Eventit</label>
        <div class="input-group">
          <span class="input-group-text"><i class="bi bi-card-heading text-primary"></i></span>
          <input type="text" class="form-control" id="title" name="title" required
                 placeholder="Shkruani titullin e eventit" value="<?= h($title) ?>">
        </div>
      </div>

      <!-- Përshkrimi -->
      <div class="mb-4">
        <label class="form-label">Përshkrimi i Eventit</label>
        <textarea id="description" name="description"><?= h($description) ?></textarea>
      </div>

      <!-- Kategoria -->
      <div class="mb-4">
        <label for="category" class="form-label">Kategoria</label>
        <select class="form-select" id="category" name="category" required>
          <option value="">Zgjidhni kategorinë…</option>
          <?php foreach ($allowed_categories as $cat): ?>
            <option value="<?= h($cat) ?>" <?= $cat===$category?'selected':'' ?>><?= ucfirst(strtolower($cat)) ?></option>
          <?php endforeach; ?>
        </select>
      </div>

      <!-- Statusi -->
      <div class="mb-4">
        <label for="status" class="form-label">Statusi</label>
        <select class="form-select" id="status" name="status" required>
          <?php foreach ($allowed_statuses as $st): ?>
            <option value="<?= h($st) ?>" <?= $st===$status?'selected':'' ?>><?= h($st) ?></option>
          <?php endforeach; ?>
        </select>
      </div>

      <!-- Data & Ora -->
      <div class="mb-4">
        <label for="event_datetime" class="form-label">Data dhe Ora e Eventit</label>
        <div class="input-group">
          <span class="input-group-text"><i class="bi bi-calendar text-primary"></i></span>
          <input type="datetime-local" class="form-control" id="event_datetime" name="event_datetime" required
                 value="<?= h($datetime_local) ?>">
        </div>
      </div>

      <!-- Foto -->
      <div class="mb-4">
        <label class="form-label">Foto e Eventit (opsionale)</label>
        <div class="file-upload position-relative" id="dropArea">
          <input type="file" class="form-control visually-hidden" id="event_photo" name="event_photo" accept="image/*">
          <div class="upload-content text-center">
            <i class="bi bi-cloud-arrow-up fs-1 text-muted mb-2"></i>
            <h6 class="mb-1">Klikoni për të ngarkuar foton ose tërhiqni & lëshoni</h6>
            <div class="small text-muted">Lejohen: <?= h(implode(', ', $img_ext_allow)) ?> • maks 5MB</div>
          </div>
        </div>
        <div id="uploadStatus" class="form-text mt-1"></div>

        <?php if ($current_photo !== ''): ?>
          <div class="current-image mt-3">
            <p class="text-muted mb-2">Foto aktuale:</p>
            <img src="<?= h($public_events_prefix . $current_photo) ?>" alt="Foto e Eventit" class="img-fluid">
          </div>
        <?php endif; ?>
      </div>

      <!-- Veprime -->
      <div class="d-grid gap-3 mt-4">
        <button type="submit" class="btn btn-primary">
          <i class="bi bi-save me-2"></i>Ruaj Ndryshimet
        </button>
        <a href="event_details.php?event_id=<?= (int)$event_id ?>" class="btn btn-outline-secondary">
          <i class="bi bi-arrow-left me-2"></i>Anulo
        </a>
      </div>
    </form>
  </div>
</main>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script src="https://cdn.jsdelivr.net/simplemde/latest/simplemde.min.js"></script>
<script>
  // Markdown editor
  const simplemde = new SimpleMDE({
    element: document.getElementById('description'),
    toolbar: ['bold','italic','heading','|','code','quote','unordered-list','ordered-list','|','link','image','table','|','preview','guide'],
    spellChecker: false,
    placeholder: "Shkruani përshkrimin e eventit këtu..."
  });

  // Drag & drop për foton
  const drop = document.getElementById('dropArea');
  const file = document.getElementById('event_photo');
  const st   = document.getElementById('uploadStatus');

  drop.addEventListener('click', () => file.click());
  drop.addEventListener('dragover', (e) => { e.preventDefault(); drop.style.borderColor='#2A4B7C'; });
  drop.addEventListener('dragleave', () => { drop.style.borderColor='#e5e7eb'; });
  drop.addEventListener('drop', (e) => {
    e.preventDefault();
    if (e.dataTransfer.files.length > 0) { file.files = e.dataTransfer.files; showSelected(); }
    drop.style.borderColor='#e5e7eb';
  });
  file.addEventListener('change', showSelected);

  function showSelected(){
    if (!file.files.length){ st.textContent=''; return; }
    st.textContent = 'Foto e zgjedhur: ' + file.files[0].name;
  }
</script>
</body>
</html>
