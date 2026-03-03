<?php
declare(strict_types=1);
session_start();
require_once __DIR__ . '/lib/database.php';

/* ------------------------------- RBAC ------------------------------- */
if (!isset($_SESSION['user'])) { header('Location: login.php'); exit; }
$ROLE  = $_SESSION['user']['role'] ?? '';
$ME_ID = (int)($_SESSION['user']['id'] ?? 0);

// Vetëm Administrator + Instruktor
if (!in_array($ROLE, ['Administrator','Instruktor'], true)) {
  header('Location: courses_student.php');
  exit;
}

/* ------------------------------- CSRF ------------------------------- */
if (empty($_SESSION['csrf_token'])) {
  $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$CSRF = $_SESSION['csrf_token'];

/* ----------------------------- Flash Msg ---------------------------- */
function set_flash(string $msg, string $type='success'): void {
  $_SESSION['flash'] = ['msg'=>$msg, 'type'=>$type];
}
function get_flash(): ?array {
  if (!empty($_SESSION['flash'])) {
    $f = $_SESSION['flash'];
    unset($_SESSION['flash']);
    return $f;
  }
  return null;
}

/* --------------------------- Kategori ENUM -------------------------- */
$CATEGORY_ENUM = ['PROGRAMIM','GRAFIKA','WEB','GJUHE TE HUAJA','IT','TJETRA'];

/* ----------------------------- Input-e ------------------------------ */
function h(?string $s): string { return htmlspecialchars($s ?? '', ENT_QUOTES, 'UTF-8'); }

$search     = trim((string)($_GET['q'] ?? ''));
$category   = strtoupper(trim((string)($_GET['category'] ?? '')));
$status     = strtoupper(trim((string)($_GET['status'] ?? ''))); // "", ACTIVE, INACTIVE, ARCHIVED
$creator_id = (int)($_GET['creator_id'] ?? 0);
$date_from  = trim((string)($_GET['date_from'] ?? ''));
$date_to    = trim((string)($_GET['date_to'] ?? ''));
$sort       = (string)($_GET['sort'] ?? 'created_desc');
$per_page   = (int)($_GET['per_page'] ?? 12);
$page       = max(1, (int)($_GET['page'] ?? 1));
$per_page   = min(max($per_page, 6), 60);
$offset     = ($page - 1) * $per_page;

// Instruktorët: shohin vetëm kurset e tyre; ?all=1 lejon të gjitha
$show_all = isset($_GET['all']) && $_GET['all'] === '1';

/* -------------------------- Toast nga session ----------------------- */
$flash = get_flash();
$flashMsg  = '';
$flashType = '';
if (is_array($flash)) {
  $flashMsg  = (string)($flash['msg'] ?? '');
  $flashType = (string)($flash['type'] ?? 'info');
  if ($flashType === 'error') { $flashType = 'danger'; }
}

/* ---------------------- Normalizime / Validime ---------------------- */
if ($category !== '' && !in_array($category, $CATEGORY_ENUM, true)) {
  $category = '';
}

/* --------------------------- ORDER BY Map --------------------------- */
$ORDER_BY_MAP = [
  'created_desc'   => 'c.created_at DESC',
  'created_asc'    => 'c.created_at ASC',
  'title_asc'      => 'c.title ASC',
  'title_desc'     => 'c.title DESC',
  'students_desc'  => 'participants DESC',
  'students_asc'   => 'participants ASC',
  'updated_desc'   => 'c.updated_at DESC',
  'updated_asc'    => 'c.updated_at ASC',
];
$orderBy = $ORDER_BY_MAP[$sort] ?? $ORDER_BY_MAP['created_desc'];

/* ------------------------------ WHERE ------------------------------- */
$where  = [];
$params = [];

if ($ROLE === 'Instruktor' && !$show_all) {
  $where[] = "c.id_creator = :me";
  $params[':me'] = $ME_ID;
}
if ($search !== '') {
  $where[] = "(c.title LIKE :q OR c.description LIKE :q)";
  $params[':q'] = "%{$search}%";
}
if ($category !== '') {
  $where[] = "c.category = :cat";
  $params[':cat'] = $category;
}
if (in_array($status, ['ACTIVE','INACTIVE','ARCHIVED'], true)) {
  $where[] = "c.status = :st";
  $params[':st'] = $status;
}
if ($creator_id > 0) {
  $where[] = "c.id_creator = :cid";
  $params[':cid'] = $creator_id;
}
if ($date_from !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $date_from)) {
  $where[] = "DATE(c.created_at) >= :df";
  $params[':df'] = $date_from;
}
if ($date_to !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $date_to)) {
  $where[] = "DATE(c.created_at) <= :dt";
  $params[':dt'] = $date_to;
}
$where_sql = $where ? ('WHERE ' . implode(' AND ', $where)) : '';

