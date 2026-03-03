<?php
// myAssignments.php — Detyrat e studentit (LMS-style, cards/grid si instruktori)

declare(strict_types=1);
require_once __DIR__ . '/lib/bootstrap.php';

if (empty($_SESSION['user']) || (string)($_SESSION['user']['role'] ?? '') !== 'Student') {
    header('Location: login.php');
    exit;
}

$ME_ID = (int)($_SESSION['user']['id'] ?? 0);

function h(?string $s): string {
    return htmlspecialchars($s ?? '', ENT_QUOTES, 'UTF-8');
}

$today = date('Y-m-d');

/* -------------------- Input filters -------------------- */
$search    = trim((string)($_GET['q'] ?? ''));
$course_id = (int)($_GET['course_id'] ?? 0);

$statusRaw = (string)($_GET['status'] ?? 'all');   // all | not_submitted | submitted | graded | overdue
$STATUS_ALLOWED = ['all','not_submitted','submitted','graded','overdue'];
$status = in_array($statusRaw, $STATUS_ALLOWED, true) ? $statusRaw : 'all';

$viewRaw = (string)($_GET['view'] ?? 'all');       // all | upcoming | past
$VIEW_ALLOWED = ['all','upcoming','past'];
$view = in_array($viewRaw, $VIEW_ALLOWED, true) ? $viewRaw : 'all';

$sortRaw = (string)($_GET['sort'] ?? 'due_asc');
$SORT_ALLOWED = [
    'due_asc','due_desc',
    'title_asc','title_desc',
    'course_asc','course_desc',
    'created_desc','created_asc',
];
$sort = in_array($sortRaw, $SORT_ALLOWED, true) ? $sortRaw : 'due_asc';

$per_page = (int)($_GET['per_page'] ?? 10);
$per_page = min(max($per_page, 5), 60);
$page     = max(1, (int)($_GET['page'] ?? 1));
$offset   = ($page - 1) * $per_page;

