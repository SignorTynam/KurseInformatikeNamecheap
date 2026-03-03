<?php
declare(strict_types=1);
if (session_status() === PHP_SESSION_NONE) { session_start(); }
require_once __DIR__ . '/../lib/database.php';
require_once __DIR__ . '/../lib/lib_access_code.php';

/* -------------------- RBAC -------------------- */
if (!isset($_SESSION['user']) || !in_array($_SESSION['user']['role'] ?? '', ['Administrator','Instruktor'], true)) {
    header('Location: ../login.php'); exit;
}
$ROLE   = $_SESSION['user']['role'];
$ME_ID  = (int)($_SESSION['user']['id'] ?? 0);

/* -------------------- CSRF -------------------- */
if (empty($_SESSION['csrf_token'])) { $_SESSION['csrf_token'] = bin2hex(random_bytes(32)); }
$CSRF = $_SESSION['csrf_token'];

/* -------------------- Helpers ----------------- */
function h(?string $s): string { return htmlspecialchars($s ?? '', ENT_QUOTES, 'UTF-8'); }
function ensure_dir(string $dir): void { if (!is_dir($dir)) { @mkdir($dir, 0775, true); } }
function table_has_column(PDO $pdo, string $table, string $column): bool {
    try {
        $stmt = $pdo->prepare("SHOW COLUMNS FROM `$table` LIKE ?");
        $stmt->execute([$column]);
        return (bool)$stmt->fetch(PDO::FETCH_ASSOC);
    } catch (Throwable $e) { return false; }
}

/* -------------------- Defaults ---------------- */
$errors = [];
$title = $description = $status = $category = '';
$id_lesson = null;
$photo_filename = null;
$copy_photo_name = null;
$aula_virtuale = null;
$copy_course_id = isset($_GET['copy_course_id']) ? (int)$_GET['copy_course_id'] : 0;

$allowed_categories = ['PROGRAMIM','GRAFIKA','WEB','GJUHE TE HUAJA','IT','TJETRA'];
$allowed_statuses   = ['ACTIVE','INACTIVE','ARCHIVED'];
$max_upload_bytes   = 6 * 1024 * 1024; // 6MB
$upload_courses_dir = __DIR__ . '/../uploads/courses';
$upload_lessons_dir = __DIR__ . '/../uploads/lessons';
$upload_assign_dir  = __DIR__ . '/../uploads/assignments';
ensure_dir($upload_courses_dir);
ensure_dir($upload_lessons_dir);
ensure_dir($upload_assign_dir);

/* ----------- Prefill kur kopjojmë kurs -------- */
$copy_course = null;
$copy_students_count = 0;
if ($copy_course_id > 0) {
    try {
        $stmtCopy = $pdo->prepare("SELECT * FROM courses WHERE id = ?");
        $stmtCopy->execute([$copy_course_id]);
        $copy_course = $stmtCopy->fetch(PDO::FETCH_ASSOC);

        $stmtEnr = $pdo->prepare("SELECT COUNT(*) FROM enroll WHERE course_id = ?");
        $stmtEnr->execute([$copy_course_id]);
        $copy_students_count = (int)$stmtEnr->fetchColumn();

        if ($copy_course) {
            // Prefill — kursi i ri si default INACTIVE
            $title           = (string)$copy_course['title'];
            $description     = (string)$copy_course['description'];
            $category        = (string)$copy_course['category'];
            $status          = 'INACTIVE';
            $id_lesson       = $copy_course['id_lesson'] ? (int)$copy_course['id_lesson'] : null;
            $copy_photo_name = $copy_course['photo'] ?: null;
            $aula_virtuale   = $copy_course['AulaVirtuale'] ?? null;
        } else {
            $errors[] = 'Kursi për kopjim nuk u gjet.';
        }
    } catch (PDOException $e) {
        $errors[] = 'Gabim gjatë leximit të kursit për kopjim.';
    }
}

