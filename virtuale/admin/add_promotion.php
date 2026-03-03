<?php
// add_promotion.php — Krijo Promocion Kursi (Admin/Instruktor) me Markdown për description
declare(strict_types=1);
session_start();
require_once __DIR__ . '/../lib/database.php';

/* ------------------------------- RBAC ------------------------------- */
if (!isset($_SESSION['user'])) { header('Location: ../login.php'); exit; }
$ROLE  = $_SESSION['user']['role'] ?? '';
if (!in_array($ROLE, ['Administrator','Instruktor'], true)) {
  header('Location: ../courses_student.php'); exit;
}

/* ------------------------------- CSRF ------------------------------- */
if (empty($_SESSION['csrf_token'])) { $_SESSION['csrf_token'] = bin2hex(random_bytes(32)); }
$CSRF = $_SESSION['csrf_token'];

/* ----------------------------- Helpers ------------------------------ */
function h(?string $s): string { return htmlspecialchars($s ?? '', ENT_QUOTES, 'UTF-8'); }
function set_flash(string $msg, string $type='success'): void { $_SESSION['flash'] = ['msg'=>$msg, 'type'=>$type]; }
function get_flash(): ?array { if (!empty($_SESSION['flash'])) { $f=$_SESSION['flash']; unset($_SESSION['flash']); return $f; } return null; }

/* ----------------------------- Defaults ----------------------------- */
$form = [
  'name'        => '',
  'short_desc'  => '',
  'description' => '',
  'hours_total' => '0',
  'price'       => '',
  'old_price'   => '',
  'level'       => 'ALL',
  'label'       => '',
  'badge_color' => '#F0B323',
  'photo'       => '',
  'video_url'   => ''
];

/* --------------------------- Copy from existing --------------------- */
if ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['copy_promotion_id']) && ctype_digit((string)$_GET['copy_promotion_id'])) {
  $copyId = (int)$_GET['copy_promotion_id'];
  try {
    $st = $pdo->prepare("SELECT * FROM promoted_courses WHERE id = :id");
    $st->execute([':id'=>$copyId]);
    if ($row = $st->fetch(PDO::FETCH_ASSOC)) {
      $form['name']        = 'Kopje — ' . (string)$row['name'];
      $form['short_desc']  = (string)($row['short_desc'] ?? '');
      $form['description'] = (string)($row['description'] ?? '');
      $form['hours_total'] = (string)($row['hours_total'] ?? '0');
      $form['price']       = $row['price'] !== null ? (string)$row['price'] : '';
      $form['old_price']   = $row['old_price'] !== null ? (string)$row['old_price'] : '';
      $form['level']       = (string)($row['level'] ?? 'ALL');
      $form['label']       = (string)($row['label'] ?? '');
      $form['badge_color'] = (string)($row['badge_color'] ?? '#F0B323');
      $form['photo']       = (string)($row['photo'] ?? '');
      $form['video_url']   = (string)($row['video_url'] ?? '');
    }
  } catch (Throwable $e) { /* ignore prefill errors */ }
}

