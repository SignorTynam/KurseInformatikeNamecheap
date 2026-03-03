<?php
// assignments.php — Pamje e re (LMS-style) për të gjitha detyrat e instruktori(t)
// FRESH: vetëm leximi i detyrave, pa veprime, pa varësi nga skedari i vjetër.

declare(strict_types=1);
require_once __DIR__ . '/lib/bootstrap.php'; // duhet të ketë $pdo dhe session_start()

/* -------------------- RBAC i thjeshtë -------------------- */
if (empty($_SESSION['user'])) {
    header('Location: login.php');
    exit;
}

$ROLE  = (string)($_SESSION['user']['role'] ?? '');
$ME_ID = (int)($_SESSION['user']['id'] ?? 0);

if (!in_array($ROLE, ['Administrator', 'Instruktor'], true)) {
    header('Location: login.php');
    exit;
}

/* -------------------- Helper i vogël ---------------------- */
function h(?string $s): string {
    return htmlspecialchars($s ?? '', ENT_QUOTES, 'UTF-8');
}

$today = date('Y-m-d');

/* -------------------- Input-et e filtrave ----------------- */

$search    = trim((string)($_GET['q'] ?? ''));
$course_id = (int)($_GET['course_id'] ?? 0);

// Vetëm admini mund të filtrojë sipas instruktori
$instructor_id = 0;
if ($ROLE === 'Administrator') {
    $instructor_id = (int)($_GET['instructor_id'] ?? 0);
}

// Pamja kohore: all | upcoming | overdue | grading
$viewRaw = (string)($_GET['view'] ?? 'all');
$VIEW_ALLOWED = ['all','upcoming','overdue','grading'];
$view = in_array($viewRaw, $VIEW_ALLOWED, true) ? $viewRaw : 'all';

// Has submissions: any | with | without
$hasSubRaw = (string)($_GET['has_sub'] ?? 'any');
$HAS_SUB_ALLOWED = ['any','with','without'];
$has_sub = in_array($hasSubRaw, $HAS_SUB_ALLOWED, true) ? $hasSubRaw : 'any';

// Sortimi
$sortRaw = (string)($_GET['sort'] ?? 'due_asc');
$SORT_ALLOWED = [
    'due_asc','due_desc',
    'title_asc','title_desc',
    'created_desc','created_asc',
    'submissions_desc','submissions_asc',
];
$sort = in_array($sortRaw, $SORT_ALLOWED, true) ? $sortRaw : 'due_asc';

// Pagination e thjeshtë
$per_page = (int)($_GET['per_page'] ?? 10);
$per_page = min(max($per_page, 5), 60);
$page     = max(1, (int)($_GET['page'] ?? 1));
$offset   = ($page - 1) * $per_page;

/* -------------------- Dropdown data ----------------------- */

$coursesOptions     = [];
$instructorsOptions = [];

