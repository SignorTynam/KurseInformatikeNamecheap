<?php
// courses_student.php — Pamja e kurseve për Studentin (stil si courses.php)
declare(strict_types=1);
session_start();
require_once __DIR__ . '/lib/database.php'; // supozojmë që këtu inicializohet $pdo (ose getPDO)

// CSRF token (për veprime POST si çregjistrimi)
if (empty($_SESSION['csrf_token'])) {
  $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$CSRF = (string)$_SESSION['csrf_token'];

// ------------------------------- RBAC -------------------------------
if (empty($_SESSION['user']) || (string)($_SESSION['user']['role'] ?? '') !== 'Student') {
  header('Location: login.php');
  exit;
}
$ME_ID = (int)($_SESSION['user']['id'] ?? 0);

// ----------------------------- Helpers ------------------------------
function h(?string $s): string {
  return htmlspecialchars($s ?? '', ENT_QUOTES, 'UTF-8');
}

// Flash message
$flash = $_SESSION['flash'] ?? null;
unset($_SESSION['flash']);
$flashMsg = is_array($flash) ? (string)($flash['msg'] ?? '') : '';
$flashType = is_array($flash) ? (string)($flash['type'] ?? 'info') : '';
if ($flashType === 'error') $flashType = 'danger';

// ----------------------------- Inputs -------------------------------
$tab      = ($_GET['tab'] ?? 'my') === 'available' ? 'available' : 'my';
$search   = trim((string)($_GET['q'] ?? ''));
$category = trim((string)($_GET['category'] ?? ''));
$status   = strtoupper(trim((string)($_GET['status'] ?? ''))); // ACTIVE/INACTIVE/ARCHIVED/"" (pa filtër)
$sort     = (string)($_GET['sort'] ?? 'created_desc');
$per_page = (int)($_GET['per_page'] ?? 12);
$per_page = min(max($per_page, 6), 60);

// Paginë për secilën tabu
$page_my = max(1, (int)($_GET['page_my'] ?? 1));
$page_av = max(1, (int)($_GET['page_av'] ?? 1));
$off_my  = ($page_my - 1) * $per_page;
$off_av  = ($page_av - 1) * $per_page;

// --------------------------- ORDER BY Map ---------------------------
$ORDER_BY_MAP = [
  'created_desc'  => 'c.created_at DESC',
  'created_asc'   => 'c.created_at ASC',
  'updated_desc'  => 'c.updated_at DESC',
  'updated_asc'   => 'c.updated_at ASC',
  'title_asc'     => 'c.title ASC',
  'title_desc'    => 'c.title DESC',
  'students_desc' => 'participants DESC',
  'students_asc'  => 'participants ASC',
];
$orderBy = $ORDER_BY_MAP[$sort] ?? $ORDER_BY_MAP['created_desc'];

// ------------------------------ Stats --------------------------------
try {
  // Kurset ku jam i regjistruar
  $stMy = $pdo->prepare("SELECT COUNT(*) FROM enroll WHERE user_id=?");
  $stMy->execute([$ME_ID]);
  $myCount = (int)$stMy->fetchColumn();

  // Kurset e disponueshme (aktive) ku NUK jam i regjistruar
  $stAvail = $pdo->prepare("
    SELECT COUNT(*)
    FROM courses c
    WHERE c.status='ACTIVE'
      AND NOT EXISTS (SELECT 1 FROM enroll e WHERE e.course_id=c.id AND e.user_id=?)
  ");
  $stAvail->execute([$ME_ID]);
  $availableCount = (int)$stAvail->fetchColumn();

  // Total kurse aktive
  $activeTotal = (int)$pdo->query("SELECT COUNT(*) FROM courses WHERE status='ACTIVE'")->fetchColumn();
} catch (PDOException $e) {
  $myCount = 0;
  $availableCount = 0;
  $activeTotal = 0;
}

// ---------------------------- Filters data ---------------------------
try {
  $cats = $pdo->query("
    SELECT DISTINCT category 
    FROM courses 
    WHERE category IS NOT NULL AND category<>'' 
    ORDER BY category
  ")->fetchAll(PDO::FETCH_COLUMN) ?: [];
} catch (PDOException $e) {
  $cats = [];
}

// ---------------------- WHERE për secilën tabu ----------------------
// “Kurset e mia”
$whereMy  = ["EXISTS (SELECT 1 FROM enroll e WHERE e.course_id=c.id AND e.user_id=:uid)"];
$paramsMy = [':uid' => $ME_ID];

if ($search !== '') {
  $whereMy[]        = "(c.title LIKE :q OR c.description LIKE :q)";
  $paramsMy[':q']   = "%{$search}%";
}
if ($category !== '') {
  $whereMy[]        = "c.category = :cat";
  $paramsMy[':cat'] = $category;
}
if (in_array($status, ['ACTIVE','INACTIVE','ARCHIVED'], true)) {
  $whereMy[]        = "c.status = :st";
  $paramsMy[':st']  = $status;
}

$where_sql_my = $whereMy ? ('WHERE ' . implode(' AND ', $whereMy)) : '';

// “Kurset e disponueshme”
$whereAv  = ["NOT EXISTS (SELECT 1 FROM enroll e WHERE e.course_id=c.id AND e.user_id=:uid)"];
$paramsAv = [':uid' => $ME_ID];

if ($search !== '') {
  $whereAv[]        = "(c.title LIKE :q OR c.description LIKE :q)";
  $paramsAv[':q']   = "%{$search}%";
}
if ($category !== '') {
  $whereAv[]        = "c.category = :cat";
  $paramsAv[':cat'] = $category;
}
if (in_array($status, ['ACTIVE','INACTIVE','ARCHIVED'], true)) {
  $whereAv[]        = "c.status = :st";
  $paramsAv[':st']  = $status;
} else {
  // default për “available”: vetëm ACTIVE
  $whereAv[] = "c.status='ACTIVE'";
}

$where_sql_av = 'WHERE ' . implode(' AND ', $whereAv);

// ----------------------------- Counts --------------------------------
try {
  $countMy = $pdo->prepare("SELECT COUNT(*) FROM courses c {$where_sql_my}");
  foreach ($paramsMy as $k => $v) { $countMy->bindValue($k, $v); }
  $countMy->execute();
  $totalMy = (int)$countMy->fetchColumn();

  $countAv = $pdo->prepare("SELECT COUNT(*) FROM courses c {$where_sql_av}");
  foreach ($paramsAv as $k => $v) { $countAv->bindValue($k, $v); }
  $countAv->execute();
  $totalAv = (int)$countAv->fetchColumn();
} catch (PDOException $e) {
  $totalMy = 0;
  $totalAv = 0;
}

// ----------------------------- Queries --------------------------------
// NOTE: për progresin, përfshijmë lessons_visible njësoj si te course.php (admin)
$SELECT_BASE = "
  SELECT 
    c.*,
    u.full_name AS creator_name,
    (SELECT COUNT(*) FROM enroll e2 WHERE e2.course_id=c.id) AS participants,
    (SELECT COUNT(*) FROM lessons l WHERE l.course_id=c.id) AS lessons_total,
    (SELECT COUNT(*) 
       FROM lessons l 
       LEFT JOIN sections s ON s.id = l.section_id 
      WHERE l.course_id = c.id AND COALESCE(s.hidden,0) = 0
    ) AS lessons_visible
  FROM courses c
  LEFT JOIN users u ON u.id = c.id_creator
";

try {
  // Kurset e mia
  $sqlMy = $SELECT_BASE . " {$where_sql_my} ORDER BY {$orderBy} LIMIT :lim OFFSET :off";
  $st1   = $pdo->prepare($sqlMy);
  foreach ($paramsMy as $k => $v) { $st1->bindValue($k, $v); }
  $st1->bindValue(':lim', $per_page, PDO::PARAM_INT);
  $st1->bindValue(':off', $off_my,  PDO::PARAM_INT);
  $st1->execute();
  $MyCourses = $st1->fetchAll(PDO::FETCH_ASSOC) ?: [];

  // Kurset e disponueshme
  $sqlAv = $SELECT_BASE . " {$where_sql_av} ORDER BY {$orderBy} LIMIT :lim OFFSET :off";
  $st2   = $pdo->prepare($sqlAv);
  foreach ($paramsAv as $k => $v) { $st2->bindValue($k, $v); }
  $st2->bindValue(':lim', $per_page, PDO::PARAM_INT);
  $st2->bindValue(':off', $off_av,  PDO::PARAM_INT);
  $st2->execute();
  $availableCourses = $st2->fetchAll(PDO::FETCH_ASSOC) ?: [];
} catch (PDOException $e) {
  die('Gabim në kërkesë: ' . h($e->getMessage()));
}

// --------------------------- Pagination calc --------------------------
$pagesMy = max(1, (int)ceil($totalMy / $per_page));
$pagesAv = max(1, (int)ceil($totalAv / $per_page));
?>
<!DOCTYPE html>
<html lang="sq">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <title>Kurset e mia — Virtuale</title>

  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
  <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css" rel="stylesheet">
  <!-- CSS i përbashkët i kurseve (versioni i ri, si për courses.php) -->
  <link rel="stylesheet" href="css/courses.css?v=1">
  <link rel="icon" href="image/favicon.ico" type="image/x-icon" />
</head>
<body class="course-body">

<?php include __DIR__ . '/navbar_logged_student.php'; ?>

<!-- HERO -->
<header class="course-hero">
  <div class="container">
    <div class="d-flex flex-column gap-2">
      <div class="course-breadcrumb">
        <i class="fa-solid fa-house me-1"></i> Paneli / Kurset
      </div>
      <div class="d-flex flex-column flex-lg-row align-items-start align-items-lg-center justify-content-between gap-3">
        <div>
          <h1>Kurset e mia</h1>
          <p>Shfleto kurset ku je i regjistruar ose zbulo kurse të reja në platformë.</p>
        </div>
        <div class="d-flex gap-2 flex-wrap">
          <div class="course-stat" title="Kurset e mia">
            <div class="icon"><i class="fa-solid fa-book-bookmark"></i></div>
            <div>
              <div class="label">Kurset e mia</div>
              <div class="value"><?= (int)$myCount ?></div>
            </div>
          </div>
          <div class="course-stat" title="Të disponueshme (aktive)">
            <div class="icon"><i class="fa-solid fa-graduation-cap"></i></div>
            <div>
              <div class="label">Kurse të disponueshme</div>
              <div class="value"><?= (int)$availableCount ?></div>
            </div>
          </div>
          <div class="course-stat" title="Kurse aktive gjithsej">
            <div class="icon"><i class="fa-solid fa-layer-group"></i></div>
            <div>
              <div class="label">Kurse aktive gjithsej</div>
              <div class="value"><?= (int)$activeTotal ?></div>
            </div>
          </div>
        </div>
      </div>
    </div>
  </div>
</header>

<main class="course-main">
  <div class="container">

    <?php if ($flashMsg !== ''): ?>
      <div class="alert alert-<?= h($flashType ?: 'info') ?> mb-3">
        <?= h($flashMsg) ?>
      </div>
    <?php endif; ?>

    <!-- Quick Actions (student) -->
    <section class="row g-3 mb-3">
      <div class="col-sm-6 col-lg-6">
        <a class="course-quick-card" href="appointments_student.php" aria-label="Shiko orarin">
          <div class="icon-wrap"><i class="fa-regular fa-calendar-days"></i></div>
          <div>
            <div class="title">Shiko orarin</div>
            <div class="subtitle">Seancat e ardhshme dhe takimet online.</div>
          </div>
        </a>
      </div>
      <div class="col-sm-6 col-lg-6">
        <a class="course-quick-card" href="myAssignments.php" aria-label="Detyrat e mia">
          <div class="icon-wrap"><i class="fa-solid fa-list-check"></i></div>
          <div>
            <div class="title">Detyrat e mia</div>
            <div class="subtitle">Dorëzo detyrat dhe ndiq notat.</div>
          </div>
        </a>
      </div>
    </section>

    <!-- Layout: Sidebar + Content (si marketplace i vërtetë LMS) -->
    <div class="row course-layout g-3">
      <!-- Sidebar Filters (desktop) -->
      <aside class="col-lg-3 d-none d-lg-block">
        <div class="course-sidebar">
          <div class="course-sidebar-title">
            <span><i class="fa-solid fa-sliders me-1"></i> Filtra</span>
            <a href="courses_student.php?tab=<?= h($tab) ?>" class="btn-link-reset">
              <i class="fa-solid fa-eraser"></i> Pastro
            </a>
          </div>
          <form method="get" class="vstack gap-3">
            <input type="hidden" name="tab"      value="<?= h($tab) ?>">
            <input type="hidden" name="q"        value="<?= h($search) ?>">
            <input type="hidden" name="sort"     value="<?= h($sort) ?>">
            <input type="hidden" name="per_page" value="<?= (int)$per_page ?>">
            <input type="hidden" name="<?= $tab==='my' ? 'page_my' : 'page_av' ?>" value="1">

            <div>
              <label class="form-label">Kategoria</label>
              <select class="form-select" name="category">
                <option value="">Të gjitha</option>
                <?php foreach ($cats as $catOpt): ?>
                  <option value="<?= h($catOpt) ?>" <?= $category === $catOpt ? 'selected' : '' ?>>
                    <?= h($catOpt) ?>
                  </option>
                <?php endforeach; ?>
              </select>
            </div>

            <div>
              <label class="form-label">Statusi i kursit</label>
              <select class="form-select" name="status">
                <option value="">Të gjithë</option>
                <?php foreach (['ACTIVE'=>'Aktive', 'INACTIVE'=>'Joaktive', 'ARCHIVED'=>'Arkivuara'] as $k=>$v): ?>
                  <option value="<?= $k ?>" <?= $status === $k ? 'selected' : '' ?>><?= $v ?></option>
                <?php endforeach; ?>
              </select>
            </div>

            <div class="d-grid">
              <button class="btn btn-primary course-btn-main btn-sm" type="submit">
                <i class="fa-solid fa-rotate me-1"></i> Zbato filtrat
              </button>
            </div>
          </form>
        </div>
      </aside>

      <!-- Content: Toolbar + Tabs + Grid -->
      <section class="col-12 col-lg-9">

        <!-- Toolbar (search + sort + per_page + filtra mobile) -->
        <section class="course-toolbar mb-3">
          <form class="row g-2 align-items-center" method="get">
            <input type="hidden" name="tab" value="<?= h($tab) ?>">
            <div class="col-12 col-md-5">
              <div class="input-group">
                <span class="input-group-text bg-white border-end-0">
                  <i class="fa-solid fa-search"></i>
                </span>
                <input
                  type="text"
                  class="form-control border-start-0"
                  name="q"
                  value="<?= h($search) ?>"
                  placeholder="Kërko sipas titullit ose përshkrimit…">
              </div>
            </div>

            <div class="col-6 col-md-2">
              <select class="form-select" name="sort" aria-label="Rendit">
                <option value="created_desc"  <?= $sort==='created_desc'?'selected':''  ?>>Më të rejat</option>
                <option value="created_asc"   <?= $sort==='created_asc'?'selected':''   ?>>Më të vjetrat</option>
                <option value="updated_desc"  <?= $sort==='updated_desc'?'selected':''  ?>>Të përditësuarat ↓</option>
                <option value="updated_asc"   <?= $sort==='updated_asc'?'selected':''   ?>>Të përditësuarat ↑</option>
                <option value="title_asc"     <?= $sort==='title_asc'?'selected':''     ?>>Titulli A→Z</option>
                <option value="title_desc"    <?= $sort==='title_desc'?'selected':''    ?>>Titulli Z→A</option>
                <option value="students_desc" <?= $sort==='students_desc'?'selected':'' ?>>Pjesëmarrës ↓</option>
                <option value="students_asc"  <?= $sort==='students_asc'?'selected':''  ?>>Pjesëmarrës ↑</option>
              </select>
            </div>

            <div class="col-6 col-md-2">
              <select class="form-select" name="per_page" aria-label="Rezultate për faqe">
                <?php foreach([6,12,18,24,36,48,60] as $pp): ?>
                  <option value="<?= $pp ?>" <?= $per_page===$pp?'selected':'' ?>>
                    <?= $pp ?>/faqe
                  </option>
                <?php endforeach; ?>
              </select>
            </div>

            <div class="col-12 col-md-3 d-flex gap-2 justify-content-md-end">
              <!-- Butoni për filtra në mobile (offcanvas) -->
              <button class="btn btn-outline-secondary course-btn-ghost d-lg-none" type="button"
                      data-bs-toggle="offcanvas" data-bs-target="#filtersCanvas">
                <i class="fa-solid fa-filter me-1"></i> Filtra
              </button>
              <button class="btn btn-primary course-btn-main" type="submit">
                <i class="fa-solid fa-magnifying-glass me-1"></i> Kërko
              </button>
            </div>
          </form>

          <!-- Chips të filtrave aktivë -->
          <div class="mt-2 d-flex align-items-center flex-wrap gap-2">
            <span class="course-chip">
              <i class="fa-regular fa-folder-open"></i>
              Rezultate: <strong><?= $tab === 'my' ? $totalMy : $totalAv ?></strong>
            </span>
            <?php if ($category): ?>
              <span class="course-chip">
                <i class="fa-solid fa-tag"></i> <?= h($category) ?>
              </span>
            <?php endif; ?>
            <?php if ($status): ?>
              <span class="course-chip">
                <i class="fa-solid fa-signal"></i> <?= h($status) ?>
              </span>
            <?php endif; ?>
            <?php if ($search): ?>
              <span class="course-chip">
                <i class="fa-solid fa-magnifying-glass"></i> “<?= h($search) ?>”
              </span>
            <?php endif; ?>
            <?php
              $hasFilters = !empty($_GET);
              if ($hasFilters && (count($_GET) > (isset($_GET['tab']) ? 1 : 0))):
            ?>
              <a class="course-chip text-decoration-none" href="courses_student.php?tab=<?= h($tab) ?>">
                <i class="fa-solid fa-eraser"></i> Pastro filtrat
              </a>
            <?php endif; ?>
          </div>
        </section>

        <!-- Tabs kryesore: Kurset e mia / Të disponueshme -->
        <ul class="nav nav-pills course-status-tabs mb-3" role="tablist" aria-label="Zgjedh listën e kurseve">
          <li class="nav-item" role="presentation">
            <a class="nav-link <?= $tab==='my'?'active':'' ?>"
               href="?<?= h(http_build_query(array_merge($_GET, [
                 'tab'     => 'my',
                 'page_my' => 1,
                 'page_av' => null
               ]))) ?>">
              <i class="fa-solid fa-book-bookmark me-1"></i> Kurset e mia
              <span class="badge text-bg-light ms-1"><?= (int)$totalMy ?></span>
            </a>
          </li>
          <li class="nav-item" role="presentation">
            <a class="nav-link <?= $tab==='available'?'active':'' ?>"
               href="?<?= h(http_build_query(array_merge($_GET, [
                 'tab'     => 'available',
                 'page_av' => 1,
                 'page_my' => null
               ]))) ?>">
              <i class="fa-solid fa-graduation-cap me-1"></i> Kurset e disponueshme
              <span class="badge text-bg-light ms-1"><?= (int)$totalAv ?></span>
            </a>
          </li>
        </ul>

        <div class="tab-content">
          <!-- PANE: Kurset e mia -->
          <div class="tab-pane fade <?= $tab==='my'?'show active':'' ?>">
            <?php if (!$MyCourses): ?>
              <div class="course-empty">
                <div class="icon"><i class="fa-regular fa-face-smile-beam"></i></div>
                <div class="title">S’je regjistruar ende në asnjë kurs</div>
                <div class="subtitle">
                  Kalo te “Kurset e disponueshme” për të gjetur dhe zgjedhur kursin tënd të parë.
                </div>
              </div>
            <?php else: ?>
              <div class="row g-3 course-grid">
                <?php foreach ($MyCourses as $c):
                  $photo    = $c['photo'] ? 'uploads/courses/' . h($c['photo']) : 'image/course_placeholder.jpg';
                  $vis      = (int)($c['lessons_visible'] ?? 0);
                  $tot      = (int)($c['lessons_total']   ?? 0);
                  $progress = $tot > 0 ? round(($vis / $tot) * 100) : 0;
                  $statusClass = 'course-status-active';
                  if ($c['status'] === 'INACTIVE')  $statusClass = 'course-status-inactive';
                  elseif ($c['status'] === 'ARCHIVED') $statusClass = 'course-status-archived';
                ?>
                <div class="col-12 col-sm-6 col-lg-4 col-xl-4">
                  <article class="course-card h-100">
                    <div class="thumb">
                      <img src="<?= h($photo) ?>" alt="Kurs: <?= h($c['title']) ?>" loading="lazy">
                      <span class="cat-badge">
                        <i class="fa-regular fa-folder me-1"></i><?= h($c['category'] ?? 'TJETRA') ?>
                      </span>
                    </div>
                    <div class="card-body">
                      <div class="d-flex align-items-start justify-content-between mb-1 gap-2">
                        <h2 class="course-title flex-grow-1">
                          <a href="course_details_student.php?course_id=<?= (int)$c['id'] ?>">
                            <?= h($c['title']) ?>
                          </a>
                        </h2>
                        <span class="course-status-pill <?= $statusClass ?>">
                          <?= h($c['status']) ?>
                        </span>
                      </div>

                      <?php if (!empty($c['description'])): ?>
                        <p class="course-desc">
                          <?= h(mb_strimwidth((string)$c['description'], 0, 120, '…', 'UTF-8')) ?>
                        </p>
                      <?php endif; ?>

                      <div class="course-meta mb-2">
                        <span><i class="fa-solid fa-user-group me-1"></i><?= (int)$c['participants'] ?> pjes.</span>
                        <span><i class="fa-solid fa-book me-1"></i><?= $vis ?>/<?= $tot ?> leks.</span>
                        <span><i class="fa-regular fa-clock me-1"></i><?= date('d.m.Y', strtotime($c['created_at'])) ?></span>
                      </div>

                      <div class="mb-2">
                        <div class="progress" style="height:7px;">
                          <div class="progress-bar" style="width: <?= $progress ?>%"></div>
                        </div>
                      </div>

                      <div class="d-flex align-items-center justify-content-between gap-2">
                        <div class="d-flex align-items-center gap-2">
                          <div class="course-avatar" title="<?= h($c['creator_name'] ?: '—') ?>">
                            <?= strtoupper(mb_substr((string)$c['creator_name'], 0, 1, 'UTF-8')) ?>
                          </div>
                          <div class="small">
                            <div class="fw-semibold"><?= h($c['creator_name'] ?: '—') ?></div>
                            <div class="text-muted" style="font-size:.78rem;">
                              Krijuar më <?= date('d.m.Y', strtotime($c['created_at'])) ?>
                            </div>
                          </div>
                        </div>
                        <div class="d-flex align-items-center gap-1">
                          <a class="btn btn-sm btn-outline-primary"
                             href="course_details_student.php?course_id=<?= (int)$c['id'] ?>"
                             title="Vazhdo kursin">
                            <i class="fa-solid fa-play"></i>
                          </a>

                          <form method="post" action="course_unenroll.php" class="d-inline"
                                onsubmit="return confirm('Je i sigurt që do të çregjistrohesh nga ky kurs?');">
                            <input type="hidden" name="course_id" value="<?= (int)$c['id'] ?>">
                            <input type="hidden" name="csrf" value="<?= h($CSRF) ?>">
                            <button class="btn btn-sm btn-outline-danger" type="submit" title="Çregjistrohu nga kursi">
                              <i class="fa-solid fa-right-from-bracket"></i>
                            </button>
                          </form>

                          <?php if (!empty($c['AulaVirtuale']) && filter_var($c['AulaVirtuale'], FILTER_VALIDATE_URL)): ?>
                            <a class="btn btn-sm btn-outline-secondary"
                               target="_blank" rel="noopener"
                               href="<?= h($c['AulaVirtuale']) ?>"
                               title="Hap lidhjen video">
                              <i class="fa-solid fa-video"></i>
                            </a>
                          <?php endif; ?>
                        </div>
                      </div>
                    </div>
                  </article>
                </div>
                <?php endforeach; ?>
              </div>
            <?php endif; ?>

            <!-- Pagination (My) -->
            <?php
              if ($pagesMy > 1):
                $qs = $_GET;
                $qs['tab'] = 'my';
                unset($qs['page_my']);
                $base = '?' . http_build_query($qs);
            ?>
            <nav class="mt-3" aria-label="Faqëzimi Kurset e mia">
              <ul class="pagination pagination-sm">
                <li class="page-item <?= $page_my<=1?'disabled':'' ?>">
                  <a class="page-link" href="<?= $base . '&page_my=1' ?>" aria-label="E para">&laquo;&laquo;</a>
                </li>
                <li class="page-item <?= $page_my<=1?'disabled':'' ?>">
                  <a class="page-link" href="<?= $base . '&page_my=' . max(1, $page_my-1) ?>" aria-label="Para">&laquo;</a>
                </li>
                <?php
                  $start = max(1, $page_my - 2);
                  $end   = min($pagesMy, $page_my + 2);
                  for ($i = $start; $i <= $end; $i++):
                ?>
                  <li class="page-item <?= $i===$page_my?'active':'' ?>">
                    <a class="page-link" href="<?= $base . '&page_my=' . $i ?>"><?= $i ?></a>
                  </li>
                <?php endfor; ?>
                <li class="page-item <?= $page_my>=$pagesMy?'disabled':'' ?>">
                  <a class="page-link" href="<?= $base . '&page_my=' . min($pagesMy, $page_my+1) ?>" aria-label="Pas">&raquo;</a>
                </li>
                <li class="page-item <?= $page_my>=$pagesMy?'disabled':'' ?>">
                  <a class="page-link" href="<?= $base . '&page_my=' . $pagesMy ?>" aria-label="E fundit">&raquo;&raquo;</a>
                </li>
              </ul>
            </nav>
            <?php endif; ?>
          </div>

          <!-- PANE: Të disponueshme -->
          <div class="tab-pane fade <?= $tab==='available'?'show active':'' ?>">
            <?php if (!$availableCourses): ?>
              <div class="course-empty">
                <div class="icon"><i class="fa-regular fa-face-smile-beam"></i></div>
                <div class="title">Aktualisht s’ka kurse të reja</div>
                <div class="subtitle">
                  Provo të ndryshosh filtrat ose kthehu më vonë për kurse të reja.
                </div>
              </div>
            <?php else: ?>
              <div class="row g-3 course-grid">
                <?php foreach ($availableCourses as $c):
                  $photo    = $c['photo'] ? 'courses/' . h($c['photo']) : 'image/course_placeholder.jpg';
                  $vis      = (int)($c['lessons_visible'] ?? 0);
                  $tot      = (int)($c['lessons_total']   ?? 0);
                  $progress = $tot > 0 ? round(($vis / $tot) * 100) : 0;
                ?>
                <div class="col-12 col-sm-6 col-lg-4 col-xl-4">
                  <article class="course-card h-100">
                    <div class="thumb">
                      <img src="<?= h($photo) ?>" alt="Kurs: <?= h($c['title']) ?>" loading="lazy">
                      <span class="cat-badge">
                        <i class="fa-regular fa-folder me-1"></i><?= h($c['category'] ?? 'TJETRA') ?>
                      </span>
                    </div>
                    <div class="card-body">
                      <div class="d-flex align-items-start justify-content-between mb-1 gap-2">
                        <h2 class="course-title flex-grow-1">
                          <a href="course_details_student.php?course_id=<?= (int)$c['id'] ?>">
                            <?= h($c['title']) ?>
                          </a>
                        </h2>
                        <span class="course-status-pill course-status-active">
                          <?= h($c['status']) ?>
                        </span>
                      </div>

                      <?php if (!empty($c['description'])): ?>
                        <p class="course-desc">
                          <?= h(mb_strimwidth((string)$c['description'], 0, 120, '…', 'UTF-8')) ?>
                        </p>
                      <?php endif; ?>

                      <div class="course-meta mb-2">
                        <span><i class="fa-solid fa-user-group me-1"></i><?= (int)$c['participants'] ?> pjes.</span>
                        <span><i class="fa-solid fa-book me-1"></i><?= $vis ?>/<?= $tot ?> leks.</span>
                        <span><i class="fa-regular fa-clock me-1"></i><?= date('d.m.Y', strtotime($c['created_at'])) ?></span>
                      </div>

                      <div class="mb-2">
                        <div class="progress" style="height:7px;">
                          <div class="progress-bar" style="width: <?= $progress ?>%"></div>
                        </div>
                      </div>

                      <div class="d-flex align-items-center justify-content-between gap-2">
                        <div class="d-flex align-items-center gap-2">
                          <div class="course-avatar" title="<?= h($c['creator_name'] ?: '—') ?>">
                            <?= strtoupper(mb_substr((string)$c['creator_name'], 0, 1, 'UTF-8')) ?>
                          </div>
                          <div class="small">
                            <div class="fw-semibold"><?= h($c['creator_name'] ?: '—') ?></div>
                            <div class="text-muted" style="font-size:.78rem;">
                              Krijuar më <?= date('d.m.Y', strtotime($c['created_at'])) ?>
                            </div>
                          </div>
                        </div>
                        <div class="d-flex align-items-center gap-1">
                          <a class="btn btn-sm btn-outline-success"
                             href="course_enroll.php?course_id=<?= (int)$c['id'] ?>">
                            <i class="fa-solid fa-circle-plus me-1"></i> Regjistrohu
                          </a>
                          <?php if (!empty($c['AulaVirtuale']) && filter_var($c['AulaVirtuale'], FILTER_VALIDATE_URL)): ?>
                            <button class="btn btn-sm btn-outline-secondary" type="button"
                                    disabled
                                    title="Lidhja hapet pasi të regjistrohesh">
                              <i class="fa-solid fa-video"></i>
                            </button>
                          <?php endif; ?>
                        </div>
                      </div>
                    </div>
                  </article>
                </div>
                <?php endforeach; ?>
              </div>
            <?php endif; ?>

            <!-- Pagination (Available) -->
            <?php
              if ($pagesAv > 1):
                $qs = $_GET;
                $qs['tab'] = 'available';
                unset($qs['page_av']);
                $base = '?' . http_build_query($qs);
            ?>
            <nav class="mt-3" aria-label="Faqëzimi Kurset e disponueshme">
              <ul class="pagination pagination-sm">
                <li class="page-item <?= $page_av<=1?'disabled':'' ?>">
                  <a class="page-link" href="<?= $base . '&page_av=1' ?>" aria-label="E para">&laquo;&laquo;</a>
                </li>
                <li class="page-item <?= $page_av<=1?'disabled':'' ?>">
                  <a class="page-link" href="<?= $base . '&page_av=' . max(1, $page_av-1) ?>" aria-label="Para">&laquo;</a>
                </li>
                <?php
                  $start = max(1, $page_av - 2);
                  $end   = min($pagesAv, $page_av + 2);
                  for ($i = $start; $i <= $end; $i++):
                ?>
                  <li class="page-item <?= $i===$page_av?'active':'' ?>">
                    <a class="page-link" href="<?= $base . '&page_av=' . $i ?>"><?= $i ?></a>
                  </li>
                <?php endfor; ?>
                <li class="page-item <?= $page_av>=$pagesAv?'disabled':'' ?>">
                  <a class="page-link" href="<?= $base . '&page_av=' . min($pagesAv, $page_av+1) ?>" aria-label="Pas">&raquo;</a>
                </li>
                <li class="page-item <?= $page_av>=$pagesAv?'disabled':'' ?>">
                  <a class="page-link" href="<?= $base . '&page_av=' . $pagesAv ?>" aria-label="E fundit">&raquo;&raquo;</a>
                </li>
              </ul>
            </nav>
            <?php endif; ?>
          </div>
        </div>
      </section>
    </div>
  </div>
