<?php
// add_lesson.php — Shto ELEMENT (LEKSION/VIDEO/LINK/FILE/REFERENCA/LAB/TJETER)
declare(strict_types=1);
if (session_status() === PHP_SESSION_NONE) { session_start(); }
require_once __DIR__ . '/../lib/database.php';

/* -------------------- RBAC -------------------- */
if (!isset($_SESSION['user']) || !in_array($_SESSION['user']['role'] ?? '', ['Administrator','Instruktor'], true)) {
  header('Location: ../login.php');
  exit;
}
$ROLE  = (string)($_SESSION['user']['role'] ?? '');
$ME_ID = (int)($_SESSION['user']['id'] ?? 0);

/* -------------------- CSRF -------------------- */
if (empty($_SESSION['csrf_token'])) {
  $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$CSRF = (string)$_SESSION['csrf_token'];

/* -------------------- Helpers ----------------- */
function h(?string $s): string { return htmlspecialchars($s ?? '', ENT_QUOTES, 'UTF-8'); }
function ensure_dir(string $dir): void { if (!is_dir($dir)) { @mkdir($dir, 0775, true); } }

/** Root i projektit (një nivel sipër /admin) */
$BASE_ABS = realpath(__DIR__ . '/..') ?: dirname(__DIR__);

/** Upload dirs (ABS + REL për DB/URL) */
$LESSON_FILES_ABS = $BASE_ABS . '/uploads/lessons';
$LESSON_FILES_REL = 'uploads/lessons/';

$LESSON_IMAGES_ABS_ROOT = $BASE_ABS . '/uploads/images/lessons';
$LESSON_IMAGES_REL_ROOT = 'uploads/images/lessons';

ensure_dir($LESSON_FILES_ABS);
ensure_dir($LESSON_IMAGES_ABS_ROOT);

/* -------------------- Upload validators ----------------- */
function detect_mime(string $tmpPath): string {
  $finfo = new finfo(FILEINFO_MIME_TYPE);
  return (string)($finfo->file($tmpPath) ?: '');
}

/** Attachment (lesson_file) — 15MB, allowlist e arsyeshme */
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
  $mime = detect_mime((string)$f['tmp_name']);

  // extension allowlist (pragmatike)
  $allowedExt = [
    'pdf','doc','docx','ppt','pptx','xls','xlsx','csv','txt',
    'zip','rar','7z',
    'mp4','mov','avi',
    'jpg','jpeg','png','gif','webp'
  ];
  if ($ext === '' || !in_array($ext, $allowedExt, true)) {
    return [false, 'Tip skedari i palejuar.', null, null];
  }

  // klasifikim i thjeshtë për UI/DB
  $map = [
    'pdf'  => 'PDF',
    'ppt'  => 'SLIDES',
    'pptx' => 'SLIDES',
    'mp4'  => 'VIDEO',
    'avi'  => 'VIDEO',
    'mov'  => 'VIDEO',
  ];
  $fileType = strtoupper($map[$ext] ?? 'DOC');

  return [true, '', $ext, $fileType];
}

/** Validim imazhi (për block_images / lesson_images) */
function validate_image_upload(string $tmpPath): bool {
  if (!is_uploaded_file($tmpPath)) return false;
  $mime = detect_mime($tmpPath);
  return in_array($mime, ['image/jpeg','image/png','image/gif','image/webp'], true);
}

/* -------------------- Schema helpers ----------------- */
function km_table_exists(PDO $pdo, string $table): bool {
  try {
    $st = $pdo->prepare('SHOW TABLES LIKE ?');
    $st->execute([$table]);
    return (bool)$st->fetchColumn();
  } catch (Throwable $e) {
    return false;
  }
}

/**
 * Siguro që tabela lesson_images ekziston.
 * E bën page-in resilient kur DB skema s'është up-to-date.
 */
