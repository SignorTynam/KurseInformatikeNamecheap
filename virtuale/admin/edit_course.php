<?php
// edit_course.php — Revamp (Admin/Instruktor) • përdor km-lms-forms.css
declare(strict_types=1);
session_start();
require_once __DIR__ . '/../lib/database.php';

/* ------------------------------- Helpers ------------------------------- */
function h(?string $s): string { return htmlspecialchars($s ?? '', ENT_QUOTES, 'UTF-8'); }
function flash(string $msg, string $type='danger'): void { $_SESSION['flash'] = ['msg'=>$msg,'type'=>$type]; }
function get_flash(): ?array { $f=$_SESSION['flash']??null; unset($_SESSION['flash']); return $f; }
function safe_filename(string $name): string {
  $base = preg_replace('/[^a-zA-Z0-9_\-\.]/','_', basename($name));
  return $base !== '' ? $base : ('img_'.time());
}

/* -------------------------------- Auth --------------------------------- */
if (!isset($_SESSION['user']) || !in_array($_SESSION['user']['role'] ?? '', ['Administrator','Instruktor'], true)) {
  header('Location: ../login.php'); exit;
}
$ROLE  = (string)($_SESSION['user']['role'] ?? '');
$ME_ID = (int)($_SESSION['user']['id'] ?? 0);

/* ------------------------------ CSRF token ----------------------------- */
if (empty($_SESSION['csrf'])) { $_SESSION['csrf'] = bin2hex(random_bytes(16)); }
$csrf = (string)$_SESSION['csrf'];

/* ------------------------------ course_id ------------------------------ */
if (!isset($_GET['course_id']) || !is_numeric((string)$_GET['course_id'])) {
  http_response_code(400);
  flash('Kursi nuk është specifikuar.', 'danger');
  header('Location: ../course.php'); exit;
}
$course_id = (int)$_GET['course_id'];

/* -------------------- Upload folder (admin -> ../uploads/courses) -------------------- */
$COURSE_UPLOAD_DIR = realpath(__DIR__ . '/../uploads/courses') ?: (__DIR__ . '/../uploads/courses');
if (!is_dir($COURSE_UPLOAD_DIR)) { @mkdir($COURSE_UPLOAD_DIR, 0775, true); }

