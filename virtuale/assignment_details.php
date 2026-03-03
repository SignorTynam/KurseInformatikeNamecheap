<?php
// assignment_details.php — Faqja e detyrës me layout si lesson_details.php (Udemy/Moodle)
declare(strict_types=1);
session_start();

require_once __DIR__ . '/lib/database.php';
require_once __DIR__ . '/lib/Parsedown.php';

/* ------------------------------ Helpers -------------------------------- */
function h(?string $s): string {
    return htmlspecialchars($s ?? '', ENT_QUOTES, 'UTF-8');
}

function fmt_date(?string $d, string $fmt = 'd M Y'): string {
    if (!$d || trim($d)==='' || $d==='0000-00-00' || $d==='0000-00-00 00:00:00') return 'Pa afat';
    $ts = strtotime($d);
    return $ts ? date($fmt, $ts) : 'Pa afat';
}

/**
 * CSRF token i unifikuar (përdor të njëjtën fushë si 'csrf' dhe 'csrf_token')
 */
function csrf_token(): string {
    if (!empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf'] = $_SESSION['csrf_token'];
        return $_SESSION['csrf_token'];
    }
    if (!empty($_SESSION['csrf'])) {
        $_SESSION['csrf_token'] = $_SESSION['csrf'];
        return $_SESSION['csrf'];
    }
    $t = bin2hex(random_bytes(32));
    $_SESSION['csrf'] = $_SESSION['csrf_token'] = $t;
    return $t;
}

function file_size_pretty(?string $abs): string {
    if (!$abs || !is_file($abs)) return '—';
    $b = filesize($abs);
    if ($b >= 1024*1024) return max(1, (int)round($b/1024/1024)) . ' MB';
    return max(1, (int)round($b/1024)) . ' KB';
}

function icon_for_ext(string $ext): string {
    $ext = strtolower($ext);
    if ($ext === 'pdf') return 'fa-file-pdf text-danger';
    if (in_array($ext, ['doc','docx'], true)) return 'fa-file-word text-primary';
    if (in_array($ext, ['xls','xlsx'], true)) return 'fa-file-excel text-success';
    if (in_array($ext, ['ppt','pptx'], true)) return 'fa-file-powerpoint text-warning';
    if (in_array($ext, ['zip','rar','7z'], true)) return 'fa-file-archive text-secondary';
    if (in_array($ext, ['png','jpg','jpeg','gif','webp','svg'], true)) return 'fa-file-image text-info';
    if (in_array($ext, ['txt','md'], true)) return 'fa-file-lines';
    return 'fa-file';
}

function time_left_label(?string $dateYmd): string {
    if (!$dateYmd || $dateYmd === '0000-00-00') return '—';
    $end = strtotime($dateYmd.' 23:59:59');
    if (!$end) return '—';
    $now = time();
    if ($end <= $now) return 'Skaduar';
    $sec = $end - $now;
    $d = intdiv($sec, 86400); $sec -= $d*86400;
    $h = intdiv($sec, 3600);  $sec -= $h*3600;
    $m = intdiv($sec, 60);
    if ($d > 0) return "$d ditë, $h orë";
    if ($h > 0) return "$h orë, $m min";
    return "$m min";
}

/* ------------------------------ Auth / RBAC ---------------------------- */
if (!isset($_SESSION['user'])) {
    header('Location: login.php');
    exit;
}
$user     = $_SESSION['user'];
$user_id  = (int)($user['id'] ?? 0);
$userRole = (string)($user['role'] ?? '');

$assignment_id = (int)($_GET['assignment_id'] ?? 0);
if ($assignment_id <= 0) {
    die('Detyra nuk është specifikuar.');
}

