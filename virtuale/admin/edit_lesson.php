<?php
// admin/edit_lesson.php — Modifiko ELEMENT (LEKSION/VIDEO/LINK/FILE/REFERENCA/LAB/TJETER)
// Dizajn si add_lesson.php + block-editor (Normal/Markdown) për përshkrimin
// ✅ IMG block: upload (type=file) → ruan imazhin në /uploads/lessons/images/ dhe e fut në Markdown si ![alt](url)
// ✅ FIX: përditëson section_items që outline të mos prishet
declare(strict_types=1);

if (session_status() === PHP_SESSION_NONE) { session_start(); }
require_once __DIR__ . '/../lib/database.php';

/* ------------------------------- Helpers ------------------------------- */
function h(?string $s): string {
  return htmlspecialchars($s ?? '', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}
function ensure_dir(string $dir): void {
  if (!is_dir($dir)) { @mkdir($dir, 0775, true); }
}
function detect_mime(string $tmpPath): string {
  $finfo = new finfo(FILEINFO_MIME_TYPE);
  return (string)($finfo->file($tmpPath) ?: '');
}

/** Attachment validator (si te add_lesson.php) */
function validate_attachment_upload(array $f, int $maxBytes = 15728640): array {
  if (($f['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
    return [false, 'Skedari nuk u ngarkua (error).', null, null];
  }
  if (!is_uploaded_file($f['tmp_name'] ?? '')) {
    return [false, 'Skedari nuk është valid (upload).', null, null];
  }
  $size = (int)($f['size'] ?? 0);
  if ($size <= 0 || $size > $maxBytes) {
    return [false, 'Skedari është shumë i madh (max 15MB).', null, null];
  }

  $name = (string)($f['name'] ?? '');
  $ext  = strtolower(pathinfo($name, PATHINFO_EXTENSION));
  $mime = detect_mime((string)$f['tmp_name']); // informative

  $allowedExt = [
    'pdf','doc','docx','ppt','pptx','xls','xlsx','csv','txt','md',
    'zip','rar','7z',
    'mp4','mov','avi',
    'jpg','jpeg','png','gif','webp',
    'mp3','wav'
  ];
  if ($ext === '' || !in_array($ext, $allowedExt, true)) {
    return [false, 'Tip skedari i palejuar.', null, null];
  }

  $map = [
    'pdf'  => 'PDF',
    'ppt'  => 'SLIDES', 'pptx' => 'SLIDES',
    'mp4'  => 'VIDEO',  'avi'  => 'VIDEO', 'mov' => 'VIDEO'
  ];
  $fileType = strtoupper($map[$ext] ?? 'DOC');

  // (Opsionale) Këtu mund të shtosh validim mime më strikt sipas nevojës
  return [true, '', $ext, $fileType];
}

/* -------------------------------- Auth --------------------------------- */
if (!isset($_SESSION['user']) || !in_array($_SESSION['user']['role'] ?? '', ['Administrator','Instruktor'], true)) {
  header('Location: ../login.php');
  exit;
}
$ROLE  = (string)($_SESSION['user']['role'] ?? '');
$ME_ID = (int)($_SESSION['user']['id'] ?? 0);

/* ------------------------------ CSRF token ----------------------------- */
if (empty($_SESSION['csrf_token'])) {
  $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$CSRF = (string)$_SESSION['csrf_token'];

/* ----------------------------- lesson_id ------------------------------- */
if (!isset($_GET['lesson_id']) || !is_numeric($_GET['lesson_id'])) {
  $_SESSION['flash'] = ['msg'=>'Leksioni nuk është specifikuar.', 'type'=>'danger'];
  header('Location: ../course.php'); exit;
}
$lesson_id = (int)$_GET['lesson_id'];

/* -------------------- Upload dirs (si add_lesson.php) ------------------ */
/** Root i projektit (një nivel sipër /admin) */
$BASE_ABS = realpath(__DIR__ . '/..') ?: dirname(__DIR__);

// attachments (lesson files)
$LESSON_FILES_ABS = $BASE_ABS . '/uploads/lessons';
$LESSON_FILES_REL = 'uploads/lessons/';
ensure_dir($LESSON_FILES_ABS);

// images nga block-editor
$LESSON_IMG_ABS = $BASE_ABS . '/uploads/lessons/images';
$LESSON_IMG_REL = 'uploads/lessons/images/';
ensure_dir($LESSON_IMG_ABS);

$MAX_IMG_BYTES = 5 * 1024 * 1024; // 5MB për imazhet (ndrysho si do)

/* ----------------------- Lexo leksionin + kursin ---------------------- */
try {
  $stmt = $pdo->prepare("
    SELECT 
      l.*,
      c.id         AS course_id,
      c.title      AS course_title,
      c.id_creator AS creator_id
    FROM lessons l
    LEFT JOIN courses c ON c.id = l.course_id
    WHERE l.id = ?
    LIMIT 1
  ");
  $stmt->execute([$lesson_id]);
  $lesson = $stmt->fetch(PDO::FETCH_ASSOC);
  if (!$lesson) {
    $_SESSION['flash'] = ['msg'=>'Leksioni nuk u gjet.', 'type'=>'danger'];
    header('Location: ../course.php'); exit;
  }

  if ($ROLE === 'Instruktor' && (int)($lesson['creator_id'] ?? 0) !== $ME_ID) {
    $_SESSION['flash'] = ['msg'=>'Nuk keni akses në këtë leksion.', 'type'=>'danger'];
    header('Location: ../course.php'); exit;
  }
} catch (PDOException $e) {
  $_SESSION['flash'] = ['msg'=>'Gabim: ' . h($e->getMessage()), 'type'=>'danger'];
  header('Location: ../course.php'); exit;
}

$course_id        = (int)($lesson['course_id'] ?? 0);
$course_title     = (string)($lesson['course_title'] ?? '');
$title            = (string)($lesson['title'] ?? '');
$selectedCategory = strtoupper((string)($lesson['category'] ?? 'LEKSION'));
$url              = (string)($lesson['URL'] ?? '');
$description      = (string)($lesson['description'] ?? '');
$section_id       = isset($lesson['section_id']) ? (int)$lesson['section_id'] : 0;
$errors = [];

/* ----------------------- Seksionet e kursit --------------------------- */
$sections = [];
try {
  $stmtSec = $pdo->prepare("
    SELECT id, title 
    FROM sections 
    WHERE course_id = ? 
    ORDER BY position ASC, id ASC
  ");
  $stmtSec->execute([$course_id]);
  $sections = $stmtSec->fetchAll(PDO::FETCH_ASSOC) ?: [];
} catch (PDOException $e) { $sections = []; }

$sectionsById = [];
foreach ($sections as $s) { $sectionsById[(int)$s['id']] = $s; }

/* ----------------------- Skedarët ekzistues --------------------------- */
$lessonFiles = [];
try {
  $stmtFiles = $pdo->prepare("
    SELECT * 
    FROM lesson_files 
    WHERE lesson_id = ? 
    ORDER BY uploaded_at DESC, id DESC
  ");
  $stmtFiles->execute([$lesson_id]);
  $lessonFiles = $stmtFiles->fetchAll(PDO::FETCH_ASSOC) ?: [];
} catch (PDOException $e) { $lessonFiles = []; }

/* --------------------------------- POST -------------------------------- */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  if (!isset($_POST['csrf']) || !hash_equals($CSRF, (string)$_POST['csrf'])) {
    $errors[] = 'Seancë e pavlefshme (CSRF). Ringarko faqen.';
  }

  $title            = trim((string)($_POST['title'] ?? ''));
  $description      = (string)($_POST['description'] ?? '');
  $url              = trim((string)($_POST['url'] ?? ''));
  $section_post     = (int)($_POST['section_id'] ?? 0);
  $selectedCategory = strtoupper(trim((string)($_POST['category'] ?? $selectedCategory)));
  $cat              = $selectedCategory;

  if ($title === '') $errors[] = 'Titulli është i detyrueshëm.';
  if ($selectedCategory === '') $errors[] = 'Zgjedhja e kategorisë është e detyrueshme.';

  if (in_array($cat, ['VIDEO','LINK'], true)) {
    if ($url === '' || !filter_var($url, FILTER_VALIDATE_URL)) {
      $errors[] = 'URL e vlefshme është e detyrueshme për VIDEO/LINK.';
    }
  } elseif ($cat === 'LEKSION') {
    if (trim($description) === '') {
      $errors[] = 'Përshkrimi (përmbajtja) është i detyrueshëm për LEKSION.';
    }
  }
  // FILE: në edit skedari i ri është opsional (mund të ketë ekzistues)

  if ($section_post > 0 && !isset($sectionsById[$section_post])) {
    $errors[] = 'Seksioni i zgjedhur nuk i përket këtij kursi.';
  }

  $section_id = $section_post;

  /* --------------------- Upload i ri (opsional) — lesson_file --------- */
  $uploadedFileRel  = null;
  $uploadedFileType = null;

  $hasUpload = isset($_FILES['lesson_file'])
    && ($_FILES['lesson_file']['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_OK
    && is_uploaded_file($_FILES['lesson_file']['tmp_name'] ?? '');

  if (in_array($cat, ['FILE','LEKSION','LAB'], true) && empty($errors) && $hasUpload) {
    $f = $_FILES['lesson_file'];
    [$ok, $msg, $ext, $ftype] = validate_attachment_upload($f, 15 * 1024 * 1024);
    if (!$ok) {
      $errors[] = $msg;
    } else {
      $safeName = preg_replace('/[^A-Za-z0-9._-]/', '_', (string)$f['name']);
      $newBase  = time() . '_' . bin2hex(random_bytes(4)) . '_' . $safeName;

      $abs = $LESSON_FILES_ABS . '/' . $newBase;
      $rel = $LESSON_FILES_REL . $newBase;

      if (!move_uploaded_file((string)$f['tmp_name'], $abs)) {
        $errors[] = 'S’u ruajt dot skedari.';
      } else {
        $uploadedFileRel  = $rel;
        $uploadedFileType = $ftype ?: 'DOC';
      }
    }
  }

  /* ---------------- Upload images nga block-editor (IMG blocks) -------- */
  // Input-et janë: name="img_files[<KEY>]"
  if (empty($errors) && !empty($_FILES['img_files']) && is_array($_FILES['img_files']['name'] ?? null)) {

    $imgNames = $_FILES['img_files']['name'];
    foreach ($imgNames as $key => $name) {
      if ($name === '' || $name === null) continue;

      $tmp  = $_FILES['img_files']['tmp_name'][$key] ?? '';
      $err  = (int)($_FILES['img_files']['error'][$key] ?? UPLOAD_ERR_NO_FILE);
      $size = (int)($_FILES['img_files']['size'][$key] ?? 0);

      if ($err !== UPLOAD_ERR_OK || !is_uploaded_file($tmp)) {
        $errors[] = "Ngarkimi i fotos dështoi ({$key}).";
        continue;
      }
      if ($size <= 0 || $size > $MAX_IMG_BYTES) {
        $errors[] = "Foto shumë e madhe ({$key}). Max " . (int)($MAX_IMG_BYTES / 1024 / 1024) . "MB.";
        continue;
      }

      $ext = strtolower(pathinfo((string)$name, PATHINFO_EXTENSION));
      $allowedImgExt = ['jpg','jpeg','png','gif','webp'];
      if ($ext === '' || !in_array($ext, $allowedImgExt, true)) {
        $errors[] = "Tip foto i palejuar ({$key}).";
        continue;
      }

      // (Opsionale) mime check
      $mime = detect_mime((string)$tmp);
      $allowedImgMime = ['image/jpeg','image/png','image/gif','image/webp'];
      if ($mime !== '' && !in_array($mime, $allowedImgMime, true)) {
        $errors[] = "MIME foto i palejuar ({$key}).";
        continue;
      }

      $safeName = preg_replace('/[^A-Za-z0-9._-]/', '_', (string)$name);
      $newBase  = time() . '_' . bin2hex(random_bytes(4)) . '_' . $safeName;

      $abs = $LESSON_IMG_ABS . '/' . $newBase;
      $rel = $LESSON_IMG_REL . $newBase;

      if (!move_uploaded_file((string)$tmp, $abs)) {
        $errors[] = "S’u ruajt dot fotoja ({$key}).";
        continue;
      }

      // Zëvendëso placeholder-in në Markdown me path-in real
      $placeholder = '__IMG_' . (string)$key . '__';
      $publicPath  = '/' . ltrim($rel, '/'); // absolute nga root
      $description = str_replace($placeholder, $publicPath, $description);
    }
  }

  /* ------------------------------ UPDATE DB ----------------------------- */
  if (!$errors) {
    try {
      $pdo->beginTransaction();

      $stmtU = $pdo->prepare("
        UPDATE lessons
           SET title      = ?,
               description= ?,
               URL        = ?,
               category   = ?,
               section_id = ?,
               updated_at = NOW()
         WHERE id = ?
      ");
      $stmtU->execute([
        $title,
        $description,
        $url !== '' ? $url : null,
        $cat,
        $section_id > 0 ? $section_id : null,
        $lesson_id
      ]);

      if ($uploadedFileRel) {
        $stmtF = $pdo->prepare("
          INSERT INTO lesson_files (lesson_id, file_path, file_type)
          VALUES (?,?,?)
        ");
        $stmtF->execute([$lesson_id, $uploadedFileRel, $uploadedFileType ?: 'DOC']);
      }

      /* ✅ FIX: përditëso section_items që outline të mos prishet */
      $sidNew = $section_id ?: 0;

      $stmtSI = $pdo->prepare("
        SELECT id, section_id
        FROM section_items
        WHERE course_id = ? AND item_type = 'LESSON' AND item_ref_id = ?
        LIMIT 1
      ");
      $stmtSI->execute([$course_id, $lesson_id]);
      $siRow = $stmtSI->fetch(PDO::FETCH_ASSOC);

      // gjej position në fund të seksionit të ri
      $stmtPos = $pdo->prepare("
        SELECT COALESCE(MAX(position), 0) + 1
        FROM section_items
        WHERE course_id = ? AND section_id = ?
      ");
      $stmtPos->execute([$course_id, $sidNew]);
      $nextPos = (int)$stmtPos->fetchColumn();

      if ($siRow) {
        $siId = (int)$siRow['id'];
        $sidOldSI = (int)($siRow['section_id'] ?? 0);

        if ($sidOldSI !== $sidNew) {
          $stmtUpdSI = $pdo->prepare("
            UPDATE section_items
               SET section_id = ?, position = ?
             WHERE id = ?
          ");
          $stmtUpdSI->execute([$sidNew, $nextPos, $siId]);
        }
      } else {
        $stmtInsSI = $pdo->prepare("
          INSERT INTO section_items (course_id, section_id, item_type, item_ref_id, position)
          VALUES (?,?,?,?,?)
        ");
        $stmtInsSI->execute([$course_id, $sidNew, 'LESSON', $lesson_id, $nextPos]);
      }

      $pdo->commit();
      header('Location: ../lesson_details.php?lesson_id=' . (int)$lesson_id);
      exit;

    } catch (Throwable $e) {
      if ($pdo->inTransaction()) $pdo->rollBack();
      $errors[] = 'Gabim gjatë përditësimit: ' . h($e->getMessage());
    }
  }
}
?>
<!DOCTYPE html>
<html lang="sq" data-bs-theme="light">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Modifiko element — kurseinformatike.com</title>

  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
  <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css" rel="stylesheet">
  <link rel="stylesheet" href="../css/km-lms-forms.css">
  <link rel="icon" href="/image/favicon.ico" type="image/x-icon" />

  <style>
    /* small add-on për file picker clean */
    .km-file-picker{display:flex;align-items:center;gap:.6rem;padding:.55rem .6rem;border:1px solid rgba(229,231,235,.95);border-radius:999px;background:#fff}
    .km-file-name{color:#475569;font-weight:800;font-size:.9rem;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;max-width:280px}

    /* img block helper */
    .km-img-picked{color:#0f172a;font-weight:700}
  </style>
</head>
<body class="km-body">

<?php
  if ($ROLE === 'Administrator') {
    include __DIR__ . '/../navbar_logged_administrator.php';
  } else {
    include __DIR__ . '/../navbar_logged_instruktor.php';
  }
?>

<div class="km-page-shell">
  <div class="container">

    <header class="km-page-header d-flex flex-column flex-md-row justify-content-between align-items-start align-items-md-center gap-3">
      <div>
        <div class="km-breadcrumb small mb-1">
          <a href="../courses.php" class="km-breadcrumb-link">
            <i class="fa-solid fa-layer-group me-1"></i>Kursët
          </a>
          <span class="mx-1">/</span>
          <a href="../course_details.php?course_id=<?= (int)$course_id ?>&tab=materials" class="km-breadcrumb-link">
            <?= h($course_title) ?>
          </a>
          <span class="mx-1">/</span>
          <span class="km-breadcrumb-current">Modifiko element</span>
        </div>

        <h1 class="km-page-title">
          <i class="fa-solid fa-pen-to-square me-2 text-primary"></i>
          Modifiko elementin e kursit
        </h1>
        <p class="km-page-subtitle mb-0">
          Përditëso titullin, kategorinë, përshkrimin, URL-në ose skedarët e lidhur me këtë element.
        </p>
      </div>

      <div class="d-flex flex-column align-items-md-end gap-2">
        <a href="../lesson_details.php?lesson_id=<?= (int)$lesson_id ?>"
           class="btn btn-outline-secondary btn-sm km-btn-pill">
          <i class="fa-regular fa-eye me-1"></i>
          Shiko elementin
        </a>
        <a href="../course_details.php?course_id=<?= (int)$course_id ?>&tab=materials"
           class="btn btn-outline-secondary btn-sm km-btn-pill">
          <i class="fa-solid fa-arrow-left-long me-1"></i>
          Kthehu te kursi
        </a>
      </div>
    </header>

    <?php if (!empty($errors)): ?>
      <div class="km-alert km-alert-danger mb-3">
        <div class="d-flex align-items-start gap-2">
          <i class="fa-solid fa-triangle-exclamation mt-1"></i>
          <div>
            <div class="fw-semibold mb-1">Gabime gjatë ruajtjes:</div>
            <ul class="mb-0">
              <?php foreach ($errors as $e): ?>
                <li><?= h((string)$e) ?></li>
              <?php endforeach; ?>
            </ul>
          </div>
        </div>
      </div>
    <?php endif; ?>

    <form
      method="POST"
      enctype="multipart/form-data"
      class="row g-4 km-form-grid"
      action="<?= h($_SERVER['PHP_SELF']) . '?lesson_id=' . (int)$lesson_id ?>"
    >
      <input type="hidden" name="csrf" value="<?= h($CSRF) ?>">

      <div class="col-12 col-lg-8">
        <section class="km-card km-card-main mb-3">
          <div class="km-card-header">
            <div>
              <h2 class="km-card-title">
                <span class="km-step-badge">1</span>
                Detajet e elementit
              </h2>
              <p class="km-card-subtitle mb-0">
                Modifiko titullin, kategorinë, seksionin dhe përshkrimin.
              </p>
            </div>
          </div>

          <div class="km-card-body">
            <div class="mb-3">
              <label class="form-label">Titulli</label>
              <input
                type="text"
                name="title"
                class="form-control"
                required
                placeholder="p.sh. OOP në PHP"
                value="<?= h($title) ?>"
              >
            </div>

            <div class="row g-3">
              <div class="col-md-6">
                <label class="form-label">Kategoria</label>
                <select class="form-select" id="category" name="category" required>
                  <option value="">Zgjidh...</option>
                  <?php
                    $cats = ['LEKSION','VIDEO','LINK','FILE','REFERENCA','LAB','TJETER'];
                    foreach ($cats as $c) {
                      $sel = ($selectedCategory === $c) ? 'selected' : '';
                      echo '<option value="' . h($c) . '" ' . $sel . '>' . h($c) . '</option>';
                    }
                  ?>
                </select>
                <div class="km-help-text mt-1">
                  LEKSION → përmbajtje + link + file; VIDEO/LINK/FILE → forma më të fokusuara.
                </div>
              </div>

              <div class="col-md-6">
                <label class="form-label">Seksioni (opsional)</label>
                <select class="form-select" name="section_id">
                  <option value="0">— Jashtë seksioneve —</option>
                  <?php foreach ($sections as $s): ?>
                    <option value="<?= (int)$s['id'] ?>" <?= $section_id === (int)$s['id'] ? 'selected' : '' ?>>
                      <?= h((string)$s['title']) ?>
                    </option>
                  <?php endforeach; ?>
                </select>
                <div class="km-help-text mt-1">
                  Zgjidh modulën ku ky element do të shfaqet në outline.
                </div>
              </div>
            </div>

            <div class="mt-3 d-none" id="urlWrap">
              <label class="form-label" id="urlLabel">URL (për VIDEO / LINK)</label>
              <input
                type="url"
                class="form-control"
                id="url"
                name="url"
                placeholder="https://..."
                value="<?= h($url) ?>"
              >
              <div class="km-help-text mt-1" id="urlHelp">
                Vendos URL-në e videos ose linkun e burimit të jashtëm.
              </div>
            </div>

            <div class="mt-3" id="descWrap">
              <label class="form-label">Përshkrimi i elementit</label>

              <div id="km-block-editor" class="km-block-editor">
                <div class="km-editor-header d-flex flex-wrap justify-content-between align-items-center mb-2">
                  <div class="km-block-toolbar">
                    <button type="button" class="btn btn-outline-secondary btn-sm km-btn-pill km-add-block" data-type="p">
                      <i class="fa-regular fa-paragraph me-1"></i> Paragraf
                    </button>
                    <button type="button" class="btn btn-outline-secondary btn-sm km-btn-pill km-add-block" data-type="h2">
                      <i class="fa-solid fa-heading me-1"></i> Titull
                    </button>
                    <button type="button" class="btn btn-outline-secondary btn-sm km-btn-pill km-add-block" data-type="img">
                      <i class="fa-regular fa-image me-1"></i> Foto
                    </button>
                    <button type="button" class="btn btn-outline-secondary btn-sm km-btn-pill km-add-block" data-type="code">
                      <i class="fa-solid fa-code me-1"></i> Kod
                    </button>
                  </div>

                  <div class="km-editor-view-toggle">
                    <button type="button" class="btn km-editor-tab active" data-view="normal">
                      <i class="fa-regular fa-rectangle-list me-1"></i> Normal View
                    </button>
                    <button type="button" class="btn km-editor-tab" data-view="markdown">
                      <i class="fa-solid fa-code me-1"></i> Markdown View
                    </button>
                  </div>
                </div>

                <div id="km-block-list" class="km-block-list"></div>

                <textarea id="km-markdown-editor"
                          class="form-control d-none mt-2"
                          rows="8"
                          placeholder="Markdown: ## tituj, ```code```, ![alt](url) ..."><?= h($description) ?></textarea>

                <div class="km-help-text mt-2">
                  Shto blloqe ose përdor Markdown View. Për <strong>LEKSION</strong> përshkrimi është i detyrueshëm.
                </div>
              </div>

              <!-- Markdown final që shkon në DB -->
              <textarea id="description" name="description" class="d-none"><?= h($description) ?></textarea>
              <input type="hidden" id="blocks_json" name="blocks_json" value="">
            </div>
          </div>
        </section>
      </div>

      <div class="col-12 col-lg-4">

        <aside id="fileCard" class="km-card km-card-side km-sticky-side d-none">
          <div class="km-card-header">
            <div>
              <h2 class="km-card-title">
                <span class="km-step-badge">2</span>
                Skedari i bashkangjitur
              </h2>
              <p class="km-card-subtitle mb-0">
                Ngarko ose menaxho skedarët e lidhur me këtë element.
              </p>
            </div>
          </div>

          <div class="km-card-body">
            <label class="form-label">Skedar i ri (opsional)</label>

            <!-- input real i fshehur -->
            <input class="d-none" type="file" id="lesson_file" name="lesson_file">

            <!-- UI clean -->
            <div class="km-file-picker">
              <button type="button" class="btn btn-outline-secondary btn-sm km-btn-pill" id="kmPickLessonFile">
                <i class="fa-solid fa-paperclip me-1"></i> Zgjidh skedarin
              </button>
              <span class="km-file-name" id="kmLessonFileName">Asnjë skedar i zgjedhur</span>
            </div>

            <div class="km-help-text mt-2">
              Maksimumi 15MB. Për FILE është material kryesor; për LEKSION/LAB material shtesë.
            </div>

            <?php if (!empty($lessonFiles)): ?>
              <hr>
              <div class="km-help-text mb-2">Skedarët ekzistues:</div>

              <div class="km-file-list">
                <?php foreach ($lessonFiles as $f): ?>
                  <?php
                    $fp = (string)($f['file_path'] ?? '');
                    $href = ($fp !== '' && $fp[0] === '/') ? $fp : '/' . ltrim($fp, '/');
                  ?>
                  <div class="d-flex align-items-center justify-content-between gap-2 km-file-item mb-2">
                    <div class="text-truncate">
                      <i class="fa-regular fa-file me-2"></i>
                      <?= h(basename($fp)) ?>
                      <?php if (!empty($f['file_type'])): ?>
                        <small class="text-muted"> • <?= h((string)$f['file_type']) ?></small>
                      <?php endif; ?>
                    </div>
                    <div class="d-flex gap-1">
                      <a class="btn btn-outline-secondary btn-sm km-btn-pill"
                         href="<?= h($href) ?>" target="_blank" rel="noopener">
                        <i class="fa-solid fa-up-right-from-square"></i>
                      </a>
                      <a class="btn btn-outline-danger btn-sm km-btn-pill"
                         href="delete_lesson_file.php?file_id=<?= (int)$f['id'] ?>&lesson_id=<?= (int)$lesson_id ?>"
                         onclick="return confirm('A jeni i sigurt që dëshironi ta fshini këtë dokument?');">
                        <i class="fa-regular fa-trash-can"></i>
                      </a>
                    </div>
                  </div>
                <?php endforeach; ?>
              </div>
            <?php endif; ?>
          </div>
        </aside>

        <div class="d-grid gap-2 mt-3">
          <button class="btn btn-primary km-btn-pill" type="submit">
            <i class="fa-regular fa-floppy-disk me-1"></i>
            Ruaj ndryshimet
          </button>
          <a href="../course_details.php?course_id=<?= (int)$course_id ?>&tab=materials"
             class="btn btn-outline-secondary km-btn-pill">
            <i class="fa-solid fa-arrow-left-long me-1"></i>
            Kthehu te kursi
          </a>
        </div>

      </div>
    </form>

  </div>
</div>

<?php include __DIR__ . '/../footer.php'; ?>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script>
  const blockList        = document.getElementById('km-block-list');
  const mdEditor         = document.getElementById('km-markdown-editor');
  const blocksJsonInput  = document.getElementById('blocks_json');
  let   currentView      = 'normal';
  let   viewTabs         = [];

  /* ------------------------ Block editor JS -------------------------- */
  function createBlock(type) {
    const block = document.createElement('div');
    block.className = 'km-block';
    block.setAttribute('data-type', type);

    let labelIcon = '', labelText = '';
    if (type === 'p')   { labelIcon = 'fa-regular fa-paragraph'; labelText = 'Paragraf'; }
    if (type === 'h2')  { labelIcon = 'fa-solid fa-heading';     labelText = 'Titull seksioni'; }
    if (type === 'code'){ labelIcon = 'fa-solid fa-code';        labelText = 'Bllok kodi'; }
    if (type === 'img') { labelIcon = 'fa-regular fa-image';     labelText = 'Foto'; }

    block.innerHTML = `
      <div class="km-block-header">
        <span class="km-block-label"><i class="${labelIcon}"></i> ${labelText}</span>
        <div class="km-block-actions">
          <button type="button" class="btn btn-link btn-sm km-block-move-up">Lart</button>
          <button type="button" class="btn btn-link btn-sm km-block-move-down">Poshtë</button>
          <button type="button" class="btn btn-link btn-sm text-danger km-block-remove">Hiq</button>
        </div>
      </div>
      <div class="km-block-body"></div>
    `;

    const body = block.querySelector('.km-block-body');

    if (type === 'p' || type === 'h2') {
      body.innerHTML = `
        <textarea class="form-control km-block-text"
                  rows="${type === 'p' ? 3 : 2}"
                  placeholder="${type === 'p' ? 'Shkruaj paragraf...' : 'Titulli i seksionit...'}"></textarea>
      `;
    } else if (type === 'code') {
      body.innerHTML = `
        <div class="mb-2">
          <label class="form-label small mb-1">Gjuha (opsionale)</label>
          <input type="text" class="form-control form-control-sm km-block-lang" placeholder="p.sh. php, js, python">
        </div>
        <div>
          <label class="form-label small mb-1">Kodi</label>
          <textarea class="form-control km-block-code" rows="4" placeholder="Shkruaj kodin..."></textarea>
        </div>
      `;
    } else if (type === 'img') {
      // key unik për këtë bllok (përdoret në $_FILES['img_files'][key])
      const key = 'img_' + Date.now().toString(36) + '_' + Math.random().toString(16).slice(2);
      block.setAttribute('data-img-key', key);

      body.innerHTML = `
        <div class="mb-2">
          <label class="form-label small mb-1">Titulli / alt</label>
          <input type="text" class="form-control form-control-sm km-block-alt" placeholder="p.sh. Diagramë e algoritmit">
        </div>

        <div class="mb-2">
          <label class="form-label small mb-1">Ngarko imazhin</label>
          <input type="file"
                 class="form-control form-control-sm km-block-imgfile"
                 name="img_files[${key}]"
                 accept="image/*">
          <div class="form-text">JPG/PNG/GIF/WEBP – max 5MB</div>
          <div class="small mt-1 km-img-picked km-block-img-picked d-none"></div>
        </div>

        <!-- ruan src aktual (kur vjen nga Markdown) -->
        <input type="hidden" class="km-block-src" value="">
        <div class="small text-muted km-block-img-current d-none"></div>
      `;
    }

    return block;
  }

  document.querySelectorAll('.km-add-block').forEach(btn => {
    btn.addEventListener('click', () => {
      const type = btn.getAttribute('data-type') || 'p';
      blockList.appendChild(createBlock(type));
    });
  });

  // Delegation: veprime + ndryshim file në img block
  if (blockList) {
    blockList.addEventListener('click', (e) => {
      const target = e.target;
      if (!(target instanceof HTMLElement)) return;
      const block = target.closest('.km-block');
      if (!block) return;

      if (target.classList.contains('km-block-remove')) {
        block.remove();
      } else if (target.classList.contains('km-block-move-up')) {
        const prev = block.previousElementSibling;
        if (prev) blockList.insertBefore(block, prev);
      } else if (target.classList.contains('km-block-move-down')) {
        const next = block.nextElementSibling;
        if (next) blockList.insertBefore(next, block);
      }
    });

    blockList.addEventListener('change', (e) => {
      const target = e.target;
      if (!(target instanceof HTMLElement)) return;

      if (target.classList.contains('km-block-imgfile')) {
        const block = target.closest('.km-block');
        if (!block) return;

        const pickedEl = block.querySelector('.km-block-img-picked');
        const inp = target;
        const fileName = (inp.files && inp.files.length) ? inp.files[0].name : '';

        if (pickedEl) {
          if (fileName) {
            pickedEl.textContent = 'Zgjedhur: ' + fileName;
            pickedEl.classList.remove('d-none');
          } else {
            pickedEl.textContent = '';
            pickedEl.classList.add('d-none');
          }
        }
      }
    });
  }

  function readBlocksFromDOM() {
    const blocks = [];
    if (!blockList) return blocks;

    blockList.querySelectorAll('.km-block').forEach((el) => {
      const type = (el.getAttribute('data-type') || 'p').toLowerCase();

      if (type === 'p' || type === 'h2') {
        const txtEl = el.querySelector('.km-block-text');
        const text = txtEl ? txtEl.value.trim() : '';
        if (text !== '') blocks.push({ type, text });

      } else if (type === 'code') {
        const langEl = el.querySelector('.km-block-lang');
        const codeEl = el.querySelector('.km-block-code');
        const lang = langEl ? langEl.value.trim() : '';
        const code = codeEl ? codeEl.value : '';
        if (code.trim() !== '') blocks.push({ type: 'code', lang, code });

      } else if (type === 'img') {
        const altEl  = el.querySelector('.km-block-alt');
        const srcEl  = el.querySelector('.km-block-src');      // src aktual (nëse ekziston)
        const fileEl = el.querySelector('.km-block-imgfile');  // file i zgjedhur
        const key    = el.getAttribute('data-img-key') || ('img_' + Math.random().toString(16).slice(2));

        const alt = altEl ? altEl.value.trim() : '';
        const currentSrc = srcEl ? srcEl.value.trim() : '';

        // nëse user ka zgjedhur file të ri → placeholder për PHP
        if (fileEl && fileEl.files && fileEl.files.length > 0) {
          const placeholder = `__IMG_${key}__`;
          blocks.push({ type: 'img', alt, src: placeholder, key });
        } else if (currentSrc !== '') {
          // nuk zgjedh file → mbaj src ekzistues
          blocks.push({ type: 'img', alt, src: currentSrc, key });
        }
      }
    });

    return blocks;
  }

  function blocksToMarkdown(blocks) {
    let md = '';
    (blocks || []).forEach((b) => {
      if (!b) return;

      if (b.type === 'p') {
        if (b.text && b.text.trim() !== '') md += b.text.trim() + "\n\n";
      } else if (b.type === 'h2') {
        if (b.text && b.text.trim() !== '') md += '## ' + b.text.trim() + "\n\n";
      } else if (b.type === 'code') {
        const lang = (b.lang || '').trim();
        const code = (b.code || '');
        if (code.trim() !== '') md += '```' + lang + "\n" + code + "\n```\n\n";
      } else if (b.type === 'img') {
        const alt = (b.alt || '').trim();
        const src = (b.src || '').trim();
        if (src !== '') md += '![' + alt + '](' + src + ")\n\n";
      }
    });
    return md.trim();
  }

  function markdownToBlocks(md) {
    const blocks = [];
    const lines = (md || '').split(/\r?\n/);

    let buffer = [];
    let inCode = false;
    let codeLang = '';
    let codeLines = [];

    function flushParagraph() {
      if (!buffer.length) return;
      const text = buffer.join('\n').trim();
      if (text !== '') blocks.push({ type: 'p', text });
      buffer = [];
    }

    lines.forEach((line) => {
      const fenceOpenMatch = line.match(/^(```|~~~)\s*([^\s`~]*)\s*$/);
      if (fenceOpenMatch && !inCode) {
        flushParagraph();
        inCode = true;
        codeLang = fenceOpenMatch[2] || '';
        codeLines = [];
        return;
      }
      if (inCode && /^(```|~~~)\s*$/.test(line)) {
        blocks.push({ type: 'code', lang: codeLang, code: codeLines.join('\n') });
        inCode = false; codeLang = ''; codeLines = [];
        return;
      }
      if (inCode) { codeLines.push(line); return; }

      if (/^\s*$/.test(line)) { flushParagraph(); return; }

      const hMatch = line.match(/^#{1,2}\s+(.*)$/);
      if (hMatch) {
        flushParagraph();
        const text = hMatch[1].trim();
        if (text !== '') blocks.push({ type: 'h2', text });
        return;
      }

      const imgMatch = line.match(/^!\[(.*?)\]\((.*?)\)\s*$/);
      if (imgMatch) {
        flushParagraph();
        const alt = (imgMatch[1] || '').trim();
        const src = (imgMatch[2] || '').trim();
        if (src !== '') {
          const key = 'img_' + (blocks.length + 1);
          blocks.push({ type: 'img', alt, src, key });
        }
        return;
      }

      buffer.push(line);
    });

    flushParagraph();
    return blocks;
  }

  function renderBlocks(blocks) {
    if (!blockList) return;
    blockList.innerHTML = '';

    const list = Array.isArray(blocks) ? blocks : [];
    if (!list.length) { blockList.appendChild(createBlock('p')); return; }

    list.forEach((b, idx) => {
      if (!b || !b.type) return;
      const type = b.type.toLowerCase();
      const blockEl = createBlock(type);

      if (type === 'p' || type === 'h2') {
        const txtEl = blockEl.querySelector('.km-block-text');
        if (txtEl) txtEl.value = b.text || '';

      } else if (type === 'code') {
        const langEl = blockEl.querySelector('.km-block-lang');
        const codeEl = blockEl.querySelector('.km-block-code');
        if (langEl) langEl.value = b.lang || '';
        if (codeEl) codeEl.value = b.code || '';

      } else if (type === 'img') {
        const altEl  = blockEl.querySelector('.km-block-alt');
        const srcEl  = blockEl.querySelector('.km-block-src');
        const curEl  = blockEl.querySelector('.km-block-img-current');
        const fileEl = blockEl.querySelector('.km-block-imgfile');

        const key = (b.key ? String(b.key) : ('img_' + (idx + 1)));
        blockEl.setAttribute('data-img-key', key);
        if (fileEl) fileEl.setAttribute('name', `img_files[${key}]`);

        if (altEl) altEl.value = b.alt || '';
        if (srcEl) srcEl.value = b.src || '';

        if (curEl && b.src) {
          curEl.textContent = 'Aktual: ' + b.src;
          curEl.classList.remove('d-none');
        }
      }

      blockList.appendChild(blockEl);
    });
  }

  function setEditorView(view) {
    const toolbar = document.querySelector('.km-block-toolbar');
    view = (view === 'markdown') ? 'markdown' : 'normal';

    if (view === 'markdown') {
      if (currentView === 'normal' && mdEditor) {
        mdEditor.value = blocksToMarkdown(readBlocksFromDOM());
      }
      mdEditor.classList.remove('d-none');
      blockList.classList.add('d-none');
      if (toolbar) toolbar.classList.add('d-none');
    } else {
      if (currentView === 'markdown' && mdEditor) {
        renderBlocks(markdownToBlocks(mdEditor.value || ''));
      }
      mdEditor.classList.add('d-none');
      blockList.classList.remove('d-none');
      if (toolbar) toolbar.classList.remove('d-none');
    }

    currentView = view;
    viewTabs.forEach(tab => {
      const tabView = tab.getAttribute('data-view') || 'normal';
      tab.classList.toggle('active', tabView === currentView);
    });
  }

  document.addEventListener('DOMContentLoaded', () => {
    const descHidden = document.getElementById('description');
    const initialMd = (mdEditor && mdEditor.value.trim() !== '') ? mdEditor.value : (descHidden ? descHidden.value : '');
    renderBlocks(markdownToBlocks(initialMd || ''));

    viewTabs = Array.from(document.querySelectorAll('.km-editor-tab'));
    viewTabs.forEach(tab => tab.addEventListener('click', () => setEditorView(tab.getAttribute('data-view') || 'normal')));
    setEditorView('normal');
  });

  const formEl = document.querySelector('form.km-form-grid');
  if (formEl) {
    formEl.addEventListener('submit', () => {
      const descHidden = document.getElementById('description');

      // NOTE: IMG uploads punojnë vetëm në Normal View (sepse aty ekzistojnë input[type=file])
      // Në Markdown View, nuk kemi si të lidhim file me një IMG markdown, prandaj e kthejmë në normal përpara submit.
      if (currentView === 'markdown') {
        // Parse markdown → blocks → render normal që input file të jetë në DOM
        const parsed = markdownToBlocks(mdEditor.value || '');
        renderBlocks(parsed);
        setEditorView('normal');
      }

      const blocks = readBlocksFromDOM();
      const mdFinal = blocksToMarkdown(blocks);

      if (descHidden) descHidden.value = mdFinal.trim();
      if (blocksJsonInput) blocksJsonInput.value = JSON.stringify(blocks);
    });
  }

  /* -------------------- Toggle URL / FILE sipas kategorisë -------------- */
  const catSel   = document.getElementById('category');
  const urlWrap  = document.getElementById('urlWrap');
  const urlInput = document.getElementById('url');
  const urlLabel = document.getElementById('urlLabel');
  const urlHelp  = document.getElementById('urlHelp');
  const fileCard = document.getElementById('fileCard');
  const fileInput= document.getElementById('lesson_file');

  function toggleFields() {
    const v = (catSel.value || '').toUpperCase();
    if (urlWrap)  urlWrap.classList.add('d-none');
    if (fileCard) fileCard.classList.add('d-none');
    if (urlInput) urlInput.required = false;

    let labelText = 'URL (për VIDEO / LINK)';
    let helpText  = 'Vendos URL-në e videos ose linkun e burimit të jashtëm.';

    if (v === 'LEKSION') {
      urlWrap.classList.remove('d-none');
      fileCard.classList.remove('d-none');
      labelText = 'Video / link (opsionale)';
      helpText  = 'Opsionale: vendos linkun e videos ose një faqeje të jashtme.';
    } else if (v === 'VIDEO') {
      urlWrap.classList.remove('d-none');
      urlInput.required = true;
      labelText = 'URL e videos';
      helpText  = 'Link i videos (YouTube, Vimeo, Loom, etj.).';
    } else if (v === 'LINK') {
      urlWrap.classList.remove('d-none');
      urlInput.required = true;
      labelText = 'URL (link i jashtëm)';
      helpText  = 'Link i faqes së jashtme (artikull, dokumentacion, etj.).';
    } else if (v === 'FILE') {
      fileCard.classList.remove('d-none');
      labelText = 'URL (opsionale)';
      helpText  = 'Opsionale: link shoqërues për skedarin.';
    } else if (v === 'REFERENCA') {
      urlWrap.classList.remove('d-none');
      labelText = 'URL e referencës (opsionale)';
      helpText  = 'Opsionale: link i një libri, artikulli ose dokumentacioni.';
    } else if (v === 'LAB') {
      urlWrap.classList.remove('d-none');
      fileCard.classList.remove('d-none');
      labelText = 'URL (opsionale)';
      helpText  = 'Opsionale: link i enoncit apo repo (GitHub, etj.).';
    } else {
      urlWrap.classList.remove('d-none');
      labelText = 'URL (opsionale)';
      helpText  = 'Opsionale: link shoqërues për këtë element.';
    }

    if (urlLabel) urlLabel.textContent = labelText;
    if (urlHelp)  urlHelp.textContent  = helpText;
  }

  if (catSel) {
    catSel.addEventListener('change', toggleFields);
    toggleFields();
  }

  /* -------------------- Custom file picker UI -------------------------- */
  const pickBtn = document.getElementById('kmPickLessonFile');
  const nameEl  = document.getElementById('kmLessonFileName');

  if (pickBtn && fileInput) {
    pickBtn.addEventListener('click', () => fileInput.click());
    fileInput.addEventListener('change', () => {
      const name = (fileInput.files && fileInput.files.length) ? fileInput.files[0].name : 'Asnjë skedar i zgjedhur';
      if (nameEl) nameEl.textContent = name;
    });
  }
</script>
</body>
</html>