/* --------------------------- Load course row --------------------------- */
try {
  $stmt = $pdo->prepare("
    SELECT c.*, u.full_name AS creator_name, u.id AS creator_id
    FROM courses c
    LEFT JOIN users u ON c.id_creator=u.id
    WHERE c.id=? LIMIT 1
  ");
  $stmt->execute([$course_id]);
  $course = $stmt->fetch(PDO::FETCH_ASSOC);
  if (!$course) {
    http_response_code(404);
    flash('Kursi nuk u gjet.', 'danger');
    header('Location: ../course.php'); exit;
  }

  // Instruktori lejohet vetëm nëse është krijuesi
  if ($ROLE === 'Instruktor' && (int)($course['creator_id'] ?? 0) !== $ME_ID) {
    http_response_code(403);
    flash('Nuk keni akses në këtë kurs.', 'danger');
    header('Location: ../course.php'); exit;
  }
} catch (PDOException $e) {
  http_response_code(500);
  flash('Gabim: '.h($e->getMessage()), 'danger');
  header('Location: ../course.php'); exit;
}

/* ------------------------------ Defaults ------------------------------- */
$title         = (string)($course['title'] ?? '');
$description   = (string)($course['description'] ?? '');
$status        = (string)($course['status'] ?? 'ACTIVE');
$category      = (string)($course['category'] ?? 'TJETRA');
$image         = (string)($course['photo'] ?? '');
$aula_virtuale = (string)($course['AulaVirtuale'] ?? '');

$allowedCategories = ['PROGRAMIM','GRAFIKA','WEB','GJUHE TE HUAJA','IT','TJETRA'];
$allowedStatus     = ['ACTIVE','INACTIVE','ARCHIVED'];

$errors = [];
$flash  = get_flash();

/* --------------------------------- POST -------------------------------- */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {

  // CSRF
  if (!hash_equals($csrf, (string)($_POST['csrf'] ?? ''))) {
    $errors[] = 'Seancë e pasigurt. Ringarkoni faqen dhe provoni sërish.';
  }

  // Inputs
  $title       = trim((string)($_POST['title'] ?? ''));
  $description = (string)($_POST['description'] ?? '');
  $status      = in_array((string)($_POST['status'] ?? ''), $allowedStatus, true) ? (string)$_POST['status'] : 'ACTIVE';
  $category    = in_array((string)($_POST['category'] ?? ''), $allowedCategories, true) ? (string)$_POST['category'] : $allowedCategories[0];

  $aula_virtuale_raw = trim((string)($_POST['aula_virtuale'] ?? ''));
  $remove_image      = isset($_POST['remove_image']) && (string)$_POST['remove_image'] === '1';

  // Validime
  if ($title === '') { $errors[] = 'Titulli i kursit është i detyrueshëm.'; }
  if ($aula_virtuale_raw !== '' && !filter_var($aula_virtuale_raw, FILTER_VALIDATE_URL)) {
    $errors[] = 'Linku i Teams (Aula Virtuale) nuk është URL e vlefshme.';
  }
  $aula_virtuale = ($aula_virtuale_raw !== '') ? $aula_virtuale_raw : null;

  // Upload foto
  $newImageName = $image; // default: ruaj foton ekzistuese
  $uploaded     = false;

  if (isset($_FILES['image']) && ($_FILES['image']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
    $f = $_FILES['image'];

    if (($f['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
      $errors[] = 'Ngarkimi i fotos dështoi.';
    } else {
      if ((int)($f['size'] ?? 0) > 5 * 1024 * 1024) {
        $errors[] = 'Fotoja tejkalon madhësinë maksimale 5MB.';
      }

      $info = @getimagesize((string)($f['tmp_name'] ?? ''));
      $mime = $info['mime'] ?? '';
      $okMime = in_array($mime, ['image/jpeg','image/png','image/gif'], true);
      $extMap = ['image/jpeg'=>'jpg','image/png'=>'png','image/gif'=>'gif'];

      if (!$okMime) { $errors[] = 'Formati i fotos nuk lejohet. Lejohen: JPG, PNG, GIF.'; }

      if (!$errors) {
        $ext = $extMap[$mime] ?? 'jpg';

        // RUAN te ../uploads/courses (absolute path)
        $newImageName = time() . '-' . bin2hex(random_bytes(6)) . '-' . safe_filename("cover.$ext");
        $dest = $COURSE_UPLOAD_DIR . DIRECTORY_SEPARATOR . $newImageName;

        if (!move_uploaded_file((string)$f['tmp_name'], $dest)) {
          $errors[] = 'Nuk u arrit të ruhej fotoja në server.';
        } else {
          $uploaded = true;
        }
      }
    }
  }

  // Nëse u shënua "Hiq foton" dhe nuk u ngarkua një e re
  if ($remove_image && !$uploaded) { $newImageName = ''; }

  // UPDATE
  if (!$errors) {
    try {
      $pdo->beginTransaction();

      $stmtU = $pdo->prepare("
        UPDATE courses
           SET title=?, description=?, photo=?, status=?, category=?, AulaVirtuale=?, updated_at=NOW()
         WHERE id=?
      ");
      $ok = $stmtU->execute([
        $title,
        $description,
        $newImageName !== '' ? $newImageName : null,
        $status,
        $category,
        $aula_virtuale,
        $course_id
      ]);

      if (!$ok) { throw new RuntimeException('Përditësimi dështoi.'); }

      // Fshi foton e vjetër nëse u zëvendësua ose u hoq
      if (($uploaded || $remove_image) && !empty($image)) {
        $oldPath = $COURSE_UPLOAD_DIR . DIRECTORY_SEPARATOR . $image;
        if (is_file($oldPath)) { @unlink($oldPath); }
      }

      $pdo->commit();
      flash('Kursi u përditësua me sukses!', 'success');
      header('Location: ../course_details.php?course_id='.(int)$course_id); exit;

    } catch (Throwable $e) {
      if ($pdo->inTransaction()) { $pdo->rollBack(); }

      // Nëse ngarkuam file të ri por DB dështoi: fshije file-in e ri
      if ($uploaded) {
        $path = $COURSE_UPLOAD_DIR . DIRECTORY_SEPARATOR . $newImageName;
        if (is_file($path)) { @unlink($path); }
      }
      $errors[] = 'Gabim gjatë përditësimit të kursit: '.h($e->getMessage());
    }
  }
}

// Për header
$creatorName = (string)($course['creator_name'] ?? '—');
$createdAt   = !empty($course['created_at']) ? date('d.m.Y H:i', strtotime((string)$course['created_at'])) : '';
?>
<!DOCTYPE html>
<html lang="sq" data-bs-theme="light">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Modifiko Kursin — <?= h($title ?: 'Kurs') ?></title>

  <!-- Nëse favicon është jashtë /admin -->
  <link rel="icon" href="../image/favicon.ico" type="image/x-icon" />

  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">
  <link href="https://cdn.jsdelivr.net/simplemde/latest/simplemde.min.css" rel="stylesheet">

  <!-- CSS i përbashkët i revamp-it -->
  <link rel="stylesheet" href="../css/km-lms-forms.css?v=1">
</head>

<body class="km-body">

<?php
  // Navbar (zakonisht është një nivel sipër nga /admin)
  if ($ROLE === 'Administrator' && file_exists(__DIR__.'/../navbar_logged_administrator.php')) {
    include __DIR__.'/../navbar_logged_administrator.php';
  } elseif ($ROLE === 'Instruktor' && file_exists(__DIR__.'/../navbar_logged_instruktor.php')) {
    include __DIR__.'/../navbar_logged_instruktor.php';
  } elseif (file_exists(__DIR__.'/../navbar_logged_administrator.php')) {
    include __DIR__.'/../navbar_logged_administrator.php';
  }
?>

<div class="km-page-shell">
  <div class="container">

    <!-- HEADER -->
    <header class="km-page-header d-flex flex-column flex-md-row justify-content-between align-items-start align-items-md-center gap-3">
      <div>
        <div class="km-breadcrumb small mb-1">
          <a class="km-breadcrumb-link" href="../course_details.php?course_id=<?= (int)$course_id ?>">
            <i class="bi bi-arrow-left me-1"></i>Kthehu te kursi
          </a>
          <span class="mx-1">/</span>
          <span class="km-breadcrumb-current">Modifikim</span>
        </div>

        <h1 class="km-page-title">
          <i class="bi bi-pencil-square me-2 text-primary"></i>
          Modifiko kursin
        </h1>

        <p class="km-page-subtitle mb-0">
          Përditëso titullin, përshkrimin, statusin, kategorinë, linkun e Aula Virtuale dhe foton e kursit.
        </p>
      </div>

      <div class="d-flex flex-column align-items-md-end gap-2">
        <div class="km-pill-meta">
          <i class="bi bi-toggle-on me-1"></i>Status: <?= h($status) ?>
        </div>
        <div class="km-help-text">
          Krijuar nga <strong><?= h($creatorName) ?></strong><?= $createdAt ? ' • '.$createdAt : '' ?>
        </div>
      </div>
    </header>

    <!-- Alerts -->
    <?php if ($errors): ?>
      <div class="alert alert-danger mt-3">
        <div class="d-flex align-items-start gap-2">
          <i class="bi bi-exclamation-circle fs-4"></i>
          <div>
            <div class="fw-bold mb-1">Gabime në formular</div>
            <ul class="mb-0">
              <?php foreach ($errors as $er): ?><li><?= h($er) ?></li><?php endforeach; ?>
            </ul>
          </div>
        </div>
      </div>
    <?php endif; ?>

    <?php if ($flash): ?>
      <div class="alert <?= ($flash['type']??'')==='success' ? 'alert-success' : 'alert-danger' ?> mt-3 d-flex align-items-center gap-2">
        <i class="bi <?= ($flash['type']??'')==='success' ? 'bi-check-circle' : 'bi-exclamation-triangle' ?>"></i>
        <div><?= h($flash['msg'] ?? '') ?></div>
      </div>
    <?php endif; ?>

    <!-- FORM -->
    <form method="POST" enctype="multipart/form-data" class="row g-4 km-form-grid">
      <input type="hidden" name="csrf" value="<?= h($csrf) ?>">

      <!-- LEFT: Detajet -->
      <div class="col-12 col-lg-8">
        <section class="km-card km-card-main">
          <div class="km-card-header">
            <div>
              <h2 class="km-card-title">
                <span class="km-step-badge">1</span>
                Detajet e kursit
              </h2>
              <p class="km-card-subtitle mb-0">
                Titulli, përshkrimi, statusi, kategoria dhe linku i Aula Virtuale.
              </p>
            </div>
          </div>

          <div class="km-card-body">
            <!-- Titulli -->
            <div class="mb-3">
              <label class="form-label">Titulli i Kursit</label>
              <div class="input-group">
                <span class="input-group-text bg-white"><i class="bi bi-card-heading text-primary"></i></span>
                <input class="form-control" name="title" required maxlength="200" value="<?= h($title) ?>"
                       placeholder="p.sh. Programim në PHP (Nivel Fillestar)">
              </div>
            </div>

            <!-- Përshkrimi (Markdown) -->
            <div class="mb-3">
              <label class="form-label">Përshkrimi i Kursit (Markdown)</label>
              <textarea id="description" name="description"><?= h($description) ?></textarea>
              <div class="km-help-text mt-1">
                Mbështet Markdown. Do të shfaqet i renderuar te faqet publike.
              </div>
            </div>

            <div class="row g-3">
              <!-- Statusi -->
              <div class="col-md-4">
                <label class="form-label">Statusi</label>
                <select name="status" class="form-select" required>
                  <?php foreach ($allowedStatus as $s): ?>
                    <option value="<?= h($s) ?>" <?= $status===$s?'selected':'' ?>><?= h($s) ?></option>
                  <?php endforeach; ?>
                </select>
              </div>

              <!-- Kategoria -->
              <div class="col-md-4">
                <label class="form-label">Kategoria</label>
                <select name="category" class="form-select" required>
                  <?php foreach ($allowedCategories as $cat): ?>
                    <option value="<?= h($cat) ?>" <?= $category===$cat?'selected':'' ?>>
                      <?= h(ucfirst(strtolower($cat))) ?>
                    </option>
                  <?php endforeach; ?>
                </select>
              </div>

              <!-- Aula Virtuale -->
              <div class="col-md-4">
                <label class="form-label">Link Teams (Aula Virtuale)</label>
                <input type="url" name="aula_virtuale" class="form-control" value="<?= h($aula_virtuale) ?>"
                       placeholder="https://teams.microsoft.com/...">
              </div>
            </div>
          </div>
        </section>
      </div>

      <!-- RIGHT: Foto + veprime -->
      <div class="col-12 col-lg-4">
        <!-- Foto e kursit -->
        <aside class="km-card km-card-side mb-3">
          <div class="km-card-body">
            <h3 class="km-side-title mb-2">
              <i class="bi bi-image me-2"></i>Foto e kursit
            </h3>

            <div class="km-dropzone mb-2" id="fileDrop">
              <input type="file" id="image" name="image" class="d-none" accept="image/*">
              <div id="uploadInner">
                <i class="bi bi-cloud-arrow-up fs-2 text-muted d-block mb-2"></i>
                <div class="fw-semibold mb-1">Kliko për të ngarkuar foton</div>
                <div class="km-help-text">ose tërhiqe & lësho këtu (max 5MB)</div>
              </div>
            </div>

            <div class="km-preview" id="previewWrap">
              <?php if (!empty($image)): ?>
                <img src="../uploads/courses/<?= h($image) ?>" alt="Foto e Kursit" id="imgPreview" style="width:100%;height:auto;display:block;">
              <?php else: ?>
                <img src="https://placehold.co/600x350?text=Pa+foto" alt="Pa foto" id="imgPreview" style="width:100%;height:auto;display:block;">
              <?php endif; ?>
            </div>

            <?php if (!empty($image)): ?>
              <div class="form-check mt-2">
                <input class="form-check-input" type="checkbox" id="remove_image" name="remove_image" value="1">
                <label class="form-check-label" for="remove_image">Hiq foton aktuale</label>
              </div>
            <?php endif; ?>

            <div class="km-help-text mt-2">
              Formate të lejuara: JPG, PNG, GIF. Maksimumi 5MB.
            </div>
          </div>
        </aside>

        <!-- Ruaj -->
        <aside class="km-card km-card-side km-sticky-side">
          <div class="km-card-body">
            <h3 class="km-side-title mb-2">
              <i class="bi bi-save2 me-2 text-success"></i>Ruaj ndryshimet
            </h3>

            <p class="km-help-text mb-3">
              Kontrollo titullin, kategorinë, statusin dhe foton. Pastaj ruaj.
            </p>

            <ul class="km-checklist mb-3">
              <li><i class="bi bi-check2-square me-1"></i>Titull i plotë</li>
              <li><i class="bi bi-check2-square me-1"></i>Kategori & status korrekt</li>
              <li><i class="bi bi-check2-square me-1"></i>Përshkrim i përditësuar</li>
              <li><i class="bi bi-check2-square me-1"></i>Foto (opsionale)</li>
            </ul>

            <div class="d-grid gap-2">
              <button class="btn btn-primary km-btn-pill" type="submit">
                <i class="bi bi-save me-1"></i>Ruaj ndryshimet
              </button>
              <a class="btn btn-outline-secondary km-btn-pill" href="../course_details.php?course_id=<?= (int)$course_id ?>">
                <i class="bi bi-arrow-left me-1"></i>Kthehu te kursi
              </a>
            </div>
          </div>
        </aside>
      </div>
    </form>

  </div>
</div>

<?php
  // Footer zakonisht jashtë /admin
  if (file_exists(__DIR__.'/../footer.php')) { include __DIR__.'/../footer.php'; }
?>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script src="https://cdn.jsdelivr.net/simplemde/latest/simplemde.min.js"></script>
<script>
// SimpleMDE
const mde = new SimpleMDE({
  element: document.getElementById('description'),
  spellChecker: false,
  placeholder: 'Shkruani përshkrimin e kursit këtu…',
  toolbar: ['bold','italic','heading','|','code','quote','unordered-list','ordered-list','|','link','image','table','|','preview','guide']
});

// Drag & Drop foto
const drop  = document.getElementById('fileDrop');
const input = document.getElementById('image');
const inner = document.getElementById('uploadInner');
const prev  = document.getElementById('imgPreview');

function setPickedUI(file){
  inner.innerHTML = `
    <i class="bi bi-image fs-2 text-success d-block mb-2"></i>
    <div class="fw-semibold">Foto e zgjedhur</div>
    <div class="km-help-text">${file.name}</div>
  `;
}

drop?.addEventListener('click', ()=> input?.click());

input?.addEventListener('change', ()=> {
  if (input.files && input.files[0]) {
    const f = input.files[0];
    setPickedUI(f);
    const url = URL.createObjectURL(f);
    prev.src = url;
  }
});

['dragenter','dragover'].forEach(ev => drop?.addEventListener(ev, e=>{
  e.preventDefault(); drop.classList.add('drag');
}));
['dragleave','drop'].forEach(ev => drop?.addEventListener(ev, e=>{
  e.preventDefault(); drop.classList.remove('drag');
}));
drop?.addEventListener('drop', e=>{
  if (e.dataTransfer?.files?.length) {
    input.files = e.dataTransfer.files;
    input.dispatchEvent(new Event('change'));
  }
});
</script>
</body>
</html>
