<?php
declare(strict_types=1);
if (session_status() === PHP_SESSION_NONE) { session_start(); }
require_once __DIR__ . '/../lib/database.php';

/* -------------------- RBAC -------------------- */
if (!isset($_SESSION['user']) || ($_SESSION['user']['role'] ?? '') !== 'Administrator') {
    header('Location: ../login.php'); exit;
}
$ME_ID = (int)($_SESSION['user']['id'] ?? 0);

/* -------------------- CSRF -------------------- */
if (empty($_SESSION['csrf_token'])) { $_SESSION['csrf_token'] = bin2hex(random_bytes(32)); }
$CSRF = $_SESSION['csrf_token'];

/* -------------------- Helpers ----------------- */
function h(?string $s): string { return htmlspecialchars($s ?? '', ENT_QUOTES, 'UTF-8'); }
function ensure_dir(string $dir): void { if (!is_dir($dir)) { @mkdir($dir, 0775, true); } }

/* -------------------- Defaults ---------------- */
$errors = [];
$title = $description = $status = $category = '';
$event_dt_mysql = null;                 // ruhet si Y-m-d H:i:s
$event_dt_local_input = '';             // ruhet si Y-m-d\TH:i për input-in
$photo_filename = null;

$events_dir_abs = __DIR__ . '/../uploads/events';
$events_dir_rel = 'uploads/events/';
ensure_dir($events_dir_abs);

$allowed_categories = ['KONFERENCA','SEMINAR','WORKSHOP','TJETRA'];
$allowed_statuses   = ['ACTIVE','INACTIVE','ARCHIVED'];
$max_upload_bytes   = 6 * 1024 * 1024; // 6MB
$ok_mimes = [
    'image/jpeg' => 'jpg',
    'image/png'  => 'png',
    'image/gif'  => 'gif',
    'image/webp' => 'webp',
];

