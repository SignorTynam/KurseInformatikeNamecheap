<?php
// add_assignment.php — Shto Detyrë me block-editor (Normal/Markdown) + foto në përshkrim + auto-link te section_items
// ✅ (1) Skedarët e detyrës/zgjidhjes: pranohen PA kufizime tipi (pa ext/mime whitelist + pa accept në input)
// ⚠️ Vërejtje: PHP/serveri prapë ka kufizime reale (upload_max_filesize, post_max_size) që s’i anashkalon dot kodi.
// ✅ (2) Butonat “Ruaj detyrën” dhe “Anulo…” janë jashtë kutisë së skedarëve (jashtë aside card).

declare(strict_types=1);
if (session_status() === PHP_SESSION_NONE) { session_start(); }
require_once __DIR__ . '/../lib/database.php';

/* -------------------- RBAC -------------------- */
if (!isset($_SESSION['user']) || !in_array(($_SESSION['user']['role'] ?? ''), ['Administrator','Instruktor'], true)) {
  header('Location: ../login.php');
  exit("Nuk jeni të kyçur ose nuk keni të drejta për këtë faqe.");
}
$ROLE  = (string)$_SESSION['user']['role'];
$ME_ID = (int)($_SESSION['user']['id'] ?? 0);

/* -------------------- Helpers ----------------- */
function h(?string $s): string {
  return htmlspecialchars($s ?? '', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}
function ensure_dir(string $dir): void {
  if (!is_dir($dir)) {
    @mkdir($dir, 0775, true);
  }
}
function safe_filename(string $name): string {
  $name = trim($name);
  if ($name === '') return 'file';
  // hiq path traversal
  $name = basename($name);
  // zëvendëso karakteret e rrezikshme
  $name = preg_replace('/[^A-Za-z0-9._-]/', '_', $name);
  $name = trim((string)$name, '._-');
  return $name !== '' ? $name : 'file';
}

/**
 * Ruaj një upload pa kufizime tipi.
 * Kthen: [relPath|null, errorMsg|null]
 */
function save_any_upload(array $file, string $uploadAbs, string $uploadRel, string $prefix): array {
  $err = (int)($file['error'] ?? UPLOAD_ERR_NO_FILE);

  if ($err === UPLOAD_ERR_NO_FILE) {
    return [null, null]; // opsional
  }
  if ($err !== UPLOAD_ERR_OK) {
    return [null, 'Ngarkimi i skedarit dështoi (error code: ' . $err . ').'];
  }

  $tmp  = (string)($file['tmp_name'] ?? '');
  $name = (string)($file['name'] ?? '');

  if ($tmp === '' || !is_uploaded_file($tmp)) {
    return [null, 'Skedari nuk është valid (upload).'];
  }

  $safe = safe_filename($name);
  // ruaj extension nëse ekziston; nëse jo, vendos .bin
  $ext  = strtolower(pathinfo($safe, PATHINFO_EXTENSION));
  if ($ext === '') {
    $safe .= '.bin';
  }

  $newName = time() . '_' . $prefix . '_' . bin2hex(random_bytes(6)) . '_' . $safe;
  $abs     = rtrim($uploadAbs, '/') . '/' . $newName;
  $rel     = rtrim($uploadRel, '/') . '/' . $newName;

  if (!@move_uploaded_file($tmp, $abs)) {
    return [null, 'Dështoi ruajtja e skedarit.'];
  }

  return [$rel, null];
}

/**
 * Përpunon fotot e përshkrimit të detyrës.
 *
 * Markdown vendos:
 *   ![alt](#IMG1), ![alt](#IMG2), ...
 *
 * Kjo funksion:
 *   - ngarkon imazhet në: uploads/images/assignments/<assignment_id>/
 *   - zëvendëson #IMG1, #IMG2, ... me path real relativ
 *   - fshin çdo ![](#IMGn) që nuk ka skedar real.
 */
function process_content_images_for_assignment(int $assignmentId, string $description, array $images): string {
  if (empty($images['name']) || !is_array($images['name'])) {
    return $description;
  }

  $rootAbs = __DIR__ . '/../uploads/images/assignments';
  $rootRel = 'uploads/images/assignments';

  ensure_dir($rootAbs);

  $assDirAbs = $rootAbs . '/' . $assignmentId;
  ensure_dir($assDirAbs);

  $finfo        = new finfo(FILEINFO_MIME_TYPE);
  $allowedMimes = ['image/jpeg','image/png','image/gif','image/webp'];
  $maxBytes     = 8 * 1024 * 1024; // 8MB për foto (mbaje për siguri)

  $count    = count($images['name']);
  $imgIndex = 0;

  for ($i = 0; $i < $count; $i++) {
    if (($images['error'][$i] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) continue;
    if (!is_uploaded_file($images['tmp_name'][$i] ?? '')) continue;

    $size = (int)($images['size'][$i] ?? 0);
    if ($size <= 0 || $size > $maxBytes) continue;

    $tmp  = (string)$images['tmp_name'][$i];
    $mime = $finfo->file($tmp) ?: '';
    if (!in_array($mime, $allowedMimes, true)) continue;

    $origName = (string)($images['name'][$i] ?? 'image');
    $ext      = strtolower(pathinfo($origName, PATHINFO_EXTENSION));
    if ($ext === '') {
      if     ($mime === 'image/jpeg') $ext = 'jpg';
      elseif ($mime === 'image/png')  $ext = 'png';
      elseif ($mime === 'image/gif')  $ext = 'gif';
      elseif ($mime === 'image/webp') $ext = 'webp';
      else                            $ext = 'jpg';
    }

    $safeName = preg_replace('/[^A-Za-z0-9._-]/', '_', basename($origName)) ?: ('img_'.$assignmentId.'.'.$ext);
    if (strtolower(pathinfo($safeName, PATHINFO_EXTENSION)) !== $ext) {
      $safeName .= '.' . $ext;
    }

    $newName = time() . '_' . bin2hex(random_bytes(3)) . '_' . $safeName;
    $abs = $assDirAbs . '/' . $newName;
    $rel = $rootRel . '/' . $assignmentId . '/' . $newName;

    if (!move_uploaded_file($tmp, $abs)) continue;

    $imgIndex++;
    $placeholder = '#IMG' . $imgIndex;
    $description = str_replace($placeholder, $rel, $description);
  }

  $description = preg_replace('/!\[[^\]]*]\(#IMG\d+\)/', '', (string)$description);
  return (string)$description;
}

/* -------------------- CSRF -------------------- */
if (empty($_SESSION['csrf_token'])) {
  $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$CSRF = (string)$_SESSION['csrf_token'];

/* -------------------- Inputs bazë ------------- */
if (!isset($_GET['course_id']) || (string)$_GET['course_id'] === '') {
  http_response_code(400);
  exit('Kursi nuk është specifikuar.');
}
$course_id       = (int)$_GET['course_id'];
$pref_section_id = isset($_GET['section_id']) ? (int)$_GET['section_id'] : 0;

/* --- Verifiko kursin + të drejtën e instruktorit për ta edituar --- */
try {
  $stmtC = $pdo->prepare("
    SELECT c.*, u.full_name AS creator_name, u.id AS creator_id
    FROM courses c
    LEFT JOIN users u ON u.id = c.id_creator
    WHERE c.id = ?
    LIMIT 1
  ");
  $stmtC->execute([$course_id]);
  $course = $stmtC->fetch(PDO::FETCH_ASSOC);
  if (!$course) {
    exit('Kursi nuk u gjet.');
  }
  if ($ROLE === 'Instruktor' && (int)$course['creator_id'] !== $ME_ID) {
    http_response_code(403);
    exit('Nuk keni të drejta për këtë kurs.');
  }
} catch (PDOException $e) {
  http_response_code(500);
  exit('Gabim: ' . h($e->getMessage()));
}

/* -------------------- Sections dropdown -------- */
try {
  $stmtS = $pdo->prepare("
    SELECT id, title
    FROM sections
    WHERE course_id = ?
    ORDER BY position ASC, id ASC
  ");
  $stmtS->execute([$course_id]);
  $sections = $stmtS->fetchAll(PDO::FETCH_ASSOC) ?: [];
} catch (PDOException $e) {
  $sections = [];
}

$sectionsById = [];
foreach ($sections as $s) {
  $sectionsById[(int)$s['id']] = $s;
}

/* Seksioni i lock-uar nëse vjen nga URL */
$lock_section = false;
if ($pref_section_id > 0) {
  if (!isset($sectionsById[$pref_section_id])) {
    $pref_section_id = 0;
  } else {
    $lock_section = true;
  }
}

/* -------------------- Defaults ----------------- */
$errors      = [];
$title       = '';
$description = '';
$due_date    = '';
$section_id  = $pref_section_id > 0 ? $pref_section_id : 0;

/* -------------------- Upload config ------------- */
$uploadDirAbs = __DIR__ . '/../uploads/assignments';
$uploadDirRel = 'uploads/assignments';
ensure_dir($uploadDirAbs);

/* -------------------- POST --------------------- */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  if (!isset($_POST['csrf']) || !hash_equals($CSRF, (string)$_POST['csrf'])) {
    $errors[] = 'Seancë e pavlefshme (CSRF). Ringarko faqen.';
  }

  $title       = trim((string)($_POST['title'] ?? ''));
  $description = (string)($_POST['description'] ?? '');
  $section_id  = $lock_section ? $pref_section_id : (int)($_POST['section_id'] ?? 0);
  $due_date_in = trim((string)($_POST['due_date'] ?? ''));

  if ($title === '' || mb_strlen($title) < 3) {
    $errors[] = 'Titulli i detyrës është i detyrueshëm (min 3 karaktere).';
  }

  if ($section_id > 0 && !isset($sectionsById[$section_id])) {
    $errors[] = 'Seksioni i zgjedhur nuk i përket këtij kursi.';
  }

  $due_date_db = null;
  if ($due_date_in !== '') {
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $due_date_in)) {
      $errors[] = 'Formati i datës së afatit është i pavlefshëm.';
    } else {
      $due_date_db = $due_date_in;
      $due_date    = $due_date_in;
    }
  }

  // ✅ Pa kufizime tipi/skedari për resource_file & solution_file
  $resource_rel = null;
  $solution_rel = null;

  if (!$errors) {
    [$resource_rel, $errRes] = save_any_upload($_FILES['resource_file'] ?? [], $uploadDirAbs, $uploadDirRel, 'res');
    if ($errRes) $errors[] = $errRes;

    [$solution_rel, $errSol] = save_any_upload($_FILES['solution_file'] ?? [], $uploadDirAbs, $uploadDirRel, 'sol');
    if ($errSol) $errors[] = $errSol;
  }

  if (!$errors) {
    try {
      $pdo->beginTransaction();

      $stmt = $pdo->prepare("
        INSERT INTO assignments (
          course_id, section_id, title, description, due_date,
          resource_path, solution_path, uploaded_at
        ) VALUES (?,?,?,?,?,?,?, NOW())
      ");
      $stmt->execute([
        $course_id,
        $section_id ?: null,
        $title,
        $description, // përmbajtja do të përditësohet pas fotove
        $due_date_db,
        $resource_rel,
        $solution_rel
      ]);

      $aid = (int)$pdo->lastInsertId();

      // Përpunimi i fotove të përshkrimit (assignment_images[] nga block editor)
      if (!empty($_FILES['assignment_images']) && !empty($_FILES['assignment_images']['name'][0])) {
        try {
          $descriptionFinal = process_content_images_for_assignment(
            $aid,
            $description,
            $_FILES['assignment_images']
          );
          if ($descriptionFinal !== $description) {
            $stmtUpd = $pdo->prepare("UPDATE assignments SET description = ? WHERE id = ?");
            $stmtUpd->execute([$descriptionFinal, $aid]);
            $description = $descriptionFinal;
          }
        } catch (Throwable $e) {}
      }

      // AUTO-LINK në section_items që detyra të shfaqet në outline
      $sidForSI = $section_id ?: 0;

      $stmtPos = $pdo->prepare("
        SELECT COALESCE(MAX(position),0)+1
        FROM section_items
        WHERE course_id = ? AND section_id = ?
      ");
      $stmtPos->execute([$course_id, $sidForSI]);
      $nextPos = (int)$stmtPos->fetchColumn();

      $stmtSI = $pdo->prepare("
        INSERT INTO section_items (course_id, section_id, item_type, item_ref_id, position)
        VALUES (?,?,?,?,?)
      ");
      $stmtSI->execute([$course_id, $sidForSI, 'ASSIGNMENT', $aid, $nextPos]);

      $pdo->commit();

      $_SESSION['flash'] = ['msg'=>'Detyra u shtua me sukses.', 'type'=>'success'];
      header('Location: ../course_details.php?course_id=' . $course_id . '&tab=materials');
      exit;
    } catch (Throwable $e) {
      if ($pdo->inTransaction()) $pdo->rollBack();
      $errors[] = 'Gabim gjatë ruajtjes: ' . h($e->getMessage());
    }
  }
}
?>
<!DOCTYPE html>
<html lang="sq" data-bs-theme="light">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <title>Shto Detyrë — kurseinformatike.com</title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
  <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css" rel="stylesheet">
  <link rel="stylesheet" href="../css/km-lms-forms.css">
  <link rel="icon" href="image/favicon.ico" type="image/x-icon" />
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
          <span class="km-breadcrumb-current">Detyrë e re</span>
        </div>
        <h1 class="km-page-title">
          <i class="fa-solid fa-clipboard-list me-2 text-primary"></i>
          Shto detyrë
        </h1>
        <p class="km-page-subtitle mb-0">
          Krijo një detyrë për studentët e kursit <strong><?= h((string)($course['title'] ?? '')) ?></strong>.
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

    <form
      method="POST"
      enctype="multipart/form-data"
      class="row g-4 km-form-grid"
      action="<?= h($_SERVER['PHP_SELF']) . '?course_id=' . (int)$course_id . ($pref_section_id ? '&section_id=' . (int)$pref_section_id : '') ?>"
    >
      <input type="hidden" name="csrf" value="<?= h($CSRF) ?>">

      <!-- Majtas -->
      <div class="col-12 col-lg-8">
        <section class="km-card km-card-main mb-3">
          <div class="km-card-header">
            <div>
              <h2 class="km-card-title">
                <span class="km-step-badge">1</span>
                Detajet e detyrës
              </h2>
              <p class="km-card-subtitle mb-0">
                Përshkruaj qartë çfarë duhet të bëjnë studentët.
              </p>
            </div>
          </div>

          <div class="km-card-body">
            <div class="mb-3">
              <label class="form-label">Titulli i detyrës</label>
              <input
                type="text"
                name="title"
                class="form-control"
                required
                value="<?= h($title) ?>"
                placeholder="p.sh. Ushtrime me cikle for/while"
              >
              <div class="km-help-text mt-1">
                Shfaqet në listën e materialeve dhe në njoftime.
              </div>
            </div>

            <div class="mb-3">
              <label class="form-label">Përshkrimi i detyrës</label>

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
                          placeholder="Markdown: ## tituj, ```code```, ![alt](#IMG1) ..."><?= h($description) ?></textarea>

                <div class="km-help-text mt-2">
                  Për foto, shto bllokun <strong>Foto</strong>; sistemi do të vendosë automatikisht
                  <code>![Alt](#IMG1)</code>, <code>![Alt](#IMG2)</code>... dhe do t’i lidhë me skedarët.
                </div>
              </div>

              <textarea id="description" name="description" class="d-none"><?= h($description) ?></textarea>
              <input type="hidden" id="blocks_json" name="blocks_json" value="">
            </div>

            <div class="row g-3">
              <div class="col-md-6">
                <label class="form-label">Seksioni</label>

                <?php if ($lock_section && $section_id > 0 && isset($sectionsById[$section_id])): ?>
                  <input type="hidden" name="section_id" value="<?= (int)$section_id ?>">
                  <input type="text" class="form-control" value="<?= h((string)$sectionsById[$section_id]['title']) ?>" readonly>
                  <div class="km-help-text mt-1">Seksioni u zgjodh nga struktura e kursit.</div>
                <?php else: ?>
                  <select name="section_id" class="form-select">
                    <option value="0">— Jashtë seksioneve —</option>
                    <?php foreach ($sections as $s): ?>
                      <option value="<?= (int)$s['id'] ?>" <?= $section_id === (int)$s['id'] ? 'selected' : '' ?>>
                        <?= h((string)$s['title']) ?>
                      </option>
                    <?php endforeach; ?>
                  </select>
                  <div class="km-help-text mt-1">
                    Zgjidh modulën ku kjo detyrë do të shfaqet në outline të kursit.
                  </div>
                <?php endif; ?>
              </div>

              <div class="col-md-6">
                <label class="form-label">Afati i dorëzimit (opsional)</label>
                <input type="date" name="due_date" class="form-control" value="<?= h($due_date) ?>">
                <div class="km-help-text mt-1">
                  Afati mund të përdoret për njoftime, renditje dhe shfaqje tek studenti.
                </div>
              </div>
            </div>

          </div>
        </section>
      </div>

      <!-- Djathtas -->
      <div class="col-12 col-lg-4">

        <aside class="km-card km-card-side km-sticky-side">
          <div class="km-card-header">
            <div>
              <h2 class="km-card-title">
                <span class="km-step-badge">2</span>
                Skedarët e detyrës
              </h2>
              <p class="km-card-subtitle mb-0">
                Shto skedarin e enonciatit dhe (nëse dëshiron) skedarin e zgjidhjes.
              </p>
            </div>
          </div>

          <div class="km-card-body">
            <div class="mb-3">
              <label class="form-label">Skedari i detyrës (opsional)</label>
              <!-- ✅ pa accept (lejon çdo tip) -->
              <input class="form-control" type="file" name="resource_file">
              <div class="km-help-text mt-1">
                Enonc, dataset, projekt etj. Studentët do ta shkarkojnë këtë skedar për ta zgjidhur detyrën.
                (Kufizimet reale varen nga serveri/PHP.)
              </div>
            </div>

            <div class="mb-3">
              <label class="form-label">Skedari i zgjidhjes (opsional)</label>
              <!-- ✅ pa accept (lejon çdo tip) -->
              <input class="form-control" type="file" name="solution_file">
              <div class="km-help-text mt-1">
                Mund ta shfaqësh më vonë tek studenti (p.sh. pas afatit), sipas logjikës në faqen e detajeve.
                (Kufizimet reale varen nga serveri/PHP.)
              </div>
            </div>

            <div class="km-help-text">
              Fotot brenda enonciatit shtohen nga blloqet <strong>Foto</strong> në editorin më sipër.
            </div>
          </div>
        </aside>

        <!-- ✅ Butonat jashtë kutisë së skedarëve -->
        <div class="d-grid gap-2 mt-3">
          <button class="btn btn-primary km-btn-pill" type="submit">
            <i class="fa-regular fa-floppy-disk me-1"></i>
            Ruaj detyrën
          </button>
          <a href="../course_details.php?course_id=<?= (int)$course_id ?>&tab=materials"
             class="btn btn-outline-secondary km-btn-pill">
            <i class="fa-solid fa-arrow-left-long me-1"></i>
            Anulo dhe kthehu mbrapa
          </a>
        </div>

      </div>
    </form>

  </div>
</div>

<?php include __DIR__ . '/../footer2.php'; ?>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script>
  const blockList        = document.getElementById('km-block-list');
  const mdEditor         = document.getElementById('km-markdown-editor');
  const blocksJsonInput  = document.getElementById('blocks_json');
  let   currentView      = 'normal';
  let   viewTabs         = [];

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
      body.innerHTML = `
        <div class="mb-2">
          <label class="form-label small mb-1">Titulli / alt</label>
          <input type="text" class="form-control form-control-sm km-block-alt" placeholder="p.sh. Diagramë e algoritmit">
        </div>
        <div>
          <label class="form-label small mb-1">Skedari i fotos</label>
          <input type="file"
                 class="form-control form-control-sm km-block-image-input"
                 name="assignment_images[]"
                 accept="image/*">
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
    if (!list.length) { blockList.appendChild(createBlock('p')); return; }

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

      let blocks = [];
      if (currentView === 'markdown' && mdEditor) {
        blocks = markdownToBlocks(mdEditor.value || '');
      } else {
        blocks = readBlocksFromDOM();
      }

      const mdFinal = blocksToMarkdown(blocks);

      if (descHidden) descHidden.value = mdFinal.trim();
      if (blocksJsonInput) blocksJsonInput.value = JSON.stringify(blocks);
    });
  }
</script>
</body>
</html>