/* ---------------------- Fetch assignment + course + section ------------ */
try {
    $st = $pdo->prepare("
        SELECT 
          a.*,
          c.title AS course_title,
          c.id    AS course_id,
          c.id_creator,
          c.category AS course_category,
          s.id    AS section_id,
          s.title AS section_title,
          s.hidden AS section_hidden
        FROM assignments a
        JOIN courses  c ON a.course_id = c.id
        LEFT JOIN sections s ON s.id = a.section_id
        WHERE a.id = ?
        LIMIT 1
    ");
    $st->execute([$assignment_id]);
    $assignment = $st->fetch(PDO::FETCH_ASSOC);
    if (!$assignment) {
        die('Detyra nuk u gjet.');
    }
} catch (PDOException $e) {
    die('Gabim: ' . h($e->getMessage()));
}

/* ------------------------------ Permissions ---------------------------- */
$assignHidden      = (int)($assignment['hidden'] ?? 0) === 1;
$sectionHidden     = (int)($assignment['section_hidden'] ?? 0) === 1;
$isOwnerInstructor = ($userRole === 'Instruktor' && (int)$assignment['id_creator'] === $user_id);
$effectiveHidden   = $assignHidden || $sectionHidden;

// Studentët duhet të jenë të regjistruar në kurs
if ($userRole === 'Student') {
    $stmtEn = $pdo->prepare("
        SELECT 1
        FROM enroll
        WHERE course_id = ? AND user_id = ?
        LIMIT 1
    ");
    $stmtEn->execute([(int)$assignment['course_id'], $user_id]);
    if (!$stmtEn->fetchColumn()) {
        http_response_code(403);
        exit('Nuk jeni të regjistruar në këtë kurs.');
    }
}

// Nëse detyra/seksoni është i fshehur, vetëm admin + instruktori mund ta shohin
if ($effectiveHidden) {
    if (!in_array($userRole, ['Administrator'], true) && !$isOwnerInstructor) {
        http_response_code(403);
        exit('DETYRA NUK ËSHTË E DISPONUESHME.');
    }
}

/* ------------------------------ Markdown (prose) ----------------------- */
$Parsedown = new Parsedown();
if (method_exists($Parsedown, 'setSafeMode')) {
    $Parsedown->setSafeMode(true);
}
$descriptionText = (string)($assignment['description'] ?? '');
$descriptionHtml = $Parsedown->text($descriptionText);
$hasDescription  = trim(strip_tags($descriptionHtml)) !== '';

/* ------------------------------ Files ---------------------------------- */
$resource_path = (string)($assignment['resource_path'] ?? '');
$solution_path = (string)($assignment['solution_path'] ?? '');

$resource_abs = $resource_path ? (__DIR__ . '/' . ltrim($resource_path, '/')) : '';
$solution_abs = $solution_path ? (__DIR__ . '/' . ltrim($solution_path, '/')) : '';

$resource_exists = $resource_abs ? is_file($resource_abs) : false;
$solution_exists = $solution_abs ? is_file($solution_abs) : false;

/* Skedarë shtesë (attachments) */
$stF = $pdo->prepare("
    SELECT id, file_path, uploaded_at
    FROM assignments_files
    WHERE assignment_id = ?
    ORDER BY id ASC
");
$stF->execute([$assignment_id]);
$assignment_files = $stF->fetchAll(PDO::FETCH_ASSOC);

/* ------------------------------ Deadline ------------------------------- */
$due_date       = (string)($assignment['due_date'] ?? '');
$hasDue         = (!empty($due_date) && $due_date !== '0000-00-00');
$deadlinePassed = $hasDue ? (strtotime($due_date.' 23:59:59') < time()) : false;
$timeLeft       = $hasDue ? time_left_label($due_date) : '—';

/* ------------------------------ Section data --------------------------- */
$course_id          = (int)($assignment['course_id'] ?? 0);
$assignmentSectionId = (int)($assignment['section_id'] ?? 0);

$sectionAssignments = [];
if ($assignmentSectionId > 0) {
    $sql = "
      SELECT a.id, a.title, a.hidden, a.due_date
      FROM assignments a
      WHERE a.section_id = ?
    ";
    if ($userRole === 'Student') {
        $sql .= " AND COALESCE(a.hidden,0) = 0";
    }
    $sql .= " ORDER BY a.id ASC";
    $stmtSec = $pdo->prepare($sql);
    $stmtSec->execute([$assignmentSectionId]);
    $sectionAssignments = $stmtSec->fetchAll(PDO::FETCH_ASSOC);
}
$hasSectionAssignments = !empty($sectionAssignments);

/* Për studentin: cilat detyra i ka dorëzuar në këtë seksion */
$submittedByAssign = [];
if ($userRole === 'Student' && $hasSectionAssignments) {
    $ids = array_map(fn($a) => (int)$a['id'], $sectionAssignments);
    $ids = array_values(array_unique(array_filter($ids)));
    if ($ids) {
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $params = array_merge([$user_id], $ids);
        $stSA = $pdo->prepare("
            SELECT DISTINCT assignment_id
            FROM assignments_submitted
            WHERE user_id = ? AND assignment_id IN ($placeholders)
        ");
        $stSA->execute($params);
        while ($row = $stSA->fetch(PDO::FETCH_ASSOC)) {
            $submittedByAssign[(int)$row['assignment_id']] = true;
        }
    }
}

/* ------------------------------ Prev / Next ---------------------------- */
$filterHiddenSQL = ($userRole === 'Student')
    ? "AND COALESCE(a.hidden,0)=0 AND COALESCE(s.hidden,0)=0"
    : "";

$stPrev = $pdo->prepare("
  SELECT a.id, a.title 
  FROM assignments a
  LEFT JOIN sections s ON s.id = a.section_id
  WHERE a.course_id = ? AND a.id < ? $filterHiddenSQL
  ORDER BY a.id DESC LIMIT 1
");
$stPrev->execute([$course_id, $assignment_id]);
$prevAssignment = $stPrev->fetch(PDO::FETCH_ASSOC);

$stNext = $pdo->prepare("
  SELECT a.id, a.title 
  FROM assignments a
  LEFT JOIN sections s ON s.id = a.section_id
  WHERE a.course_id = ? AND a.id > ? $filterHiddenSQL
  ORDER BY a.id ASC LIMIT 1
");
$stNext->execute([$course_id, $assignment_id]);
$nextAssignment = $stNext->fetch(PDO::FETCH_ASSOC);

/* ------------------------------ Submissions ---------------------------- */
// Të gjitha dorëzimet (për Admin/Instruktor)
$stSubs = $pdo->prepare("
  SELECT s.*, u.full_name
  FROM assignments_submitted s
  JOIN users u ON u.id = s.user_id
  WHERE s.assignment_id = ?
  ORDER BY s.submitted_at DESC, s.id DESC
");
$stSubs->execute([$assignment_id]);
$submissions = $stSubs->fetchAll(PDO::FETCH_ASSOC);

// Dorëzimi i studentit (i fundit)
$studentSubmission = null;
if ($userRole === 'Student') {
    $stMine = $pdo->prepare("
        SELECT *
        FROM assignments_submitted
        WHERE assignment_id = ? AND user_id = ?
        ORDER BY id DESC
        LIMIT 1
    ");
    $stMine->execute([$assignment_id, $user_id]);
    $studentSubmission = $stMine->fetch(PDO::FETCH_ASSOC) ?: null;
}

/* ------------------------------ Meta & UI data ------------------------- */
$courseTitle    = (string)($assignment['course_title'] ?? 'Kursi');
$sectionTitle   = (string)($assignment['section_title'] ?? '');
$courseCat      = (string)($assignment['course_category'] ?? '');
$courseCatNice  = $courseCat !== '' ? ucwords(strtolower($courseCat)) : '—';
$coursePhoto    = (string)($assignment['course_photo'] ?? '');
$csrfToken      = csrf_token();
$AREA           = 'MATERIALS';

$hasMaterials = ($resource_path && $resource_exists) || !empty($assignment_files);

/* Breadcrumb destinacionet */
$homeHref = ($userRole === 'Administrator')
    ? 'dashboard_admin.php'
    : (($userRole === 'Instruktor')
        ? 'dashboard_instruktor.php'
        : 'courses_student.php');

$courseDetailsHref = ($userRole === 'Student')
    ? 'course_details_student.php?course_id=' . urlencode((string)$course_id) . '&tab=materials'
    : 'course_details.php?course_id=' . urlencode((string)$course_id) . '&tab=materials';

?><!DOCTYPE html>
<html lang="sq" data-bs-theme="light">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title><?= h($assignment['title']) ?> — Detyrë</title>
  <link rel="icon" href="image/favicon.ico" type="image/x-icon">

  <!-- Fonts -->
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&family=JetBrains+Mono:wght@400;500;600&family=Source+Serif+4:ital,wght@0,400;0,600;1,400;1,600&display=swap" rel="stylesheet">

  <!-- CSS / Icons -->
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css">
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css">
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css">
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/highlight.js/11.7.0/styles/github.min.css">

  <!-- CSS e njëjtë si lesson_details.php -->
  <!-- ZËVENDËSOJE rrugën me atë që përdor aktualisht tek lesson_details.php -->
  <link rel="stylesheet" href="css/km-lesson.css">

  <!-- MathJax (për formula në përshkrimin e detyrës) -->
  <script>
    window.MathJax = {
      tex: {
        inlineMath: [['$', '$'], ['\\(', '\\)']],
        displayMath: [['$$','$$'], ['\\[','\\]']],
        processEscapes: true
      },
      options: {
        skipHtmlTags: ['script','noscript','style','textarea','pre','code']
      }
    };
  </script>
  <script defer src="https://cdn.jsdelivr.net/npm/mathjax@3/es5/tex-chtml.js"></script>
</head>
<body>

<?php
  if ($userRole === 'Administrator')      include __DIR__.'/navbar_logged_administrator.php';
  elseif ($userRole === 'Instruktor')     include __DIR__.'/navbar_logged_instruktor.php';
  else                                    include __DIR__.'/navbar_logged_student.php';
?>

<div class="km-lesson-root">
  <!-- Progress bar leximi (përdoret edhe për detyrën) -->
  <div class="km-lesson-read-progress" id="readProgress"></div>

  <!-- LAYOUT KRYESOR: si Udemy/Moodle -->
  <main class="km-lesson-main">
    <div class="container-fluid py-3 py-md-4">
      <div class="row g-4 km-lesson-layout">
        <!-- Sidebar majtas: Përmbajtja e seksionit (detyrat) + outline i detyrës -->
        <aside class="col-12 col-lg-3 order-2 order-lg-1">
          <div class="km-lesson-sidebar-sticky">
            <!-- Karta: Detyrat e seksionit (si course content) -->
            <div class="km-lesson-sidebar-outline card shadow-sm">
              <div class="card-header d-flex align-items-center justify-content-between">
                <div class="d-flex align-items-center gap-2">
                  <span class="badge rounded-pill bg-primary-subtle text-primary-emphasis">
                    <i class="fa fa-list-ul me-1"></i> Detyrat e seksionit
                  </span>
                </div>
              </div>
              <div class="card-body p-0">
                <div class="km-lesson-outline-section-title px-3 pt-3 pb-2">
                  <div class="small text-uppercase text-muted mb-1">Seksioni</div>
                  <div class="fw-semibold">
                    <?= $sectionTitle !== '' ? h($sectionTitle) : 'Pa seksion' ?>
                  </div>
                </div>

                <div class="km-lesson-outline-list">
                  <?php if ($hasSectionAssignments): ?>
                    <?php foreach ($sectionAssignments as $a): ?>
                      <?php
                        $aid       = (int)$a['id'];
                        $isCurrent = ($aid === $assignment_id);
                        $hasSubmit = isset($submittedByAssign[$aid]);
                        $dueTxt    = (!empty($a['due_date']) && $a['due_date'] !== '0000-00-00')
                          ? 'Afati: ' . date('d M', strtotime((string)$a['due_date']))
                          : 'Pa afat';
                      ?>
                      <a href="assignment_details.php?assignment_id=<?= $aid ?>"
                         class="km-lesson-outline-item km-lesson-outline-activity <?= $isCurrent ? 'is-current' : '' ?>">
                        <div class="km-lesson-outline-icon">
                          <i class="fa fa-clipboard-list"></i>
                        </div>
                        <div class="km-lesson-outline-text">
                          <div class="km-lesson-outline-title text-truncate">
                            <?= h($a['title']) ?>
                          </div>
                          <div class="km-lesson-outline-meta small text-muted">
                            Detyrë • <?= h($dueTxt) ?>
                          </div>
                        </div>
                        <?php if ($userRole === 'Student'): ?>
                          <?php if ($hasSubmit): ?>
                            <span class="badge bg-success-subtle text-success-emphasis ms-2">Dërguar</span>
                          <?php elseif ($isCurrent): ?>
                            <span class="badge bg-warning-subtle text-warning-emphasis ms-2">Aktuale</span>
                          <?php else: ?>
                            <span class="badge bg-warning-subtle text-warning-emphasis ms-2">Në pritje</span>
                          <?php endif; ?>
                        <?php else: ?>
                          <?php if ((int)($a['hidden'] ?? 0) === 1): ?>
                            <span class="badge bg-secondary-subtle text-secondary-emphasis ms-2">Fshehur</span>
                          <?php endif; ?>
                        <?php endif; ?>
                      </a>
                    <?php endforeach; ?>
                  <?php else: ?>
                    <div class="px-3 pb-3 small text-muted">
                      Nuk ka detyra të tjera në këtë seksion.
                    </div>
                  <?php endif; ?>
                </div>
              </div>
            </div>

            <!-- Karta: Përmbajtja e detyrës (outline nga heading-et) -->
            <div class="km-lesson-sidebar-outline card shadow-sm mt-3">
              <div class="card-header d-flex align-items-center">
                <i class="fa fa-diagram-project me-2"></i>
                <span>Përmbajtja e detyrës</span>
              </div>
              <div class="card-body p-0">
                <nav id="assignment-outline" class="km-lesson-lesson-outline" aria-label="Përmbajtja e detyrës">
                  <div class="px-3 py-2 small text-muted">
                    Kjo detyrë nuk ka ende tituj kryesorë.
                  </div>
                </nav>
              </div>
            </div>
          </div>
        </aside>

        <!-- Kolona kryesore: "hero" + tabs + dorëzime -->
        <section class="col-12 col-lg-9 order-1 order-lg-2">

          <!-- HERO: meta e detyrës + butonat -->
          <div class="card km-lesson-hero shadow-sm mb-3">
            <!-- Nuk kemi video; përdorim placeholder-in -->
            <div class="km-lesson-hero-media km-lesson-hero-media--placeholder">
              <div class="km-lesson-hero-placeholder-inner">
                <?php if ($coursePhoto): ?>
                  <div class="km-lesson-course-thumb"
                       style="background-image:url('<?= h($coursePhoto) ?>');"></div>
                <?php else: ?>
                  <div class="km-lesson-course-thumb km-lesson-course-thumb--placeholder">
                    <i class="fa fa-clipboard-list"></i>
                  </div>
                <?php endif; ?>
                <div>
                  <div class="small text-uppercase text-muted mb-1">Detyrë e kursit</div>
                  <div class="fw-semibold">
                    Afati: <?= h(fmt_date($due_date)) ?>
                    <?php if ($hasDue): ?>
                      · Koha e mbetur: <?= h($timeLeft) ?>
                    <?php else: ?>
                      · Pa afat
                    <?php endif; ?>
                  </div>
                  <div class="small text-muted">
                    Kursi: <?= h($courseTitle) ?>
                    <?php if ($sectionTitle !== ''): ?>
                      · Seksioni: <?= h($sectionTitle) ?>
                    <?php endif; ?>
                  </div>
                </div>
              </div>
            </div>

            <div class="card-body">
              <div class="d-flex justify-content-between align-items-start flex-wrap gap-2 mb-2">
                <div class="flex-grow-1">
                  <div class="d-flex flex-wrap gap-2 align-items-center mb-2">
                    <span class="km-lesson-badge-cat">
                      <i class="fa fa-tag me-1"></i>Detyrë
                    </span>
                    <?php if ($courseCat): ?>
                      <span class="badge rounded-pill bg-light-subtle text-body-secondary border">
                        <i class="fa fa-layer-group me-1"></i><?= h($courseCatNice) ?>
                      </span>
                    <?php endif; ?>
                    <?php if ($sectionTitle !== ''): ?>
                      <span class="badge rounded-pill bg-light-subtle text-body-secondary border">
                        <i class="fa fa-folder-tree me-1"></i><?= h($sectionTitle) ?>
                      </span>
                    <?php endif; ?>
                    <?php if (($userRole === 'Administrator' || $isOwnerInstructor) && $effectiveHidden): ?>
                      <?php if ($assignHidden): ?>
                        <span class="badge rounded-pill bg-secondary-subtle text-secondary-emphasis border">
                          <i class="fa fa-eye-slash me-1"></i>Detyra e fshehur
                        </span>
                      <?php endif; ?>
                      <?php if ($sectionHidden): ?>
                        <span class="badge rounded-pill bg-secondary-subtle text-secondary-emphasis border">
                          <i class="fa fa-folder-minus me-1"></i>Seksioni i fshehur
                        </span>
                      <?php endif; ?>
                    <?php endif; ?>
                  </div>

                  <h1 class="km-lesson-hero-title h3 mb-1">
                    <?= h($assignment['title']) ?>
                  </h1>

                  <div class="km-lesson-hero-meta small text-muted">
                    <span>
                      <i class="fa-regular fa-calendar me-1"></i>
                      Afati: <?= h(fmt_date($due_date)) ?>
                    </span>
                    <?php if ($hasDue): ?>
                      <span class="ms-3">
                        <i class="fa-regular fa-hourglass-half me-1"></i>
                        Koha e mbetur: <?= h($timeLeft) ?>
                      </span>
                    <?php endif; ?>
                    <?php if ($hasMaterials): ?>
                      <span class="ms-3">
                        <i class="fa fa-paperclip me-1"></i>
                        Materiale: kryesor + <?= count($assignment_files) ?> shtesë
                      </span>
                    <?php endif; ?>
                  </div>
                </div>

                <div class="km-lesson-hero-actions text-end">
                  <?php if ($userRole === 'Student'): ?>
                    <?php if ($studentSubmission): ?>
                      <?php if ($studentSubmission['grade'] !== null): ?>
                        <div class="badge bg-success-subtle text-success-emphasis px-3 py-2 rounded-pill mb-2">
                          <i class="fa fa-check-circle me-1"></i> Vlerësuar (nota: <?= h((string)$studentSubmission['grade']) ?>)
                        </div>
                      <?php else: ?>
                        <div class="badge bg-warning-subtle text-warning-emphasis px-3 py-2 rounded-pill mb-2">
                          <i class="fa fa-hourglass-half me-1"></i> Dorëzuar, në pritje
                        </div>
                      <?php endif; ?>
                    <?php endif; ?>

                    <?php if (!$deadlinePassed): ?>
                      <a class="btn btn-primary btn-sm rounded-pill km-lesson-mark-btn"
                         href="add_submission.php?assignment_id=<?= (int)$assignment['id'] ?>&course_id=<?= $course_id ?>">
                        <i class="fa fa-cloud-upload-alt me-1"></i> Dorëzo detyrën
                      </a>
                    <?php else: ?>
                      <button class="btn btn-outline-secondary btn-sm rounded-pill km-lesson-mark-btn" disabled>
                        <i class="fa fa-lock me-1"></i> Afati ka skaduar
                      </button>
                    <?php endif; ?>
                  <?php endif; ?>

                  <?php if ($userRole === 'Administrator' || $userRole === 'Instruktor'): ?>
                    <div class="dropdown mt-2">
                      <button class="btn btn-outline-secondary btn-sm rounded-pill" type="button" data-bs-toggle="dropdown">
                        <i class="fa fa-gear me-1"></i> Menaxho
                      </button>
                      <ul class="dropdown-menu dropdown-menu-end">
                        <li>
                          <a class="dropdown-item" href="admin/edit_assignment.php?assignment_id=<?= (int)$assignment['id'] ?>">
                            <i class="fa fa-edit me-2"></i> Modifiko detyrën
                          </a>
                        </li>
                        <li><hr class="dropdown-divider"></li>
                        <li>
                          <form method="POST"
                                action="delete_assignment.php"
                                onsubmit="return confirm('Fshi përfundimisht këtë detyrë?');"
                                class="px-3 py-1">
                            <input type="hidden" name="csrf" value="<?= h($csrfToken) ?>">
                            <input type="hidden" name="course_id" value="<?= $course_id ?>">
                            <input type="hidden" name="assignment_id" value="<?= $assignment_id ?>">
                            <input type="hidden" name="area" value="<?= h($AREA) ?>">
                            <button class="btn btn-sm btn-danger w-100">
                              <i class="fa fa-trash me-1"></i> Fshi detyrën
                            </button>
                          </form>
                        </li>
                      </ul>
                    </div>
                  <?php endif; ?>
                </div>
              </div>
            </div>
          </div>

          <!-- Tabs: Përshkrimi / Materialet -->
          <div class="card km-lesson-tabcard shadow-sm mb-3">
            <div class="card-header border-0 pb-0">
              <ul class="nav nav-underline km-lesson-tabs" role="tablist">
                <li class="nav-item" role="presentation">
                  <button class="nav-link active"
                          id="tab-desc-tab"
                          data-bs-toggle="tab"
                          data-bs-target="#tab-desc"
                          type="button"
                          role="tab"
                          aria-controls="tab-desc"
                          aria-selected="true">
                    <i class="fa fa-file-lines me-1"></i> Përshkrimi
                  </button>
                </li>
                <?php if ($hasMaterials): ?>
                  <li class="nav-item" role="presentation">
                    <button class="nav-link"
                            id="tab-materials-tab"
                            data-bs-toggle="tab"
                            data-bs-target="#tab-materials"
                            type="button"
                            role="tab"
                            aria-controls="tab-materials"
                            aria-selected="false">
                      <i class="fa fa-download me-1"></i> Materialet
                    </button>
                  </li>
                <?php endif; ?>
              </ul>
            </div>

            <div class="card-body pt-3">
              <div class="tab-content">
                <!-- TAB 1: Përshkrimi (Markdown) -->
                <div class="tab-pane fade show active"
                     id="tab-desc"
                     role="tabpanel"
                     aria-labelledby="tab-desc-tab">
                  <?php if ($hasDescription): ?>
                    <article class="lesson-content prose" id="assignment-prose">
                      <?= $descriptionHtml ?>
                    </article>
                  <?php else: ?>
                    <div class="text-muted small">
                      Kjo detyrë nuk ka përshkrim tekstual të plotësuar ende.
                    </div>
                  <?php endif; ?>
                </div>

                <!-- TAB 2: Materialet (skedari i detyrës + shtesat) -->
                <?php if ($hasMaterials): ?>
                  <div class="tab-pane fade"
                       id="tab-materials"
                       role="tabpanel"
                       aria-labelledby="tab-materials-tab">

                    <!-- Skedari kryesor i detyrës -->
                    <div class="km-lesson-resource-group mb-3">
                      <div class="km-lesson-file-group-title small text-uppercase text-muted mb-1">
                        Skedari i detyrës
                      </div>
                      <div class="km-assign-file-card d-flex align-items-center justify-content-between">
                        <div class="d-flex align-items-center gap-3">
                          <div class="km-assign-file-icon">
                            <?php if ($resource_path && $resource_exists): ?>
                              <?php $rExt = strtolower(pathinfo($resource_path, PATHINFO_EXTENSION)); ?>
                              <i class="fa-regular <?= icon_for_ext($rExt) ?>"></i>
                            <?php else: ?>
                              <i class="fa-regular fa-file text-muted"></i>
                            <?php endif; ?>
                          </div>
                          <div>
                            <div class="fw-semibold">
                              <?= $resource_path && $resource_exists ? h(basename($resource_path)) : 'Nuk ka skedar kryesor' ?>
                            </div>
                            <div class="small text-muted">
                              <?php if ($resource_path && $resource_exists): ?>
                                <?= h(file_size_pretty($resource_abs)) ?>
                              <?php else: ?>
                                Nuk është bashkangjitur skedar detyre.
                              <?php endif; ?>
                            </div>
                          </div>
                        </div>
                        <?php if ($resource_path && $resource_exists): ?>
                          <a class="btn btn-sm btn-outline-primary rounded-pill"
                             href="<?= h($resource_path) ?>"
                             download>
                            <i class="fa fa-arrow-down me-1"></i> Shkarko
                          </a>
                        <?php endif; ?>
                      </div>
                    </div>

                    <!-- Skedarë shtesë -->
                    <?php if (!empty($assignment_files)): ?>
                      <div class="km-lesson-resource-group mb-3">
                        <div class="km-lesson-file-group-title small text-uppercase text-muted mb-1">
                          Burime shtesë
                        </div>
                        <ul class="list-group list-group-flush km-lesson-resource-list">
                          <?php foreach ($assignment_files as $f): ?>
                            <?php
                              $af_path = (string)$f['file_path'];
                              $af_abs  = $af_path ? (__DIR__ . '/' . ltrim($af_path, '/')) : '';
                              $af_ok   = $af_abs && is_file($af_abs);
                              $af_ext  = $af_path ? strtolower(pathinfo($af_path, PATHINFO_EXTENSION)) : '';
                            ?>
                            <li class="list-group-item d-flex align-items-center justify-content-between px-0">
                              <div class="d-flex align-items-center">
                                <div class="km-lesson-file-icon me-2">
                                  <i class="fa-regular <?= icon_for_ext($af_ext) ?>"></i>
                                </div>
                                <div>
                                  <div class="fw-semibold text-truncate" style="max-width: 260px;">
                                    <?= h(basename($af_path)) ?>
                                  </div>
                                  <div class="small text-muted">
                                    <?= $af_ok ? h(file_size_pretty($af_abs)) : 'Skedari nuk u gjet në server.' ?>
                                  </div>
                                </div>
                              </div>
                              <?php if ($af_ok): ?>
                                <a href="<?= h($af_path) ?>"
                                   class="btn btn-sm btn-outline-primary rounded-pill"
                                   target="_blank"
                                   download>
                                  <i class="fa fa-arrow-down me-1"></i> Shkarko
                                </a>
                              <?php endif; ?>
                            </li>
                          <?php endforeach; ?>
                        </ul>
                      </div>
                    <?php endif; ?>

                    <!-- Zgjidhja e detyrës -->
                    <div class="km-lesson-resource-group mb-3">
                      <div class="km-lesson-file-group-title small text-uppercase text-muted mb-1">
                        Zgjidhja e detyrës
                      </div>
                      <?php if ($solution_path && $solution_exists): ?>
                        <?php if (in_array($userRole, ['Administrator','Instruktor'], true) || $deadlinePassed): ?>
                          <div class="km-assign-file-card d-flex align-items-center justify-content-between">
                            <div class="d-flex align-items-center gap-3">
                              <div class="km-assign-file-icon">
                                <?php $sExt = strtolower(pathinfo($solution_path, PATHINFO_EXTENSION)); ?>
                                <i class="fa-regular <?= icon_for_ext($sExt) ?>"></i>
                              </div>
                              <div>
                                <div class="fw-semibold"><?= h(basename($solution_path)) ?></div>
                                <div class="small text-muted"><?= h(file_size_pretty($solution_abs)) ?></div>
                              </div>
                            </div>
                            <a class="btn btn-sm btn-success rounded-pill"
                               href="<?= h($solution_path) ?>"
                               download>
                              <i class="fa fa-arrow-down me-1"></i> Shkarko
                            </a>
                          </div>
                          <?php if (in_array($userRole, ['Administrator','Instruktor'], true) && !$deadlinePassed): ?>
                            <div class="small text-muted mt-2">
                              * Aktualisht e dukshme vetëm për ju. Studentët e shohin pas datës <?= h(fmt_date($due_date)) ?>.
                            </div>
                          <?php endif; ?>
                        <?php else: ?>
                          <div class="km-assign-lock-card mt-1">
                            <div class="d-flex align-items-center justify-content-between">
                              <div class="d-flex align-items-center gap-3">
                                <div class="km-assign-lock-icon">
                                  <i class="fa fa-lock"></i>
                                </div>
                                <div>
                                  <div class="fw-semibold">Zgjidhja është e bllokuar</div>
                                  <div class="small text-muted">Zgjidhja hapet automatikisht pas skadimit të afatit.</div>
                                </div>
                              </div>
                              <span class="km-status-pill km-status-pill-warn">
                                <i class="fa fa-hourglass-half me-1"></i>Në pritje
                              </span>
                            </div>
                          </div>
                        <?php endif; ?>
                      <?php else: ?>
                        <div class="text-muted small">
                          Nuk ka skedar zgjidhjeje për këtë detyrë.
                        </div>
                      <?php endif; ?>
                    </div>

                  </div>
                <?php endif; ?>
              </div>
            </div>
          </div>

          <!-- Dorëzimet -->
          <div class="card km-lesson-tabcard shadow-sm mb-3" id="submissions">
            <div class="card-header border-0 pb-0">
              <?php if (in_array($userRole, ['Administrator','Instruktor'], true)): ?>
                <h2 class="h6 mb-0">
                  <i class="fa fa-people-group me-1"></i> Dorëzimet e studentëve
                </h2>
              <?php else: ?>
                <h2 class="h6 mb-0">
                  <i class="fa fa-paper-plane me-1"></i> Dorëzimi juaj
                </h2>
              <?php endif; ?>
            </div>
            <div class="card-body pt-3">
              <?php if (in_array($userRole, ['Administrator','Instruktor'], true)): ?>
                <?php if ($submissions): ?>
                  <div class="vstack gap-2">
                    <?php foreach ($submissions as $s): ?>
                      <article class="km-assign-submission-card">
                        <header class="km-assign-submission-header">
                          <div class="km-assign-submission-user">
                            <div class="km-assign-avatar">
                              <?= strtoupper(substr((string)$s['full_name'], 0, 1)) ?>
                            </div>
                            <div>
                              <div class="fw-semibold small text-truncate" style="max-width: 230px;">
                                <?= h($s['full_name']) ?>
                              </div>
                              <div class="small text-muted">
                                <i class="fa fa-clock me-1"></i>
                                <?= h(date('d M Y, H:i', strtotime((string)$s['submitted_at']))) ?>
                              </div>
                            </div>
                          </div>
                          <div class="km-assign-submission-status">
                            <?php if ($s['grade'] !== null): ?>
                              <span class="km-status-pill km-status-pill-ok">
                                <i class="fa fa-check-circle me-1"></i> Nota: <?= h((string)$s['grade']) ?>
                              </span>
                            <?php else: ?>
                              <span class="km-status-pill km-status-pill-warn">
                                <i class="fa fa-hourglass-half me-1"></i> Pa vlerësuar
                              </span>
                            <?php endif; ?>
                          </div>
                        </header>

                        <div class="km-assign-submission-body mt-1">
                          <div class="d-flex align-items-center justify-content-between flex-wrap gap-2">
                            <div class="d-flex gap-2">
                              <?php if (!empty($s['file_path'])): ?>
                                <a class="btn btn-sm btn-outline-primary rounded-pill"
                                   href="<?= h($s['file_path']) ?>">
                                  <i class="fa fa-download me-1"></i> Skedari
                                </a>
                              <?php endif; ?>
                            </div>
                            <div>
                              <?php if ($s['grade'] === null): ?>
                                <a class="btn btn-sm btn-outline-secondary rounded-pill"
                                   href="add_grade.php?submission_id=<?= (int)$s['id'] ?>">
                                  <i class="fa fa-pencil-square me-1"></i> Vlerëso
                                </a>
                              <?php endif; ?>
                            </div>
                          </div>

                          <?php if (!empty($s['feedback'])): ?>
                            <div class="km-assign-submission-feedback mt-2">
                              <div class="small text-muted mb-1">
                                <i class="fa fa-comment-dots me-1"></i> Feedback
                              </div>
                              <div class="small"><?= nl2br(h($s['feedback'])) ?></div>
                            </div>
                          <?php endif; ?>
                        </div>
                      </article>
                    <?php endforeach; ?>
                  </div>
                <?php else: ?>
                  <div class="text-muted small">Ende nuk ka dorëzime për këtë detyrë.</div>
                <?php endif; ?>
              <?php else: ?>
                <?php if ($studentSubmission): ?>
                  <?php if ($studentSubmission['grade'] !== null): ?>
                    <div class="km-assign-submission-alert km-assign-submission-graded">
                      <div class="d-flex justify-content-between align-items-center flex-wrap gap-2">
                        <div>
                          <div class="fw-bold mb-1">
                            <i class="fa fa-check-circle me-1"></i>
                            Nota: <?= h((string)$studentSubmission['grade']) ?>
                          </div>
                          <?php if (!empty($studentSubmission['feedback'])): ?>
                            <div class="small">
                              Feedback: <?= h($studentSubmission['feedback']) ?>
                            </div>
                          <?php endif; ?>
                          <div class="small text-muted mt-1">
                            <i class="fa fa-clock me-1"></i>
                            <?= h(date('d M Y, H:i', strtotime((string)$studentSubmission['submitted_at']))) ?>
                          </div>
                        </div>
                        <div class="d-flex flex-column align-items-end gap-2">
                          <span class="km-status-pill km-status-pill-ok">Vlerësuar</span>
                          <?php if (!empty($studentSubmission['file_path'])): ?>
                            <a class="btn btn-sm btn-outline-success rounded-pill"
                               href="<?= h($studentSubmission['file_path']) ?>">
                              <i class="fa fa-download me-1"></i> Shkarko dorëzimin
                            </a>
                          <?php endif; ?>
                        </div>
                      </div>
                    </div>
                  <?php else: ?>
                    <div class="km-assign-submission-alert km-assign-submission-pending">
                      <div class="d-flex justify-content-between align-items-center flex-wrap gap-2">
                        <div>
                          <div class="fw-bold mb-1">
                            <i class="fa fa-hourglass-half me-1"></i> Dorëzimi u pranua
                          </div>
                          <div class="small text-muted">
                            Më: <?= h(date('d M Y, H:i', strtotime((string)$studentSubmission['submitted_at']))) ?>
                          </div>
                        </div>
                        <span class="km-status-pill km-status-pill-warn">Në pritje të vlerësimit</span>
                      </div>
                      <?php if (!$deadlinePassed): ?>
                        <div class="mt-2">
                          <a class="btn btn-sm btn-outline-primary rounded-pill w-100"
                             href="add_submission.php?assignment_id=<?= $assignment_id ?>&course_id=<?= $course_id ?>">
                            <i class="fa fa-pencil-square me-1"></i> Ridorëzo detyrën
                          </a>
                        </div>
                      <?php endif; ?>
                    </div>
                  <?php endif; ?>
                <?php elseif ($deadlinePassed): ?>
                  <div class="km-assign-submission-alert km-assign-submission-closed">
                    <div class="d-flex align-items-center gap-3">
                      <div class="km-assign-lock-icon">
                        <i class="fa fa-xmark"></i>
                      </div>
                      <div>
                        <div class="fw-bold mb-1">Afati i detyrës ka skaduar</div>
                        <div class="small text-muted">Nuk mund të dorëzoni më për këtë detyrë.</div>
                      </div>
                    </div>
                  </div>
                <?php else: ?>
                  <div class="mb-3 text-muted small">
                    Nuk keni dorëzuar ende. Mund të ngarkoni një skedar me zgjidhjen tuaj.
                  </div>
                  <a class="btn btn-primary rounded-pill"
                     href="add_submission.php?assignment_id=<?= $assignment_id ?>&course_id=<?= $course_id ?>">
                    <i class="fa fa-cloud-upload-alt me-1"></i> Dorëzo detyrën
                  </a>
                <?php endif; ?>
              <?php endif; ?>
            </div>
          </div>

          <!-- Prev / Next detyrë -->
          <?php if ($prevAssignment || $nextAssignment): ?>
            <div class="card km-lesson-tabcard shadow-sm mb-3">
              <div class="card-body d-flex justify-content-between gap-2 flex-wrap">
                <?php if ($prevAssignment): ?>
                  <a href="assignment_details.php?assignment_id=<?= (int)$prevAssignment['id'] ?>"
                     class="km-assign-prevnext-link">
                    <div class="small text-muted text-uppercase">Paraardhësja</div>
                    <div class="fw-semibold text-truncate">
                      <?= h($prevAssignment['title']) ?>
                    </div>
                  </a>
                <?php else: ?>
                  <span></span>
                <?php endif; ?>

                <?php if ($nextAssignment): ?>
                  <a href="assignment_details.php?assignment_id=<?= (int)$nextAssignment['id'] ?>"
                     class="km-assign-prevnext-link text-end">
                    <div class="small text-muted text-uppercase">Tjetra</div>
                    <div class="fw-semibold text-truncate">
                      <?= h($nextAssignment['title']) ?>
                    </div>
                  </a>
                <?php endif; ?>
              </div>
            </div>
          <?php endif; ?>

        </section>
      </div>
    </div>
  </main>

  <?php include __DIR__ . '/footer2.php'; ?>
</div>

<!-- Toasts (flash mesazhet) -->
<div class="toast-container position-fixed top-0 end-0 p-3">
  <?php if (function_exists('flash_get') && ($m = flash_get('success'))): ?>
    <div class="toast align-items-center text-bg-success border-0 show" role="alert">
      <div class="d-flex">
        <div class="toast-body">
          <i class="fa fa-check-circle me-2"></i><?= h($m) ?>
        </div>
        <button type="button" class="btn-close btn-close-white me-2 m-auto" data-bs-dismiss="toast"></button>
      </div>
    </div>
  <?php endif; ?>
  <?php if (function_exists('flash_get') && ($m = flash_get('error'))): ?>
    <div class="toast align-items-center text-bg-danger border-0 show" role="alert">
      <div class="d-flex">
        <div class="toast-body">
          <i class="fa fa-exclamation-triangle me-2"></i><?= h($m) ?>
        </div>
        <button type="button" class="btn-close btn-close-white me-2 m-auto" data-bs-dismiss="toast"></button>
      </div>
    </div>
  <?php endif; ?>
</div>

<!-- JS -->
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/highlight.js/11.7.0/highlight.min.js"></script>
<script>
document.addEventListener('DOMContentLoaded', () => {
  const proseRoot =
    document.getElementById('assignment-prose') ||
    document.getElementById('lesson-prose');

  /* -------------------------- highlight.js --------------------------- */
  document.querySelectorAll('pre code').forEach(el => {
    try { hljs.highlightElement(el); } catch (e) {}
  });

  /* --------- Codeblocks modernë (me gjuhë + traffic lights + Copy) --- */
  if (proseRoot) {
    const langMeta = (raw) => {
      const norm = (raw || '').toLowerCase();
      let key = 'code';
      if (['py','python'].includes(norm)) key = 'python';
      else if (['c'].includes(norm)) key = 'c';
      else if (['cpp','c++'].includes(norm)) key = 'cpp';
      else if (['java'].includes(norm)) key = 'java';
      else if (['php'].includes(norm)) key = 'php';
      else if (['js','javascript'].includes(norm)) key = 'js';
      else if (['ts','typescript'].includes(norm)) key = 'ts';
      else if (['html','xml'].includes(norm)) key = 'html';
      else if (['css'].includes(norm)) key = 'css';
      else if (['sql'].includes(norm)) key = 'sql';
      else if (norm) key = norm;

      const map = {
        python: { label: 'Python',    icon: 'fa-brands fa-python' },
        c:      { label: 'C',         icon: 'fa-solid fa-code' },
        cpp:    { label: 'C++',       icon: 'fa-solid fa-code' },
        java:   { label: 'Java',      icon: 'fa-brands fa-java' },
        php:    { label: 'PHP',       icon: 'fa-brands fa-php' },
        js:     { label: 'JavaScript',icon: 'fa-brands fa-js' },
        ts:     { label: 'TypeScript',icon: 'fa-solid fa-code' },
        html:   { label: 'HTML',      icon: 'fa-brands fa-html5' },
        css:    { label: 'CSS',       icon: 'fa-brands fa-css3-alt' },
        sql:    { label: 'SQL',       icon: 'fa-solid fa-database' },
        code:   { label: 'Code',      icon: 'fa-solid fa-code' },
      };
      return map[key] || { label: raw || 'Code', icon: 'fa-solid fa-code' };
    };

    proseRoot.querySelectorAll('pre').forEach(pre => {
      const code = pre.querySelector('code');
      if (!code) return;

      let classLang = [...code.classList].find(c => c.startsWith('language-')) || '';
      classLang = classLang.replace('language-', '');
      let rawLang = (code.getAttribute('data-lang') || classLang || '').trim();
      const meta = langMeta(rawLang);

      const wrapper = document.createElement('div');
      wrapper.className = 'km-codeblock';

      const header = document.createElement('div');
      header.className = 'km-codeblock-header';

      const metaDiv = document.createElement('div');
      metaDiv.className = 'km-codeblock-meta';

      const dots = document.createElement('div');
      dots.className = 'km-codeblock-dots';
      ['red','amber','green'].forEach(color => {
        const dot = document.createElement('span');
        dot.className = 'km-codeblock-dot km-codeblock-dot-' + color;
        dots.appendChild(dot);
      });

      const langSpan = document.createElement('span');
      langSpan.className = 'km-codeblock-lang';
      langSpan.innerHTML = '<i class="' + meta.icon + ' me-1"></i>' + meta.label;

      metaDiv.appendChild(dots);
      metaDiv.appendChild(langSpan);

      const actions = document.createElement('div');
      actions.className = 'km-codeblock-actions';

      const copyBtn = document.createElement('button');
      copyBtn.type = 'button';
      copyBtn.className = 'btn btn-xs btn-outline-light km-codeblock-copy';
      copyBtn.innerHTML = '<i class="fa-regular fa-copy me-1"></i>Copy';

      copyBtn.addEventListener('click', async () => {
        try {
          await navigator.clipboard.writeText(code.innerText);
          copyBtn.innerHTML = '<i class="fa-solid fa-check me-1"></i>Copied';
          copyBtn.classList.add('copied');
          setTimeout(() => {
            copyBtn.innerHTML = '<i class="fa-regular fa-copy me-1"></i>Copy';
            copyBtn.classList.remove('copied');
          }, 1200);
        } catch {
          copyBtn.innerHTML = '<i class="fa-solid fa-triangle-exclamation me-1"></i>Error';
          setTimeout(() => {
            copyBtn.innerHTML = '<i class="fa-regular fa-copy me-1"></i>Copy';
          }, 1200);
        }
      });

      actions.appendChild(copyBtn);

      header.appendChild(metaDiv);
      header.appendChild(actions);

      pre.parentNode.insertBefore(wrapper, pre);
      wrapper.appendChild(header);
      wrapper.appendChild(pre);
    });

    /* Tables → table-responsive wrapper */
    proseRoot.querySelectorAll('table').forEach(tbl => {
      if (tbl.closest('.table-responsive')) return;
      const wrap = document.createElement('div');
      wrap.className = 'table-responsive';
      tbl.parentNode.insertBefore(wrap, tbl);
      wrap.appendChild(tbl);
    });

    /* External links → hapen në tab të ri */
    proseRoot.querySelectorAll('a[href^="http"]').forEach(a => {
      a.setAttribute('target', '_blank');
      a.setAttribute('rel', 'noopener');
    });

    /* -------------------- Outline i detyrës (TOC) -------------------- */
    const outlineRoot =
      document.getElementById('assignment-outline') ||
      document.getElementById('lesson-outline');

    if (outlineRoot && proseRoot) {
      const allHeadings = Array.from(
        proseRoot.querySelectorAll('h1, h2, h3, h4, h5, h6')
      );

      if (!allHeadings.length) {
        outlineRoot.innerHTML =
          '<div class="px-3 py-2 small text-muted">Kjo detyrë nuk ka ende tituj kryesorë.</div>';
      } else {
        const levels = allHeadings.map(h => parseInt(h.tagName.substring(1), 10));
        const minLevel = Math.min.apply(null, levels);
        const secondLevel = minLevel + 1;

        const ul = document.createElement('ul');
        ul.className = 'km-lesson-lesson-outline-list list-unstyled mb-0';

        let lastTopLi = null;

        allHeadings.forEach((h, idx) => {
          const level = parseInt(h.tagName.substring(1), 10);
          if (level !== minLevel && level !== secondLevel) return;

          if (!h.id) {
            h.id = 'sec-' + (idx + 1);
          }

          const li = document.createElement('li');
          li.className = 'km-lesson-lesson-outline-item toc-level-' + (level === minLevel ? '1' : '2');

          const a = document.createElement('a');
          a.href = '#' + h.id;
          a.textContent = h.textContent.trim();
          a.className = 'km-lesson-lesson-outline-link';

          a.addEventListener('click', function (ev) {
            ev.preventDefault();
            const target = document.getElementById(h.id);
            if (target) {
              target.scrollIntoView({ behavior: 'smooth', block: 'start' });
            }
          });

          li.appendChild(a);

          if (level === minLevel) {
            ul.appendChild(li);
            lastTopLi = li;
          } else if (level === secondLevel && lastTopLi) {
            let sub = lastTopLi.querySelector('ul');
            if (!sub) {
              sub = document.createElement('ul');
              sub.className = 'km-lesson-lesson-outline-sublist list-unstyled';
              lastTopLi.appendChild(sub);
            }
            sub.appendChild(li);
          }
        });

        outlineRoot.innerHTML = '';
        if (ul.children.length) {
          outlineRoot.appendChild(ul);
        } else {
          outlineRoot.innerHTML =
            '<div class="px-3 py-2 small text-muted">Kjo detyrë nuk ka ende tituj kryesorë.</div>';
        }
      }
    }
  }

  /* ----------------------- Reading progress bar ----------------------- */
  const progressBar = document.getElementById('readProgress');
  if (progressBar) {
    const onScroll = () => {
      const doc = document.documentElement;
      const scrollTop = doc.scrollTop || document.body.scrollTop || 0;
      const height    = (doc.scrollHeight - doc.clientHeight) || 1;
      const pct       = Math.max(0, Math.min(100, (scrollTop / height) * 100));
      progressBar.style.width = pct + '%';
    };
    window.addEventListener('scroll', onScroll, { passive: true });
    onScroll();
  }
});
</script>
</body>
</html>