/* ----------------------------- Statistika --------------------------- */
try {
  $statStmt = $pdo->query("
    SELECT 
      COUNT(*) AS total,
      SUM(CASE WHEN status='ACTIVE' THEN 1 ELSE 0 END)   AS active_cnt,
      SUM(CASE WHEN status='INACTIVE' THEN 1 ELSE 0 END) AS inactive_cnt,
      SUM(CASE WHEN status='ARCHIVED' THEN 1 ELSE 0 END) AS archived_cnt
    FROM courses
  ");
  $topStats = $statStmt->fetch(PDO::FETCH_ASSOC) ?: [
    'total'=>0,'active_cnt'=>0,'inactive_cnt'=>0,'archived_cnt'=>0
  ];
} catch (PDOException $e) {
  $topStats = ['total'=>0,'active_cnt'=>0,'inactive_cnt'=>0,'archived_cnt'=>0];
}

/* -------------------------- Lista filtrash -------------------------- */
try {
  $cats = $CATEGORY_ENUM;
  $creators = $pdo->query("
    SELECT id, full_name 
    FROM users 
    WHERE role IN ('Administrator','Instruktor')
    ORDER BY full_name
  ")->fetchAll(PDO::FETCH_ASSOC) ?: [];
} catch (PDOException $e) {
  $cats = $CATEGORY_ENUM; $creators = [];
}

/* -------------------------- Count e filtruar ------------------------ */
try {
  $countSql   = "SELECT COUNT(*) FROM courses c {$where_sql}";
  $countStmt  = $pdo->prepare($countSql);
  foreach ($params as $k=>$v) { $countStmt->bindValue($k, $v); }
  $countStmt->execute();
  $totalFiltered = (int)$countStmt->fetchColumn();
} catch (PDOException $e) {
  $totalFiltered = 0;
}

/* ------------------------- SELECT kryesor --------------------------- */
/* Version minimalist: vetëm meta bazë për kursin */
try {
  $sql = "
    SELECT 
      c.*,
      u.full_name AS creator_name,
      (SELECT COUNT(*) FROM enroll e WHERE e.course_id = c.id) AS participants,
      (SELECT COUNT(*) FROM lessons l WHERE l.course_id = c.id) AS lessons_total,
      (SELECT COUNT(*) FROM assignments a WHERE a.course_id = c.id) AS assignments_total
    FROM courses c
    LEFT JOIN users u ON u.id = c.id_creator
    {$where_sql}
    ORDER BY {$orderBy}
    LIMIT :lim OFFSET :off
  ";
  $stmt = $pdo->prepare($sql);
  foreach ($params as $k=>$v) {
    if (in_array($k, [':me', ':cid'], true)) $stmt->bindValue($k, (int)$v, PDO::PARAM_INT);
    else $stmt->bindValue($k, $v);
  }
  $stmt->bindValue(':lim', $per_page, PDO::PARAM_INT);
  $stmt->bindValue(':off', $offset,   PDO::PARAM_INT);
  $stmt->execute();
  $courses = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
} catch (PDOException $e) {
  die("Gabim në kërkesë: " . h($e->getMessage()));
}
?>
<!DOCTYPE html>
<html lang="sq">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <title>Kurset — Virtuale</title>

  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
  <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css" rel="stylesheet">
  <link rel="stylesheet" href="css/courses.css?v=1">
  <link rel="icon" href="image/favicon.ico" type="image/x-icon" />
</head>
<body class="course-body">

<?php
  if     ($ROLE === 'Administrator') include __DIR__ . '/navbar_logged_administrator.php';
  elseif ($ROLE === 'Instruktor')    include __DIR__ . '/navbar_logged_instructor.php';
  else                               include __DIR__ . '/navbar_logged_student.php';
?>

<!-- HERO -->
<header class="course-hero">
  <div class="container">
    <div class="d-flex flex-column flex-lg-row align-items-start align-items-lg-center justify-content-between gap-3">
      <div>
        <div class="course-breadcrumb">
          <i class="fa-solid fa-house me-1"></i> Paneli / Kurset
        </div>
        <h1>Menaxhimi i kurseve</h1>
        <p>Gjithë kurset në një vend: filtro, kërko dhe vepro shpejt.</p>
      </div>
      <div class="d-flex flex-wrap gap-2">
        <div class="course-stat">
          <div class="icon"><i class="fa-solid fa-layer-group"></i></div>
          <div>
            <div class="label">Kurse total</div>
            <div class="value"><?= (int)$topStats['total'] ?></div>
          </div>
        </div>
        <div class="course-stat">
          <div class="icon"><i class="fa-solid fa-circle-check"></i></div>
          <div>
            <div class="label">Aktive</div>
            <div class="value"><?= (int)$topStats['active_cnt'] ?></div>
          </div>
        </div>
        <div class="course-stat">
          <div class="icon"><i class="fa-solid fa-circle-pause"></i></div>
          <div>
            <div class="label">Joaktive</div>
            <div class="value"><?= (int)$topStats['inactive_cnt'] ?></div>
          </div>
        </div>
        <div class="course-stat">
          <div class="icon"><i class="fa-solid fa-box-archive"></i></div>
          <div>
            <div class="label">Arkivuara</div>
            <div class="value"><?= (int)$topStats['archived_cnt'] ?></div>
          </div>
        </div>
      </div>
    </div>
  </div>
</header>

<main class="course-main">
  <div class="container">

    <!-- Quick actions -->
    <section class="course-quick row g-3 mb-3">
      <div class="col-sm-6">
        <a class="course-quick-card" href="admin/add_course.php">
          <div class="icon-wrap"><i class="fa-solid fa-plus-circle"></i></div>
          <div>
            <div class="title">Shto kurs</div>
            <div class="subtitle">Krijo një kurs të ri nga zero</div>
          </div>
        </a>
      </div>
      <div class="col-sm-6">
        <a class="course-quick-card" href="copy_course.php" data-bs-toggle="modal" data-bs-target="#copyCourseModal">
          <div class="icon-wrap"><i class="fa-solid fa-copy"></i></div>
          <div>
            <div class="title">Kopjo kurs</div>
            <div class="subtitle">Krijo kurs të ri nga një ekzistues</div>
          </div>
        </a>
      </div>
    </section>

    <!-- Layout kryesor: sidebar filtrash + grid -->
    <div class="row course-layout">
      <!-- SIDEBAR FILTRASH (desktop) -->
      <aside class="col-lg-3 d-none d-lg-block">
        <div class="course-sidebar">
          <div class="course-sidebar-title">
            <span><i class="fa-solid fa-filter me-1"></i> Filtra</span>
            <a href="course.php" class="btn-link-reset">
              <i class="fa-solid fa-eraser me-1"></i> Reseto
            </a>
          </div>

          <form method="get" class="vstack gap-3">
            <!-- Persisto parametrat kryesorë -->
            <input type="hidden" name="q"        value="<?= h($search) ?>">
            <input type="hidden" name="sort"     value="<?= h($sort) ?>">
            <input type="hidden" name="per_page" value="<?= (int)$per_page ?>">
            <?php if ($show_all): ?><input type="hidden" name="all" value="1"><?php endif; ?>

            <div>
              <label class="form-label">Kategoria</label>
              <select class="form-select form-select-sm" name="category">
                <option value="">Të gjitha</option>
                <?php foreach ($cats as $catOpt): ?>
                  <option value="<?= h($catOpt) ?>" <?= $category===$catOpt?'selected':'' ?>>
                    <?= h($catOpt) ?>
                  </option>
                <?php endforeach; ?>
              </select>
            </div>

            <div>
              <label class="form-label">Statusi</label>
              <select class="form-select form-select-sm" name="status">
                <option value="">Të gjithë</option>
                <?php foreach(['ACTIVE'=>'Aktive','INACTIVE'=>'Joaktive','ARCHIVED'=>'Arkivuara'] as $k=>$v): ?>
                  <option value="<?= $k ?>" <?= $status===$k?'selected':'' ?>><?= $v ?></option>
                <?php endforeach; ?>
              </select>
            </div>

            <div>
              <label class="form-label">Krijuesi</label>
              <select class="form-select form-select-sm" name="creator_id">
                <option value="">Të gjithë</option>
                <?php foreach ($creators as $cr): ?>
                  <option value="<?= (int)$cr['id'] ?>" <?= $creator_id===(int)$cr['id']?'selected':'' ?>>
                    <?= h($cr['full_name']) ?>
                  </option>
                <?php endforeach; ?>
              </select>
            </div>

            <div class="row g-2">
              <div class="col-6">
                <label class="form-label">Nga data</label>
                <input type="date" class="form-control form-control-sm"
                       name="date_from" value="<?= h($date_from) ?>">
              </div>
              <div class="col-6">
                <label class="form-label">Deri më</label>
                <input type="date" class="form-control form-control-sm"
                       name="date_to" value="<?= h($date_to) ?>">
              </div>
            </div>

            <div class="d-grid mt-1">
              <button class="btn btn-sm btn-primary course-btn-main" type="submit">
                <i class="fa-solid fa-rotate me-1"></i> Zbato filtrat
              </button>
            </div>
          </form>
        </div>
      </aside>

      <!-- KOLUMNA KRYESORE -->
      <div class="col-12 col-lg-9">

        <!-- Toolbar / Filters (search + sort + butona) -->
        <section class="course-toolbar mb-3">
          <form class="row g-2 align-items-center" method="get">
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

            <div class="col-6 col-md-2">
              <select class="form-select" name="per_page">
                <?php foreach([6,12,18,24,36,48,60] as $pp): ?>
                  <option value="<?= $pp ?>" <?= $per_page===$pp?'selected':'' ?>>
                    <?= $pp ?>/faqe
                  </option>
                <?php endforeach; ?>
              </select>
            </div>

            <div class="col-12 col-md-3 d-flex gap-2 justify-content-md-end">
              <button class="btn btn-outline-secondary course-btn-ghost" type="button" id="selectAllBtn">
                <i class="fa-regular fa-square-check me-1"></i> Zgjidh të gjitha
              </button>

              <!-- Filtra offcanvas: vetëm në mobile -->
              <button class="btn btn-outline-secondary course-btn-ghost d-lg-none" type="button"
                      data-bs-toggle="offcanvas" data-bs-target="#filtersCanvas">
                <i class="fa-solid fa-filter me-1"></i> Filtra
              </button>

              <button class="btn btn-primary course-btn-main" type="submit">
                <i class="fa-solid fa-magnifying-glass me-1"></i> Kërko
              </button>
            </div>

            <?php if ($ROLE === 'Instruktor'):
              $qs = $_GET;
              if ($show_all) { unset($qs['all']); } else { $qs['all'] = '1'; }
              $qs['page'] = 1;
              $toggleUrl = 'course.php?' . http_build_query($qs);
            ?>
              <div class="col-12 mt-1">
                <a class="course-chip" href="<?= h($toggleUrl) ?>">
                  <?php if ($show_all): ?>
                    <i class="fa-solid fa-user-check"></i> Shfaq vetëm kurset e mia
                  <?php else: ?>
                    <i class="fa-solid fa-globe"></i> Shfaq të gjitha kurset
                  <?php endif; ?>
                </a>
              </div>
            <?php endif; ?>
          </form>

          <!-- Chips të filtrave aktivë -->
          <div class="mt-2 d-flex align-items-center flex-wrap gap-2">
            <span class="course-chip">
              <i class="fa-regular fa-folder-open"></i>
              Rezultate: <strong><?= $totalFiltered ?></strong>
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
            <?php if ($creator_id): ?>
              <span class="course-chip">
                <i class="fa-regular fa-user"></i> Krijues #<?= (int)$creator_id ?>
              </span>
            <?php endif; ?>
            <?php if ($search): ?>
              <span class="course-chip">
                <i class="fa-solid fa-magnifying-glass"></i> “<?= h($search) ?>”
              </span>
            <?php endif; ?>
            <?php if (!empty($_GET) && (count($_GET) > (isset($_GET['page'])?1:0))): ?>
              <a class="course-chip text-decoration-none" href="course.php">
                <i class="fa-solid fa-eraser"></i> Pastro filtrat
              </a>
            <?php endif; ?>
          </div>
        </section>

        <!-- Status tabs -->
        <?php $qsBase = $_GET; unset($qsBase['page']); ?>
        <ul class="nav nav-pills course-status-tabs mb-3" role="tablist">
          <?php
            $tabs = [
              ''          => ['Të gjitha', (int)$topStats['total']],
              'ACTIVE'    => ['Aktive',    (int)$topStats['active_cnt']],
              'INACTIVE'  => ['Joaktive',  (int)$topStats['inactive_cnt']],
              'ARCHIVED'  => ['Arkivuara', (int)$topStats['archived_cnt']],
            ];
            foreach ($tabs as $k=>$meta):
              [$label,$cnt] = $meta;
              $qsTab = $qsBase; $qsTab['status'] = $k;
              $tabUrl = 'course.php?' . http_build_query($qsTab);
              $active = ($status === $k) ? 'active' : '';
          ?>
            <li class="nav-item">
              <a class="nav-link <?= $active ?>" href="<?= h($tabUrl) ?>">
                <?= h($label) ?> <span class="badge text-bg-light ms-1"><?= $cnt ?></span>
              </a>
            </li>
          <?php endforeach; ?>
        </ul>

        <!-- GRID VIEW -->
        <section class="course-grid">
          <?php if (!$courses): ?>
            <div class="course-empty">
              <div class="icon"><i class="fa-regular fa-face-smile-beam"></i></div>
              <div class="title">S’u gjet asnjë kurs me këto filtra.</div>
              <div class="subtitle">Provo të ndryshosh filtrat ose krijo një kurs të ri.</div>
              <a class="btn btn-primary course-btn-main" href="admin/add_course.php">
                <i class="fa-solid fa-plus me-1"></i> Shto kurs
              </a>
            </div>
          <?php else: ?>
            <div class="row g-3">
              <?php foreach ($courses as $c):
                $cat   = $c['category'] ?? 'TJETRA';
                $photo = $c['photo'] ? 'uploads/courses/'.h($c['photo']) : 'image/course_placeholder.jpg';

                $statusClass = $c['status']==='ACTIVE'
                    ? 'course-status-active'
                    : ($c['status']==='INACTIVE' ? 'course-status-inactive' : 'course-status-archived');

                $lessons = (int)($c['lessons_total']     ?? 0);
                $assigns = (int)($c['assignments_total'] ?? 0);

                $canEdit = in_array($ROLE, ['Administrator','Instruktor'], true)
                           && ($ROLE==='Administrator' || (int)$c['id_creator']===$ME_ID);
              ?>
              <div class="col-12 col-sm-6 col-lg-4">
                <article class="course-card h-100">
                  <div class="thumb">
                    <img src="<?= h($photo) ?>" alt="Kurs: <?= h($c['title']) ?>" loading="lazy">
                    <span class="cat-badge">
                      <i class="fa-regular fa-folder"></i> <?= h($cat) ?>
                    </span>
                    <div class="form-check selbox">
                      <input class="form-check-input bulk-check" type="checkbox"
                             value="<?= (int)$c['id'] ?>" aria-label="Zgjidh kursin">
                    </div>
                  </div>

                  <div class="card-body">
                    <div class="d-flex justify-content-between align-items-start gap-2 mb-1">
                      <h2 class="course-title">
                        <a href="course_details.php?course_id=<?= (int)$c['id'] ?>">
                          <?= h($c['title']) ?>
                        </a>
                      </h2>
                      <span class="course-status-pill <?= $statusClass ?>">
                        <?= h($c['status']) ?>
                      </span>
                    </div>

                    <div class="course-meta mb-2">
                      <span><i class="fa-solid fa-user-group me-1"></i><?= (int)$c['participants'] ?> pjes.</span>
                      <span><i class="fa-solid fa-book me-1"></i><?= $lessons ?> leksione</span>
                      <span><i class="fa-regular fa-rectangle-list me-1"></i><?= $assigns ?> detyra</span>
                    </div>

                    <div class="d-flex justify-content-between align-items-center">
                      <div class="d-flex align-items-center gap-2">
                        <div class="course-avatar" title="<?= h($c['creator_name'] ?: '—') ?>">
                          <?= strtoupper(mb_substr((string)$c['creator_name'],0,1,'UTF-8')) ?>
                        </div>
                        <div class="small">
                          <div class="fw-semibold"><?= h($c['creator_name'] ?: '—') ?></div>
                          <div class="text-muted">
                            Krijuar më <?= date('d.m.Y', strtotime($c['created_at'])) ?>
                          </div>
                        </div>
                      </div>
                      <div class="btn-group btn-group-sm">
                        <a class="btn btn-outline-primary" href="course_details.php?course_id=<?= (int)$c['id'] ?>" title="Shiko">
                          <i class="fa-regular fa-eye"></i>
                        </a>
                        <?php if ($canEdit): ?>
                          <a class="btn btn-outline-secondary" href="admin/edit_course.php?course_id=<?= (int)$c['id'] ?>" title="Redakto">
                            <i class="fa-regular fa-pen-to-square"></i>
                          </a>
                          <button class="btn btn-outline-danger"
                                  title="Fshi"
                                  data-bs-toggle="modal"
                                  data-bs-target="#deleteCourseModal"
                                  data-course-id="<?= (int)$c['id'] ?>"
                                  data-course-title="<?= h($c['title']) ?>">
                            <i class="fa-regular fa-trash-can"></i>
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
        </section>

        <!-- Pagination -->
        <?php
          $pages = $per_page>0 ? (int)ceil($totalFiltered / $per_page) : 1;
          $pages = max(1, $pages);
          if ($pages > 1):
            $qs = $_GET; unset($qs['page']);
            $base = '?' . http_build_query($qs);
        ?>
          <nav class="mt-3" aria-label="Faqëzimi">
            <ul class="pagination pagination-sm">
              <li class="page-item <?= $page<=1?'disabled':'' ?>">
                <a class="page-link" href="<?= $base . '&page=1' ?>" aria-label="E para">&laquo;&laquo;</a>
              </li>
              <li class="page-item <?= $page<=1?'disabled':'' ?>">
                <a class="page-link" href="<?= $base . '&page=' . max(1,$page-1) ?>" aria-label="Para">&laquo;</a>
              </li>
              <?php
                $start = max(1, $page-2);
                $end   = min($pages, $page+2);
                for ($i=$start; $i<=$end; $i++):
              ?>
                <li class="page-item <?= $i===$page?'active':'' ?>">
                  <a class="page-link" href="<?= $base . '&page=' . $i ?>"><?= $i ?></a>
                </li>
              <?php endfor; ?>
              <li class="page-item <?= $page>=$pages?'disabled':'' ?>">
                <a class="page-link" href="<?= $base . '&page=' . min($pages,$page+1) ?>" aria-label="Pas">&raquo;</a>
              </li>
              <li class="page-item <?= $page>=$pages?'disabled':'' ?>">
                <a class="page-link" href="<?= $base . '&page=' . $pages ?>" aria-label="E fundit">&raquo;&raquo;</a>
              </li>
            </ul>
          </nav>
        <?php endif; ?>

        <!-- Bulk actions bar -->
        <div id="bulkBar" class="course-bulkbar d-none mt-3" role="region" aria-label="Veprime masive">
          <div class="d-flex align-items-center justify-content-between">
            <div><i class="fa-solid fa-circle-check me-1"></i> <span id="selCount">0</span> të selektuar</div>
            <div class="d-flex gap-2">
              <?php if (in_array($ROLE, ['Administrator','Instruktor'], true)): ?>
                <form id="bulkForm" method="POST" action="courses_bulk_action.php" class="d-flex gap-2 mb-0">
                  <input type="hidden" name="ids"    id="bulkIds">
                  <input type="hidden" name="action" id="bulkAction">
                  <input type="hidden" name="csrf"   value="<?= h($CSRF) ?>">
                  <button type="button" class="btn btn-sm btn-light" onclick="submitBulk('activate')">
                    <i class="fa-solid fa-toggle-on me-1"></i> Aktivo
                  </button>
                  <button type="button" class="btn btn-sm btn-light" onclick="submitBulk('deactivate')">
                    <i class="fa-solid fa-toggle-off me-1"></i> Çaktivo
                  </button>
                  <button type="button" class="btn btn-sm btn-warning" onclick="submitBulk('archive')">
                    <i class="fa-solid fa-box-archive me-1"></i> Arkivo
                  </button>
                  <button type="button" class="btn btn-sm btn-danger"
                          data-bs-toggle="modal" data-bs-target="#bulkDeleteModal">
                    <i class="fa-regular fa-trash-can me-1"></i> Fshi
                  </button>
                </form>
              <?php endif; ?>
            </div>
          </div>
        </div>

      </div><!-- /.col-lg-9 -->
    </div><!-- /.row -->

    <br>
  </div>
</main>

<!-- Offcanvas Filters (Advanced, për mobile) -->
<div class="offcanvas offcanvas-end" tabindex="-1" id="filtersCanvas" aria-labelledby="filtersCanvasLabel">
  <div class="offcanvas-header">
    <h5 class="offcanvas-title" id="filtersCanvasLabel">
      <i class="fa-solid fa-filter me-1"></i> Filtra të avancuar
    </h5>
    <button type="button" class="btn-close" data-bs-dismiss="offcanvas" aria-label="Mbyll"></button>
  </div>
  <div class="offcanvas-body">
    <form method="get" class="vstack gap-3">
      <!-- Persisto parametrat kryesorë -->
      <input type="hidden" name="q"        value="<?= h($search) ?>">
      <input type="hidden" name="sort"     value="<?= h($sort) ?>">
      <input type="hidden" name="per_page" value="<?= (int)$per_page ?>">
      <?php if ($show_all): ?><input type="hidden" name="all" value="1"><?php endif; ?>

      <div>
        <label class="form-label">Kategoria</label>
        <select class="form-select" name="category">
          <option value="">Të gjitha</option>
          <?php foreach ($cats as $catOpt): ?>
            <option value="<?= h($catOpt) ?>" <?= $category===$catOpt?'selected':'' ?>>
              <?= h($catOpt) ?>
            </option>
          <?php endforeach; ?>
        </select>
      </div>

      <div>
        <label class="form-label">Statusi</label>
        <select class="form-select" name="status">
          <option value="">Të gjithë</option>
          <?php foreach(['ACTIVE'=>'Aktive','INACTIVE'=>'Joaktive','ARCHIVED'=>'Arkivuara'] as $k=>$v): ?>
            <option value="<?= $k ?>" <?= $status===$k?'selected':'' ?>><?= $v ?></option>
          <?php endforeach; ?>
        </select>
      </div>

      <div>
        <label class="form-label">Krijuesi</label>
        <select class="form-select" name="creator_id">
          <option value="">Të gjithë</option>
          <?php foreach ($creators as $cr): ?>
            <option value="<?= (int)$cr['id'] ?>" <?= $creator_id===(int)$cr['id']?'selected':'' ?>>
              <?= h($cr['full_name']) ?>
            </option>
          <?php endforeach; ?>
        </select>
      </div>

      <div class="row g-2">
        <div class="col-6">
          <label class="form-label">Nga data</label>
          <input type="date" class="form-control" name="date_from" value="<?= h($date_from) ?>">
        </div>
        <div class="col-6">
          <label class="form-label">Deri më</label>
          <input type="date" class="form-control" name="date_to" value="<?= h($date_to) ?>">
        </div>
      </div>

      <div class="d-grid">
        <button class="btn btn-primary course-btn-main" type="submit">
          <i class="fa-solid fa-rotate me-1"></i> Zbato filtrat
        </button>
      </div>
    </form>
    <hr>
    <div class="d-grid">
      <a class="btn btn-outline-secondary" href="course.php">
        <i class="fa-solid fa-eraser me-1"></i> Pastro filtrat
      </a>
    </div>
  </div>
</div>

<!-- Modal: Kopjo Kurs -->
<div class="modal fade" id="copyCourseModal" tabindex="-1" aria-labelledby="copyCourseModalLabel" aria-hidden="true">
  <div class="modal-dialog modal-lg">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title" id="copyCourseModalLabel">
          <i class="fa-solid fa-copy me-2"></i>Kopjo një kurs
        </h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Mbyll"></button>
      </div>
      <div class="modal-body">
        <p class="text-secondary small mb-3">
          Zgjidh një kurs ekzistues për të kopjuar <strong>strukturën</strong>
          (seksione, leksione, detyra, kuize). S’kopjohen studentët, pagesat
          dhe kalendari. Kursi i ri krijohet <em>INACTIVE</em>.
        </p>

        <input type="hidden" id="csrfCopy" value="<?= h($CSRF) ?>">

        <label class="form-label">Kursi burim</label>
        <select class="form-select mb-3" id="courseCopySelect" size="6" aria-label="Zgjidh kursin burim">
          <?php
          try {
            $stmtAll = $pdo->query("SELECT id, title FROM courses ORDER BY title ASC");
            foreach ($stmtAll as $row) {
              echo '<option value="'.(int)$row['id'].'">'.h($row['title']).'</option>';
            }
          } catch (PDOException $e) { /* ignore */ }
          ?>
        </select>

        <div class="mb-2">
          <label class="form-label">Titulli i ri (opsionale)</label>
          <input type="text" class="form-control" id="newCourseTitle" placeholder="p.sh. 'Kursi X (kopje)'">
          <div class="form-text">Nëse e lë bosh, shtohet “(Kopje)” te titulli ekzistues.</div>
        </div>

        <div class="alert alert-info mt-3 small mb-0">
          <i class="fa-regular fa-circle-question me-1"></i>
          Seksionet e kursit të ri do të kenë <strong>hidden=1</strong> dhe <strong>highlighted=0</strong>.
        </div>
      </div>
      <div class="modal-footer">
        <button class="btn btn-secondary" data-bs-dismiss="modal">Anulo</button>
        <button class="btn btn-primary course-btn-main" id="copyCourseButton">
          <i class="fa-solid fa-copy me-1"></i>Kopjo kursin
        </button>
      </div>
    </div>
  </div>
</div>

<!-- Modal: Fshi Kurs (single) -->
<div class="modal fade" id="deleteCourseModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog">
    <form class="modal-content" method="POST" action="admin/delete_course.php">
      <div class="modal-header">
        <h5 class="modal-title">
          <i class="fa-solid fa-triangle-exclamation me-2"></i>Konfirmo fshirjen
        </h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Mbyll"></button>
      </div>
      <div class="modal-body">
        <p>Jeni të sigurt që doni të fshini kursin <strong id="courseTitlePlaceholder"></strong>?</p>
        <p class="text-danger small mb-0">
          <i class="fa-solid fa-circle-exclamation me-1"></i>Ky veprim është i pakthyeshëm.
        </p>
      </div>
      <div class="modal-footer">
        <input type="hidden" name="course_id" id="deleteCourseIdInput">
        <input type="hidden" name="csrf"     value="<?= h($CSRF) ?>">
        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Anulo</button>
        <button type="submit" class="btn btn-danger">
          <i class="fa-regular fa-trash-can me-1"></i> Fshij
        </button>
      </div>
    </form>
  </div>
</div>

<!-- Modal: Fshirje MASIVE -->
<div class="modal fade" id="bulkDeleteModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title">
          <i class="fa-solid fa-triangle-exclamation me-2"></i>Konfirmo fshirjen masive
        </h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Mbyll"></button>
      </div>
      <div class="modal-body">
        <p>Do të fshihen <strong id="bulkCount">0</strong> kurse të përzgjedhura.</p>
        <p class="text-danger small mb-0">
          <i class="fa-solid fa-circle-exclamation me-1"></i>Ky veprim është i pakthyeshëm.
        </p>
      </div>
      <div class="modal-footer">
        <button class="btn btn-secondary" data-bs-dismiss="modal">Anulo</button>
        <button class="btn btn-danger" id="confirmBulkDeleteBtn">
          <i class="fa-regular fa-trash-can me-1"></i> Fshij
        </button>
      </div>
    </div>
  </div>
</div>

<!-- Toast container -->
<div id="toastZone" aria-live="polite" aria-atomic="true"></div>

<?php include __DIR__ . '/footer2.php'; ?>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script>
// ==================== Toast-i i RI ====================
function toastIcon(type){
  if (type==='success')               return '<i class="fa-solid fa-circle-check me-2"></i>';
  if (type==='danger' || type==='error') return '<i class="fa-solid fa-triangle-exclamation me-2"></i>';
  if (type==='warning')              return '<i class="fa-solid fa-circle-exclamation me-2"></i>';
  return '<i class="fa-solid fa-circle-info me-2"></i>';
}
function showToast(type, msg){
  const zone = document.getElementById('toastZone');
  const id = 't' + Math.random().toString(16).slice(2);
  const el = document.createElement('div');
  el.className = 'toast kurse align-items-center';
  el.id = id;
  el.setAttribute('role','alert');
  el.setAttribute('aria-live','assertive');
  el.setAttribute('aria-atomic','true');
  el.innerHTML = `
    <div class="toast-header">
      <strong class="me-auto d-flex align-items-center">${toastIcon(type)} Njoftim</strong>
      <small class="text-white-50">tani</small>
      <button type="button" class="btn-close ms-2 mb-1" data-bs-dismiss="toast" aria-label="Mbyll"></button>
    </div>
    <div class="toast-body">${msg}</div>`;
  zone.appendChild(el);
  const t = new bootstrap.Toast(el, { delay: 3500, autohide: true });
  t.show();
}

// ==================== Copy course (AJAX) ====================
document.getElementById('copyCourseButton')?.addEventListener('click', async function() {
  const id    = document.getElementById('courseCopySelect')?.value;
  const title = document.getElementById('newCourseTitle')?.value || '';
  const csrf  = document.getElementById('csrfCopy')?.value || '';
  if (!id) { showToast('warning','Zgjidh një kurs për kopjim.'); return; }

  const form = new URLSearchParams();
  form.set('course_id', id);
  form.set('title_new', title);
  form.set('csrf', csrf);

  try {
    const res  = await fetch('copy_course.php', {
      method: 'POST',
      headers: { 'Content-Type':'application/x-www-form-urlencoded; charset=UTF-8' },
      body: form.toString()
    });
    const data = await res.json().catch(()=> ({}));
    if (!res.ok || !data.ok) {
      showToast('error', (data && data.error) ? data.error : 'Kopjimi dështoi.');
      return;
    }
    showToast('success','Kursi u kopjua me sukses! Po hap redaktimin…');
    setTimeout(()=>{ window.location.href = 'admin/edit_course.php?course_id=' + encodeURIComponent(data.new_course_id); }, 800);
  } catch (e) {
    showToast('error','Gabim rrjeti gjatë kopjimit.');
  }
});

// ==================== Delete single ====================
const delModal = document.getElementById('deleteCourseModal');
delModal?.addEventListener('show.bs.modal', (ev)=>{
  const btn   = ev.relatedTarget;
  const id    = btn.getAttribute('data-course-id');
  const title = btn.getAttribute('data-course-title');
  delModal.querySelector('#deleteCourseIdInput').value = id;
  delModal.querySelector('#courseTitlePlaceholder').textContent = title;
});

// ==================== Bulk actions ====================
const bulkChecks = document.querySelectorAll('.bulk-check');
const bulkBar    = document.getElementById('bulkBar');
const selCount   = document.getElementById('selCount');
const bulkIds    = document.getElementById('bulkIds');
const bulkAction = document.getElementById('bulkAction');

function refreshBulk(){
  const ids = Array.from(bulkChecks).filter(x=>x.checked).map(x=>x.value);
  if (ids.length > 0) {
    bulkBar.classList.remove('d-none');
    selCount.textContent = ids.length;
    bulkIds.value = ids.join(',');
  } else {
    bulkBar.classList.add('d-none');
    selCount.textContent = '0';
    bulkIds.value = '';
  }
}
bulkChecks.forEach(ch => ch.addEventListener('change', refreshBulk));

function submitBulk(action){
  const ids = bulkIds.value.trim();
  if (!ids) { showToast('warning','Zgjidh të paktën një kurs.'); return; }
  bulkAction.value = action;
  document.getElementById('bulkForm').submit();
}

// Modal për fshirje masive
const bulkDeleteModal = document.getElementById('bulkDeleteModal');
bulkDeleteModal?.addEventListener('show.bs.modal', ()=>{
  const count = (bulkIds.value ? bulkIds.value.split(',').length : 0);
  document.getElementById('bulkCount').textContent = count;
  if (!count){
    const m = bootstrap.Modal.getInstance(bulkDeleteModal) || new bootstrap.Modal(bulkDeleteModal);
    m.hide();
    showToast('warning','Zgjidh të paktën një kurs.');
  }
});
document.getElementById('confirmBulkDeleteBtn')?.addEventListener('click', ()=>{
  submitBulk('delete');
});

// Select all në faqe
document.getElementById('selectAllBtn')?.addEventListener('click', ()=>{
  const all = Array.from(bulkChecks);
  const allChecked = all.every(ch => ch.checked);
  all.forEach(ch => ch.checked = !allChecked);
  refreshBulk();
});

// ==================== Flash toast ====================
<?php if ($flashMsg !== ''): ?>
  showToast(<?= json_encode($flashType) ?>, <?= json_encode($flashMsg) ?>);
<?php endif; ?>
</script>
</body>
</html>