/* -------------------- Dropdown data: kurset ku jam i regjistruar -------------------- */
$coursesOptions = [];
try {
    $st = $pdo->prepare("
        SELECT DISTINCT c.id, c.title
        FROM courses c
        JOIN enroll e ON e.course_id = c.id
        WHERE e.user_id = :me
        ORDER BY c.title
    ");
    $st->execute([':me' => $ME_ID]);
    $coursesOptions = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
} catch (Throwable $e) {
    $coursesOptions = [];
}

/* -------------------- Bazë: assignments për këtë student -------------------- */
$FROM_BASE = "
  FROM assignments a
  JOIN courses c ON c.id = a.course_id
  JOIN enroll  e ON e.course_id = c.id AND e.user_id = :me
  LEFT JOIN users u ON u.id = c.id_creator
  LEFT JOIN assignments_submitted s
         ON s.assignment_id = a.id AND s.user_id = :me
";

$whereBase  = ["a.hidden = 0"];
$paramsBase = [':me' => $ME_ID];

if ($course_id > 0) {
    $whereBase[] = "c.id = :course_id";
    $paramsBase[':course_id'] = $course_id;
}
if ($search !== '') {
    $whereBase[]      = "(a.title LIKE :q OR c.title LIKE :q)";
    $paramsBase[':q'] = "%{$search}%";
}

/* -------------------- KPI: statistika për studentin -------------------- */
$totalAll        = 0;
$totalToDo       = 0;
$totalSubmitted  = 0;
$totalGraded     = 0;
$totalOverdue    = 0;

try {
    $whereSql= $whereBase ? ('WHERE ' . implode(' AND ', $whereBase)) : '';
    $sql     = "SELECT COUNT(DISTINCT a.id) {$FROM_BASE} {$whereSql}";
    $st      = $pdo->prepare($sql);
    $st->execute($paramsBase);
    $totalAll = (int)$st->fetchColumn();

    $where   = $whereBase;
    $params  = $paramsBase;
    $where[] = "s.id IS NULL";
    $sql     = "SELECT COUNT(DISTINCT a.id) {$FROM_BASE} WHERE " . implode(' AND ', $where);
    $st      = $pdo->prepare($sql);
    $st->execute($params);
    $totalToDo = (int)$st->fetchColumn();

    $where   = $whereBase;
    $params  = $paramsBase;
    $where[] = "s.id IS NOT NULL";
    $sql     = "SELECT COUNT(DISTINCT a.id) {$FROM_BASE} WHERE " . implode(' AND ', $where);
    $st      = $pdo->prepare($sql);
    $st->execute($params);
    $totalSubmitted = (int)$st->fetchColumn();

    $where   = $whereBase;
    $params  = $paramsBase;
    $where[] = "s.grade IS NOT NULL";
    $sql     = "SELECT COUNT(DISTINCT a.id) {$FROM_BASE} WHERE " . implode(' AND ', $where);
    $st      = $pdo->prepare($sql);
    $st->execute($params);
    $totalGraded = (int)$st->fetchColumn();

    $where   = $whereBase;
    $params  = $paramsBase;
    $where[] = "s.id IS NULL";
    $where[] = "a.due_date IS NOT NULL AND a.due_date < :today_over";
    $params[':today_over'] = $today;
    $sql     = "SELECT COUNT(DISTINCT a.id) {$FROM_BASE} WHERE " . implode(' AND ', $where);
    $st      = $pdo->prepare($sql);
    $st->execute($params);
    $totalOverdue = (int)$st->fetchColumn();

} catch (Throwable $e) {
    $totalAll = $totalToDo = $totalSubmitted = $totalGraded = $totalOverdue = 0;
}

/* -------------------- WHERE për listimin (status + view) -------------------- */
$whereList  = $whereBase;
$paramsList = $paramsBase;

if ($status === 'not_submitted') {
    $whereList[] = "s.id IS NULL";
} elseif ($status === 'submitted') {
    $whereList[] = "s.id IS NOT NULL AND s.grade IS NULL";
} elseif ($status === 'graded') {
    $whereList[] = "s.grade IS NOT NULL";
} elseif ($status === 'overdue') {
    $whereList[] = "s.id IS NULL";
    $whereList[] = "a.due_date IS NOT NULL AND a.due_date < :today_over_status";
    $paramsList[':today_over_status'] = $today;
}

if ($view === 'upcoming') {
    $whereList[] = "a.due_date IS NOT NULL AND a.due_date >= :today_up";
    $paramsList[':today_up'] = $today;
} elseif ($view === 'past') {
    $whereList[] = "a.due_date IS NOT NULL AND a.due_date < :today_past";
    $paramsList[':today_past'] = $today;
}

$whereListSql = $whereList ? ('WHERE ' . implode(' AND ', $whereList)) : '';

/* -------------------- Total për pagination -------------------- */
$totalFiltered = 0;
try {
    $sql = "SELECT COUNT(DISTINCT a.id) {$FROM_BASE} {$whereListSql}";
    $st  = $pdo->prepare($sql);
    $st->execute($paramsList);
    $totalFiltered = (int)$st->fetchColumn();
} catch (Throwable $e) {
    $totalFiltered = 0;
}

/* -------------------- ORDER BY -------------------- */
$orderMap = [
    'due_asc'      => 'a.due_date IS NULL, a.due_date ASC',
    'due_desc'     => 'a.due_date IS NULL, a.due_date DESC',
    'title_asc'    => 'a.title ASC',
    'title_desc'   => 'a.title DESC',
    'course_asc'   => 'c.title ASC',
    'course_desc'  => 'c.title DESC',
    'created_desc' => 'a.uploaded_at DESC',
    'created_asc'  => 'a.uploaded_at ASC',
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
        a.uploaded_at AS created_at,
        a.status      AS assignment_status,
        a.hidden      AS assignment_hidden,

        c.id          AS course_id,
        c.title       AS course_title,

        u.full_name   AS instructor_name,

        s.id          AS submission_id,
        s.submitted_at,
        s.grade
      {$FROM_BASE}
      {$whereListSql}
      ORDER BY {$orderBy}
      LIMIT :limit OFFSET :offset
    ";

    $stmt = $pdo->prepare($sql);
    foreach ($paramsList as $k => $v) $stmt->bindValue($k, $v);
    $stmt->bindValue(':limit',  $per_page, PDO::PARAM_INT);
    $stmt->bindValue(':offset', $offset,   PDO::PARAM_INT);
    $stmt->execute();
    $assignments = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
} catch (Throwable $e) {
    $assignments = [];
}

/* -------------------- Pagination calc -------------------- */
$pages = $per_page > 0 ? (int)ceil($totalFiltered / $per_page) : 1;
$pages = max(1, $pages);

$qsPag = $_GET;
unset($qsPag['page']);
$queryStr = http_build_query($qsPag);
$basePag  = 'myAssignments.php';
if ($queryStr !== '') $basePag .= '?' . $queryStr;
$sepPag = ($queryStr !== '') ? '&' : '?';
?>
<!DOCTYPE html>
<html lang="sq">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <title>Detyrat e mia — Student</title>

  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css">
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css">
  <link rel="stylesheet" href="css/assignments.css?v=1">
  <link rel="icon" href="image/favicon.ico" type="image/x-icon" />
</head>
<body class="course-body">

<?php if (file_exists(__DIR__.'/navbar_logged_student.php')) include __DIR__.'/navbar_logged_student.php'; ?>

<header class="course-hero">
  <div class="container">
    <div class="d-flex flex-column gap-2">
      <div class="course-breadcrumb">
        <i class="fa-solid fa-house me-1"></i> Paneli / Detyrat e mia
      </div>

      <div class="d-flex flex-column flex-lg-row align-items-start align-items-lg-center justify-content-between gap-3">
        <div>
          <h1>Detyrat e mia</h1>
          <p>Shiko detyrat nga kurset ku je i regjistruar, afatet e dorëzimit dhe notat e marra.</p>
        </div>

        <div class="d-flex flex-wrap gap-2">
          <div class="course-stat">
            <div class="icon"><i class="fa-solid fa-layer-group"></i></div>
            <div>
              <div class="label">Detyra gjithsej</div>
              <div class="value"><?= (int)$totalAll ?></div>
            </div>
          </div>
          <div class="course-stat">
            <div class="icon"><i class="fa-solid fa-hourglass-half"></i></div>
            <div>
              <div class="label">Për t’u dorëzuar</div>
              <div class="value"><?= (int)$totalToDo ?></div>
            </div>
          </div>
          <div class="course-stat">
            <div class="icon"><i class="fa-solid fa-paper-plane"></i></div>
            <div>
              <div class="label">Dorëzuara</div>
              <div class="value"><?= (int)$totalSubmitted ?></div>
            </div>
          </div>
          <div class="course-stat">
            <div class="icon"><i class="fa-solid fa-award"></i></div>
            <div>
              <div class="label">Të vlerësuara</div>
              <div class="value"><?= (int)$totalGraded ?></div>
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
              <label class="as-label">Kërko</label>
              <input type="search" name="q" class="form-control"
                     value="<?= h($search) ?>" placeholder="Titulli i detyrës ose kursit...">
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

            <div>
              <label class="as-label">Gjendja ime</label>
              <select name="status" class="form-select">
                <option value="all"           <?= $status==='all'?'selected':'' ?>>Të gjitha</option>
                <option value="not_submitted" <?= $status==='not_submitted'?'selected':'' ?>>Nuk është dorëzuar</option>
                <option value="submitted"     <?= $status==='submitted'?'selected':'' ?>>Dorëzuar (pa notë)</option>
                <option value="graded"        <?= $status==='graded'?'selected':'' ?>>Vlerësuar (me notë)</option>
                <option value="overdue"       <?= $status==='overdue'?'selected':'' ?>>Me afat të kaluar</option>
              </select>
            </div>

            <div>
              <label class="as-label">Afati</label>
              <select name="view" class="form-select">
                <option value="all"      <?= $view==='all'?'selected':'' ?>>Të gjitha afatet</option>
                <option value="upcoming" <?= $view==='upcoming'?'selected':'' ?>>Afate në vijim</option>
                <option value="past"     <?= $view==='past'?'selected':'' ?>>Afate të kaluara</option>
              </select>
            </div>

            <div>
              <label class="as-label">Rendit sipas</label>
              <select name="sort" class="form-select">
                <option value="due_asc"      <?= $sort==='due_asc'?'selected':'' ?>>Afati (më i afërt)</option>
                <option value="due_desc"     <?= $sort==='due_desc'?'selected':'' ?>>Afati (më i largët)</option>
                <option value="created_desc" <?= $sort==='created_desc'?'selected':'' ?>>Më të rejat</option>
                <option value="created_asc"  <?= $sort==='created_asc'?'selected':'' ?>>Më të vjetrat</option>
                <option value="title_asc"    <?= $sort==='title_asc'?'selected':'' ?>>Titulli A→Z</option>
                <option value="title_desc"   <?= $sort==='title_desc'?'selected':'' ?>>Titulli Z→A</option>
                <option value="course_asc"   <?= $sort==='course_asc'?'selected':'' ?>>Kursi A→Z</option>
                <option value="course_desc"  <?= $sort==='course_desc'?'selected':'' ?>>Kursi Z→A</option>
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
              <a href="myAssignments.php" class="btn btn-outline-secondary as-btn-ghost">
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
            <div class="title">Nuk ke detyra për momentin.</div>
            <div class="subtitle">Kontrollo kurset e tua ose ndrysho filtrat e kërkimit.</div>
          </div>
        <?php else: ?>
          <div class="row g-3 as-grid">
            <?php foreach ($assignments as $row):
              $aid         = (int)$row['assignment_id'];
              $title       = (string)$row['assignment_title'];
              $courseTitle = (string)$row['course_title'];
              $instructor  = (string)($row['instructor_name'] ?? '');

              $due         = $row['due_date'] ?? null;
              $dueTs       = $due ? strtotime((string)$due . ' 23:59:59') : null;

              $submissionId = $row['submission_id'] ?? null;
              $submittedAt  = $row['submitted_at'] ?? null;
              $grade        = $row['grade'] ?? null;

              $isOverdue = ($dueTs !== null) && (time() > $dueTs) && !$submissionId;

              $wasLate = false;
              if ($submissionId && $dueTs !== null && $submittedAt) {
                  $wasLate = strtotime((string)$submittedAt) > $dueTs;
              }

              $shortDesc = '';
              if (!empty($row['assignment_description'])) {
                  $shortDesc = mb_strimwidth((string)$row['assignment_description'], 0, 180, '…', 'UTF-8');
              }

              // Status pill (student)
              $pillClass = 'as-status-pill-muted';
              $pillText  = 'Nuk është dorëzuar';
              if ($isOverdue) {
                  $pillClass = 'as-status-pill-danger';
                  $pillText  = 'Afat i kaluar';
              } elseif ($submissionId && $grade === null) {
                  $pillClass = 'as-status-pill-warn';
                  $pillText  = 'Dorëzuar (pa notë)';
              } elseif ($submissionId && $grade !== null) {
                  $pillClass = 'as-status-pill-ok';
                  $pillText  = 'Vlerësuar';
              }

              // Due box
              $dueText  = $due ? date('d.m.Y', strtotime((string)$due)) : 'Pa afat';
              $dueClass = !$due ? 'is-none' : ($isOverdue ? 'is-overdue' : 'is-ok');
              $dueHint  = '—';
              if ($due) {
                  if ($isOverdue) $dueHint = 'Ka kaluar';
                  else $dueHint = 'Në afat';
                  if ($submissionId && $wasLate) $dueHint = 'Dorëzuar vonë';
                  if ($submissionId && !$wasLate) $dueHint = 'Dorëzuar';
              }

              $detailsUrl = 'assignment_details.php?assignment_id=' . $aid;
              $courseUrl  = 'course_details_student.php?course_id=' . (int)$row['course_id'] . '&tab=materials';

              $progress = $submissionId ? 100 : 0;

              $submittedText = $submittedAt ? date('d.m.Y H:i', strtotime((string)$submittedAt)) : '—';
              $gradeText     = ($grade !== null) ? (string)$grade : '—';
            ?>
              <div class="col-12 col-md-6">
                <article class="as-card">

                  <div class="as-card-top">
                    <div class="as-card-icon" aria-hidden="true">
                      <i class="fa-solid fa-file-pen"></i>
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

                      <?php if ($instructor !== ''): ?>
                        <div class="as-card-course" style="margin-top:.25rem;">
                          <i class="fa-solid fa-user-tie me-1"></i><?= h($instructor) ?>
                        </div>
                      <?php endif; ?>
                    </div>

                    <div class="as-due-box <?= h($dueClass) ?>">
                      <div class="lbl">Afati</div>
                      <div class="val"><?= h($dueText) ?></div>
                      <div class="hint"><?= h($dueHint) ?></div>
                    </div>
                  </div>

                  <div class="as-card-body">
                    <?php if ($shortDesc !== ''): ?>
                      <p class="as-card-desc mb-2"><?= h($shortDesc) ?></p>
                    <?php endif; ?>

                    <div class="as-metrics">
                      <div class="as-metric">
                        <div class="k">Dorëzimi</div>
                        <div class="v"><?= $submissionId ? 'Po' : 'Jo' ?></div>
                      </div>
                      <div class="as-metric">
                        <div class="k">Dorëzuar më</div>
                        <div class="v"><?= h($submittedText) ?></div>
                      </div>
                      <div class="as-metric">
                        <div class="k">Nota</div>
                        <div class="v"><?= h($gradeText) ?></div>
                      </div>
                      <div class="as-metric">
                        <div class="k">Status</div>
                        <div class="v"><?= h($pillText) ?></div>
                      </div>
                    </div>

                    <div class="as-progress mt-2">
                      <div class="d-flex justify-content-between small text-muted mb-1">
                        <span>Progresi im</span>
                        <span><?= (int)$progress ?>%</span>
                      </div>
                      <div class="progress" style="height:6px;">
                        <div class="progress-bar" style="width: <?= (int)$progress ?>%;"></div>
                      </div>
                    </div>
                  </div>

                  <div class="as-card-footer">
                    <div class="d-flex flex-wrap gap-2">
                      <a class="btn btn-sm btn-outline-primary" href="<?= h($detailsUrl) ?>">
                        <i class="fa-regular fa-eye me-1"></i> Hap detyrën
                      </a>

                      <?php if (!$submissionId): ?>
                        <a class="btn btn-sm btn-outline-success" href="<?= h($detailsUrl) ?>#submit">
                          <i class="fa-solid fa-upload me-1"></i> Dorëzo detyrën
                        </a>
                      <?php else: ?>
                        <a class="btn btn-sm btn-outline-secondary" href="<?= h($detailsUrl) ?>#submission">
                          <i class="fa-solid fa-file-lines me-1"></i> Shiko dorëzimin
                        </a>
                      <?php endif; ?>
                    </div>
                  </div>

                </article>
              </div>
            <?php endforeach; ?>
          </div>
        <?php endif; ?>

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