try {
    if ($ROLE === 'Instruktor') {
        // Kurset e këtij instruktori
        $stmt = $pdo->prepare("
            SELECT id, title
            FROM courses
            WHERE id_creator = :me
            ORDER BY title
        ");
        $stmt->execute([':me' => $ME_ID]);
        $coursesOptions = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } else {
        // Admin: të gjitha kurset
        $stmt = $pdo->query("SELECT id, title FROM courses ORDER BY title");
        $coursesOptions = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

        // Admin: lista e instruktorëve
        $stmt = $pdo->query("
            SELECT id, full_name
            FROM users
            WHERE role IN ('Instruktor','Administrator')
            ORDER BY full_name
        ");
        $instructorsOptions = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }
} catch (Throwable $e) {
    $coursesOptions     = [];
    $instructorsOptions = [];
}

/* -------------------- WHERE bazë (filtra) ---------------- */

$FROM_BASE   = "FROM assignments a JOIN courses c ON c.id = a.course_id";
$whereBase   = [];
$paramsBase  = [];

// Instruktor: vetëm kurset që ka krijuar ai
if ($ROLE === 'Instruktor') {
    $whereBase[]          = "c.id_creator = :me";
    $paramsBase[':me']    = $ME_ID;
}
// Administrator: mund të filtrojë sipas instruktori
elseif ($ROLE === 'Administrator' && $instructor_id > 0) {
    $whereBase[]             = "c.id_creator = :instr";
    $paramsBase[':instr']    = $instructor_id;
}

if ($course_id > 0) {
    $whereBase[]             = "c.id = :course_id";
    $paramsBase[':course_id'] = $course_id;
}

if ($search !== '') {
    $whereBase[]        = "(a.title LIKE :q OR c.title LIKE :q)";
    $paramsBase[':q']   = "%{$search}%";
}

// Has submissions filter
if ($has_sub === 'with') {
    $whereBase[] = "EXISTS (SELECT 1 FROM assignments_submitted s WHERE s.assignment_id = a.id)";
} elseif ($has_sub === 'without') {
    $whereBase[] = "NOT EXISTS (SELECT 1 FROM assignments_submitted s WHERE s.assignment_id = a.id)";
}

$baseWhereSql = $whereBase ? ('WHERE ' . implode(' AND ', $whereBase)) : '';

/* -------------------- KPI-të (statistika) ---------------- */

$totalAssignments   = 0;
$totalWithSub       = 0;
$totalNeedingGrade  = 0;
$totalOverdue       = 0;
$totalUpcoming      = 0;

try {
    // Total detyra
    $where = $whereBase;
    $params = $paramsBase;
    $whereSql = $where ? ('WHERE ' . implode(' AND ', $where)) : '';
    $sql = "SELECT COUNT(*) {$FROM_BASE} {$whereSql}";
    $st = $pdo->prepare($sql);
    $st->execute($params);
    $totalAssignments = (int)$st->fetchColumn();

    // Detyra me të paktën një dorëzim
    $where = $whereBase;
    $params = $paramsBase;
    $where[] = "EXISTS (SELECT 1 FROM assignments_submitted s WHERE s.assignment_id = a.id)";
    $whereSql = 'WHERE ' . implode(' AND ', $where);
    $sql = "SELECT COUNT(*) {$FROM_BASE} {$whereSql}";
    $st = $pdo->prepare($sql);
    $st->execute($params);
    $totalWithSub = (int)$st->fetchColumn();

    // Detyra që kanë dorëzime pa notë (për t’u vlerësuar)
    $where = $whereBase;
    $params = $paramsBase;
    $where[] = "EXISTS (SELECT 1 FROM assignments_submitted s WHERE s.assignment_id = a.id AND s.grade IS NULL)";
    $whereSql = 'WHERE ' . implode(' AND ', $where);
    $sql = "SELECT COUNT(*) {$FROM_BASE} {$whereSql}";
    $st = $pdo->prepare($sql);
    $st->execute($params);
    $totalNeedingGrade = (int)$st->fetchColumn();

    // Detyra me afat kaluar
    $where = $whereBase;
    $params = $paramsBase;
    $where[] = "a.due_date IS NOT NULL AND a.due_date < :today_over";
    $params[':today_over'] = $today;
    $whereSql = 'WHERE ' . implode(' AND ', $where);
    $sql = "SELECT COUNT(*) {$FROM_BASE} {$whereSql}";
    $st = $pdo->prepare($sql);
    $st->execute($params);
    $totalOverdue = (int)$st->fetchColumn();

    // Detyra me afate në të ardhmen
    $where = $whereBase;
    $params = $paramsBase;
    $where[] = "a.due_date IS NOT NULL AND a.due_date >= :today_up";
    $params[':today_up'] = $today;
    $whereSql = 'WHERE ' . implode(' AND ', $where);
    $sql = "SELECT COUNT(*) {$FROM_BASE} {$whereSql}";
    $st = $pdo->prepare($sql);
    $st->execute($params);
    $totalUpcoming = (int)$st->fetchColumn();

} catch (Throwable $e) {
    $totalAssignments = $totalWithSub = $totalNeedingGrade = $totalOverdue = $totalUpcoming = 0;
}

/* -------------------- WHERE për listimin ------------------ */

$whereList   = $whereBase;
$paramsList  = $paramsBase;

// Pamja kohore (view)
if ($view === 'upcoming') {
    $whereList[] = "a.due_date IS NOT NULL AND a.due_date >= :today_up_view";
    $paramsList[':today_up_view'] = $today;
} elseif ($view === 'overdue') {
    $whereList[] = "a.due_date IS NOT NULL AND a.due_date < :today_over_view";
    $paramsList[':today_over_view'] = $today;
} elseif ($view === 'grading') {
    $whereList[] = "EXISTS (
        SELECT 1 FROM assignments_submitted s2
        WHERE s2.assignment_id = a.id AND s2.grade IS NULL
    )";
}

$whereListSql = $whereList ? ('WHERE ' . implode(' AND ', $whereList)) : '';

/* -------------------- Numri total (për pagination) -------- */

$totalFiltered = 0;

try {
    $sql = "SELECT COUNT(*) {$FROM_BASE} {$whereListSql}";
    $st  = $pdo->prepare($sql);
    $st->execute($paramsList);
    $totalFiltered = (int)$st->fetchColumn();
} catch (Throwable $e) {
    $totalFiltered = 0;
}

/* -------------------- ORDER BY ---------------------------- */

$orderMap = [
    'due_asc'          => 'a.due_date IS NULL, a.due_date ASC',
    'due_desc'         => 'a.due_date IS NULL, a.due_date DESC',
    'title_asc'        => 'a.title ASC',
    'title_desc'       => 'a.title DESC',
    'created_desc'     => 'a.uploaded_at DESC',
    'created_asc'      => 'a.uploaded_at ASC',
    'submissions_desc' => 'submissions_total DESC',
    'submissions_asc'  => 'submissions_total ASC',
];
$orderBy = $orderMap[$sort] ?? $orderMap['due_asc'];

/* -------------------- Lista e detyrave -------------------- */

$assignments = [];

try {
    $sql = "
      SELECT
        a.id          AS assignment_id,
        a.title       AS assignment_title,
        a.description AS assignment_description,
        a.due_date    AS due_date,
        a.status      AS assignment_status,
        a.hidden      AS assignment_hidden,
        a.uploaded_at AS created_at,

        c.id          AS course_id,
        c.title       AS course_title,

        (SELECT COUNT(*) FROM enroll e WHERE e.course_id = c.id) AS students_total,
        (SELECT COUNT(*) FROM assignments_submitted s WHERE s.assignment_id = a.id) AS submissions_total,
        (SELECT COUNT(*) FROM assignments_submitted s WHERE s.assignment_id = a.id AND s.grade IS NULL) AS submissions_ungraded,
        (SELECT COUNT(*) FROM assignments_submitted s WHERE s.assignment_id = a.id AND s.grade IS NOT NULL) AS submissions_graded,
        (SELECT AVG(s.grade) FROM assignments_submitted s WHERE s.assignment_id = a.id AND s.grade IS NOT NULL) AS avg_grade
      {$FROM_BASE}
      {$whereListSql}
      ORDER BY {$orderBy}
      LIMIT :limit OFFSET :offset
    ";

    $stmt = $pdo->prepare($sql);
    foreach ($paramsList as $k => $v) {
        $stmt->bindValue($k, $v);
    }
    $stmt->bindValue(':limit',  $per_page, PDO::PARAM_INT);
    $stmt->bindValue(':offset', $offset,   PDO::PARAM_INT);
    $stmt->execute();
    $assignments = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
} catch (Throwable $e) {
    $assignments = [];
}

/* -------------------- Pagination calc --------------------- */
$pages = $per_page > 0 ? (int)ceil($totalFiltered / $per_page) : 1;
$pages = max(1, $pages);

// Baza për linkët e pagination-it
$qsPag = $_GET;
unset($qsPag['page']);
$queryStr = http_build_query($qsPag);
$basePag  = 'assignments.php';
if ($queryStr !== '') {
    $basePag .= '?' . $queryStr;
}
$sepPag = ($queryStr !== '') ? '&' : '?';

?>
<!DOCTYPE html>
<html lang="sq">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <title>Detyrat — Paneli i instruktori(t)</title>

  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css">
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css">
  <link rel="stylesheet" href="css/assignments.css?v=1">
  <link rel="icon" href="image/favicon.ico" type="image/x-icon" />
</head>
<body class="course-body">

<?php
  // Navbar sipas rolit, nëse ekziston
  if ($ROLE === 'Administrator' && file_exists(__DIR__.'/navbar_logged_administrator.php')) {
      include __DIR__.'/navbar_logged_administrator.php';
  } elseif ($ROLE === 'Instruktor' && file_exists(__DIR__.'/navbar_logged_instructor.php')) {
      include __DIR__.'/navbar_logged_instructor.php';
  }
?>

<!-- HERO -->
<header class="course-hero">
  <div class="container">
    <div class="d-flex flex-column gap-2">
      <div class="course-breadcrumb">
        <i class="fa-solid fa-house me-1"></i> Paneli / Detyrat
      </div>

      <div class="d-flex flex-column flex-lg-row align-items-start align-items-lg-center justify-content-between gap-3">
        <div>
          <h1>Detyrat e kurseve</h1>
          <p>Shiko të gjitha detyrat që u janë dhënë studentëve në kurset e tua. Filtrim sipas kursit, afatit dhe dorëzimeve.</p>
        </div>

        <div class="d-flex flex-wrap gap-2">
          <div class="course-stat">
            <div class="icon"><i class="fa-solid fa-layer-group"></i></div>
          </div>
          <div class="course-stat">
            <div class="icon"><i class="fa-solid fa-paper-plane"></i></div>
            <div>
              <div class="label">Me dorëzime</div>
              <div class="value"><?= (int)$totalWithSub ?></div>
            </div>
          </div>
          <div class="course-stat">
            <div class="icon"><i class="fa-solid fa-hourglass-half"></i></div>
            <div>
              <div class="label">Për t’u vlerësuar</div>
              <div class="value"><?= (int)$totalNeedingGrade ?></div>
            </div>
          </div>
          <div class="course-stat">
            <div class="icon"><i class="fa-solid fa-circle-exclamation"></i></div>
            <div>
              <div class="label">Afat i kaluar</div>
              <div class="value"><?= (int)$totalOverdue ?></div>
            </div>
          </div>
        </div>
      </div>
    </div>
  </div>
</header>

<main class="as-main">
  <div class="container">
    <div class="row g-3">
      <!-- Sidebar filtrash -->
      <aside class="col-lg-3">
        <div class="as-sidebar">
          <div class="as-sidebar-header">
            <span class="title"><i class="fa-solid fa-filter me-1"></i> Filtra</span>
          </div>

          <form method="get" class="vstack gap-3">
            <div>
              <label class="as-label">Fjalë kyçe</label>
              <input
                type="search"
                name="q"
                class="form-control"
                value="<?= h($search) ?>"
                placeholder="Titulli i detyrës ose i kursit...">
            </div>

            <div>
              <label class="as-label">Kursi</label>
              <select name="course_id" class="form-select">
                <option value="0">Të gjitha kurset</option>
                <?php foreach ($coursesOptions as $c): ?>
                  <option value="<?= (int)$c['id'] ?>" <?= $course_id===(int)$c['id'] ? 'selected' : '' ?>>
                    <?= h((string)$c['title']) ?>
                  </option>
                <?php endforeach; ?>
              </select>
            </div>

            <?php if ($ROLE === 'Administrator'): ?>
            <div>
              <label class="as-label">Instruktori</label>
              <select name="instructor_id" class="form-select">
                <option value="0">Të gjithë instruktorët</option>
                <?php foreach ($instructorsOptions as $u): ?>
                  <option value="<?= (int)$u['id'] ?>" <?= $instructor_id===(int)$u['id'] ? 'selected' : '' ?>>
                    <?= h((string)$u['full_name']) ?>
                  </option>
                <?php endforeach; ?>
              </select>
            </div>
            <?php endif; ?>

            <div>
              <label class="as-label">Pamja kohore</label>
              <select name="view" class="form-select">
                <option value="all"      <?= $view==='all' ? 'selected' : '' ?>>Të gjitha</option>
                <option value="upcoming" <?= $view==='upcoming' ? 'selected' : '' ?>>Afate në vijim</option>
                <option value="overdue"  <?= $view==='overdue' ? 'selected' : '' ?>>Afat i kaluar</option>
                <option value="grading"  <?= $view==='grading' ? 'selected' : '' ?>>Detyra për t’u vlerësuar</option>
              </select>
            </div>

            <div>
              <label class="as-label">Dorëzimet</label>
              <select name="has_sub" class="form-select">
                <option value="any"    <?= $has_sub==='any' ? 'selected' : '' ?>>Çdo detyrë</option>
                <option value="with"   <?= $has_sub==='with' ? 'selected' : '' ?>>Vetëm me dorëzime</option>
                <option value="without"<?= $has_sub==='without' ? 'selected' : '' ?>>Vetëm pa dorëzime</option>
              </select>
            </div>

            <div>
              <label class="as-label">Rendit sipas</label>
              <select name="sort" class="form-select">
                <option value="due_asc"          <?= $sort==='due_asc'?'selected':'' ?>>Afati (më i afërt)</option>
                <option value="due_desc"         <?= $sort==='due_desc'?'selected':'' ?>>Afati (më i largët)</option>
                <option value="created_desc"     <?= $sort==='created_desc'?'selected':'' ?>>Më të rejat</option>
                <option value="created_asc"      <?= $sort==='created_asc'?'selected':'' ?>>Më të vjetrat</option>
                <option value="title_asc"        <?= $sort==='title_asc'?'selected':'' ?>>Titulli A→Z</option>
                <option value="title_desc"       <?= $sort==='title_desc'?'selected':'' ?>>Titulli Z→A</option>
                <option value="submissions_desc" <?= $sort==='submissions_desc'?'selected':'' ?>>Më shumë dorëzime</option>
                <option value="submissions_asc"  <?= $sort==='submissions_asc'?'selected':'' ?>>Më pak dorëzime</option>
              </select>
            </div>

            <div>
              <label class="as-label">Rezultate për faqe</label>
              <select name="per_page" class="form-select">
                <?php foreach ([5,10,15,20,30,40,60] as $pp): ?>
                  <option value="<?= $pp ?>" <?= $per_page===$pp?'selected':'' ?>><?= $pp ?></option>
                <?php endforeach; ?>
              </select>
            </div>

            <div class="d-grid gap-2">
              <button type="submit" class="btn btn-primary as-btn-main">
                <i class="fa-solid fa-magnifying-glass me-1"></i> Zbato filtrat
              </button>
              <a href="assignments.php" class="btn btn-outline-secondary as-btn-ghost">
                <i class="fa-solid fa-eraser me-1"></i> Pastro
              </a>
            </div>
          </form>
        </div>
      </aside>

      <!-- Lista e detyrave -->
      <section class="col-lg-9">
        <div class="as-results-header d-flex justify-content-between align-items-center mb-2">
          <h2 class="as-results-title">Detyrat</h2>
          <div class="as-results-meta text-muted small">
            <?= (int)$totalFiltered ?> detyra të gjetura
          </div>
        </div>

        <?php if (!$assignments): ?>
          <div class="as-empty">
            <div class="icon"><i class="fa-solid fa-inbox"></i></div>
            <div class="title">Nuk u gjet asnjë detyrë.</div>
            <div class="subtitle">Provo të ndryshosh filtrat ose kontrollo në një kurs tjetër.</div>
          </div>
        <?php else: ?>
          <div class="row g-3 as-grid">
            <?php foreach ($assignments as $row):
              $aid          = (int)$row['assignment_id'];
              $title        = (string)$row['assignment_title'];
              $courseTitle  = (string)$row['course_title'];
              $due          = $row['due_date'] ?? null;
              $statusLabel  = (string)($row['assignment_status'] ?? '');
              $hidden       = (int)($row['assignment_hidden'] ?? 0) === 1;

              $studentsTot  = (int)($row['students_total'] ?? 0);
              $subsTot      = (int)($row['submissions_total'] ?? 0);
              $subsUngraded = (int)($row['submissions_ungraded'] ?? 0);
              $subsGraded   = (int)($row['submissions_graded'] ?? 0);
              $avgGrade     = $row['avg_grade'] !== null ? round((float)$row['avg_grade'], 1) : null;

              $dueTs     = $due ? strtotime((string)$due . ' 23:59:59') : null;
              $isOverdue = $dueTs !== null && time() > $dueTs;

              $shortDesc = '';
              if (!empty($row['assignment_description'])) {
                $shortDesc = mb_strimwidth((string)$row['assignment_description'], 0, 180, '…', 'UTF-8');
              }

              $progress = 0;
              if ($studentsTot > 0) $progress = (int)round(($subsTot / $studentsTot) * 100);

              // Badge gjendje (si më parë)
              $pillClass = 'as-status-pill-muted';
              $pillText  = 'Aktive';

              if ($hidden) {
                $pillClass = 'as-status-pill-muted';
                $pillText  = 'Fshehur';
              } elseif ($isOverdue) {
                $pillClass = 'as-status-pill-danger';
                $pillText  = 'Afat i kaluar';
              } elseif ($subsUngraded > 0) {
                $pillClass = 'as-status-pill-warn';
                $pillText  = 'Për t’u vlerësuar';
              } elseif ($subsTot > 0) {
                $pillClass = 'as-status-pill-ok';
                $pillText  = 'Me dorëzime';
              }

              $dueText  = $due ? date('d.m.Y', strtotime((string)$due)) : 'Pa afat';
              $dueClass = !$due ? 'is-none' : ($isOverdue ? 'is-overdue' : 'is-ok');

              $courseUrl  = 'course_details.php?course_id=' . (int)$row['course_id'] . '&tab=materials';
              $detailsUrl = 'assignment_details.php?assignment_id=' . $aid;
              $subsUrl    = $detailsUrl . '#submissions';
            ?>
              <div class="col-12 col-md-6">
                <article class="as-card">

                  <!-- TOP BAR -->
                  <div class="as-card-top">
                    <div class="as-card-icon" aria-hidden="true">
                      <i class="fa-solid fa-clipboard-list"></i>
                    </div>

                    <div class="as-card-top-main">
                      <div class="as-card-top-row">
                        <h3 class="as-card-title mb-0">
                          <a href="<?= h($detailsUrl) ?>"><?= h($title) ?></a>
                        </h3>
                        <span class="as-status-pill <?= $pillClass ?>"><?= h($pillText) ?></span>
                      </div>

                      <a class="as-card-course" href="<?= h($courseUrl) ?>">
                        <i class="fa-solid fa-book me-1"></i><?= h($courseTitle) ?>
                      </a>
                    </div>

                    <div class="as-due-box <?= h($dueClass) ?>">
                      <div class="lbl">Afati</div>
                      <div class="val"><?= h($dueText) ?></div>
                      <?php if ($due && $isOverdue): ?>
                        <div class="hint">Ka kaluar</div>
                      <?php elseif ($due): ?>
                        <div class="hint">Në afat</div>
                      <?php else: ?>
                        <div class="hint">—</div>
                      <?php endif; ?>
                    </div>
                  </div>

                  <!-- BODY -->
                  <div class="as-card-body">
                    <?php if ($shortDesc !== ''): ?>
                      <p class="as-card-desc mb-2"><?= h($shortDesc) ?></p>
                    <?php endif; ?>

                    <div class="as-metrics">
                      <div class="as-metric">
                        <div class="k">Studentë</div>
                        <div class="v"><?= (int)$studentsTot ?></div>
                      </div>
                      <div class="as-metric">
                        <div class="k">Dorëzime</div>
                        <div class="v"><?= (int)$subsTot ?></div>
                      </div>
                      <div class="as-metric">
                        <div class="k">Pa notë</div>
                        <div class="v"><?= (int)$subsUngraded ?></div>
                      </div>
                      <div class="as-metric">
                        <div class="k">Mes.</div>
                        <div class="v"><?= $avgGrade !== null ? h((string)$avgGrade) : '—' ?></div>
                      </div>
                    </div>

                    <div class="as-progress mt-2">
                      <div class="d-flex justify-content-between small text-muted mb-1">
                        <span>Progresi</span>
                        <span>
                          <?php if ($studentsTot > 0): ?>
                            <?= $subsTot ?> / <?= $studentsTot ?> (<?= $progress ?>%)
                          <?php else: ?>
                            <?= $subsTot ?> dorëzime
                          <?php endif; ?>
                        </span>
                      </div>
                      <div class="progress" style="height:6px;">
                        <div class="progress-bar" style="width: <?= $progress ?>%;"></div>
                      </div>
                    </div>
                  </div>

                  <!-- FOOTER -->
                  <div class="as-card-footer">
                    <div class="d-flex flex-wrap gap-2">
                      <a class="btn btn-sm btn-outline-primary" href="<?= h($detailsUrl) ?>">
                        <i class="fa-regular fa-eye me-1"></i> Hap detyrën
                      </a>
                      <a class="btn btn-sm btn-outline-secondary" href="<?= h($subsUrl) ?>">
                        <i class="fa-solid fa-users-viewfinder me-1"></i> Dorëzimet
                      </a>

                      <?php if ($hidden): ?>
                        <span class="as-mini-badge">
                          <i class="fa-solid fa-eye-slash me-1"></i>Fshehur
                        </span>
                      <?php endif; ?>

                      <?php if ($statusLabel !== ''): ?>
                        <span class="as-mini-badge">
                          Gjendja: <strong><?= h($statusLabel) ?></strong>
                        </span>
                      <?php endif; ?>
                    </div>
                  </div>

                </article>
              </div>
            <?php endforeach; ?>
          </div>
        <?php endif; ?>

        <!-- Pagination -->
        <?php if ($pages > 1): ?>
          <nav class="mt-3" aria-label="Faqëzimi detyrave">
            <ul class="pagination pagination-sm">
              <li class="page-item <?= $page<=1 ? 'disabled' : '' ?>">
                <a class="page-link" href="<?= h($basePag . $sepPag . 'page=1') ?>" aria-label="E para">&laquo;&laquo;</a>
              </li>
              <li class="page-item <?= $page<=1 ? 'disabled' : '' ?>">
                <a class="page-link" href="<?= h($basePag . $sepPag . 'page=' . max(1, $page-1)) ?>" aria-label="Para">&laquo;</a>
              </li>
              <?php
                $start = max(1, $page - 2);
                $end   = min($pages, $page + 2);
                for ($i = $start; $i <= $end; $i++):
              ?>
                <li class="page-item <?= $i===$page ? 'active' : '' ?>">
                  <a class="page-link" href="<?= h($basePag . $sepPag . 'page=' . $i) ?>"><?= $i ?></a>
                </li>
              <?php endfor; ?>
              <li class="page-item <?= $page>=$pages ? 'disabled' : '' ?>">
                <a class="page-link" href="<?= h($basePag . $sepPag . 'page=' . min($pages, $page+1)) ?>" aria-label="Pas">&raquo;</a>
              </li>
              <li class="page-item <?= $page>=$pages ? 'disabled' : '' ?>">
                <a class="page-link" href="<?= h($basePag . $sepPag . 'page=' . $pages) ?>" aria-label="E fundit">&raquo;&raquo;</a>
              </li>
            </ul>
          </nav>
        <?php endif; ?>

      </section>
    </div>
  </div>
</main>

<?php if (file_exists(__DIR__.'/footer2.php')) include __DIR__.'/footer2.php'; ?>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
