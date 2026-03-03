<?php
// edit_assignment.php — Modifiko Detyrën me block-editor (Normal/Markdown) + foto në përshkrim
// Layout / stil / CSS identik me add_assignment.php
declare(strict_types=1);
if (session_status() === PHP_SESSION_NONE) { session_start(); }
require_once __DIR__ . '/../lib/database.php';

/* -------------------- Helpers ----------------- */
function h(?string $s): string {
  return htmlspecialchars($s ?? '', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}
function ensure_dir(string $dir): void {
  if (!is_dir($dir)) {
    @mkdir($dir, 0775, true);
  }
}

function fail_redirect(string $msg, string $url): never {
  $_SESSION['flash'] = ['msg'=>$msg, 'type'=>'danger'];
  header('Location: ../' . $url);
  exit;
}

/**
 * Përpunon fotot e përshkrimit të detyrës (E NJËJTA si te add_assignment.php).
 *
 * Në Markdown vijnë:
 *   ![alt](#IMG1), ![alt](#IMG2), ...
 *
 * Kjo funksion:
 *   - ngarkon imazhet në: uploads/images/assignments/<assignment_id>/
 *   - zëvendëson #IMG1, #IMG2, ... me path real relativ, p.sh.
 *       uploads/images/assignments/15/foto1.jpg
 *   - fshin çdo rresht Markdown me ![](#IMGn) që nuk u korrespondon skedarëve realë.
 */
function process_content_images_for_assignment(int $assignmentId, string $description, array $images): string {
  if (empty($images['name']) || !is_array($images['name'])) {
    return $description;
  }

  // uploads/images/assignments/<assignment_id>/
  $rootAbs = __DIR__ . '/../uploads/images/assignments';
  $rootRel = 'uploads/images/assignments';

  ensure_dir($rootAbs);

  $assDirAbs = $rootAbs . '/' . $assignmentId;
  ensure_dir($assDirAbs);

  $finfo        = new finfo(FILEINFO_MIME_TYPE);
  $allowedMimes = ['image/jpeg','image/png','image/gif','image/webp'];
  $maxBytes     = 8 * 1024 * 1024; // 8MB për foto

  $count    = count($images['name']);
  $imgIndex = 0;

  for ($i = 0; $i < $count; $i++) {
    if ($images['error'][$i] !== UPLOAD_ERR_OK) {
      continue;
    }
    if (!is_uploaded_file($images['tmp_name'][$i])) {
      continue;
    }

    $size = (int)($images['size'][$i] ?? 0);
    if ($size <= 0 || $size > $maxBytes) {
      continue;
    }

    $mime = $finfo->file($images['tmp_name'][$i]) ?: '';
    if (!in_array($mime, $allowedMimes, true)) {
      continue;
    }

    $origName = (string)$images['name'][$i];
    $ext      = strtolower(pathinfo($origName, PATHINFO_EXTENSION));
    if ($ext === '') {
      if     ($mime === 'image/jpeg') $ext = 'jpg';
      elseif ($mime === 'image/png')  $ext = 'png';
      elseif ($mime === 'image/gif')  $ext = 'gif';
      elseif ($mime === 'image/webp') $ext = 'webp';
      else                            $ext = 'jpg';
    }

    $safeName = preg_replace('/[^A-Za-z0-9._-]/', '_', $origName) ?: ('img_'.$assignmentId);
    $newName  = time() . '_' . bin2hex(random_bytes(3)) . '_' . $safeName;

    $abs = $assDirAbs . '/' . $newName;
    $rel = $rootRel . '/' . $assignmentId . '/' . $newName;

    if (!move_uploaded_file($images['tmp_name'][$i], $abs)) {
      continue;
    }

    $imgIndex++;
    $placeholder = '#IMG' . $imgIndex;

    $description = str_replace($placeholder, $rel, $description);
  }

  // Fshi çdo bllok Markdown që ende ka placeholder #IMGn (nuk u shoqërua me skedar real)
  $description = preg_replace('/!\[[^\]]*]\(#IMG\d+\)/', '', $description);

  return $description;
}

/* -------------------- RBAC -------------------- */
if (!isset($_SESSION['user']) || !in_array(($_SESSION['user']['role'] ?? ''), ['Administrator','Instruktor'], true)) {
  header('Location: ../login.php');
  exit;
}
$ROLE  = (string)$_SESSION['user']['role'];
$ME_ID = (int)($_SESSION['user']['id'] ?? 0);

/* -------------------- CSRF -------------------- */
if (empty($_SESSION['csrf_token'])) {
  $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$CSRF = $_SESSION['csrf_token'];

/* -------------------- Parametri hyrës ---------- */
if (!isset($_GET['assignment_id']) || !ctype_digit((string)$_GET['assignment_id'])) {
  http_response_code(400);
  fail_redirect('Detyra nuk është specifikuar.', '../course.php');
}
$assignment_id = (int)$_GET['assignment_id'];

/* -------------------- Lexo detyrën + kursin ---- */
try {
  $stmt = $pdo->prepare("
    SELECT 
      a.*,
      c.id         AS course_id,
      c.title      AS course_title,
      c.id_creator AS course_creator_id,
      u.full_name  AS creator_name
    FROM assignments a
    JOIN courses c ON c.id = a.course_id
    LEFT JOIN users u ON u.id = c.id_creator
    WHERE a.id = ?
    LIMIT 1
  ");
  $stmt->execute([$assignment_id]);
  $row = $stmt->fetch(PDO::FETCH_ASSOC);
  if (!$row) {
    fail_redirect('Detyra nuk u gjet.', '../course.php');
  }

  // Instruktori lejohet vetëm nëse është krijues i kursit
  if ($ROLE === 'Instruktor' && (int)$row['course_creator_id'] !== $ME_ID) {
    http_response_code(403);
    fail_redirect('Nuk keni të drejta për të modifikuar këtë detyrë.', '../course.php');
  }
} catch (PDOException $e) {
  http_response_code(500);
  fail_redirect('Gabim: ' . h($e->getMessage()), '../course.php');
}

$course_id = (int)$row['course_id'];
$course = [
  'id'           => $course_id,
  'title'        => (string)$row['course_title'],
  'creator_id'   => (int)$row['course_creator_id'],
  'creator_name' => (string)($row['creator_name'] ?? ''),
];

$title        = (string)($row['title'] ?? '');
$description  = (string)($row['description'] ?? '');
$due_date     = (string)($row['due_date'] ?? '');
$due_date     = $due_date !== '' ? substr($due_date, 0, 10) : '';
$section_id   = isset($row['section_id']) ? (int)$row['section_id'] : 0;
$resource_path = (string)($row['resource_path'] ?? '');
$solution_path = (string)($row['solution_path'] ?? '');

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

/* -------------------- Upload config ------------- */
/**
 * KËTU NUK KEMI KUFIZIM TIPI DHE MADHËSIE PËR SKEDARËT E RESOURCET/SOLUTION.
 * (Limiti real do të jetë ai i PHP/nginx/Apache).
 */
$uploadDirAbs = __DIR__ . '/../uploads/assignments';
$uploadDirRel = 'uploads/assignments';
ensure_dir($uploadDirAbs);
$uploadsRootAbs = dirname(__DIR__); // për fshirjen e skedarëve ekzistues

$errors = [];

/* -------------------- POST --------------------- */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  // CSRF
  if (!isset($_POST['csrf']) || !hash_equals($CSRF, (string)$_POST['csrf'])) {
    $errors[] = 'Seancë e pavlefshme (CSRF). Ringarko faqen.';
  }

  $title       = trim((string)($_POST['title'] ?? ''));
  $description = (string)($_POST['description'] ?? '');
  $section_id  = (int)($_POST['section_id'] ?? 0);
  $due_date_in = trim((string)($_POST['due_date'] ?? ''));
  $due_date    = $due_date_in;

  $remove_resource = !empty($_POST['remove_resource']);
  $remove_solution = !empty($_POST['remove_solution']);

  // Validime bazë
  if ($title === '' || mb_strlen($title) < 3) {
    $errors[] = 'Titulli i detyrës është i detyrueshëm (min 3 karaktere).';
  }

  if ($due_date_in !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $due_date_in)) {
    $errors[] = 'Formati i datës së afatit është i pavlefshëm.';
  }

  if ($section_id !== 0 && !isset($sectionsById[$section_id])) {
    $errors[] = 'Ju lutem zgjidhni një seksion të vlefshëm të këtij kursi.';
  }

  // Hiq skedarët ekzistues nëse është zgjedhur flag-u "remove"
  if ($remove_resource && $resource_path !== '') {
    $old = $uploadsRootAbs . '/' . ltrim($resource_path, '/');
    if (is_file($old)) { @unlink($old); }
    $resource_path = '';
  }
  if ($remove_solution && $solution_path !== '') {
    $old = $uploadsRootAbs . '/' . ltrim($solution_path, '/');
    if (is_file($old)) { @unlink($old); }
    $solution_path = '';
  }

  // Upload i ri për skedarin e detyrës — PA kufizim tipi/madhësie nga ne
  if (isset($_FILES['resource_file']) && ($_FILES['resource_file']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
    $err  = (int)$_FILES['resource_file']['error'];
    $tmp  = (string)($_FILES['resource_file']['tmp_name'] ?? '');
    $name = (string)($_FILES['resource_file']['name'] ?? '');

    if ($err === UPLOAD_ERR_OK && $tmp !== '' && is_uploaded_file($tmp)) {
      $base = preg_replace('/[^A-Za-z0-9._-]/', '_', pathinfo($name, PATHINFO_FILENAME));
      if ($base === '' || $base === '.' || $base === '..') {
        $base = 'resource';
      }
      $ext  = pathinfo($name, PATHINFO_EXTENSION);
      $ext  = $ext !== '' ? ('.' . $ext) : '';
      $newName = time() . '_res_' . bin2hex(random_bytes(5)) . '_' . $base . $ext;

      $abs = $uploadDirAbs . '/' . $newName;
      $rel = $uploadDirRel . '/' . $newName;

      if (!@move_uploaded_file($tmp, $abs)) {
        $errors[] = 'Dështoi ruajtja e skedarit të detyrës.';
      } else {
        // fshi skedarin e vjetër nëse ekziston
        if ($resource_path !== '') {
          $old = $uploadsRootAbs . '/' . ltrim($resource_path, '/');
          if (is_file($old)) { @unlink($old); }
        }
        $resource_path = $rel;
      }
    } else {
      $errors[] = 'Ngarkimi i skedarit të detyrës dështoi.';
    }
  }

  // Upload i ri për skedarin e zgjidhjes — PA kufizim tipi/madhësie
  if (isset($_FILES['solution_file']) && ($_FILES['solution_file']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
    $err  = (int)$_FILES['solution_file']['error'];
    $tmp  = (string)($_FILES['solution_file']['tmp_name'] ?? '');
    $name = (string)($_FILES['solution_file']['name'] ?? '');

    if ($err === UPLOAD_ERR_OK && $tmp !== '' && is_uploaded_file($tmp)) {
      $base = preg_replace('/[^A-Za-z0-9._-]/', '_', pathinfo($name, PATHINFO_FILENAME));
      if ($base === '' || $base === '.' || $base === '..') {
        $base = 'solution';
      }
      $ext  = pathinfo($name, PATHINFO_EXTENSION);
      $ext  = $ext !== '' ? ('.' . $ext) : '';
      $newName = time() . '_sol_' . bin2hex(random_bytes(5)) . '_' . $base . $ext;

      $abs = $uploadDirAbs . '/' . $newName;
      $rel = $uploadDirRel . '/' . $newName;

      if (!@move_uploaded_file($tmp, $abs)) {
        $errors[] = 'Dështoi ruajtja e skedarit të zgjidhjes.';
      } else {
        if ($solution_path !== '') {
          $old = $uploadsRootAbs . '/' . ltrim($solution_path, '/');
          if (is_file($old)) { @unlink($old); }
        }
        $solution_path = $rel;
      }
    } else {
      $errors[] = 'Ngarkimi i skedarit të zgjidhjes dështoi.';
    }
  }

  // Përpunimi i fotove në përshkrim (assignment_images[] nga block-editor)
  $descriptionForDb = $description;
  if (!$errors && !empty($_FILES['assignment_images']) && !empty($_FILES['assignment_images']['name'][0])) {
    try {
      $processed = process_content_images_for_assignment(
        $assignment_id,
        $description,
        $_FILES['assignment_images']
      );
      if ($processed !== $description) {
        $descriptionForDb = $processed;
        $description      = $processed;
      }
    } catch (Throwable $e) {
      // Opsionale: error_log('assignment_images edit: ' . $e->getMessage());
    }
  }

  // UPDATE në DB
  if (!$errors) {
    try {
      $stmtUpd = $pdo->prepare("
        UPDATE assignments
           SET title         = ?,
               description   = ?,
               due_date      = ?,
               section_id    = ?,
               resource_path = ?,
               solution_path = ?,
               updated_at    = NOW()
         WHERE id = ?
      ");

      $stmtUpd->execute([
        $title,
        $descriptionForDb,
        $due_date !== '' ? $due_date : null,
        $section_id ?: null,
        $resource_path !== '' ? $resource_path : null,
        $solution_path !== '' ? $solution_path : null,
        $assignment_id
      ]);

      $_SESSION['flash'] = ['msg'=>'Detyra u përditësua me sukses.', 'type'=>'success'];
      header('Location: ../course_details.php?course_id=' . $course_id . '&tab=materials');
      exit;
    } catch (Throwable $e) {
      $errors[] = 'Gabim gjatë përditësimit: ' . h($e->getMessage());
    }
  }
}
?>
<!DOCTYPE html>
<html lang="sq" data-bs-theme="light">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <title>Modifiko Detyrë — kurseinformatike.com</title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
  <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css" rel="stylesheet">
  <!-- I njëjti stil si add_assignment.php -->
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

    <!-- Header i faqes (si add_assignment, por për edit) -->
    <header class="km-page-header d-flex flex-column flex-md-row justify-content-between align-items-start align-items-md-center gap-3">
      <div>
        <div class="km-breadcrumb small mb-1">
          <a href="../courses.php" class="km-breadcrumb-link">
            <i class="fa-solid fa-layer-group me-1"></i>Kursët
          </a>
          <span class="mx-1">/</span>
          <a href="../course_details.php?course_id=<?= (int)$course_id ?>&tab=materials" class="km-breadcrumb-link">
            <?= h($course['title']) ?>
          </a>
          <span class="mx-1">/</span>
          <span class="km-breadcrumb-current">Modifiko detyrë</span>
        </div>
        <h1 class="km-page-title">
          <i class="fa-solid fa-clipboard-list me-2 text-primary"></i>
          Modifiko detyrë
        </h1>
        <p class="km-page-subtitle mb-0">
          Ndrysho detyrën ekzistuese për kursin <strong><?= h($course['title']) ?></strong>.
        </p>
      </div>

      <div class="d-flex flex-column align-items-md-end gap-2">
        <div class="km-pill-meta">
          <i class="fa-solid fa-user-tie me-1"></i>
          Instruktor: <?= h($course['creator_name'] ?? '') ?>
        </div>
        <a href="../course_details.php?course_id=<?= (int)$course_id ?>&tab=materials"
           class="btn btn-outline-secondary btn-sm km-btn-pill">
          <i class="fa-solid fa-arrow-left-long me-1"></i>
          Kthehu te kursi
        </a>
      </div>
    </header>

    <?php if ($errors): ?>
      <div class="km-alert km-alert-danger mb-3">
        <div class="d-flex align-items-start gap-2">
          <i class="fa-solid fa-triangle-exclamation mt-1"></i>
          <div>
            <div class="fw-semibold mb-1">Gabime gjatë ruajtjes:</div>
            <ul class="mb-0">
              <?php foreach ($errors as $e): ?>
                <li><?= h($e) ?></li>
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
      action="<?= h($_SERVER['PHP_SELF']) . '?assignment_id=' . $assignment_id ?>"
    >
      <input type="hidden" name="csrf" value="<?= h($CSRF) ?>">

      <!-- Kolona kryesore (majtas) -->
      <div class="col-12 col-lg-8">
        <!-- Karta: Detajet -->
        <section class="km-card km-card-main mb-3">
          <div class="km-card-header">
            <div>
              <h2 class="km-card-title">
                <span class="km-step-badge">1</span>
                Detajet e detyrës
              </h2>
              <p class="km-card-subtitle mb-0">
                Përditëso titullin dhe enonciatin e detyrës, në stilin e një enunciati në Moodle/Udemy.
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
                Shfaqet në listën e materialeve, njoftime dhe tek studenti.
              </div>
            </div>

            <div class="mb-3">
              <label class="form-label">Përshkrimi i detyrës</label>

              <!-- Block editor (Normal / Markdown) si te add_assignment.php -->
              <div id="km-block-editor" class="km-block-editor">
                <div class="km-editor-header d-flex flex-wrap justify-content-between align-items-center mb-2">
                  <!-- Toolbar blloqesh (Normal View) -->
                  <div class="km-block-toolbar">
                    <button type="button"
                            class="btn btn-outline-secondary btn-sm km-btn-pill km-add-block"
                            data-type="p">
                      <i class="fa-regular fa-paragraph me-1"></i> Paragraf
                    </button>
                    <button type="button"
                            class="btn btn-outline-secondary btn-sm km-btn-pill km-add-block"
                            data-type="h2">
                      <i class="fa-solid fa-heading me-1"></i> Titull
                    </button>
                    <button type="button"
                            class="btn btn-outline-secondary btn-sm km-btn-pill km-add-block"
                            data-type="img">
                      <i class="fa-regular fa-image me-1"></i> Foto
                    </button>
                    <button type="button"
                            class="btn btn-outline-secondary btn-sm km-btn-pill km-add-block"
                            data-type="code">
                      <i class="fa-solid fa-code me-1"></i> Kod
                    </button>
                  </div>

                  <!-- Toggle Normal / Markdown -->
                  <div class="km-editor-view-toggle">
                    <button type="button"
                            class="btn km-editor-tab active"
                            data-view="normal">
                      <i class="fa-regular fa-rectangle-list me-1"></i> Normal View
                    </button>
                    <button type="button"
                            class="btn km-editor-tab"
                            data-view="markdown">
                      <i class="fa-solid fa-code me-1"></i> Markdown View
                    </button>
                  </div>
                </div>

                <!-- Normal view: lista e blloqeve -->
                <div id="km-block-list" class="km-block-list">
                  <!-- Blloqet mbushen me JavaScript -->
                </div>

                <!-- Markdown view: textarea -->
                <textarea id="km-markdown-editor"
                          class="form-control d-none mt-2"
                          rows="8"
                          placeholder="Shkruaj përmbajtjen e detyrës në Markdown (paragrafë, ## tituj, ```code```, ![alt](#IMG1), etj.)"><?= h($description) ?></textarea>

                <div class="km-help-text mt-2">
                  Shto blloqe (tekst, titull, foto, kod) ose përdor <strong>Markdown View</strong> për të shkruar drejtpërdrejt në Markdown.
                  Për fotot ekzistuese, ato do të shfaqen si blloqe <em>Foto</em>; për fotot e reja, shto një bllok <em>Foto</em>, zgjidh skedarin
                  dhe sistemi do të vendosë automatikisht <code>![Alt](#IMG1)</code>, <code>![Alt](#IMG2)</code>, ...
                </div>
              </div>

              <!-- Markdown final që shkon në DB -->
              <textarea id="description" name="description" class="d-none"><?= h($description) ?></textarea>
              <input type="hidden" id="blocks_json" name="blocks_json" value="">
            </div>

            <div class="row g-3">
              <div class="col-md-6">
                <label class="form-label">Seksioni</label>
                <select name="section_id" class="form-select">
                  <option value="0">— Jashtë seksioneve —</option>
                  <?php foreach ($sections as $s): ?>
                    <option value="<?= (int)$s['id'] ?>" <?= $section_id === (int)$s['id'] ? 'selected' : '' ?>>
                      <?= h($s['title']) ?>
                    </option>
                  <?php endforeach; ?>
                </select>
                <div class="km-help-text mt-1">
                  Zgjidh modulën ku kjo detyrë do të shfaqet në outline të kursit.
                </div>
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

      <!-- Sidebar: Skedarët (djathtas) -->
      <div class="col-12 col-lg-4">
        <aside class="km-card km-card-side km-sticky-side">
          <div class="km-card-header">
            <div>
              <h2 class="km-card-title">
                <span class="km-step-badge">2</span>
                Skedarët e detyrës
              </h2>
              <p class="km-card-subtitle mb-0">
                Menaxho skedarin e enonciatit dhe (nëse dëshiron) skedarin e zgjidhjes.
              </p>
            </div>
          </div>
          <div class="km-card-body">

            <div class="mb-3">
              <label class="form-label">Skedari i detyrës (opsional)</label>

              <?php if ($resource_path): ?>
                <div class="d-flex align-items-center justify-content-between km-file-current mb-1">
                  <div class="text-truncate">
                    <i class="fa-regular fa-file-lines me-1"></i>
                    <?= h(basename($resource_path)) ?>
                  </div>
                  <a href="../<?= h($resource_path) ?>" target="_blank"
                     class="btn btn-outline-secondary btn-sm km-btn-pill">
                    <i class="fa-solid fa-up-right-from-square"></i>
                  </a>
                </div>
                <div class="form-check mt-1 mb-2">
                  <input class="form-check-input" type="checkbox" id="remove_resource" name="remove_resource" value="1">
                  <label for="remove_resource" class="form-check-label small">
                    Hiqe skedarin ekzistues
                  </label>
                </div>
              <?php else: ?>
                <div class="km-help-text mt-1">
                  Aktualisht nuk ka skedar të ngarkuar për këtë detyrë.
                </div>
              <?php endif; ?>

              <!-- KETU NUK VENDOSIM "accept" DHE NUK FLASIM PËR MAX 25MB -->
              <input
                class="form-control mt-2"
                type="file"
                name="resource_file"
              >
              <div class="km-help-text mt-1">
                Mund të ngarkosh çdo lloj skedari (limiti real varet nga konfigurimi i serverit).
              </div>
            </div>

            <div class="mb-3">
              <label class="form-label">Skedari i zgjidhjes (opsional)</label>

              <?php if ($solution_path): ?>
                <div class="d-flex align-items-center justify-content-between km-file-current mb-1">
                  <div class="text-truncate">
                    <i class="fa-regular fa-file-lines me-1"></i>
                    <?= h(basename($solution_path)) ?>
                  </div>
                  <a href="../<?= h($solution_path) ?>" target="_blank"
                     class="btn btn-outline-secondary btn-sm km-btn-pill">
                    <i class="fa-solid fa-up-right-from-square"></i>
                  </a>
                </div>
                <div class="form-check mt-1 mb-2">
                  <input class="form-check-input" type="checkbox" id="remove_solution" name="remove_solution" value="1">
                  <label for="remove_solution" class="form-check-label small">
                    Hiqe skedarin ekzistues
                  </label>
                </div>
              <?php else: ?>
                <div class="km-help-text mt-1">
                  Aktualisht nuk ka skedar zgjidhjeje për këtë detyrë.
                </div>
              <?php endif; ?>

              <input
                class="form-control mt-2"
                type="file"
                name="solution_file"
              >
              <div class="km-help-text mt-1">
                Zgjidhja mund t’u shfaqet studentëve sipas logjikës që ke në faqen e detajeve (p.sh. pas afatit).
              </div>
            </div>

            <div class="km-help-text">
              Fotot brenda enonciatit shtohen/ndryshohen nga blloqet <strong>Foto</strong> në editorin më sipër.
            </div>
          </div>

          <div class="d-grid gap-2">
            <button class="btn btn-primary km-btn-pill" type="submit">
              <i class="fa-regular fa-floppy-disk me-1"></i>
              Ruaj ndryshimet
            </button>
            <a
              href="../course_details.php?course_id=<?= (int)$course_id ?>&tab=materials"
              class="btn btn-outline-secondary km-btn-pill"
            >
              <i class="fa-solid fa-arrow-left-long me-1"></i>
              Anulo dhe kthehu mbrapa
            </a>
          </div>

        </aside>
      </div>
    </form>
  </div>
</div>

<?php include __DIR__ . '/../footer.php'; ?>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script>
  const blockEditorWrap  = document.getElementById('km-block-editor');
  const blockList        = document.getElementById('km-block-list');
  const mdEditor         = document.getElementById('km-markdown-editor');
  const blocksJsonInput  = document.getElementById('blocks_json');
  let   currentView      = 'normal';
  let   viewTabs         = [];

  // ------------------------ Block editor JS (i përmirësuar, me src për imazhet) --------------------------

  function createBlock(type) {
    const block = document.createElement('div');
    block.className = 'km-block';
    block.setAttribute('data-type', type);

    let labelIcon = '';
    let labelText = '';
    if (type === 'p') {
      labelIcon = 'fa-regular fa-paragraph';
      labelText = 'Paragraf';
    } else if (type === 'h2') {
      labelIcon = 'fa-solid fa-heading';
      labelText = 'Titull seksioni';
    } else if (type === 'code') {
      labelIcon = 'fa-solid fa-code';
      labelText = 'Bllok kodi';
    } else if (type === 'img') {
      labelIcon = 'fa-regular fa-image';
      labelText = 'Foto';
    }

    block.innerHTML = `
      <div class="km-block-header">
        <span class="km-block-label">
          <i class="${labelIcon}"></i>
          ${labelText}
        </span>
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
      const placeholder = type === 'p'
        ? 'Shkruaj paragraf...'
        : 'Titulli i seksionit...';
      body.innerHTML = `
        <textarea class="form-control km-block-text"
                  rows="${type === 'p' ? 3 : 2}"
                  placeholder="${placeholder}"></textarea>
      `;
    } else if (type === 'code') {
      body.innerHTML = `
        <div class="mb-2">
          <label class="form-label small mb-1">Gjuha (opsionale)</label>
          <input type="text" class="form-control form-control-sm km-block-lang"
                 placeholder="p.sh. php, js, python">
        </div>
        <div>
          <label class="form-label small mb-1">Kodi</label>
          <textarea class="form-control km-block-code"
                    rows="4"
                    placeholder="Shkruaj kodin..."></textarea>
        </div>
      `;
    } else if (type === 'img') {
      body.innerHTML = `
        <div class="mb-2">
          <label class="form-label small mb-1">Titulli / alt</label>
          <input type="text"
                 class="form-control form-control-sm km-block-alt"
                 placeholder="p.sh. Diagramë e algoritmit">
        </div>
        <div class="mb-2">
          <label class="form-label small mb-1">Skedari i fotos</label>
          <input type="file"
                 class="form-control form-control-sm km-block-image-input"
                 name="assignment_images[]"
                 accept="image/*">
        </div>
        <input type="hidden" class="km-block-src" value="">
        <div class="km-block-img-info small text-muted"></div>
      `;
    }

    return block;
  }

  // Butonat "Shto bllok"
  document.querySelectorAll('.km-add-block').forEach(btn => {
    btn.addEventListener('click', () => {
      const type = btn.getAttribute('data-type') || 'p';
      const block = createBlock(type);
      blockList.appendChild(block);
    });
  });

  // Veprimet mbi blloqet (lart/poshtë/hiq)
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
        if (prev) {
          blockList.insertBefore(block, prev);
        }
      } else if (target.classList.contains('km-block-move-down')) {
        const next = block.nextElementSibling;
        if (next) {
          blockList.insertBefore(block, block.nextElementSibling);
        }
      }
    });
  }

  // Lexo blloqet nga DOM → array
  function readBlocksFromDOM() {
    const blocks = [];
    if (!blockList) return blocks;

    const blockEls = blockList.querySelectorAll('.km-block');
    blockEls.forEach((el) => {
      const type = (el.getAttribute('data-type') || 'p').toLowerCase();
      if (type === 'p' || type === 'h2') {
        const txtEl = el.querySelector('.km-block-text');
        const text = txtEl ? txtEl.value.trim() : '';
        if (text !== '') {
          blocks.push({ type, text });
        }
      } else if (type === 'code') {
        const langEl = el.querySelector('.km-block-lang');
        const codeEl = el.querySelector('.km-block-code');
        const lang = langEl ? langEl.value.trim() : '';
        const code = codeEl ? codeEl.value : '';
        if (code.trim() !== '') {
          blocks.push({ type: 'code', lang, code });
        }
      } else if (type === 'img') {
        const altEl  = el.querySelector('.km-block-alt');
        const srcEl  = el.querySelector('.km-block-src');
        const fileEl = el.querySelector('.km-block-image-input');
        const alt    = altEl ? altEl.value.trim() : '';
        const src    = srcEl ? srcEl.value.trim() : '';
        const hasNew = !!(fileEl && fileEl.files && fileEl.files.length > 0);
        blocks.push({ type: 'img', alt, src, hasNew });
      }
    });

    return blocks;
  }

  // blocks[] → Markdown (ruan src për fotot ekzistuese, përdor #IMGn për fotot e reja)
  function blocksToMarkdown(blocks) {
    let md = '';
    let imgIndex = 0;

    (blocks || []).forEach((b) => {
      if (!b) return;
      if (b.type === 'p') {
        if (b.text && b.text.trim() !== '') {
          md += b.text.trim() + "\n\n";
        }
      } else if (b.type === 'h2') {
        if (b.text && b.text.trim() !== '') {
          md += '## ' + b.text.trim() + "\n\n";
        }
      } else if (b.type === 'code') {
        const lang = (b.lang || '').trim();
        const code = (b.code || '');
        if (code.trim() !== '') {
          md += '```' + lang + "\n" + code + "\n```\n\n";
        }
      } else if (b.type === 'img') {
        const alt = (b.alt || '').trim();
        const src = (b.src || '').trim();
        const hasNew = !!b.hasNew;

        if (hasNew || !src) {
          // Foto e re → placeholder #IMGn
          imgIndex++;
          md += '![' + alt + '](#IMG' + imgIndex + ")\n\n";
        } else {
          // Foto ekzistuese → ruaj src
          md += '![' + alt + '](' + src + ")\n\n";
        }
      }
    });

    return md.trim();
  }

  // Markdown → blocks[] (lexon edhe src të imazhit)
  function markdownToBlocks(md) {
    const blocks = [];
    const lines = (md || '').split(/\r?\n/);

    let buffer = [];
    let inCode = false;
    let codeLang = '';
    let codeLines = [];

    function flushParagraph() {
      if (buffer.length) {
        const text = buffer.join('\n').trim();
        if (text !== '') {
          blocks.push({ type: 'p', text });
        }
        buffer = [];
      }
    }

    lines.forEach((line) => {
      // Code fence opener ```lang ose ~~~lang
      const fenceOpenMatch = line.match(/^(```|~~~)\s*([^\s`~]*)\s*$/);
      if (fenceOpenMatch && !inCode) {
        flushParagraph();
        inCode = true;
        codeLang = fenceOpenMatch[2] || '';
        codeLines = [];
        return;
      }

      // Code fence closer ``` ose ~~~
      if (inCode && /^(```|~~~)\s*$/.test(line)) {
        blocks.push({ type: 'code', lang: codeLang, code: codeLines.join('\n') });
        inCode = false;
        codeLang = '';
        codeLines = [];
        return;
      }

      if (inCode) {
        codeLines.push(line);
        return;
      }

      // rresht bosh → mbyll paragraf-in aktual
      if (/^\s*$/.test(line)) {
        flushParagraph();
        return;
      }

      // Heading # ose ## → h2
      const hMatch = line.match(/^#{1,2}\s+(.*)$/);
      if (hMatch) {
        flushParagraph();
        const text = hMatch[1].trim();
        if (text !== '') {
          blocks.push({ type: 'h2', text });
        }
        return;
      }

      // Imazh ![alt](src)
      const imgMatch = line.match(/^!\[(.*?)\]\((.*?)\)\s*$/);
      if (imgMatch) {
        flushParagraph();
        const alt = (imgMatch[1] || '').trim();
        const src = (imgMatch[2] || '').trim();
        blocks.push({ type: 'img', alt, src, hasNew: false });
        return;
      }

      // Rresht normal → buffer paragrafi
      buffer.push(line);
    });

    flushParagraph();
    return blocks;
  }

  // Render blocks[] në DOM
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
        const altEl  = blockEl.querySelector('.km-block-alt');
        const srcEl  = blockEl.querySelector('.km-block-src');
        const infoEl = blockEl.querySelector('.km-block-img-info');

        if (altEl && b.alt) altEl.value = b.alt;
        if (srcEl) srcEl.value = b.src || '';
        if (infoEl && b.src) {
          infoEl.textContent = 'Foto ekzistuese: ' + b.src;
        }
      }

      blockList.appendChild(blockEl);
    });
  }

  function updateTabClasses() {
    if (!viewTabs || !viewTabs.length) return;
    viewTabs.forEach(tab => {
      const tabView = tab.getAttribute('data-view') || 'normal';
      if (tabView === currentView) {
        tab.classList.add('active');
      } else {
        tab.classList.remove('active');
      }
    });
  }

  function setEditorView(view) {
    const toolbar = document.querySelector('.km-block-toolbar');
    view = (view === 'markdown') ? 'markdown' : 'normal';

    if (view === 'markdown') {
      // nga normal → markdown → serializo blloqet
      if (currentView === 'normal' && mdEditor) {
        const blocks = readBlocksFromDOM();
        mdEditor.value = blocksToMarkdown(blocks);
      }
      if (mdEditor) mdEditor.classList.remove('d-none');
      if (blockList) blockList.classList.add('d-none');
      if (toolbar) toolbar.classList.add('d-none');
    } else {
      // nga markdown → normal → parse Markdown në blloqe
      if (currentView === 'markdown' && mdEditor) {
        const md = mdEditor.value || '';
        const blocks = markdownToBlocks(md);
        renderBlocks(blocks);
      }
      if (mdEditor) mdEditor.classList.add('d-none');
      if (blockList) blockList.classList.remove('d-none');
      if (toolbar) toolbar.classList.remove('d-none');
    }

    currentView = view;
    updateTabClasses();
  }

  // Në ngarkim: inicializo editorin me përshkrimin ekzistues (nëse ka)
  document.addEventListener('DOMContentLoaded', () => {
    const descHidden = document.getElementById('description');
    let initialMd = '';

    if (mdEditor && mdEditor.value.trim() !== '') {
      initialMd = mdEditor.value;
    } else if (descHidden && descHidden.value.trim() !== '') {
      initialMd = descHidden.value;
      mdEditor.value = initialMd;
    }

    if (initialMd) {
      const blocks = markdownToBlocks(initialMd);
      renderBlocks(blocks);
    } else {
      renderBlocks([]);
    }

    viewTabs = Array.from(document.querySelectorAll('.km-editor-tab'));
    viewTabs.forEach(tab => {
      tab.addEventListener('click', () => {
        const view = tab.getAttribute('data-view') || 'normal';
        setEditorView(view);
      });
    });

    // startojmë në Normal View
    setEditorView('normal');
  });

  // Para submit: block editor / Markdown → description + blocks_json
  const formEl = document.querySelector('form.km-form-grid');
  if (formEl) {
    formEl.addEventListener('submit', (e) => {
      const descHidden = document.getElementById('description');
      let blocks = [];

      if (currentView === 'markdown' && mdEditor) {
        const md = mdEditor.value || '';
        blocks = markdownToBlocks(md);
      } else {
        blocks = readBlocksFromDOM();
      }

      const mdFinal = blocksToMarkdown(blocks);

      if (descHidden) {
        descHidden.value = mdFinal.trim();
      }
      if (blocksJsonInput) {
        blocksJsonInput.value = JSON.stringify(blocks);
      }
    });
  }
</script>
</body>
</html>