/* -------------------- POST -------------------- */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // CSRF
    if (!isset($_POST['csrf']) || !hash_equals($CSRF, (string)$_POST['csrf'])) {
        $errors[] = 'Seancë e pavlefshme (CSRF). Ringarko faqen dhe provo përsëri.';
    }

    $title         = trim((string)($_POST['title'] ?? ''));
    $description   = (string)($_POST['description'] ?? '');
    $status_in     = (string)($_POST['status'] ?? 'ACTIVE');
    $status        = in_array($status_in, $allowed_statuses, true) ? $status_in : 'ACTIVE';
    $category_in   = (string)($_POST['category'] ?? '');
    $category      = in_array($category_in, $allowed_categories, true) ? $category_in : '';
    $event_dt_local_input = trim((string)($_POST['event_datetime'] ?? ''));

    // Validime bazë
    if ($title === '' || mb_strlen($title) < 3) { $errors[] = 'Titulli i eventit është i detyrueshëm (min 3 karaktere).'; }
    if ($category === '') { $errors[] = 'Kategoria e eventit është e detyrueshme.'; }

    if ($event_dt_local_input === '') {
        $errors[] = 'Data dhe ora e eventit janë të detyrueshme.';
    } else {
        // Presim formatin e HTML datetime-local: Y-m-d\TH:i (pa sekonda)
        $dt = DateTime::createFromFormat('Y-m-d\TH:i', $event_dt_local_input);
        if (!$dt) {
            $errors[] = 'Formati i datës/orës nuk është i vlefshëm.';
        } else {
            $event_dt_mysql = $dt->format('Y-m-d H:i:s');
        }
    }

    // Foto (e detyrueshme)
    $hasUpload = isset($_FILES['event_photo']) && is_uploaded_file($_FILES['event_photo']['tmp_name']) && ($_FILES['event_photo']['error'] === UPLOAD_ERR_OK);
    if (!$hasUpload) {
        $errors[] = 'Fotoja e eventit është e detyrueshme.';
    } else {
        $tmp  = $_FILES['event_photo']['tmp_name'];
        $size = (int)$_FILES['event_photo']['size'];

        if ($size > $max_upload_bytes) {
            $errors[] = 'Imazhi është shumë i madh (maksimumi 6MB).';
        } else {
            $finfo = new finfo(FILEINFO_MIME_TYPE);
            $mime  = $finfo->file($tmp) ?: '';
            if (!isset($ok_mimes[$mime])) {
                $errors[] = 'Lloj i papranueshëm imazhi. Lejohen JPG, PNG, GIF, WEBP.';
            } else {
                $ext = $ok_mimes[$mime];
                $photo_filename = 'event_' . bin2hex(random_bytes(6)) . '_' . time() . '.' . $ext;
                $dest = $events_dir_abs . '/' . $photo_filename;
                if (!move_uploaded_file($tmp, $dest)) {
                    $errors[] = 'Ngarkimi i imazhit dështoi.';
                }
            }
        }
    }

    // Ruaj
    if (!$errors) {
        try {
            $stmt = $pdo->prepare("
                INSERT INTO events (title, description, id_creator, status, category, photo, event_datetime)
                VALUES (:t,:d,:ic,:st,:cat,:ph,:dt)
            ");
            $stmt->execute([
                ':t' => $title,
                ':d' => $description,
                ':ic'=> $ME_ID,
                ':st'=> $status,
                ':cat'=>$category,
                ':ph'=> $photo_filename,
                ':dt'=> $event_dt_mysql,
            ]);
            $_SESSION['flash'] = ['msg'=>'Eventi u shtua me sukses!', 'type'=>'success'];
            header('Location: ../event.php'); exit;
        } catch (Throwable $e) {
            $errors[] = 'Gabim gjatë futjes së eventit: ' . h($e->getMessage());
        }
    }
}
?>
<!DOCTYPE html>
<html lang="sq" data-bs-theme="light">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <title>Shto Event — kurseinformatike.com</title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
  <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css" rel="stylesheet">
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/simplemde/latest/simplemde.min.css">
  <link rel="icon" href="image/favicon.ico" type="image/x-icon" />
  <style>
    :root{
      --radius:18px;
      --shadow:0 14px 38px rgba(2,44,56,.12);

      --primary:#2A4B7C; 
      --primary-dark:#1d3a63; 
      --muted:#6b7280; 
      --bg:#f6f8fb;
      --border:#e5e7eb;
    }
    body{ background:var(--bg); }

    /* Hero */
    .hero{
      background: radial-gradient(1000px 400px at 90% -50%, rgba(245,158,11,.25), transparent 60%),
                  linear-gradient(135deg, var(--primary), var(--primary-dark));
      color:#fff; padding:64px 0 32px; margin-bottom:22px;
    }
    .hero .chip{
      background:#ffffff22; border:1px solid #ffffff40; color:#fff; padding:.25rem .6rem; border-radius:999px; font-weight:600;
    }

    /* Card */
    .cardx{
      background:#fff; border:0; border-radius:var(--radius); box-shadow:var(--shadow);
    }

    /* Inputs */
    .form-control, .form-select{
      border:2px solid var(--border); border-radius:12px; padding:.8rem .95rem;
    }
    .form-control:focus, .form-select:focus{
      border-color:var(--primary); box-shadow:0 0 0 .2rem rgba(14,116,144,.12);
    }

    /* Uploader */
    .drop{
      border:2px dashed var(--border); border-radius:14px; padding:22px; text-align:center; background:#fff;
      transition:.15s border-color ease, .15s background ease; cursor:pointer;
    }
    .drop.drag{ border-color:var(--primary); background:#ECFEFF; }
    .preview{
      display:none; border:1px solid var(--border); border-radius:14px; overflow:hidden; margin-top:10px;
    }
    .preview img{ width:100%; height:auto; display:block; }

    .btn-primary{ background:var(--primary); border:none; }
    .btn-primary:hover{ background:var(--primary-dark); }
    .hint{ color:var(--muted); font-size:.9rem; }
  </style>
</head>
<body>

<?php include __DIR__ . '/../navbar_logged_administrator.php'; ?>

<section class="hero">
  <div class="container">
    <div class="d-flex flex-column flex-lg-row align-items-start align-items-lg-center justify-content-between gap-3">
      <div>
        <h1 class="mb-1"><i class="fa-solid fa-calendar-plus me-2"></i>Shto event</h1>
        <p class="mb-0">Publikoni një event të ri për komunitetin tuaj.</p>
      </div>
      <span class="chip"><i class="fa-regular fa-user me-1"></i> Administrator</span>
    </div>
  </div>
</section>

<div class="container">
  <!-- Alerts -->
  <?php if ($errors): ?>
    <div class="alert alert-danger cardx p-3 mb-3">
      <div class="d-flex align-items-start gap-2">
        <i class="fa-solid fa-triangle-exclamation mt-1"></i>
        <div>
          <strong>Gabim në formular:</strong>
          <ul class="mb-0 mt-1">
            <?php foreach ($errors as $e): ?><li><?= h($e) ?></li><?php endforeach; ?>
          </ul>
        </div>
      </div>
    </div>
  <?php endif; ?>

  <form method="POST" enctype="multipart/form-data" class="row g-3">
    <input type="hidden" name="csrf" value="<?= h($CSRF) ?>">

    <!-- Majtas -->
    <div class="col-12 col-lg-8">
      <div class="cardx p-3">
        <h5 class="fw-bold mb-3" style="color: var(--primary)"><i class="fa-regular fa-rectangle-list me-2"></i>Detajet e eventit</h5>

        <div class="mb-3">
          <label class="form-label">Titulli i eventit</label>
          <input type="text" name="title" class="form-control" required placeholder="p.sh. Konferenca e Inovacionit 2025" value="<?= h($title) ?>">
        </div>

        <div class="row g-3">
          <div class="col-md-6">
            <label class="form-label">Kategoria</label>
            <select class="form-select" name="category" required>
              <option value="">Zgjidh...</option>
              <?php foreach ($allowed_categories as $cat): ?>
                <option value="<?= h($cat) ?>" <?= $category===$cat?'selected':'' ?>><?= h($cat) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="col-md-6">
            <label class="form-label">Statusi</label>
            <select class="form-select" name="status">
              <?php foreach ($allowed_statuses as $st): ?>
                <option value="<?= h($st) ?>" <?= $status===$st?'selected':'' ?>><?= h($st) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
        </div>

        <div class="mt-3">
          <label class="form-label">Data & ora</label>
          <div class="input-group">
            <span class="input-group-text"><i class="fa-regular fa-clock"></i></span>
            <input type="datetime-local" class="form-control" id="event_datetime" name="event_datetime" required
                   value="<?= h($event_dt_local_input) ?>">
          </div>
          <div class="hint mt-1">Zgjidhni datën dhe orën e fillimit të eventit.</div>
        </div>

        <div class="mt-3">
          <label class="form-label">Përshkrimi (Markdown)</label>
          <textarea id="description" name="description"><?= h($description) ?></textarea>
          <div class="hint mt-1"><i class="fa-regular fa-circle-question me-1"></i>Mund të përdorni sintaksën Markdown për lista, kode, etj.</div>
        </div>
      </div>
    </div>

    <!-- Djathtas -->
    <div class="col-12 col-lg-4">
      <div class="cardx p-3">
        <h5 class="fw-bold mb-3" style="color: var(--primary)"><i class="fa-regular fa-image me-2"></i>Foto e eventit</h5>

        <div class="drop" id="dropZone">
          <i class="fa-solid fa-cloud-arrow-up fs-1 mb-2"></i>
          <div class="mb-1">Tërhiq & lësho ose kliko për të zgjedhur</div>
          <div class="hint">Lejohen JPG, PNG, GIF, WEBP (maks 6MB)</div>
          <input class="d-none" type="file" id="event_photo" name="event_photo" accept="image/*" required>
        </div>
        <div class="preview mt-2" id="previewWrap">
          <img id="previewImg" alt="">
        </div>

        <div class="d-grid mt-3">
          <button class="btn btn-primary btn-lg" type="submit"><i class="fa-regular fa-floppy-disk me-1"></i>Ruaj eventin</button>
          <a href="../event.php" class="btn btn-outline-secondary mt-2"><i class="fa-solid fa-arrow-left-long me-1"></i>Kthehu te eventet</a>
        </div>
      </div>
    </div>
  </form>
</div>

<br>

<?php include __DIR__ . '/footer.php'; ?>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script src="https://cdn.jsdelivr.net/simplemde/latest/simplemde.min.js"></script>
<script>
  // Markdown editor
  const simplemde = new SimpleMDE({
    element: document.getElementById('description'),
    toolbar: ['bold','italic','heading','|','code','quote','unordered-list','ordered-list','|','link','image','table','|','preview','guide'],
    spellChecker:false,
    placeholder: "Përshkrimi i eventit..."
  });

  // Datetime min (opsionale)
  (function(){
    const input = document.getElementById('event_datetime');
    if (!input.value) {
      const now = new Date();
      now.setMinutes(now.getMinutes() - now.getTimezoneOffset());
      input.min = now.toISOString().slice(0,16);
    }
  })();

  // Dropzone & preview
  const dropZone = document.getElementById('dropZone');
  const fileInput = document.getElementById('event_photo');
  const prevWrap  = document.getElementById('previewWrap');
  const prevImg   = document.getElementById('previewImg');

  function openPicker(){ fileInput.click(); }
  function setPreview(file){
    if (!file) return;
    const reader = new FileReader();
    reader.onload = e => {
      prevImg.src = e.target.result;
      prevWrap.style.display = 'block';
    };
    reader.readAsDataURL(file);
  }

  dropZone.addEventListener('click', openPicker);
  dropZone.addEventListener('dragover', (e)=>{ e.preventDefault(); dropZone.classList.add('drag'); });
  dropZone.addEventListener('dragleave', ()=> dropZone.classList.remove('drag'));
  dropZone.addEventListener('drop', (e)=>{
    e.preventDefault(); dropZone.classList.remove('drag');
    if (e.dataTransfer.files && e.dataTransfer.files[0]) {
      fileInput.files = e.dataTransfer.files;
      setPreview(e.dataTransfer.files[0]);
    }
  });
  fileInput.addEventListener('change', ()=>{ if (fileInput.files && fileInput.files[0]) setPreview(fileInput.files[0]); });
</script>
</body>
</html>