/* -------------------- POST -------------------- */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // CSRF
    if (!isset($_POST['csrf']) || !hash_equals($CSRF, (string)$_POST['csrf'])) {
        $errors[] = 'Seancë e pavlefshme (CSRF). Ringarko faqen.';
    }

    // Marrje inputesh
    $title         = trim((string)($_POST['title'] ?? ''));
    $description   = (string)($_POST['description'] ?? '');
    $category      = (string)($_POST['category'] ?? '');
    $statusIn      = (string)($_POST['status'] ?? 'ACTIVE');
    $status        = in_array($statusIn, $allowed_statuses, true) ? $statusIn : 'ACTIVE';
    $id_lesson     = isset($_POST['id_lesson']) && $_POST['id_lesson'] !== '' ? (int)$_POST['id_lesson'] : null;
    $aula_virtuale = trim((string)($_POST['aula_virtuale'] ?? '')) ?: null;

    // Validime bazë
    if ($title === '' || mb_strlen($title) < 3) {
        $errors[] = 'Titulli i kursit është i detyrueshëm (min 3 karaktere).';
    }
    if (!in_array($category, $allowed_categories, true)) {
        $errors[] = 'Kategoria e kursit është e pavlefshme.';
    }
    if ($aula_virtuale !== null) {
        if (!filter_var($aula_virtuale, FILTER_VALIDATE_URL)) {
            $errors[] = 'Linku i Aula Virtuale nuk është URL e vlefshme.';
        } else {
            $scheme = parse_url($aula_virtuale, PHP_URL_SCHEME);
            if (!in_array($scheme, ['http','https'], true)) {
                $errors[] = 'Linku i Aula Virtuale duhet të jetë http ose https.';
            }
        }
    }

    // Nëse është kopjim — refuzo nëse kursi burim s’ka studentë
    if ($copy_course_id > 0) {
        try {
            $stmtEnr = $pdo->prepare("SELECT COUNT(*) FROM enroll WHERE course_id = ?");
            $stmtEnr->execute([$copy_course_id]);
            $copy_students_count = (int)$stmtEnr->fetchColumn();
            if ($copy_students_count === 0) {
                $errors[] = 'Kopjimi nuk lejohet: kursi burim nuk ka asnjë student të regjistruar.';
            }
        } catch (Throwable $e) {
            $errors[] = 'Nuk mund të verifikohej numri i studentëve të kursit burim.';
        }
    }

    // Foto: ose ngarko, ose përdor kopjen e fotos
    $hasUpload = isset($_FILES['course_photo']) && is_uploaded_file($_FILES['course_photo']['tmp_name']) && ($_FILES['course_photo']['error'] === UPLOAD_ERR_OK);
    if ($hasUpload) {
        $tmp  = $_FILES['course_photo']['tmp_name'];
        $size = (int)$_FILES['course_photo']['size'];
        if ($size > $max_upload_bytes) {
            $errors[] = 'Imazhi është shumë i madh. Maksimumi 6MB.';
        } else {
            $finfo = new finfo(FILEINFO_MIME_TYPE);
            $mime  = $finfo->file($tmp) ?: '';
            $ok_mimes = ['image/jpeg'=>'jpg','image/png'=>'png','image/gif'=>'gif','image/webp'=>'webp'];
            if (!isset($ok_mimes[$mime])) {
                $errors[] = 'Lloj i papranueshëm imazhi. Lejohen JPG, PNG, GIF, WEBP.';
            } else {
                $ext = $ok_mimes[$mime];
                $photo_filename = uniqid('course_', true) . '.' . $ext;
                $dest = $upload_courses_dir . '/' . $photo_filename;
                if (!move_uploaded_file($tmp, $dest)) {
                    $errors[] = 'Ngarkimi i imazhit dështoi.';
                }
            }
        }
    } else {
        if ($copy_course && $copy_photo_name) {
            $src = $upload_courses_dir . '/' . basename($copy_photo_name);
            if (is_file($src)) {
                $ext = strtolower(pathinfo($src, PATHINFO_EXTENSION)) ?: 'jpg';
                $photo_filename = uniqid('course_', true) . '.' . $ext;
                $dest = $upload_courses_dir . '/' . $photo_filename;
                if (!@copy($src, $dest)) {
                    $errors[] = 'Kopjimi i fotos nga kursi burim dështoi.';
                }
            } else {
                $errors[] = 'Fotoja origjinale e kursit nuk u gjet në server.';
            }
        } else {
            $errors[] = 'Imazhi i kursit është i detyrueshëm.';
        }
    }

    // Nëse nuk ka gabime -> ruaj dhe kopjo strukturën
    if (!$errors) {
        try {
            $pdo->beginTransaction();

            // 1) Krijo kursin e ri
            $stmt = $pdo->prepare("
                INSERT INTO courses (title, description, id_lesson, id_creator, status, category, photo, AulaVirtuale)
                VALUES (:t,:d,:il,:ic,:st,:cat,:ph,:av)
            ");
            $stmt->execute([
                ':t'  => $title,
                ':d'  => $description,
                ':il' => $id_lesson,
                ':ic' => $ME_ID,
                ':st' => $status,
                ':cat'=> $category,
                ':ph' => $photo_filename,
                ':av' => $aula_virtuale,
            ]);
            $new_course_id = (int)$pdo->lastInsertId();

            // 1.1) Gjenero access code (nëse kolona ekziston)
            $newAccessCode = null;
            try {
              $newAccessCode = ki_set_course_access_code_if_empty($pdo, $new_course_id);
            } catch (Throwable $e) {
              // mos e blloko krijimin e kursit për këtë arsye
              $newAccessCode = null;
            }

            // Do t’na duhet hartë e ID-ve të leksioneve: vjetër -> i riu
            $lessonIdMap = [];

            if ($copy_course) {
                // 2) Kopjo leksionet
                $stmtLessons = $pdo->prepare("SELECT * FROM lessons WHERE course_id = ? ORDER BY id ASC");
                $stmtLessons->execute([$copy_course_id]);
                $lessonsToCopy = $stmtLessons->fetchAll(PDO::FETCH_ASSOC) ?: [];

                foreach ($lessonsToCopy as $lesson) {
                    $stmtInsL = $pdo->prepare("
                        INSERT INTO lessons (course_id, title, description, URL, category, hidden, uploaded_at)
                        VALUES (?,?,?,?,?,1,NOW())
                    ");
                    $stmtInsL->execute([
                        $new_course_id,
                        $lesson['title'],
                        $lesson['description'],
                        $lesson['URL'],
                        $lesson['category']
                    ]);
                    $new_lesson_id = (int)$pdo->lastInsertId();
                    $lessonIdMap[(int)$lesson['id']] = $new_lesson_id;

                    // Kopjo skedarët e leksionit
                    $stmtFiles = $pdo->prepare("SELECT * FROM lesson_files WHERE lesson_id = ?");
                    $stmtFiles->execute([$lesson['id']]);
                    $filesToCopy = $stmtFiles->fetchAll(PDO::FETCH_ASSOC) ?: [];

                    foreach ($filesToCopy as $lf) {
                        $oldRel = (string)$lf['file_path'];
                        $oldAbs = is_file($oldRel) ? $oldRel : (__DIR__ . '/' . ltrim($oldRel, '/'));
                        if (is_file($oldAbs)) {
                            $newName = time() . '_' . bin2hex(random_bytes(4)) . '_' . basename($oldAbs);
                            $newRel  = 'uploads/lessons/' . $newName;
                            $newAbs  = $upload_lessons_dir . '/' . $newName;
                            if (@copy($oldAbs, $newAbs)) {
                                $stmtCopyFile = $pdo->prepare("INSERT INTO lesson_files (lesson_id, file_path, file_type) VALUES (?,?,?)");
                                $stmtCopyFile->execute([$new_lesson_id, $newRel, $lf['file_type']]);
                            }
                        }
                    }
                }

                // 3) Kopjo assignments
                $hasAssignHidden = table_has_column($pdo, 'assignments', 'hidden');
                $hasAssignStatus = table_has_column($pdo, 'assignments', 'status');
                $hasAssignDue    = table_has_column($pdo, 'assignments', 'due_date');
                $hasAssignLink   = table_has_column($pdo, 'assignments', 'link');
                $hasAssignLesson = table_has_column($pdo, 'assignments', 'lesson_id');

                $stmtA = $pdo->prepare("SELECT * FROM assignments WHERE course_id = ? ORDER BY id ASC");
                $stmtA->execute([$copy_course_id]);
                $assignments = $stmtA->fetchAll(PDO::FETCH_ASSOC) ?: [];

                $assignIdMap = [];

                foreach ($assignments as $a) {
                    $cols = ['course_id','title','description'];
                    $vals = [$new_course_id, $a['title'], $a['description']];

                    if ($hasAssignDue)    { $cols[]='due_date'; $vals[]=$a['due_date']; }
                    if ($hasAssignLink)   { $cols[]='link';     $vals[]=$a['link']; }
                    if ($hasAssignLesson) {
                        $oldLid = (int)($a['lesson_id'] ?? 0);
                        $newLid = $lessonIdMap[$oldLid] ?? null;
                        $cols[]='lesson_id'; $vals[]=$newLid;
                    }
                    if ($hasAssignHidden) { $cols[]='hidden';   $vals[]=1; }
                    if ($hasAssignStatus) { $cols[]='status';   $vals[]='DRAFT'; }

                    $ph = '(' . implode(',', array_fill(0, count($cols), '?')) . ')';
                    $sqlIns = "INSERT INTO assignments (".implode(',', $cols).") VALUES $ph";
                    $stmtIns = $pdo->prepare($sqlIns);
                    $stmtIns->execute($vals);
                    $newAssignId = (int)$pdo->lastInsertId();
                    $assignIdMap[(int)$a['id']] = $newAssignId;

                    // Skedarët e assignments
                    try {
                        $stmtAF = $pdo->prepare("SELECT * FROM assignments_files WHERE assignment_id = ?");
                        $stmtAF->execute([$a['id']]);
                        $aFiles = $stmtAF->fetchAll(PDO::FETCH_ASSOC) ?: [];

                        foreach ($aFiles as $af) {
                            $oldRelF = (string)$af['file_path'];
                            $oldAbsF = is_file($oldRelF) ? $oldRelF : (__DIR__ . '/' . ltrim($oldRelF, '/'));
                            if (is_file($oldAbsF)) {
                                $newNameF = time() . '_' . bin2hex(random_bytes(4)) . '_' . basename($oldAbsF);
                                $newRelF  = 'uploads/assignments/' . $newNameF;
                                $newAbsF  = $upload_assign_dir . '/' . $newNameF;
                                if (@copy($oldAbsF, $newAbsF)) {
                                    $stmtCopyAF = $pdo->prepare("INSERT INTO assignments_files (assignment_id, file_path) VALUES (?, ?)"); 
                                    $stmtCopyAF->execute([$newAssignId, $newRelF]);
                                }
                            }
                        }
                    } catch (Throwable $e) { /* ignore */ }
                }
            }

            $pdo->commit();
            $msg = 'Kursi u shtua me sukses!';
            if (is_string($newAccessCode) && $newAccessCode !== '') {
              $msg .= ' Access code: ' . $newAccessCode;
            }
            $_SESSION['flash'] = ['msg'=>$msg, 'type'=>'success'];
            header('Location: ../course.php'); exit;

        } catch (Throwable $e) {
            if ($pdo->inTransaction()) { $pdo->rollBack(); }
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
  <title>Shto kurs — kurseinformatike.com</title>

  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
  <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css" rel="stylesheet">
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/simplemde/latest/simplemde.min.css">
  <link rel="stylesheet" href="../css/km-lms-forms.css">
  <link rel="icon" href="image/favicon.ico" type="image/x-icon" />
</head>
<body class="km-body">

<?php
  if ($ROLE === 'Administrator')      include __DIR__ . '/../navbar_logged_administrator.php';
  elseif ($ROLE === 'Instruktor')     include __DIR__ . '/../navbar_logged_instruktor.php';
?>

<div class="km-page-shell">
  <div class="container">

    <!-- HEADER: breadcrumb + titull -->
    <header class="km-page-header d-flex flex-column flex-md-row justify-content-between align-items-start align-items-md-center gap-3">
      <div>
        <div class="km-breadcrumb small mb-1">
          <a href="../course.php" class="km-breadcrumb-link">
            <i class="fa-solid fa-journal-whills me-1"></i>Kurset
          </a>
          <span class="mx-1">/</span>
          <span class="km-breadcrumb-current">Shto kurs të ri</span>
        </div>
        <h1 class="km-page-title">
          <i class="fa-solid fa-circle-plus me-2 text-primary"></i>
          Shto kurs të ri
        </h1>
        <p class="km-page-subtitle mb-0">
          Plotësoni të dhënat bazë, ngarkoni foton dhe zgjidhni nëse kursi do të jetë aktiv apo draft.
        </p>
      </div>

      <div class="d-flex flex-column align-items-md-end gap-2">
        <?php if ($copy_course_id): ?>
          <div class="km-pill-meta">
            <i class="fa-solid fa-copy me-1"></i>
            Kopjim nga kursi #<?= (int)$copy_course_id ?>
          </div>
          <div class="km-help-text">
            Studentë në kursin burim:
            <strong><?= (int)$copy_students_count ?></strong>
            <?= $copy_students_count===0 ? ' — kopjimi do të bllokohet.' : '' ?>
          </div>
        <?php else: ?>
          <div class="km-pill-meta">
            <i class="fa-solid fa-shield-halved me-1"></i>
            Roli: <?= h($ROLE) ?>
          </div>
        <?php endif; ?>
      </div>
    </header>

    <!-- Nuk shfaqim alertë HTML – errorët dalin si toast -->

    <!-- FORM -->
    <form id="addCourseForm" method="POST" enctype="multipart/form-data" class="row g-4 km-form-grid">
      <input type="hidden" name="csrf" value="<?= h($CSRF) ?>">

      <!-- Kolona majtas: detajet e kursit -->
      <div class="col-12 col-lg-8">
        <section class="km-card km-card-main">
          <div class="km-card-header">
            <div>
              <h2 class="km-card-title">
                <span class="km-step-badge">1</span>
                Detajet e kursit
              </h2>
              <p class="km-card-subtitle mb-0">
                Titulli, përshkrimi, kategoria dhe statusi (ACTIVE / INACTIVE / ARCHIVED).
              </p>
            </div>
          </div>
          <div class="km-card-body">
            <div class="mb-3">
              <label class="form-label">Titulli i kursit</label>
              <input
                type="text"
                name="title"
                class="form-control"
                required
                value="<?= h($title) ?>"
                placeholder="p.sh. Programimi në PHP nga zero në avancuar"
              >
            </div>

            <div class="mb-3">
              <label class="form-label">Përshkrimi (Markdown)</label>
              <textarea id="description" name="description"><?= h($description) ?></textarea>
              <div class="km-help-text mt-1">
                Mund të përdorni sintaksën Markdown për tituj, lista, fragmente kodi, etj.
              </div>
            </div>

            <div class="row g-3">
              <div class="col-md-6">
                <label class="form-label">Kategoria</label>
                <select name="category" class="form-select" required>
                  <option value="">Zgjidh...</option>
                  <?php foreach ($allowed_categories as $cat): ?>
                    <option value="<?= h($cat) ?>" <?= $category===$cat?'selected':'' ?>>
                      <?= h($cat) ?>
                    </option>
                  <?php endforeach; ?>
                </select>
              </div>
              <div class="col-md-6">
                <label class="form-label">Statusi</label>
                <select name="status" id="statusSelect" class="form-select">
                  <?php foreach ($allowed_statuses as $st): ?>
                    <option value="<?= $st ?>" <?= $status===$st?'selected':'' ?>><?= $st ?></option>
                  <?php endforeach; ?>
                </select>
                <div class="km-help-text mt-1">
                  ACTIVE → i dukshëm për studentët; INACTIVE/ARCHIVED → i fshehur nga lista kryesore.
                </div>
              </div>
            </div>

            <div class="row g-3 mt-1">
              <div class="col-md-6">
                <label class="form-label">ID e leksionit fillestar (opsional)</label>
                <input
                  type="number"
                  name="id_lesson"
                  class="form-control"
                  value="<?= $id_lesson!==null ? (int)$id_lesson : '' ?>"
                  placeholder="p.sh. 101"
                >
                <div class="km-help-text mt-1">
                  ID e leksionit ku dëshironi të nisë kursi (p.sh. leksioni i hyrjes).
                </div>
              </div>
              <div class="col-md-6">
                <label class="form-label">Link i Teams (Aula Virtuale)</label>
                <input
                  type="url"
                  name="aula_virtuale"
                  class="form-control"
                  value="<?= h($aula_virtuale) ?>"
                  placeholder="https://teams.microsoft.com/..."
                >
                <div class="km-help-text mt-1">
                  Opsionale: linku i sallës virtuale (MS Teams, Zoom, etj.).
                </div>
              </div>
            </div>
          </div>
        </section>

        <?php if ($copy_course): ?>
          <section class="km-card km-card-main mt-3">
            <div class="km-card-body">
              <div class="d-flex align-items-start gap-2">
                <i class="fa-regular fa-circle-question mt-1" style="color:#2563eb"></i>
                <div class="km-help-text">
                  <strong>Kopjim strukture:</strong> do të kopjohen <u>të gjitha leksionet</u> dhe <u>detyrat</u> (bashkë me skedarët)
                  dhe gjithçka do të shënohet si <em>e fshehur/draft</em> në kursin e ri.
                  Pagesat dhe dorëzimet e studentëve <strong>nuk</strong> kopjohen.
                  <?= $copy_students_count===0 ? '<span class="text-danger ms-1">Ky kurs nuk ka studentë – kopjimi do të bllokohet.</span>' : '' ?>
                </div>
              </div>
            </div>
          </section>
        <?php endif; ?>
      </div>

      <!-- Kolona djathtas: foto + ruajtja -->
      <div class="col-12 col-lg-4">
        <!-- Foto e kursit -->
        <aside class="km-card km-card-side mb-3">
          <div class="km-card-body">
            <h3 class="km-side-title mb-2">
              <i class="fa-regular fa-image me-2"></i>
              Foto e kursit
            </h3>
            <div class="km-dropzone mb-2" id="dropZone">
              <i class="fa-solid fa-cloud-arrow-up fs-4 mb-2 d-block"></i>
              <div class="fw-semibold mb-1">Tërhiq & lësho ose kliko për të zgjedhur</div>
              <div class="km-help-text">
                Lejohen JPG, PNG, GIF, WEBP (maksimumi 6MB).
              </div>
              <input
                class="d-none"
                type="file"
                id="course_photo"
                name="course_photo"
                accept="image/*"
                <?= $copy_course ? '' : 'required' ?>
              >
            </div>

            <div class="km-preview d-none" id="previewWrap">
              <img id="previewImg" alt="" style="width:100%;height:auto;display:block;">
            </div>

            <?php if ($copy_course && $copy_photo_name): ?>
              <div class="km-help-text mt-2">
                <i class="fa-regular fa-image me-1"></i>
                Nëse nuk ngarkoni imazh, do kopjohet fotoja ekzistuese e kursit burim.
              </div>
            <?php endif; ?>
          </div>
        </aside>

        <!-- Ruaj kursin -->
        <aside class="km-card km-card-side km-sticky-side">
          <div class="km-card-body">
            <h3 class="km-side-title mb-2">
              <i class="fa-regular fa-circle-check me-2 text-success"></i>
              Ruaj kursin
            </h3>
            <p class="km-help-text mb-3">
              Pasi të ruhet, struktura (leksione & detyra) mund të menaxhohet nga paneli i kursit.
              Statusi ACTIVE e bën kursin të dukshëm për studentët, sipas rregullave të tua.
            </p>

            <ul class="km-checklist mb-3">
              <li><i class="fa-regular fa-square-check me-1"></i>Titull i plotë</li>
              <li><i class="fa-regular fa-square-check me-1"></i>Kategori e saktë</li>
              <li><i class="fa-regular fa-square-check me-1"></i>Imazh i kursit</li>
              <li><i class="fa-regular fa-square-check me-1"></i>Status i dëshiruar (draft / aktiv)</li>
            </ul>

            <div class="d-grid gap-2">
              <!-- type="button" që të kapim modalin e konfirmimit -->
              <button class="btn btn-primary km-btn-pill" id="saveBtn" type="button">
                <i class="fa-regular fa-floppy-disk me-1"></i>
                Ruaj kursin
              </button>
              <button class="d-none" id="realSubmit" type="submit" aria-hidden="true"></button>
              <a href="../course.php" class="btn btn-outline-secondary km-btn-pill">
                <i class="fa-solid fa-arrow-left-long me-1"></i>
                Kthehu te kurset
              </a>
            </div>
          </div>
        </aside>
      </div>
    </form>
  </div>
</div>

<!-- Modal: Konfirmim publikimi kur Status = ACTIVE -->
<div class="modal fade" id="confirmSaveModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title">
          <i class="fa-solid fa-triangle-exclamation me-2"></i>
          Publikim i kursit
        </h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Mbyll"></button>
      </div>
      <div class="modal-body">
        Po ruani kursin me status <strong>ACTIVE</strong>. Kjo e bën kursin të dukshëm për studentët (sipas rregullave tuaja).
        Jeni i sigurt që dëshironi të vazhdoni?
      </div>
      <div class="modal-footer">
        <button class="btn btn-secondary" data-bs-dismiss="modal">Anulo</button>
        <button class="btn btn-primary" id="confirmSaveBtn">
          <i class="fa-regular fa-floppy-disk me-1"></i>Po, ruaj
        </button>
      </div>
    </div>
  </div>
</div>

<!-- Toast container -->
<div id="toastZone" aria-live="polite" aria-atomic="true"></div>

<?php include __DIR__ . '/../footer2.php'; ?>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script src="https://cdn.jsdelivr.net/simplemde/latest/simplemde.min.js"></script>
<script>
  // Markdown editor
  const simplemde = new SimpleMDE({
    element: document.getElementById('description'),
    toolbar: [
      'bold','italic','heading','|',
      'code','quote','unordered-list','ordered-list','|',
      'link','image','table','|',
      'preview','guide'
    ],
    spellChecker:false,
    placeholder: "Përshkrimi i kursit (Markdown)..."
  });

  // Dropzone & preview
  const dropZone = document.getElementById('dropZone');
  const fileInput = document.getElementById('course_photo');
  const prevWrap  = document.getElementById('previewWrap');
  const prevImg   = document.getElementById('previewImg');

  function openPicker(){ fileInput.click(); }
  function setPreview(file){
    const reader = new FileReader();
    reader.onload = e => {
      prevImg.src = e.target.result;
      prevWrap.classList.remove('d-none');
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
  fileInput.addEventListener('change', ()=>{
    if (fileInput.files && fileInput.files[0]) setPreview(fileInput.files[0]);
  });

  /* -------- Toast helpers -------- */
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
    el.setAttribute('role','alert'); el.setAttribute('aria-live','assertive'); el.setAttribute('aria-atomic','true');
    el.innerHTML = `
      <div class="toast-header">
        <strong class="me-auto d-flex align-items-center">
          ${toastIcon(type)} Njoftim
        </strong>
        <small class="text-white-50">tani</small>
        <button type="button" class="btn-close ms-2 mb-1" data-bs-dismiss="toast" aria-label="Mbyll"></button>
      </div>
      <div class="toast-body">${msg}</div>`;
    zone.appendChild(el);
    const t = new bootstrap.Toast(el, { delay: 3800, autohide: true });
    t.show();
  }

  /* -------- Konfirmim publikimi (ACTIVE) -------- */
  const saveBtn      = document.getElementById('saveBtn');
  const realSubmit   = document.getElementById('realSubmit');
  const statusSelect = document.getElementById('statusSelect');
  const confirmModal = new bootstrap.Modal(document.getElementById('confirmSaveModal'));

  document.getElementById('confirmSaveBtn')?.addEventListener('click', ()=> {
    realSubmit.click();
  });

  saveBtn?.addEventListener('click', ()=> {
    const st = statusSelect?.value || 'INACTIVE';
    if (st === 'ACTIVE') {
      confirmModal.show();
    } else {
      realSubmit.click();
    }
  });

  /* -------- Server-side messages -> toasts -------- */
  <?php if (!empty($errors)): ?>
    <?php foreach ($errors as $e): ?>
      showToast('danger', <?= json_encode($e, JSON_UNESCAPED_UNICODE) ?>);
    <?php endforeach; ?>
  <?php endif; ?>

  <?php if ($copy_course): ?>
    showToast('info', 'Kopjimi i strukturës do të shënojë leksionet/ushtrimet si të fshehura/draft. Pagesat & dorëzimet nuk kopjohen.');
    <?php if ($copy_students_count===0): ?>
      showToast('warning','Kursi burim nuk ka studentë – kopjimi do të bllokohet.');
    <?php endif; ?>
  <?php endif; ?>

  <?php if (!empty($_SESSION['flash'])): ?>
    showToast(<?= json_encode($_SESSION['flash']['type'] ?? 'success') ?>, <?= json_encode($_SESSION['flash']['msg'] ?? '') ?>);
    <?php unset($_SESSION['flash']); ?>
  <?php endif; ?>
</script>
</body>
</html>