function km_ensure_lesson_images_table(PDO $pdo): void {
  static $done = false;
  if ($done) return;
  $done = true;

  if (km_table_exists($pdo, 'lesson_images')) return;

  // Krijo tabelën (pa FK për kompatibilitet me engine-ët ekzistues).
  $pdo->exec("
    CREATE TABLE IF NOT EXISTS lesson_images (
      id INT UNSIGNED NOT NULL AUTO_INCREMENT,
      lesson_id INT NOT NULL,
      file_path VARCHAR(255) NOT NULL,
      alt_text VARCHAR(255) NULL,
      position INT NOT NULL DEFAULT 0,
      created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
      PRIMARY KEY (id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
  ");

  // Indekse (best-effort)
  try { $pdo->exec('CREATE INDEX idx_limg_lesson ON lesson_images(lesson_id)'); } catch (Throwable $e) { /* ignore */ }
  try { $pdo->exec('CREATE INDEX idx_limg_lesson_pos ON lesson_images(lesson_id, position)'); } catch (Throwable $e) { /* ignore */ }
}

/* -------------------- Image processing ----------------- */
/**
 * Ruaj imazhet e “Foto” brenda përmbajtjes dhe zëvendëso #IMG1..n me /uploads/images/lessons/<id>/<file>
 */
function process_content_images_for_lesson(int $lessonId, string $description, array $images, string $absRoot, string $relRoot): string {
  if (empty($images['name']) || !is_array($images['name'])) return $description;

  $lessonDirAbs = $absRoot . '/' . $lessonId;
  ensure_dir($lessonDirAbs);

  $count = count($images['name']);
  $imgIndex = 0;

  for ($i = 0; $i < $count; $i++) {
    if (($images['error'][$i] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) continue;
    $tmp = (string)($images['tmp_name'][$i] ?? '');
    if ($tmp === '' || !validate_image_upload($tmp)) continue;

    $origName = (string)($images['name'][$i] ?? 'image');
    $safeName = preg_replace('/[^A-Za-z0-9._-]/', '_', $origName);
    $newName  = time() . '_' . bin2hex(random_bytes(3)) . '_' . $safeName;

    $abs = $lessonDirAbs . '/' . $newName;
    $rel = $relRoot . '/' . $lessonId . '/' . $newName;

    if (!move_uploaded_file($tmp, $abs)) continue;

    $imgIndex++;
    $description = str_replace('#IMG' . $imgIndex, '/' . $rel, $description);
  }

  // hiq çdo imazh markdown që ka mbetur me placeholder
  $description = preg_replace('/!\[[^\]]*]\(#IMG\d+\)/', '', $description);

  return $description;
}

/** Ruaj fotot opsionale të leksionit në table lesson_images */
function save_lesson_images(PDO $pdo, int $lessonId, array $files, string $absRoot, string $relRoot): void {
  km_ensure_lesson_images_table($pdo);

  $lessonDirAbs = $absRoot . '/' . $lessonId;
  ensure_dir($lessonDirAbs);

  $maxBytes = 5 * 1024 * 1024;

  $stmtPos = $pdo->prepare("
    SELECT COALESCE(MAX(position), 0) + 1
    FROM lesson_images
    WHERE lesson_id = ?
  ");
  $stmtPos->execute([$lessonId]);
  $basePos = (int)$stmtPos->fetchColumn();

  $count = is_array($files['name'] ?? null) ? count($files['name']) : 0;
  if ($count === 0) return;

  for ($i = 0; $i < $count; $i++) {
    if (($files['error'][$i] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) continue;

    $tmp  = (string)($files['tmp_name'][$i] ?? '');
    $size = (int)($files['size'][$i] ?? 0);

    if ($tmp === '' || !is_uploaded_file($tmp)) continue;
    if ($size <= 0 || $size > $maxBytes) continue;
    if (!validate_image_upload($tmp)) continue;

    $origName = (string)($files['name'][$i] ?? 'image');
    $safeName = preg_replace('/[^A-Za-z0-9._-]/', '_', $origName);
    $newName  = time() . '_' . bin2hex(random_bytes(3)) . '_' . $safeName;

    $abs = $lessonDirAbs . '/' . $newName;
    $rel = $relRoot . '/' . $lessonId . '/' . $newName;

    if (!move_uploaded_file($tmp, $abs)) continue;

    $altText = pathinfo($origName, PATHINFO_FILENAME);

    $stmtIns = $pdo->prepare("
      INSERT INTO lesson_images (lesson_id, file_path, alt_text, position)
      VALUES (?,?,?,?)
    ");
    $stmtIns->execute([$lessonId, $rel, $altText, $basePos + $i]);
  }
}

/* -------------------- Inputs ------------------ */
if (!isset($_GET['course_id']) || empty($_GET['course_id'])) {
  $_SESSION['flash'] = ['msg'=>'Kursi nuk është specifikuar.', 'type'=>'danger'];
  header('Location: ../course.php'); exit;
}
$course_id       = (int)$_GET['course_id'];
$pref_section_id = isset($_GET['section_id']) ? (int)$_GET['section_id'] : 0;
$copy_lesson_id  = isset($_GET['copy_lesson_id']) ? (int)$_GET['copy_lesson_id'] : 0;

/* -------------------- Fetch course ------------- */
try {
  $stmt = $pdo->prepare("
    SELECT c.*, u.full_name AS creator_name, u.id AS creator_id
    FROM courses c
    LEFT JOIN users u ON u.id = c.id_creator
    WHERE c.id = ?
  ");
  $stmt->execute([$course_id]);
  $course = $stmt->fetch(PDO::FETCH_ASSOC);
  if (!$course) {
    $_SESSION['flash'] = ['msg'=>'Kursi nuk u gjet.', 'type'=>'danger'];
    header('Location: ../course.php'); exit;
  }

  if ($ROLE === 'Instruktor' && (int)($course['creator_id'] ?? 0) !== $ME_ID) {
    $_SESSION['flash'] = ['msg'=>'Nuk keni akses në këtë kurs.', 'type'=>'danger'];
    header('Location: ../course.php'); exit;
  }
} catch (PDOException $e) {
  $_SESSION['flash'] = ['msg'=>'Gabim: ' . h($e->getMessage()), 'type'=>'danger'];
  header('Location: ../course.php'); exit;
}

/* -------------------- Sections ---------------- */
$sections = [];
try {
  $secStmt = $pdo->prepare("
    SELECT id, title
    FROM sections
    WHERE course_id = ?
    ORDER BY position ASC, id ASC
  ");
  $secStmt->execute([$course_id]);
  $sections = $secStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
} catch (PDOException $e) {
  $sections = [];
}

$sectionsById = [];
foreach ($sections as $s) $sectionsById[(int)$s['id']] = $s;

$lock_section = false;
if ($pref_section_id > 0) {
  if (!isset($sectionsById[$pref_section_id])) {
    $pref_section_id = 0;
  } else {
    $lock_section = true;
  }
}

/* -------------------- Defaults ----------------- */
$errors = [];
$title = $description = $url = '';
$selectedCategory = '';
$section_id = $pref_section_id > 0 ? $pref_section_id : 0;

/* -------------------- Prefill (copy) ----------- */
if ($copy_lesson_id > 0) {
  try {
    $stmtCopy = $pdo->prepare("SELECT * FROM lessons WHERE id = ?");
    $stmtCopy->execute([$copy_lesson_id]);
    if ($row = $stmtCopy->fetch(PDO::FETCH_ASSOC)) {
      $title            = (string)($row['title'] ?? '');
      $description      = (string)($row['description'] ?? '');
      $url              = (string)($row['URL'] ?? '');
      $selectedCategory = (string)($row['category'] ?? '');
    } else {
      $errors[] = 'Leksioni për kopjim nuk u gjet.';
    }
  } catch (PDOException $e) {
    $errors[] = 'Gabim gjatë leximit të leksionit për kopjim.';
  }
}

/* -------------------- POST --------------------- */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  if (!isset($_POST['csrf']) || !hash_equals($CSRF, (string)$_POST['csrf'])) {
    $errors[] = 'Seancë e pavlefshme (CSRF). Ringarko faqen.';
  }

  $course_id        = (int)($_POST['course_id'] ?? 0);
  $section_id       = $lock_section ? $pref_section_id : (int)($_POST['section_id'] ?? 0);
  $title            = trim((string)($_POST['title'] ?? ''));
  $description      = (string)($_POST['description'] ?? '');
  $url              = trim((string)($_POST['url'] ?? ''));
  $selectedCategory = trim((string)($_POST['category'] ?? ''));
  $copy_lesson_id   = isset($_POST['copy_lesson_id']) ? (int)$_POST['copy_lesson_id'] : 0;

  if ($title === '') $errors[] = 'Titulli është i detyrueshëm.';
  if ($selectedCategory === '') $errors[] = 'Zgjedhja e kategorisë është e detyrueshme.';

  $cat = strtoupper($selectedCategory);

  // Validim sipas kategorisë
  if (in_array($cat, ['VIDEO','LINK'], true)) {
    if ($url === '' || !filter_var($url, FILTER_VALIDATE_URL)) {
      $errors[] = 'URL e vlefshme është e detyrueshme për VIDEO/LINK.';
    }
  } elseif ($cat === 'FILE') {
    if (empty($_FILES['lesson_file']['name'])) {
      $errors[] = 'Ngarko një skedar për kategorinë FILE.';
    }
  } elseif ($cat === 'LEKSION') {
    if (trim($description) === '') {
      $errors[] = 'Përmbajtja është e detyrueshme për LEKSION.';
    }
  }

  // Validim seksioni
  if ($section_id > 0 && !isset($sectionsById[$section_id])) {
    $errors[] = 'Seksioni i zgjedhur nuk i përket këtij kursi.';
  }

  // Upload attachment (lesson_file) — required për FILE, opsional për LEKSION/LAB
  $uploadedFileRel  = null;
  $uploadedFileType = null;

  $hasUpload = isset($_FILES['lesson_file'])
    && ($_FILES['lesson_file']['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_OK
    && is_uploaded_file($_FILES['lesson_file']['tmp_name'] ?? '');

  if ($cat === 'FILE' && !$hasUpload) {
    $errors[] = 'Ngarko një skedar për kategorinë FILE.';
  }

  if (in_array($cat, ['FILE','LEKSION','LAB'], true) && $hasUpload && empty($errors)) {
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

  if (!$errors) {
    try {
      $pdo->beginTransaction();

      // Insert lesson
      $stmtIns = $pdo->prepare("
        INSERT INTO lessons (course_id, section_id, title, description, URL, category, uploaded_at)
        VALUES (?,?,?,?,?,?,NOW())
      ");
      $stmtIns->execute([
        $course_id,
        $section_id ?: null,
        $title,
        $description,
        $url !== '' ? $url : null,
        $cat
      ]);

      $lesson_id = (int)$pdo->lastInsertId();

      // Përpunimi i block_images (#IMG1..)
      if (isset($_FILES['block_images']) && !empty($_FILES['block_images']['name'])) {
        $descriptionFinal = process_content_images_for_lesson(
          $lesson_id,
          $description,
          $_FILES['block_images'],
          $LESSON_IMAGES_ABS_ROOT,
          $LESSON_IMAGES_REL_ROOT
        );

        if ($descriptionFinal !== $description) {
          $stmtUpd = $pdo->prepare("UPDATE lessons SET description = ? WHERE id = ?");
          $stmtUpd->execute([$descriptionFinal, $lesson_id]);
          $description = $descriptionFinal;
        }
      }

      // Attachment te lesson_files
      if ($uploadedFileRel) {
        $stmtFile = $pdo->prepare("
          INSERT INTO lesson_files (lesson_id, file_path, file_type)
          VALUES (?,?,?)
        ");
        $stmtFile->execute([$lesson_id, $uploadedFileRel, $uploadedFileType ?: 'DOC']);
      }

      // Copy files nga leksioni burim (nëse copy_lesson_id)
      if ($copy_lesson_id > 0) {
        try {
          $stmtFiles = $pdo->prepare("SELECT file_path, file_type FROM lesson_files WHERE lesson_id = ?");
          $stmtFiles->execute([$copy_lesson_id]);
          $files = $stmtFiles->fetchAll(PDO::FETCH_ASSOC) ?: [];

          $stmtDup = $pdo->prepare("
            INSERT INTO lesson_files (lesson_id, file_path, file_type)
            VALUES (?,?,?)
          ");

          foreach ($files as $file) {
            $oldRel = (string)($file['file_path'] ?? '');
            if ($oldRel === '') continue;

            // Path absolut i saktë (root i projektit + uploads/...)
            $oldAbs = $BASE_ABS . '/' . ltrim($oldRel, '/');
            if (!is_file($oldAbs)) continue;

            $newName = time() . '_' . bin2hex(random_bytes(4)) . '_' . basename($oldAbs);
            $newAbs  = $LESSON_FILES_ABS . '/' . $newName;
            $newRel  = $LESSON_FILES_REL . $newName;

            if (@copy($oldAbs, $newAbs)) {
              $ftype = strtoupper((string)($file['file_type'] ?? 'DOC'));
              if (!in_array($ftype, ['PDF','VIDEO','SLIDES','DOC'], true)) $ftype = 'DOC';
              $stmtDup->execute([$lesson_id, $newRel, $ftype]);
            }
          }
        } catch (Throwable $e) {
          // ignore
        }
      }

      /* Auto-link te section_items */
      $sidForSI = $section_id ?: 0;
      $stmtPos = $pdo->prepare("
        SELECT COALESCE(MAX(position), 0) + 1
        FROM section_items
        WHERE course_id = ? AND section_id = ?
      ");
      $stmtPos->execute([$course_id, $sidForSI]);
      $nextPos = (int)$stmtPos->fetchColumn();

      $stmtSI = $pdo->prepare("
        INSERT INTO section_items (course_id, section_id, item_type, item_ref_id, position)
        VALUES (?,?,?,?,?)
      ");
      $stmtSI->execute([$course_id, $sidForSI, 'LESSON', $lesson_id, $nextPos]);

      /* Fotot e leksionit (opsionale) */
      if (!empty($_FILES['lesson_images']) && !empty($_FILES['lesson_images']['name'][0])) {
        try {
          save_lesson_images($pdo, $lesson_id, $_FILES['lesson_images'], $LESSON_IMAGES_ABS_ROOT, $LESSON_IMAGES_REL_ROOT);
        } catch (Throwable $e) {
          // ignore
        }
      }

      $pdo->commit();
      $_SESSION['flash'] = ['msg'=>'Elementi u shtua me sukses.', 'type'=>'success'];
      header("Location: ../course_details.php?course_id=" . $course_id . "&tab=materials");
      exit;

    } catch (Throwable $e) {
      if ($pdo->inTransaction()) $pdo->rollBack();
      $errors[] = 'Gabim gjatë futjes së elementit: ' . h($e->getMessage());
    }
  }
}
?>
<!DOCTYPE html>
<html lang="sq" data-bs-theme="light">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <title>Shto element — kurseinformatike.com</title>

  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
  <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css" rel="stylesheet">
  <link rel="stylesheet" href="../css/km-lms-forms.css">
  <link rel="icon" href="/image/favicon.ico" type="image/x-icon" />

  <!-- minimal add-on (që UI e file picker të duket mirë edhe pa CSS shtesë) -->
  <style>
    .km-type-pill{border:1px solid rgba(229,231,235,.95)!important;background:#fff!important;color:#334155!important}
    .km-type-pill.active{background:#eff6ff!important;border-color:rgba(37,99,235,.25)!important;color:#1d4ed8!important;box-shadow:0 10px 22px rgba(37,99,235,.10)}
    .km-alert{border-radius:18px;border:1px solid rgba(229,231,235,.95);padding:12px 14px;background:#fff;box-shadow:0 8px 22px rgba(15,23,42,.06)}
    .km-alert-danger{background:#fff1f2;border-color:rgba(244,63,94,.25);color:#7f1d1d}
    .km-file-picker{display:flex;align-items:center;gap:.6rem;padding:.55rem .6rem;border:1px solid rgba(229,231,235,.95);border-radius:999px;background:#fff}
    .km-file-name{color:#475569;font-weight:800;font-size:.9rem;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
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
            <?= h((string)($course['title'] ?? '')) ?>
          </a>
          <span class="mx-1">/</span>
          <span class="km-breadcrumb-current">Element i ri</span>
        </div>

        <h1 class="km-page-title">
          <i class="fa-solid fa-circle-plus me-2 text-primary"></i>
          Shto element mësimor
        </h1>

        <p class="km-page-subtitle mb-0">
          Krijo një leksion, video, link, file ose element tjetër për kursin <strong><?= h((string)($course['title'] ?? '')) ?></strong>.
        </p>
      </div>

      <div class="d-flex flex-column align-items-md-end gap-2">
        <div class="km-pill-meta">
          <i class="fa-solid fa-user-tie me-1"></i>
          Instruktor: <?= h((string)($course['creator_name'] ?? '')) ?>
        </div>
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

    <form method="POST" enctype="multipart/form-data" class="row g-4 km-form-grid">
      <input type="hidden" name="csrf" value="<?= h($CSRF) ?>">
      <input type="hidden" name="course_id" value="<?= (int)$course_id ?>">
      <?php if ($copy_lesson_id): ?>
        <input type="hidden" name="copy_lesson_id" value="<?= (int)$copy_lesson_id ?>">
      <?php endif; ?>

      <div class="col-12 col-lg-8">

        <section class="km-card km-card-main mb-3">
          <div class="km-card-header">
            <div>
              <h2 class="km-card-title">
                <span class="km-step-badge">1</span>
                Tipi & detajet kryesore
              </h2>
              <p class="km-card-subtitle mb-0">
                Zgjidh tipin e elementit dhe jepi një titull të qartë.
              </p>
            </div>

            <?php if ($copy_lesson_id): ?>
              <span class="badge bg-light text-dark border border-secondary-subtle">
                <i class="fa-solid fa-copy me-1"></i>
                Kopjim nga leksioni #<?= (int)$copy_lesson_id ?>
              </span>
            <?php endif; ?>
          </div>

          <div class="km-card-body">
            <div class="mb-3">
              <label class="form-label">Titulli i elementit</label>
              <input type="text"
                     name="title"
                     class="form-control"
                     required
                     placeholder="p.sh. OOP në PHP: Klasa & Objekte"
                     value="<?= h($title) ?>">
              <div class="km-help-text mt-1">
                Përdor një titull të shkurtër që duket bukur në listën e materialeve.
              </div>
            </div>

            <div class="mb-3">
              <label class="form-label">Tipi i elementit</label>
              <?php
                $allCats = ['LEKSION','VIDEO','LINK','FILE','REFERENCA','LAB','TJETER'];
                $catIcons = [
                  'LEKSION'   => 'fa-regular fa-file-lines',
                  'VIDEO'     => 'fa-solid fa-circle-play',
                  'LINK'      => 'fa-solid fa-link',
                  'FILE'      => 'fa-regular fa-folder-open',
                  'REFERENCA' => 'fa-solid fa-book-open-reader',
                  'LAB'       => 'fa-solid fa-flask',
                  'TJETER'    => 'fa-regular fa-square-plus'
                ];
                $currentCat = strtoupper($selectedCategory) ?: 'LEKSION';
              ?>

              <div class="d-flex flex-wrap gap-2 mb-2" id="typePills">
                <?php foreach ($allCats as $c): ?>
                  <?php $isActive = ($currentCat === $c); ?>
                  <button type="button"
                          class="btn btn-light btn-sm km-btn-pill km-type-pill<?= $isActive ? ' active' : '' ?>"
                          data-cat="<?= $c ?>">
                    <i class="<?= $catIcons[$c] ?? 'fa-regular fa-square' ?>"></i>
                    <span><?= $c ?></span>
                  </button>
                <?php endforeach; ?>
              </div>

              <input type="hidden" id="category" name="category" value="<?= h($currentCat) ?>">

              <div class="km-help-text mt-1">
                LEKSION → mund të ketë përmbajtje + URL + file; VIDEO/LINK/FILE → më të fokusuara.
              </div>
            </div>

            <div class="row g-3 mt-3">
              <div class="col-md-6">
                <label class="form-label">Seksioni</label>

                <?php if ($lock_section && $section_id > 0 && isset($sectionsById[$section_id])): ?>
                  <input type="hidden" name="section_id" value="<?= (int)$section_id ?>">
                  <input type="text"
                         class="form-control"
                         value="<?= h((string)$sectionsById[$section_id]['title']) ?>"
                         readonly>
                  <div class="km-help-text mt-1">Seksioni u zgjodh nga struktura e kursit.</div>
                <?php else: ?>
                  <select class="form-select" name="section_id" id="section_id">
                    <option value="0">— Jashtë seksioneve —</option>
                    <?php foreach ($sections as $s): ?>
                      <option value="<?= (int)$s['id'] ?>" <?= $section_id === (int)$s['id'] ? 'selected' : '' ?>>
                        <?= h((string)$s['title']) ?>
                      </option>
                    <?php endforeach; ?>
                  </select>
                  <div class="km-help-text mt-1">Zgjidh modulën ku do të shfaqet ky element.</div>
                <?php endif; ?>
              </div>
            </div>
          </div>
        </section>

        <section class="km-card km-card-main">
          <div class="km-card-header">
            <div>
              <h2 class="km-card-title">
                <span class="km-step-badge">2</span>
                Përmbajtja & burimet
              </h2>
              <p class="km-card-subtitle mb-0">
                Shto përshkrimin, URL-en dhe çdo informacion tjetër për studentët.
              </p>
            </div>
          </div>

          <div class="km-card-body">
            <div class="mb-3" id="urlWrap">
              <label class="form-label" id="urlLabel">URL (për VIDEO / LINK)</label>
              <input type="url"
                     class="form-control"
                     id="url"
                     name="url"
                     placeholder="https://..."
                     value="<?= h($url) ?>">
              <div class="km-help-text mt-1" id="urlHelp">
                Vendos linkun e videos ose faqes së jashtme.
              </div>
            </div>

            <div class="mt-3" id="descWrap">
              <label class="form-label">Përmbajtja e leksionit</label>

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
                          placeholder="Markdown: ## tituj, ```code```, ![alt](#IMG1) ..."></textarea>

                <div class="km-help-text mt-2">
                  Përdor blloqet ose Markdown View. Për fotot në blloqe, përdor “Foto” (placeholder #IMG1.. zëvendësohet automatikisht).
                </div>
              </div>

              <textarea id="description" name="description" class="d-none"><?= h($description) ?></textarea>
              <input type="hidden" id="blocks_json" name="blocks_json" value="">
            </div>
          </div>
        </section>
      </div>

      <div class="col-12 col-lg-4">
        <!-- File card (custom picker) -->
        <aside class="km-card km-card-side mb-3" id="fileCard" style="display:none;">
          <div class="km-card-body">
            <h3 class="km-side-title mb-2">
              <i class="fa-regular fa-folder-open me-2"></i>
              Skedari i bashkangjitur
            </h3>

            <!-- input real (hidden) -->
            <input class="d-none" type="file" id="lesson_file" name="lesson_file">

            <!-- UI custom -->
            <div class="km-file-picker">
              <button type="button" class="btn btn-outline-secondary btn-sm km-btn-pill" id="kmPickLessonFile">
                <i class="fa-solid fa-paperclip me-1"></i> Zgjidh skedarin
              </button>
              <span class="km-file-name" id="kmLessonFileName">Asnjë skedar i zgjedhur</span>
            </div>

            <div class="km-help-text mt-2">
              Maksimumi 15MB. Për FILE është i detyrueshëm; për LEKSION / LAB është opsional.
            </div>
          </div>
        </aside>

        <aside class="km-card km-card-side km-sticky-side">
          <div class="km-card-body">
            <h3 class="km-side-title mb-2">
              <i class="fa-regular fa-circle-check me-2 text-success"></i>
              Ruaj elementin
            </h3>

            <p class="km-help-text mb-3">
              Elementi do të shfaqet në strukturën e kursit.
            </p>

            <div class="d-grid gap-2">
              <button class="btn btn-primary km-btn-pill" type="submit">
                <i class="fa-regular fa-floppy-disk me-1"></i>
                Ruaj elementin
              </button>
              <a href="../course_details.php?course_id=<?= (int)$course_id ?>&tab=materials"
                 class="btn btn-outline-secondary km-btn-pill">
                <i class="fa-solid fa-arrow-left-long me-1"></i>
                Anulo dhe kthehu mbrapa
              </a>
            </div>
          </div>
        </aside>
      </div>

    </form>
  </div>
</div>

<?php include __DIR__ . '/../footer.php'; ?>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script>
  const hiddenCatInput  = document.getElementById('category');
  const urlWrap         = document.getElementById('urlWrap');
  const urlInput        = document.getElementById('url');
  const urlLabel        = document.getElementById('urlLabel');
  const urlHelp         = document.getElementById('urlHelp');

  const fileCard         = document.getElementById('fileCard');
  const fileInput        = document.getElementById('lesson_file');
  const filePickBtn      = document.getElementById('kmPickLessonFile');
  const fileNameEl       = document.getElementById('kmLessonFileName');

  const blockList       = document.getElementById('km-block-list');
  const blocksJsonInput = document.getElementById('blocks_json');
  const mdEditor        = document.getElementById('km-markdown-editor');

  let currentView = 'normal';
  let viewTabs = [];

  function toggleFields() {
    const v = (hiddenCatInput.value || '').toUpperCase();

    urlWrap.classList.add('d-none');
    fileCard.style.display = 'none';
    if (urlInput)  urlInput.required  = false;
    if (fileInput) fileInput.required = false;

    let labelText = 'URL (për VIDEO / LINK)';
    let helpText  = 'Vendos linkun e videos ose faqes së jashtme që i përket këtij elementi.';

    if (v === 'LEKSION') {
      urlWrap.classList.remove('d-none');
      fileCard.style.display = 'block';
      labelText = 'Video / link (opsionale)';
      helpText  = 'Opsionale: vendos linkun e videos ose një faqeje të jashtme për këtë leksion.';
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
      fileCard.style.display = 'block';
      fileInput.required = true;
      labelText = 'URL (opsionale)';
      helpText  = 'Opsionale: mund të shtosh edhe një link shoqërues për skedarin.';
    } else if (v === 'REFERENCA') {
      urlWrap.classList.remove('d-none');
      labelText = 'URL e referencës (opsionale)';
      helpText  = 'Opsionale: link i një libri, artikulli ose dokumentacioni.';
    } else if (v === 'LAB') {
      urlWrap.classList.remove('d-none');
      fileCard.style.display = 'block';
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

  // Pills
  document.querySelectorAll('#typePills .km-type-pill').forEach(p => {
    p.addEventListener('click', () => {
      const cat = p.getAttribute('data-cat') || 'LEKSION';
      hiddenCatInput.value = cat;

      document.querySelectorAll('#typePills .km-type-pill').forEach(x => x.classList.remove('active'));
      p.classList.add('active');

      toggleFields();
    });
  });

  // Custom file picker
  if (filePickBtn && fileInput) {
    filePickBtn.addEventListener('click', () => fileInput.click());
    fileInput.addEventListener('change', () => {
      const name = (fileInput.files && fileInput.files.length) ? fileInput.files[0].name : 'Asnjë skedar i zgjedhur';
      if (fileNameEl) fileNameEl.textContent = name;
    });
  }

  // ------------------------ Block editor --------------------------
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
      const placeholder = (type === 'p') ? 'Shkruaj paragraf...' : 'Titulli i seksionit...';
      body.innerHTML = `
        <textarea class="form-control km-block-text"
                  rows="${type === 'p' ? 3 : 2}"
                  placeholder="${placeholder}"></textarea>
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
      body.innerHTML = `
        <div class="mb-2">
          <label class="form-label small mb-1">Titulli / alt</label>
          <input type="text" class="form-control form-control-sm km-block-alt" placeholder="p.sh. Diagrama">
        </div>
        <div>
          <label class="form-label small mb-1">Skedari i fotos</label>
          <input type="file" class="form-control form-control-sm km-block-image-input" name="block_images[]" accept="image/*">
        </div>
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
        if (next) blockList.insertBefore(next, block.nextElementSibling);
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
        const altEl = el.querySelector('.km-block-alt');
        const alt = altEl ? altEl.value.trim() : '';
        blocks.push({ type: 'img', alt });
      }
    });

    return blocks;
  }

  function blocksToMarkdown(blocks) {
    let md = '';
    let imgIndex = 0;

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
        imgIndex++;
        const alt = (b.alt || '').trim();
        md += '![' + alt + '](#IMG' + imgIndex + ")\n\n";
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
      const fenceMatch = line.match(/^```(\w*)\s*$/);
      if (fenceMatch) {
        if (!inCode) {
          flushParagraph();
          inCode = true;
          codeLang = fenceMatch[1] || '';
          codeLines = [];
        } else {
          blocks.push({ type: 'code', lang: codeLang, code: codeLines.join('\n') });
          inCode = false;
          codeLang = '';
          codeLines = [];
        }
        return;
      }

      if (inCode) { codeLines.push(line); return; }

      if (/^\s*$/.test(line)) { flushParagraph(); return; }

      const h2Match = line.match(/^##\s+(.*)$/);
      if (h2Match) {
        flushParagraph();
        const text = h2Match[1].trim();
        if (text !== '') blocks.push({ type: 'h2', text });
        return;
      }

      const imgMatch = line.match(/^!\[(.*?)\]\((.*?)\)\s*$/);
      if (imgMatch) {
        flushParagraph();
        const alt = (imgMatch[1] || '').trim();
        blocks.push({ type: 'img', alt });
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
    if (!list.length) {
      blockList.appendChild(createBlock('p'));
      return;
    }

    list.forEach((b) => {
      if (!b || !b.type) return;
      const type = b.type.toLowerCase();
      const blockEl = createBlock(type);

      if (type === 'p' || type === 'h2') {
        const txtEl = blockEl.querySelector('.km-block-text');
        if (txtEl && b.text) txtEl.value = b.text;
      } else if (type === 'code') {
        const langEl = blockEl.querySelector('.km-block-lang');
        const codeEl = blockEl.querySelector('.km-block-code');
        if (langEl && b.lang) langEl.value = b.lang;
        if (codeEl && b.code) codeEl.value = b.code;
      } else if (type === 'img') {
        const altEl = blockEl.querySelector('.km-block-alt');
        if (altEl && b.alt) altEl.value = b.alt;
      }

      blockList.appendChild(blockEl);
    });
  }

  function setEditorView(view) {
    currentView = (view === 'markdown') ? 'markdown' : 'normal';
    const toolbar = document.querySelector('.km-block-toolbar');

    if (currentView === 'markdown') {
      const blocks = readBlocksFromDOM();
      mdEditor.value = blocksToMarkdown(blocks);
      mdEditor.classList.remove('d-none');
      blockList.classList.add('d-none');
      if (toolbar) toolbar.classList.add('d-none');
    } else {
      const blocks = markdownToBlocks(mdEditor.value || '');
      renderBlocks(blocks);
      mdEditor.classList.add('d-none');
      blockList.classList.remove('d-none');
      if (toolbar) toolbar.classList.remove('d-none');
    }

    viewTabs.forEach(tab => {
      const tabView = tab.getAttribute('data-view') || 'normal';
      tab.classList.toggle('active', tabView === currentView);
    });
  }

  document.addEventListener('DOMContentLoaded', () => {
    toggleFields();

    if (blockList && blockList.children.length === 0) {
      blockList.appendChild(createBlock('p'));
    }

    viewTabs = Array.from(document.querySelectorAll('.km-editor-tab'));
    viewTabs.forEach(tab => {
      tab.addEventListener('click', () => setEditorView(tab.getAttribute('data-view') || 'normal'));
    });

    setEditorView('normal');
  });

  // Submit: description + blocks_json
  const formEl = document.querySelector('form.km-form-grid');
  if (formEl) {
    formEl.addEventListener('submit', (e) => {
      const cat = (hiddenCatInput.value || '').toUpperCase();
      const descHidden = document.getElementById('description');

      let blocks = [];
      if (currentView === 'markdown') blocks = markdownToBlocks(mdEditor.value || '');
      else blocks = readBlocksFromDOM();

      if (cat === 'LEKSION' && blocks.length === 0) {
        alert('Shto të paktën një bllok përmbajtjeje për leksionin.');
        e.preventDefault();
        return;
      }

      const mdFinal = blocksToMarkdown(blocks);
      descHidden.value = mdFinal.trim();
      blocksJsonInput.value = JSON.stringify(blocks);
    });
  }
</script>
</body>
</html>