</main>

<!-- Offcanvas Filters (për mobile) -->
<div class="offcanvas offcanvas-end" tabindex="-1" id="filtersCanvas" aria-labelledby="filtersCanvasLabel">
  <div class="offcanvas-header">
    <h5 class="offcanvas-title" id="filtersCanvasLabel">
      <i class="fa-solid fa-filter me-1"></i> Filtra
    </h5>
    <button type="button" class="btn-close" data-bs-dismiss="offcanvas" aria-label="Mbyll"></button>
  </div>
  <div class="offcanvas-body">
    <form method="get" class="vstack gap-3">
      <input type="hidden" name="tab" value="<?= h($tab) ?>">
      <input type="hidden" name="q"   value="<?= h($search) ?>">

      <div>
        <label class="form-label">Kategoria</label>
        <select class="form-select" name="category">
          <option value="">Të gjitha</option>
          <?php foreach ($cats as $catOpt): ?>
            <option value="<?= h($catOpt) ?>" <?= $category === $catOpt ? 'selected' : '' ?>>
              <?= h($catOpt) ?>
            </option>
          <?php endforeach; ?>
        </select>
      </div>

      <div>
        <label class="form-label">Statusi</label>
        <select class="form-select" name="status">
          <option value="">(Pa filtër)</option>
          <?php foreach (['ACTIVE'=>'Aktive','INACTIVE'=>'Joaktive','ARCHIVED'=>'Arkivuara'] as $k=>$v): ?>
            <option value="<?= $k ?>" <?= $status === $k ? 'selected' : '' ?>><?= $v ?></option>
          <?php endforeach; ?>
        </select>
      </div>

      <div>
        <label class="form-label">Renditja</label>
        <select class="form-select" name="sort">
          <option value="created_desc"  <?= $sort==='created_desc'?'selected':''  ?>>Më të rejat</option>
          <option value="created_asc"   <?= $sort==='created_asc'?'selected':''   ?>>Më të vjetrat</option>
          <option value="updated_desc"  <?= $sort==='updated_desc'?'selected':''  ?>>Të përditësuarat ↓</option>
          <option value="updated_asc"   <?= $sort==='updated_asc'?'selected':''   ?>>Të përditësuarat ↑</option>
          <option value="title_asc"     <?= $sort==='title_asc'?'selected':''     ?>>Titulli A→Z</option>
          <option value="title_desc"    <?= $sort==='title_desc'?'selected':''    ?>>Titulli Z→A</option>
          <option value="students_desc" <?= $sort==='students_desc'?'selected':'' ?>>Pjesëmarrës ↓</option>
          <option value="students_asc"  <?= $sort==='students_asc'?'selected':''  ?>>Pjesëmarrës ↑</option>
        </select>
      </div>

      <div>
        <label class="form-label">Artikuj për faqe</label>
        <select class="form-select" name="per_page">
          <?php foreach([6,12,18,24,36,48,60] as $pp): ?>
            <option value="<?= $pp ?>" <?= $per_page===$pp?'selected':'' ?>><?= $pp ?></option>
          <?php endforeach; ?>
        </select>
      </div>

      <div class="d-grid">
        <button class="btn btn-primary course-btn-main" type="submit">
          <i class="fa-solid fa-rotate me-1"></i> Zbato filtrat
        </button>
      </div>
    </form>
    <hr>
    <div class="d-grid">
      <a class="btn btn-outline-secondary course-btn-ghost"
         href="courses_student.php?tab=<?= h($tab) ?>">
        <i class="fa-solid fa-eraser me-1"></i> Pastro filtrat
      </a>
    </div>
  </div>
</div>

<?php include __DIR__ . '/footer2.php'; ?>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