/* -------------------------------- POST ------------------------------ */
$errors = [];
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  if (!hash_equals($CSRF, (string)($_POST['csrf'] ?? ''))) {
    $errors[] = 'Seancë e pasigurt (CSRF). Rifresko faqen dhe provo sërish.';
  }

  $form['name']        = trim((string)($_POST['name'] ?? ''));
  $form['short_desc']  = trim((string)($_POST['short_desc'] ?? ''));
  $form['description'] = trim((string)($_POST['description'] ?? ''));
  $form['hours_total'] = trim((string)($_POST['hours_total'] ?? '0'));
  $form['price']       = trim((string)($_POST['price'] ?? ''));
  $form['old_price']   = trim((string)($_POST['old_price'] ?? ''));
  $form['level']       = strtoupper(trim((string)($_POST['level'] ?? 'ALL')));
  $form['label']       = trim((string)($_POST['label'] ?? ''));
  $form['badge_color'] = trim((string)($_POST['badge_color'] ?? '#F0B323'));
  $form['video_url']   = trim((string)($_POST['video_url'] ?? ''));
  $form['photo']       = trim((string)($_POST['existing_photo'] ?? ''));

  if ($form['name'] === '') { $errors[] = 'Emri është i detyrueshëm.'; }
  if (mb_strlen($form['name']) > 255) { $errors[] = 'Emri nuk duhet të kalojë 255 karaktere.'; }
  if (mb_strlen($form['short_desc']) > 255) { $errors[] = 'Përshkrimi i shkurtër nuk duhet të kalojë 255 karaktere.'; }
  if (!ctype_digit($form['hours_total']) || (int)$form['hours_total'] < 0) { $errors[] = 'Orët duhet të jenë numër >= 0.'; }

  $price     = ($form['price']     === '') ? null : (float)$form['price'];
  $old_price = ($form['old_price'] === '') ? null : (float)$form['old_price'];
  if ($price !== null && $price < 0)        { $errors[] = 'Çmimi duhet të jetë ≥ 0.'; }
  if ($old_price !== null && $old_price < 0){ $errors[] = 'Çmimi i vjetër duhet të jetë ≥ 0.'; }

  $allowedLevels = ['BEGINNER','INTERMEDIATE','ADVANCED','ALL'];
  if (!in_array($form['level'], $allowedLevels, true)) { $errors[] = 'Niveli i pavlefshëm.'; }

  if (mb_strlen($form['label']) > 40) { $errors[] = 'Label nuk duhet të kalojë 40 karaktere.'; }
  if ($form['badge_color'] !== '' && !preg_match('/^#[0-9A-Fa-f]{6}$/', $form['badge_color'])) {
    $errors[] = 'Ngjyra e badge duhet të jetë në formatin #RRGGBB.';
  }
  if ($form['video_url'] !== '' && !filter_var($form['video_url'], FILTER_VALIDATE_URL)) {
    $errors[] = 'URL e videos nuk është e vlefshme.';
  }

  $uploadedPhotoName = '';
  if (isset($_FILES['photo']) && is_array($_FILES['photo']) && (int)$_FILES['photo']['error'] !== UPLOAD_ERR_NO_FILE) {
    $err  = (int)$_FILES['photo']['error'];
    $size = (int)$_FILES['photo']['size'];
    if ($err !== UPLOAD_ERR_OK) {
      $errors[] = 'Ngarkimi i fotos dështoi (kod: '.$err.').';
    } else {
      if ($size > 5*1024*1024) {
        $errors[] = 'Fotoja është shumë e madhe (maks 5MB).';
      } else {
        $allowed = ['jpg','jpeg','png','webp'];
        $ext = strtolower(pathinfo((string)$_FILES['photo']['name'], PATHINFO_EXTENSION));
        if (!in_array($ext, $allowed, true)) {
          $errors[] = 'Lejohen vetëm: JPG, JPEG, PNG, WEBP.';
        } else {
          $dir = __DIR__ . '/../uploads/promotions';
          if (!is_dir($dir)) { @mkdir($dir, 0755, true); }
          $fname = 'promo_' . date('Ymd_His') . '_' . bin2hex(random_bytes(4)) . '.' . $ext;
          $dest = $dir . '/' . $fname;
          if (!@move_uploaded_file($_FILES['photo']['tmp_name'], $dest)) {
            $errors[] = 'S’u arrit të ruhej fotoja në server.';
          } else {
            $uploadedPhotoName = '/../uploads/promotions/' . $fname;
          }
        }
      }
    }
  }
  $photoToSave = $uploadedPhotoName !== '' ? $uploadedPhotoName : ($form['photo'] ?: null);

  if (!$errors) {
    try {
      $stmt = $pdo->prepare("
        INSERT INTO promoted_courses
          (name, short_desc, description, hours_total, price, old_price, level, label, badge_color, photo, video_url)
        VALUES
          (:name, :short_desc, :description, :hours_total, :price, :old_price, :level, :label, :badge_color, :photo, :video_url)
      ");
      $stmt->bindValue(':name',        $form['name']);
      $form['short_desc'] !== ''
        ? $stmt->bindValue(':short_desc',  $form['short_desc'], PDO::PARAM_STR)
        : $stmt->bindValue(':short_desc',  null, PDO::PARAM_NULL);
      $form['description'] !== ''
        ? $stmt->bindValue(':description', $form['description'], PDO::PARAM_STR)
        : $stmt->bindValue(':description', null, PDO::PARAM_NULL);
      $stmt->bindValue(':hours_total', (int)$form['hours_total'], PDO::PARAM_INT);
      $price === null
        ? $stmt->bindValue(':price', null, PDO::PARAM_NULL)
        : $stmt->bindValue(':price', $price);
      $old_price === null
        ? $stmt->bindValue(':old_price', null, PDO::PARAM_NULL)
        : $stmt->bindValue(':old_price', $old_price);
      $stmt->bindValue(':level',       $form['level']);
      $form['label'] !== ''
        ? $stmt->bindValue(':label', $form['label'], PDO::PARAM_STR)
        : $stmt->bindValue(':label', null, PDO::PARAM_NULL);
      $form['badge_color'] !== ''
        ? $stmt->bindValue(':badge_color', $form['badge_color'], PDO::PARAM_STR)
        : $stmt->bindValue(':badge_color', null, PDO::PARAM_NULL);
      $photoToSave === null
        ? $stmt->bindValue(':photo', null, PDO::PARAM_NULL)
        : $stmt->bindValue(':photo', $photoToSave, PDO::PARAM_STR);
      $form['video_url'] !== ''
        ? $stmt->bindValue(':video_url', $form['video_url'], PDO::PARAM_STR)
        : $stmt->bindValue(':video_url', null, PDO::PARAM_NULL);

      $stmt->execute();
      set_flash('Promocioni u krijua me sukses.', 'success');
      header('Location: ../promotions.php'); exit;
    } catch (Throwable $e) {
      $errors[] = 'Gabim gjatë ruajtjes: ' . $e->getMessage();
    }
  }
}
?>
<!DOCTYPE html>
<html lang="sq">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <title>Shto Promocion — kurseinformatike.com</title>

  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
  <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css" rel="stylesheet">
  <link rel="icon" href="image/favicon.ico" type="image/x-icon" />

  <style>
    :root{
      --primary:#2A4B7C; --primary-dark:#1d3a63; --secondary:#F0B323;
      --accent:#FF6B6B; --muted:#6b7280; --ok:#16a34a; --warn:#f59e0b; --bad:#dc2626;
      --card-r:18px; --shadow:0 10px 28px rgba(0,0,0,.08);
      --brand-font: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, "Helvetica Neue", Arial, "Noto Sans", "Liberation Sans", sans-serif, "Apple Color Emoji", "Segoe UI Emoji", "Segoe UI Symbol", "Noto Color Emoji";
    }
    body{ background:#f6f8fb; }
    h1,h2,h3,h4,h5,h6{ font-family: var(--brand-font); letter-spacing:.2px; }

    .hero{ background: linear-gradient(135deg,var(--primary),var(--primary-dark)); color:#fff; padding:34px 0 22px; margin-bottom:16px; }
    .hero h1{ font-weight:700; }

    .card-ui{ border:0; border-radius:var(--card-r); box-shadow:var(--shadow); }
    .help{ color:#6b7280; font-size:.9rem; }

    .img-preview{ width:100%; max-height:220px; object-fit:cover; border-radius:12px; background:#f1f5f9; }
    .badge-preview{ display:inline-block; padding:.25rem .5rem; border-radius:.5rem; color:#fff; }

    /* Markdown styles (minimal, të pastra) */
    .markdown-body{ font-size: .98rem; line-height: 1.6; }
    .markdown-body h1,.markdown-body h2{ border-bottom:1px solid #e5e7eb; padding-bottom:.3rem; margin-top:1rem; }
    .markdown-body pre{ background:#0f172a; color:#e2e8f0; padding:.75rem; border-radius:10px; overflow:auto; }
    .markdown-body code{ background:#f1f5f9; padding:.1rem .3rem; border-radius:6px; }
    .markdown-body a{ color:#1d4ed8; text-decoration: underline; }
    .markdown-body blockquote{ border-left:4px solid #cbd5e1; padding-left:.75rem; color:#475569; }

    /* Toast Zone bottom-right */
    #toastZone{ position: fixed; right: 16px; bottom: 16px; z-index: 1100; }
    .toast.kurse{ background: #ffffff; border: 1px solid #e8ecf4; box-shadow: var(--shadow); border-radius: 12px; overflow: hidden; }
    .toast.kurse .toast-header{ background: linear-gradient(135deg,var(--primary),var(--primary-dark)); color:#fff; }
    .toast.kurse .btn-close{ filter: invert(1); }
  </style>
</head>
<body>

<?php
  if ($ROLE === 'Administrator')      include __DIR__ . '/../navbar_logged_administrator.php';
  elseif ($ROLE === 'Instruktor')     include __DIR__ . '/../navbar_logged_instruktor.php';
  else                                include __DIR__ . '/../navbar_logged_student.php';
?>

<section class="hero">
  <div class="container">
    <div class="d-flex align-items-center justify-content-between">
      <div>
        <div class="text-white-50 small"><i class="fa-solid fa-house me-1"></i> Paneli / Promocione</div>
        <h1 class="mb-0">Shto Promocion</h1>
      </div>
      <a class="btn btn-light" href="../promotions.php"><i class="fa-solid fa-arrow-left-long me-1"></i> Kthehu</a>
    </div>
  </div>
</section>

<div class="container mb-4">
  <?php if ($errors): ?>
    <div class="alert alert-danger">
      <div class="fw-bold mb-1"><i class="fa-solid fa-triangle-exclamation me-1"></i> Disa fusha kërkojnë vëmendje:</div>
      <ul class="mb-0">
        <?php foreach ($errors as $e): ?><li><?= h($e) ?></li><?php endforeach; ?>
      </ul>
    </div>
  <?php endif; ?>

  <form method="POST" enctype="multipart/form-data" class="row g-3">
    <input type="hidden" name="csrf" value="<?= h($CSRF) ?>">
    <input type="hidden" name="existing_photo" value="<?= h($form['photo']) ?>">

    <!-- Kolona majtas: fushat kryesore -->
    <div class="col-lg-8">
      <div class="card card-ui">
        <div class="card-body">
          <div class="mb-3">
            <label class="form-label">Emri i promocionit <span class="text-danger">*</span></label>
            <input type="text" name="name" class="form-control" maxlength="255" required value="<?= h($form['name']) ?>" placeholder="p.sh. Python për Fillestarë — Intenziv (4 javë)">
          </div>

          <div class="mb-3">
            <label class="form-label">Përshkrim i shkurtër</label>
            <input type="text" name="short_desc" class="form-control" maxlength="255" value="<?= h($form['short_desc']) ?>" placeholder="Një fjali e shkurtër që shfaqet në kartë">
          </div>

          <!-- Markdown editor + preview -->
          <div class="mb-3">
            <label class="form-label d-flex align-items-center gap-2">
              Përshkrim (Markdown)
              <span class="badge text-bg-light">**bold** _italic_ [link](#) `code`</span>
            </label>

            <ul class="nav nav-tabs" role="tablist">
              <li class="nav-item" role="presentation">
                <button class="nav-link active" id="md-edit-tab" data-bs-toggle="tab" data-bs-target="#md-edit" type="button" role="tab">
                  <i class="fa-regular fa-pen-to-square me-1"></i> Shkruaj
                </button>
              </li>
              <li class="nav-item" role="presentation">
                <button class="nav-link" id="md-prev-tab" data-bs-toggle="tab" data-bs-target="#md-preview" type="button" role="tab">
                  <i class="fa-regular fa-eye me-1"></i> Parapamje
                </button>
              </li>
            </ul>

            <div class="tab-content border border-top-0 rounded-bottom">
              <div class="tab-pane fade show active p-2" id="md-edit" role="tabpanel" aria-labelledby="md-edit-tab">
                <textarea name="description" id="mdInput" rows="10" class="form-control" placeholder="# Titulli
- Piket 1
- Piket 2

**Bold**, _italic_, `inline code`, dhe ```blloqe kodi``` me tre backticks."><?= h($form['description']) ?></textarea>
                <div class="help mt-1">
                  Sintaksa e shpejtë: # H1, ## H2, **bold**, _italic_, `code`, ```bllok```, - listë, 1. listë numerike, > citim.
                </div>
              </div>
              <div class="tab-pane fade p-3 bg-white" id="md-preview" role="tabpanel" aria-labelledby="md-prev-tab">
                <div id="mdRendered" class="markdown-body"></div>
              </div>
            </div>
          </div>
          <!-- /Markdown -->
        </div>
      </div>
    </div>

    <!-- Kolona djathtas: parametra & media -->
    <div class="col-lg-4">
      <div class="card card-ui">
        <div class="card-body">
          <div class="mb-3">
            <label class="form-label">Niveli</label>
            <select name="level" class="form-select">
              <?php
                $opts = ['BEGINNER'=>'Fillestar','INTERMEDIATE'=>'Mesatar','ADVANCED'=>'I avancuar','ALL'=>'Për të gjithë'];
                foreach ($opts as $k=>$v):
              ?>
                <option value="<?= $k ?>" <?= $form['level']===$k?'selected':'' ?>><?= h($v) ?></option>
              <?php endforeach; ?>
            </select>
          </div>

          <div class="row g-2">
            <div class="col-6">
              <label class="form-label">Orët totale</label>
              <input type="number" name="hours_total" min="0" step="1" class="form-control" value="<?= h($form['hours_total']) ?>">
            </div>
            <div class="col-6">
              <label class="form-label">Label (opsionale)</label>
              <input type="text" name="label" maxlength="40" class="form-control" value="<?= h($form['label']) ?>" placeholder="p.sh. HOT, NEW, -25%">
            </div>
          </div>

          <div class="row g-2 mt-1">
            <div class="col-6">
              <label class="form-label">Badge color</label>
              <input type="color" name="badge_color" id="badgeColor" class="form-control form-control-color" value="<?= h($form['badge_color'] ?: '#F0B323') ?>">
              <div class="small mt-1">Preview: <span id="badgePreview" class="badge-preview" style="background: <?= h($form['badge_color'] ?: '#F0B323') ?>">LABEL</span></div>
            </div>
            <div class="col-6">
              <label class="form-label">Video URL</label>
              <input type="url" name="video_url" class="form-control" value="<?= h($form['video_url']) ?>" placeholder="https://…">
            </div>
          </div>

          <div class="row g-2 mt-1">
            <div class="col-6">
              <label class="form-label">Çmimi</label>
              <div class="input-group">
                <span class="input-group-text"><i class="fa-solid fa-euro-sign"></i></span>
                <input type="number" step="0.01" min="0" name="price" class="form-control" value="<?= h($form['price']) ?>" id="priceInput">
              </div>
            </div>
            <div class="col-6">
              <label class="form-label">Çmimi i vjetër</label>
              <div class="input-group">
                <span class="input-group-text"><i class="fa-solid fa-euro-sign"></i></span>
                <input type="number" step="0.01" min="0" name="old_price" class="form-control" value="<?= h($form['old_price']) ?>" id="oldPriceInput">
              </div>
              <div class="small mt-1">Zbritja: <strong id="discountPreview">—</strong></div>
            </div>
          </div>

          <div class="mt-3">
            <label class="form-label">Foto (JPG/PNG/WEBP, max 5MB)</label>
            <input type="file" name="photo" accept=".jpg,.jpeg,.png,.webp" class="form-control" id="photoInput">
            <div class="mt-2">
              <?php
                $previewSrc = 'image/course_placeholder.jpg';
                if ($form['photo'] && is_string($form['photo'])) { $previewSrc = $form['photo']; }
              ?>
              <img id="imgPreview" src="<?= h($previewSrc) ?>" class="img-preview" alt="Preview">
              <?php if ($form['photo']): ?>
                <div class="small text-secondary mt-1"><i class="fa-regular fa-image me-1"></i> Aktualisht: <?= h($form['photo']) ?></div>
              <?php endif; ?>
            </div>
          </div>
        </div>
      </div>

      <div class="d-grid mt-3">
        <button class="btn btn-primary"><i class="fa-solid fa-floppy-disk me-1"></i> Ruaj promocionin</button>
      </div>
      <div class="d-grid mt-2">
        <a class="btn btn-outline-secondary" href="../promotions.php"><i class="fa-solid fa-xmark me-1"></i> Anulo</a>
      </div>
    </div>
  </form>
</div>

<!-- Toast container (bottom-right) -->
<div id="toastZone" aria-live="polite" aria-atomic="true"></div>

<?php include __DIR__ . '/footer.php'; ?>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<!-- Markdown parser + sanitizer -->
<script src="https://cdn.jsdelivr.net/npm/marked/marked.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/dompurify/dist/purify.min.js"></script>
<script>
/* Badge color preview */
const badgeColor = document.getElementById('badgeColor');
const badgePreview = document.getElementById('badgePreview');
badgeColor?.addEventListener('input', () => {
  badgePreview.style.background = badgeColor.value || '#F0B323';
});

/* Discount live calc */
function calcDiscount(){
  const p  = parseFloat(document.getElementById('priceInput')?.value || '');
  const op = parseFloat(document.getElementById('oldPriceInput')?.value || '');
  const span = document.getElementById('discountPreview');
  if (!isNaN(p) && !isNaN(op) && op>0 && p>0 && p<op) {
    const d = Math.round(((op - p) / op) * 100);
    span.textContent = '-' + d + '%';
  } else span.textContent = '—';
}
document.getElementById('priceInput')?.addEventListener('input', calcDiscount);
document.getElementById('oldPriceInput')?.addEventListener('input', calcDiscount);
calcDiscount();

/* Image preview */
document.getElementById('photoInput')?.addEventListener('change', (ev)=>{
  const f = ev.target.files?.[0];
  if (!f) return;
  const reader = new FileReader();
  reader.onload = e => { document.getElementById('imgPreview').src = e.target.result; };
  reader.readAsDataURL(f);
});

/* Markdown preview */
const mdInput    = document.getElementById('mdInput');
const mdRendered = document.getElementById('mdRendered');
if (window.marked) {
  marked.setOptions({ breaks: true });
}
function renderMarkdown(){
  const src = mdInput?.value || '';
  const raw = (window.marked ? marked.parse(src) : src);
  mdRendered.innerHTML = window.DOMPurify ? DOMPurify.sanitize(raw) : raw;
}
mdInput?.addEventListener('input', renderMarkdown);
document.addEventListener('DOMContentLoaded', renderMarkdown);

/* Toasts */
function toastIcon(type){
  if (type==='success') return '<i class="fa-solid fa-circle-check me-2"></i>';
  if (type==='danger' || type==='error') return '<i class="fa-solid fa-triangle-exclamation me-2"></i>';
  if (type==='warning') return '<i class="fa-solid fa-circle-exclamation me-2"></i>';
  return '<i class="fa-solid fa-circle-info me-2"></i>';
}
function showToast(type, msg){
  const zone = document.getElementById('toastZone');
  const id = 't' + Math.random().toString(16).slice(2);
  const el = document.createElement('div');
  el.className = 'toast kurse align-items-center';
  el.id = id;
  el.setAttribute('role','alert');
  el.setAttribute('aria-live','assertive');
  el.setAttribute('aria-atomic','true');
  el.innerHTML = `
    <div class="toast-header">
      <strong class="me-auto d-flex align-items-center">${toastIcon(type)} Njoftim</strong>
      <small class="text-white-50">tani</small>
      <button type="button" class="btn-close ms-2 mb-1" data-bs-dismiss="toast" aria-label="Mbyll"></button>
    </div>
    <div class="toast-body">${msg}</div>`;
  zone.appendChild(el);
  const t = new bootstrap.Toast(el, { delay: 3500, autohide: true });
  t.show();
}
<?php if ($fl = get_flash()): ?>
  (function(){ showToast(<?= json_encode($fl['type']) ?>, <?= json_encode($fl['msg']) ?>); })();
<?php endif; ?>
</script>
</body>
</html>
