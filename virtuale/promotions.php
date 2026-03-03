<?php
declare(strict_types=1);

session_start();
require_once __DIR__ . '/lib/database.php';

/* ------------------------------- PDO bootstrap ------------------------------- */
if (!isset($pdo) || !($pdo instanceof PDO)) {
  if (function_exists('getPDO')) $pdo = getPDO();
}
if (!isset($pdo) || !($pdo instanceof PDO)) {
  http_response_code(500);
  die('DB connection missing ($pdo / getPDO).');
}

/* ------------------------------- RBAC ------------------------------- */
if (!isset($_SESSION['user'])) { header('Location: login.php'); exit; }
$ROLE  = (string)($_SESSION['user']['role'] ?? '');
$ME_ID = (int)($_SESSION['user']['id'] ?? 0);

if (!in_array($ROLE, ['Administrator','Instruktor'], true)) {
  header('Location: courses_student.php');
  exit;
}

/* ------------------------------- CSRF ------------------------------- */
if (empty($_SESSION['csrf_token'])) { $_SESSION['csrf_token'] = bin2hex(random_bytes(32)); }
$CSRF = (string)$_SESSION['csrf_token'];

/* ----------------------------- Flash Msg ---------------------------- */
function set_flash(string $msg, string $type='success'): void { $_SESSION['flash'] = ['msg'=>$msg, 'type'=>$type]; }
function get_flash(): ?array { if (!empty($_SESSION['flash'])) { $f=$_SESSION['flash']; unset($_SESSION['flash']); return $f; } return null; }

/* ----------------------------- Helpers ------------------------------ */
function h(?string $s): string { return htmlspecialchars($s ?? '', ENT_QUOTES, 'UTF-8'); }

function money_fmt($num): string {
  if ($num === null || $num === '') return '—';
  $v = (float)$num;
  return number_format($v, 2, ',', '.') . ' €';
}
function discount_percent($old, $now): ?int {
  if ($old === null || $now === null) return null;
  $o = (float)$old; $n = (float)$now;
  if ($o <= 0 || $n <= 0 || $n >= $o) return null;
  return (int)round((($o - $n) / $o) * 100);
}
function level_label(string $lvl): string {
  return match ($lvl) {
    'BEGINNER' => 'Fillestar',
    'INTERMEDIATE' => 'Mesatar',
    'ADVANCED' => 'I avancuar',
    'ALL' => 'Për të gjithë',
    default => '—',
  };
}
function safe_photo_path(?string $photo): string {
  $file = trim((string)$photo);
  if ($file === '') return 'image/course_placeholder.jpg';
  $file = basename($file);
  return 'uploads/promotions/' . $file;
}
function nav_for_role(string $role): string {
  if ($role === 'Administrator') return __DIR__ . '/navbar_logged_administrator.php';
  if ($role === 'Instruktor') return __DIR__ . '/navbar_logged_instructor.php';
  return __DIR__ . '/navbar_logged_student.php';
}
function url_with(array $overrides): string {
  $q = $_GET;
  foreach ($overrides as $k=>$v) {
    if ($v === null || $v === '') unset($q[$k]);
    else $q[$k] = (string)$v;
  }
  unset($q['page']);
  $base = 'promotions.php';
  return $q ? ($base . '?' . http_build_query($q)) : $base;
}

/* ----------------------------- Inputs ------------------------------- */
$search     = trim((string)($_GET['q'] ?? ''));
$level      = strtoupper(trim((string)($_GET['level'] ?? ''))); // BEGINNER/INTERMEDIATE/ADVANCED/ALL
$has_video  = (isset($_GET['has_video']) && $_GET['has_video'] === '1');
$has_disc   = (isset($_GET['has_discount']) && $_GET['has_discount'] === '1');
$min_price  = trim((string)($_GET['min_price'] ?? ''));
$max_price  = trim((string)($_GET['max_price'] ?? ''));
$sort       = (string)($_GET['sort'] ?? 'created_desc');
$per_page   = (int)($_GET['per_page'] ?? 12);
$page       = max(1, (int)($_GET['page'] ?? 1));

$per_page   = min(max($per_page, 6), 60);
$offset     = ($page - 1) * $per_page;

/* --------------------------- ORDER BY Map --------------------------- */
$ORDER_BY_MAP = [
  'created_desc'  => 'p.created_at DESC',
  'created_asc'   => 'p.created_at ASC',
  'updated_desc'  => 'p.updated_at DESC',
  'updated_asc'   => 'p.updated_at ASC',
  'name_asc'      => 'p.name ASC',
  'name_desc'     => 'p.name DESC',
  'price_asc'     => 'p.price ASC',
  'price_desc'    => 'p.price DESC',
  'hours_desc'    => 'p.hours_total DESC',
  'hours_asc'     => 'p.hours_total ASC',
];
$orderBy = $ORDER_BY_MAP[$sort] ?? $ORDER_BY_MAP['created_desc'];

/* ------------------------------ WHERE ------------------------------- */
$where = [];
$params = [];

if ($search !== '') {
  $where[] = "(p.name LIKE :q OR p.short_desc LIKE :q OR p.description LIKE :q)";
  $params[':q'] = "%{$search}%";
}
if (in_array($level, ['BEGINNER','INTERMEDIATE','ADVANCED','ALL'], true)) {
  $where[] = "p.level = :lvl";
  $params[':lvl'] = $level;
}
if ($has_video) { $where[] = "(p.video_url IS NOT NULL AND p.video_url <> '')"; }
if ($has_disc)  { $where[] = "(p.old_price IS NOT NULL AND p.price IS NOT NULL AND p.old_price > p.price)"; }

if ($min_price !== '' && is_numeric($min_price)) {
  $where[] = "(p.price IS NOT NULL AND p.price >= :pmin)";
  $params[':pmin'] = (float)$min_price;
}
if ($max_price !== '' && is_numeric($max_price)) {
  $where[] = "(p.price IS NOT NULL AND p.price <= :pmax)";
  $params[':pmax'] = (float)$max_price;
}

$where_sql = $where ? ('WHERE ' . implode(' AND ', $where)) : '';

/* ----------------------------- Stats (overall) ---------------------- */
try {
  $statStmt = $pdo->query("
    SELECT
      COUNT(*) AS total,
      SUM(CASE WHEN video_url IS NOT NULL AND video_url<>'' THEN 1 ELSE 0 END) AS with_video,
      SUM(CASE WHEN old_price IS NOT NULL AND price IS NOT NULL AND old_price>price THEN 1 ELSE 0 END) AS discounted,
      SUM(CASE WHEN level='BEGINNER' THEN 1 ELSE 0 END) AS beginner_cnt,
      SUM(CASE WHEN level='INTERMEDIATE' THEN 1 ELSE 0 END) AS inter_cnt,
      SUM(CASE WHEN level='ADVANCED' THEN 1 ELSE 0 END) AS adv_cnt,
      SUM(CASE WHEN level='ALL' THEN 1 ELSE 0 END) AS all_cnt
    FROM promoted_courses
  ");
  $topStats = $statStmt->fetch(PDO::FETCH_ASSOC) ?: [];
} catch (PDOException $e) {
  $topStats = [];
}
$topStats = array_merge(
  ['total'=>0,'with_video'=>0,'discounted'=>0,'beginner_cnt'=>0,'inter_cnt'=>0,'adv_cnt'=>0,'all_cnt'=>0],
  $topStats
);

/* -------------------------- Count filtered -------------------------- */
try {
  $countSql = "SELECT COUNT(*) FROM promoted_courses p {$where_sql}";
  $countStmt = $pdo->prepare($countSql);
  foreach ($params as $k=>$v) $countStmt->bindValue($k, $v);
  $countStmt->execute();
  $totalFiltered = (int)$countStmt->fetchColumn();
} catch (PDOException $e) {
  $totalFiltered = 0;
}

/* ------------------------- SELECT main rows ------------------------- */
try {
  $sql = "
    SELECT p.*
    FROM promoted_courses p
    {$where_sql}
    ORDER BY {$orderBy}
    LIMIT :lim OFFSET :off
  ";
  $stmt = $pdo->prepare($sql);
  foreach ($params as $k=>$v) $stmt->bindValue($k, $v);
  $stmt->bindValue(':lim', $per_page, PDO::PARAM_INT);
  $stmt->bindValue(':off', $offset, PDO::PARAM_INT);
  $stmt->execute();
  $promos = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
} catch (PDOException $e) {
  http_response_code(500);
  die("Gabim në kërkesë: " . h($e->getMessage()));
}

/* ------------------------------ Pagination -------------------------- */
$pages = (int)max(1, (int)ceil(($totalFiltered ?: 0) / $per_page));
$page  = min($page, $pages);

function page_url(string $base, int $p): string {
  return (str_contains($base, '?') ? ($base . '&page=' . $p) : ($base . '?page=' . $p));
}
$baseForPaging = url_with([]); // pa page
?>
<!DOCTYPE html>
<html lang="sq">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <title>Promocione — kurseinformatike.com</title>

  <link rel="icon" href="image/favicon.ico" type="image/x-icon" />
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
  <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css" rel="stylesheet">

  <!-- Vetëm courses.css -->
  <link rel="stylesheet" href="css/courses.css?v=1">
</head>

<body class="course-body">
<?php include nav_for_role($ROLE); ?>

<!-- HERO -->
<section class="course-hero">
  <div class="container">
    <div class="d-flex flex-column flex-lg-row align-items-start align-items-lg-center justify-content-between gap-3">
      <div>
        <div class="course-breadcrumb">
          <i class="fa-solid fa-house me-1"></i> Paneli / Promocione
        </div>
        <h1>Promocione të kurseve</h1>
        <p>Krijo dhe menaxho reklamat e kurseve që shfaqen për përdoruesit.</p>
      </div>

      <div class="d-flex gap-2 flex-wrap">
        <div class="course-stat" title="Promocione totale">
          <div class="icon"><i class="fa-solid fa-bullhorn"></i></div>
          <div>
            <div class="label">Totale</div>
            <div class="value"><?= (int)$topStats['total'] ?></div>
          </div>
        </div>

        <div class="course-stat" title="Me video">
          <div class="icon"><i class="fa-solid fa-video"></i></div>
          <div>
            <div class="label">Me video</div>
            <div class="value"><?= (int)$topStats['with_video'] ?></div>
          </div>
        </div>

        <div class="course-stat" title="Me zbritje">
          <div class="icon"><i class="fa-solid fa-tag"></i></div>
          <div>
            <div class="label">Me zbritje</div>
            <div class="value"><?= (int)$topStats['discounted'] ?></div>
          </div>
        </div>
      </div>
    </div>

    <div class="d-flex gap-2 flex-wrap mt-3">
      <span class="course-chip"><i class="fa-solid fa-signal"></i> Fillestar: <strong><?= (int)$topStats['beginner_cnt'] ?></strong></span>
      <span class="course-chip"><i class="fa-solid fa-signal"></i> Mesatar: <strong><?= (int)$topStats['inter_cnt'] ?></strong></span>
      <span class="course-chip"><i class="fa-solid fa-signal"></i> Avancuar: <strong><?= (int)$topStats['adv_cnt'] ?></strong></span>
      <span class="course-chip"><i class="fa-solid fa-users"></i> Për të gjithë: <strong><?= (int)$topStats['all_cnt'] ?></strong></span>
    </div>
  </div>
</section>

<main class="container course-main">

  <!-- QUICK ACTIONS -->
  <div class="row g-3 mb-3">
    <div class="col-12 col-lg-6">
      <a class="course-quick-card" href="admin/add_promotion.php">
        <div class="icon-wrap"><i class="fa-solid fa-plus"></i></div>
        <div>
          <div class="title">Shto promocion</div>
          <div class="subtitle">Krijo një reklamë të re për kurs</div>
        </div>
      </a>
    </div>
    <div class="col-12 col-lg-6">
      <a class="course-quick-card" href="#" data-bs-toggle="modal" data-bs-target="#copyPromotionModal">
        <div class="icon-wrap"><i class="fa-regular fa-copy"></i></div>
        <div>
          <div class="title">Kopjo promocion</div>
          <div class="subtitle">Krijo duke kopjuar një ekzistues</div>
        </div>
      </a>
    </div>
  </div>

  <!-- LAYOUT: sidebar + content (si courses) -->
  <form method="get" id="filterForm">
    <div class="row g-3 course-layout">

      <!-- SIDEBAR (desktop) -->
      <aside class="col-lg-3">
        <div class="course-sidebar">
          <div class="course-sidebar-title">
            <span><i class="fa-solid fa-filter me-1"></i> Filtra</span>
            <a class="btn-link-reset" href="promotions.php"><i class="fa-solid fa-eraser"></i> Pastro</a>
          </div>

          <div class="mb-2">
            <label class="form-label">Niveli</label>
            <select class="form-select" name="level">
              <option value="">Të gjithë</option>
              <?php foreach (['BEGINNER'=>'Fillestar','INTERMEDIATE'=>'Mesatar','ADVANCED'=>'I avancuar','ALL'=>'Për të gjithë'] as $k=>$v): ?>
                <option value="<?= h($k) ?>" <?= $level===$k?'selected':'' ?>><?= h($v) ?></option>
              <?php endforeach; ?>
            </select>
          </div>

          <div class="row g-2 mb-2">
            <div class="col-6">
              <label class="form-label">Çmimi min</label>
              <input type="number" step="0.01" class="form-control" name="min_price" value="<?= h($min_price) ?>">
            </div>
            <div class="col-6">
              <label class="form-label">Çmimi max</label>
              <input type="number" step="0.01" class="form-control" name="max_price" value="<?= h($max_price) ?>">
            </div>
          </div>

          <div class="form-check mb-2">
            <input class="form-check-input" type="checkbox" name="has_video" id="sHasVideo" value="1" <?= $has_video?'checked':'' ?>>
            <label class="form-check-label" for="sHasVideo">Vetëm me video</label>
          </div>

          <div class="form-check mb-3">
            <input class="form-check-input" type="checkbox" name="has_discount" id="sHasDiscount" value="1" <?= $has_disc?'checked':'' ?>>
            <label class="form-check-label" for="sHasDiscount">Vetëm me zbritje</label>
          </div>

          <div class="d-grid gap-2">
            <button class="btn btn-primary course-btn-main" type="submit">
              <i class="fa-solid fa-check me-1"></i> Zbato filtrat
            </button>
          </div>
        </div>
      </aside>

      <!-- CONTENT -->
      <section class="col-lg-9">

        <!-- TOOLBAR -->
        <div class="course-toolbar mb-2">
          <div class="row g-2 align-items-end">
            <div class="col-12 col-md-6">
              <label class="form-label small text-muted">Kërko</label>
              <div class="input-group">
                <span class="input-group-text bg-white"><i class="fa-solid fa-magnifying-glass"></i></span>
                <input type="text" class="form-control" name="q" value="<?= h($search) ?>" placeholder="Emri / përshkrimi / detaje…">
              </div>
            </div>

            <div class="col-6 col-md-3">
              <label class="form-label small text-muted">Renditja</label>
              <select class="form-select" name="sort" id="sortSelect">
                <option value="created_desc" <?= $sort==='created_desc'?'selected':'' ?>>Më të rejat</option>
                <option value="created_asc"  <?= $sort==='created_asc'?'selected':''  ?>>Më të vjetrat</option>
                <option value="updated_desc" <?= $sort==='updated_desc'?'selected':'' ?>>Të përditësuarat (↓)</option>
                <option value="updated_asc"  <?= $sort==='updated_asc'?'selected':''  ?>>Të përditësuarat (↑)</option>
                <option value="name_asc"     <?= $sort==='name_asc'?'selected':''     ?>>Emri A→Z</option>
                <option value="name_desc"    <?= $sort==='name_desc'?'selected':''    ?>>Emri Z→A</option>
                <option value="price_asc"    <?= $sort==='price_asc'?'selected':''    ?>>Çmimi (↑)</option>
                <option value="price_desc"   <?= $sort==='price_desc'?'selected':''   ?>>Çmimi (↓)</option>
                <option value="hours_desc"   <?= $sort==='hours_desc'?'selected':''   ?>>Orët (↓)</option>
                <option value="hours_asc"    <?= $sort==='hours_asc'?'selected':''    ?>>Orët (↑)</option>
              </select>
            </div>

            <div class="col-6 col-md-3">
              <label class="form-label small text-muted">Për faqe</label>
              <select class="form-select" name="per_page" id="perPageSelect">
                <?php foreach([6,12,18,24,36,48,60] as $pp): ?>
                  <option value="<?= $pp ?>" <?= $per_page===$pp?'selected':'' ?>><?= $pp ?>/faqe</option>
                <?php endforeach; ?>
              </select>
            </div>

            <div class="col-12 d-flex gap-2 justify-content-end mt-1 flex-wrap">
              <button type="button" class="btn btn-outline-secondary course-btn-ghost" id="selectAllBtn">
                <i class="fa-regular fa-square-check me-1"></i> Zgjidh
              </button>

              <button type="button" class="btn btn-outline-secondary d-lg-none" data-bs-toggle="offcanvas" data-bs-target="#filtersCanvas">
                <i class="fa-solid fa-filter me-1"></i> Filtra
              </button>

              <a class="btn btn-outline-secondary" href="promotions.php">
                <i class="fa-solid fa-eraser me-1"></i> Pastro
              </a>

              <button class="btn btn-primary course-btn-main" type="submit">
                <i class="fa-solid fa-magnifying-glass me-1"></i> Kërko
              </button>

              <a class="btn btn-dark course-btn-main" href="admin/add_promotion.php">
                <i class="fa-solid fa-plus me-1"></i> Shto
              </a>
            </div>
          </div>

          <!-- Chips -->
          <div class="d-flex gap-2 flex-wrap mt-2">
            <span class="course-chip"><i class="fa-regular fa-folder-open"></i> Rezultate: <strong><?= (int)$totalFiltered ?></strong></span>
            <?php if ($search !== ''): ?><span class="course-chip"><i class="fa-solid fa-magnifying-glass"></i> “<?= h($search) ?>”</span><?php endif; ?>
            <?php if ($level !== '' && in_array($level, ['BEGINNER','INTERMEDIATE','ADVANCED','ALL'], true)): ?>
              <span class="course-chip"><i class="fa-solid fa-signal"></i> <?= h(level_label($level)) ?></span>
            <?php endif; ?>
            <?php if ($has_video): ?><span class="course-chip"><i class="fa-solid fa-video"></i> Me video</span><?php endif; ?>
            <?php if ($has_disc): ?><span class="course-chip"><i class="fa-solid fa-tag"></i> Me zbritje</span><?php endif; ?>
            <?php if ($min_price !== ''): ?><span class="course-chip"><i class="fa-solid fa-euro-sign"></i> ≥ <?= h($min_price) ?></span><?php endif; ?>
            <?php if ($max_price !== ''): ?><span class="course-chip"><i class="fa-solid fa-euro-sign"></i> ≤ <?= h($max_price) ?></span><?php endif; ?>
          </div>
        </div>

        <!-- Level tabs (si status tabs) -->
        <ul class="nav nav-pills course-status-tabs flex-wrap gap-2 mb-2">
          <?php
            $tabs = [
              ''=>'Të gjithë',
              'BEGINNER'=>'Fillestar',
              'INTERMEDIATE'=>'Mesatar',
              'ADVANCED'=>'I avancuar',
              'ALL'=>'Për të gjithë',
            ];
            foreach ($tabs as $k=>$label):
              $active = ($k === '' ? ($level==='' || !in_array($level, ['BEGINNER','INTERMEDIATE','ADVANCED','ALL'], true)) : ($level===$k));
          ?>
            <li class="nav-item">
              <a class="nav-link <?= $active?'active':'' ?>" href="<?= h(url_with(['level'=>$k])) ?>">
                <?= h($label) ?>
              </a>
            </li>
          <?php endforeach; ?>
        </ul>

        <!-- GRID -->
        <div class="course-grid">
          <?php if (!$promos): ?>
            <div class="course-empty">
              <div class="icon"><i class="fa-regular fa-face-smile-beam"></i></div>
              <div class="title">S’u gjet asnjë promocion me këto filtra</div>
              <div class="subtitle">Provo të ndryshosh filtrat ose krijo një të ri.</div>
              <a class="btn btn-primary course-btn-main" href="admin/add_promotion.php">
                <i class="fa-solid fa-plus me-1"></i> Shto promocion
              </a>
            </div>
          <?php else: ?>
            <div class="row g-3">
              <?php foreach ($promos as $p):
                $photo = safe_photo_path($p['photo'] ?? null);
                $disc  = discount_percent($p['old_price'] ?? null, $p['price'] ?? null);
                $lvl   = strtoupper(trim((string)($p['level'] ?? '')));
                $video = trim((string)($p['video_url'] ?? ''));
                $isVideo = ($video !== '' && filter_var($video, FILTER_VALIDATE_URL));
                $label = trim((string)($p['label'] ?? ''));
                $name  = (string)($p['name'] ?? '');
                $short = (string)($p['short_desc'] ?? '');
                $hours = (int)($p['hours_total'] ?? 0);
                $createdAt = !empty($p['created_at']) ? date('d.m.Y', strtotime((string)$p['created_at'])) : '—';
              ?>
              <div class="col-12 col-sm-6 col-xl-4">
                <div class="course-card h-100">
                  <div class="thumb">
                    <img src="<?= h($photo) ?>" alt="Promo: <?= h($name) ?>" loading="lazy">

                    <?php if ($label !== ''): ?>
                      <span class="cat-badge"><?= h($label) ?></span>
                    <?php endif; ?>

                    <div class="selbox form-check">
                      <input class="form-check-input bulk-check" type="checkbox" value="<?= (int)$p['id'] ?>" aria-label="Zgjidh promocionin">
                    </div>

                    <?php if ($disc): ?>
                      <span class="badge text-bg-success position-absolute bottom-0 end-0 m-2">-<?= (int)$disc ?>%</span>
                    <?php endif; ?>
                  </div>

                  <div class="card-body">
                    <h3 class="course-title">
                      <a href="promotion_details.php?id=<?= (int)$p['id'] ?>"><?= h($name) ?></a>
                    </h3>

                    <?php if ($short !== ''): ?>
                      <div class="course-desc"><?= h(mb_strimwidth($short, 0, 140, '…', 'UTF-8')) ?></div>
                    <?php else: ?>
                      <div class="course-desc">—</div>
                    <?php endif; ?>

                    <div class="course-meta">
                      <span><i class="fa-regular fa-clock me-1"></i><?= $hours ?> orë</span>
                      <span><i class="fa-solid fa-signal me-1"></i><?= h(level_label($lvl)) ?></span>
                      <span>
                        <i class="fa-solid fa-euro-sign me-1"></i>
                        <strong><?= h(money_fmt($p['price'] ?? null)) ?></strong>
                        <?php if (($p['old_price'] ?? null) !== null && (float)$p['old_price'] > (float)($p['price'] ?? 0)): ?>
                          <span class="text-decoration-line-through ms-1"><?= h(money_fmt($p['old_price'])) ?></span>
                        <?php endif; ?>
                      </span>
                      <span><i class="fa-regular fa-calendar me-1"></i><?= h($createdAt) ?></span>
                      <?php if ($isVideo): ?>
                        <span><i class="fa-solid fa-video me-1"></i>Video</span>
                      <?php endif; ?>
                    </div>

                    <div class="d-flex align-items-center justify-content-between mt-3">
                      <div class="btn-group">
                        <a class="btn btn-sm btn-outline-primary" href="promotion_details.php?id=<?= (int)$p['id'] ?>" title="Shiko">
                          <i class="fa-regular fa-eye"></i>
                        </a>
                        <a class="btn btn-sm btn-outline-secondary" href="edit_promotion.php?id=<?= (int)$p['id'] ?>" title="Redakto">
                          <i class="fa-regular fa-pen-to-square"></i>
                        </a>
                        <button type="button" class="btn btn-sm btn-outline-danger"
                                title="Fshi"
                                data-bs-toggle="modal" data-bs-target="#deletePromotionModal"
                                data-promo-id="<?= (int)$p['id'] ?>"
                                data-promo-name="<?= h($name) ?>">
                          <i class="fa-regular fa-trash-can"></i>
                        </button>
                      </div>

                      <?php if ($isVideo): ?>
                        <a class="btn btn-sm btn-outline-dark course-btn-main" target="_blank" rel="noopener" href="<?= h($video) ?>" title="Video">
                          <i class="fa-solid fa-video"></i>
                        </a>
                      <?php endif; ?>
                    </div>

                  </div>
                </div>
              </div>
              <?php endforeach; ?>
            </div>
          <?php endif; ?>
        </div>

        <!-- Pagination -->
        <?php if ($pages > 1): ?>
          <nav class="mt-3" aria-label="Faqëzimi">
            <ul class="pagination pagination-sm flex-wrap">
              <li class="page-item <?= $page<=1?'disabled':'' ?>">
                <a class="page-link" href="<?= h(page_url($baseForPaging, 1)) ?>">&laquo;&laquo;</a>
              </li>
              <li class="page-item <?= $page<=1?'disabled':'' ?>">
                <a class="page-link" href="<?= h(page_url($baseForPaging, max(1,$page-1))) ?>">&laquo;</a>
              </li>

              <?php
                $window = 2;
                $start = max(1, $page - $window);
                $end   = min($pages, $page + $window);

                if ($start > 1) {
                  echo '<li class="page-item"><a class="page-link" href="'.h(page_url($baseForPaging,1)).'">1</a></li>';
                  if ($start > 2) echo '<li class="page-item disabled"><span class="page-link">…</span></li>';
                }

                for ($i=$start; $i<=$end; $i++) {
                  $active = ($i === $page) ? 'active' : '';
                  echo '<li class="page-item '.$active.'"><a class="page-link" href="'.h(page_url($baseForPaging,$i)).'">'.$i.'</a></li>';
                }

                if ($end < $pages) {
                  if ($end < $pages - 1) echo '<li class="page-item disabled"><span class="page-link">…</span></li>';
                  echo '<li class="page-item"><a class="page-link" href="'.h(page_url($baseForPaging,$pages)).'">'.$pages.'</a></li>';
                }
              ?>

              <li class="page-item <?= $page>=$pages?'disabled':'' ?>">
                <a class="page-link" href="<?= h(page_url($baseForPaging, min($pages,$page+1))) ?>">&raquo;</a>
              </li>
              <li class="page-item <?= $page>=$pages?'disabled':'' ?>">
                <a class="page-link" href="<?= h(page_url($baseForPaging, $pages)) ?>">&raquo;&raquo;</a>
              </li>
            </ul>
          </nav>
        <?php endif; ?>

        <!-- BULK BAR (stil sipas courses.css) -->
        <div id="bulkBar" class="course-bulkbar d-none mt-3">
          <div class="d-flex align-items-center justify-content-between gap-2 flex-wrap">
            <div><i class="fa-solid fa-circle-check me-1"></i> <span id="selCount">0</span> të selektuar</div>

            <form id="bulkForm" method="POST" action="promotions_bulk_action.php" class="d-flex gap-2 mb-0">
              <input type="hidden" name="ids" id="bulkIds">
              <input type="hidden" name="action" id="bulkAction">
              <input type="hidden" name="csrf" value="<?= h($CSRF) ?>">

              <button type="button" class="btn btn-sm btn-light" id="bulkDeleteBtn">
                <i class="fa-regular fa-trash-can me-1"></i> Fshi
              </button>

              <button type="button" class="btn btn-sm btn-outline-light" id="bulkClearBtn">
                <i class="fa-solid fa-xmark me-1"></i> Pastro
              </button>
            </form>
          </div>
        </div>

      </section>
    </div>
  </form>

</main>

<!-- Offcanvas Filters (mobile) -->
<div class="offcanvas offcanvas-end" tabindex="-1" id="filtersCanvas" aria-labelledby="filtersCanvasLabel">
  <div class="offcanvas-header">
    <h5 class="offcanvas-title" id="filtersCanvasLabel"><i class="fa-solid fa-filter me-1"></i> Filtra</h5>
    <button type="button" class="btn-close" data-bs-dismiss="offcanvas" aria-label="Mbyll"></button>
  </div>
  <div class="offcanvas-body">
    <form method="get" class="vstack gap-3">
      <input type="hidden" name="q" value="<?= h($search) ?>">
      <input type="hidden" name="sort" value="<?= h($sort) ?>">
      <input type="hidden" name="per_page" value="<?= (int)$per_page ?>">

      <div>
        <label class="form-label">Niveli</label>
        <select class="form-select" name="level">
          <option value="">Të gjithë</option>
          <?php foreach (['BEGINNER'=>'Fillestar','INTERMEDIATE'=>'Mesatar','ADVANCED'=>'I avancuar','ALL'=>'Për të gjithë'] as $k=>$v): ?>
            <option value="<?= h($k) ?>" <?= $level===$k?'selected':'' ?>><?= h($v) ?></option>
          <?php endforeach; ?>
        </select>
      </div>

      <div class="row g-2">
        <div class="col-6">
          <label class="form-label">Çmimi min</label>
          <input type="number" step="0.01" class="form-control" name="min_price" value="<?= h($min_price) ?>">
        </div>
        <div class="col-6">
          <label class="form-label">Çmimi max</label>
          <input type="number" step="0.01" class="form-control" name="max_price" value="<?= h($max_price) ?>">
        </div>
      </div>

      <div class="form-check">
        <input class="form-check-input" type="checkbox" name="has_video" id="fHasVideo" value="1" <?= $has_video?'checked':'' ?>>
        <label class="form-check-label" for="fHasVideo">Vetëm me video</label>
      </div>

      <div class="form-check">
        <input class="form-check-input" type="checkbox" name="has_discount" id="fHasDiscount" value="1" <?= $has_disc?'checked':'' ?>>
        <label class="form-check-label" for="fHasDiscount">Vetëm me zbritje</label>
      </div>

      <div class="d-grid gap-2">
        <button class="btn btn-primary course-btn-main" type="submit"><i class="fa-solid fa-check me-1"></i> Zbato filtrat</button>
        <a class="btn btn-outline-secondary" href="promotions.php"><i class="fa-solid fa-eraser me-1"></i> Pastro filtrat</a>
      </div>
    </form>
  </div>
</div>

<!-- Modal: Kopjo Promocion -->
<div class="modal fade" id="copyPromotionModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-lg">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title"><i class="fa-regular fa-copy me-2"></i>Kopjo një promocion</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Mbyll"></button>
      </div>
      <div class="modal-body">
        <p class="text-secondary mb-2">Zgjidh një promocion ekzistues për të kopjuar fushat.</p>
        <label class="form-label">Promocioni burim</label>
        <select class="form-select" id="promoCopySelect" size="7" aria-label="Zgjidh promocionin burim">
          <?php
          try {
            $stmtAll = $pdo->query("SELECT id, name FROM promoted_courses ORDER BY name ASC");
            foreach ($stmtAll as $row) {
              echo '<option value="'.(int)$row['id'].'">'.h((string)$row['name']).'</option>';
            }
          } catch (PDOException $e) {}
          ?>
        </select>

        <div class="alert alert-info mt-3 mb-0">
          <i class="fa-regular fa-circle-question me-1"></i>
          Promocioni i ri hapet si draft te <code>add_promotion.php?copy_promotion_id=ID</code>.
        </div>
      </div>
      <div class="modal-footer">
        <button class="btn btn-outline-secondary" data-bs-dismiss="modal">Anulo</button>
        <button class="btn btn-primary course-btn-main" id="copyPromotionButton">
          <i class="fa-regular fa-copy me-1"></i> Kopjo
        </button>
      </div>
    </div>
  </div>
</div>

<!-- Modal: Fshi Promocion -->
<div class="modal fade" id="deletePromotionModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog">
    <form class="modal-content" method="POST" action="admin/delete_promotion.php">
      <div class="modal-header">
        <h5 class="modal-title"><i class="fa-solid fa-triangle-exclamation me-2"></i>Konfirmo fshirjen</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Mbyll"></button>
      </div>
      <div class="modal-body">
        <p>Jeni të sigurt që doni të fshini <strong id="promoTitlePlaceholder"></strong>?</p>
        <p class="text-danger small mb-0"><i class="fa-solid fa-circle-exclamation me-1"></i>Ky veprim është i pakthyeshëm.</p>
      </div>
      <div class="modal-footer">
        <input type="hidden" name="id" id="deletePromoIdInput">
        <input type="hidden" name="csrf" value="<?= h($CSRF) ?>">
        <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Anulo</button>
        <button type="submit" class="btn btn-danger"><i class="fa-regular fa-trash-can me-1"></i> Fshij</button>
      </div>
    </form>
  </div>
</div>

<!-- Toast zone -->
<div id="toastZone" aria-live="polite" aria-atomic="true"></div>

<?php include __DIR__ . '/footer2.php'; ?>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script>
/* ---------------- Copy promotion ---------------- */
document.getElementById('copyPromotionButton')?.addEventListener('click', function() {
  const id = document.getElementById('promoCopySelect')?.value;
  if (!id) { showToast('warning', 'Zgjidh një promocion për kopjim.'); return; }
  window.location.href = 'admin/add_promotion.php?copy_promotion_id=' + encodeURIComponent(id);
});

/* ---------------- Delete modal populate ---------------- */
const delModal = document.getElementById('deletePromotionModal');
delModal?.addEventListener('show.bs.modal', (ev)=>{
  const btn = ev.relatedTarget;
  const id = btn.getAttribute('data-promo-id') || '';
  const name = btn.getAttribute('data-promo-name') || '';
  delModal.querySelector('#deletePromoIdInput').value = id;
  delModal.querySelector('#promoTitlePlaceholder').textContent = name;
});

/* ---------------- Auto-submit on sort/per-page ---------------- */
document.getElementById('sortSelect')?.addEventListener('change', ()=> document.getElementById('filterForm').submit());
document.getElementById('perPageSelect')?.addEventListener('change', ()=> document.getElementById('filterForm').submit());

/* ---------------- Bulk selection ---------------- */
const bulkChecks = () => Array.from(document.querySelectorAll('.bulk-check'));
const bulkBar = document.getElementById('bulkBar');
const selCount = document.getElementById('selCount');
const bulkIds = document.getElementById('bulkIds');
const selectAllBtn = document.getElementById('selectAllBtn');
const bulkClearBtn = document.getElementById('bulkClearBtn');

function refreshBulk(){
  const ids = bulkChecks().filter(x=>x.checked).map(x=>x.value);
  if (ids.length > 0) {
    bulkBar.classList.remove('d-none');
    selCount.textContent = String(ids.length);
    bulkIds.value = ids.join(',');
    selectAllBtn.innerHTML = '<i class="fa-regular fa-square-minus me-1"></i> Hiq';
  } else {
    bulkBar.classList.add('d-none');
    selCount.textContent = '0';
    bulkIds.value = '';
    selectAllBtn.innerHTML = '<i class="fa-regular fa-square-check me-1"></i> Zgjidh';
  }
}
bulkChecks().forEach(ch => ch.addEventListener('change', refreshBulk));

selectAllBtn?.addEventListener('click', ()=>{
  const list = bulkChecks();
  const anyUnchecked = list.some(x=>!x.checked);
  list.forEach(x => x.checked = anyUnchecked);
  refreshBulk();
});

bulkClearBtn?.addEventListener('click', ()=>{
  bulkChecks().forEach(x => x.checked = false);
  refreshBulk();
});

document.getElementById('bulkDeleteBtn')?.addEventListener('click', ()=>{
  if (!bulkIds.value) return;
  if (confirm('Të fshihen promocionet e përzgjedhura? Ky veprim është i pakthyeshëm.')) {
    document.getElementById('bulkAction').value = 'delete';
    document.getElementById('bulkForm').submit();
  }
});

/* ---------------- Toasts (stil sipas courses.css) ---------------- */
function toastIcon(type){
  if (type==='success') return '<i class="fa-solid fa-circle-check me-2"></i>';
  if (type==='danger' || type==='error') return '<i class="fa-solid fa-triangle-exclamation me-2"></i>';
  if (type==='warning') return '<i class="fa-solid fa-circle-exclamation me-2"></i>';
  return '<i class="fa-solid fa-circle-info me-2"></i>';
}
function showToast(type, msg){
  const zone = document.getElementById('toastZone');
  const el = document.createElement('div');
  el.className = 'toast kurse align-items-center';
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
  new bootstrap.Toast(el, { delay: 3500, autohide: true }).show();
}

<?php if ($flash = get_flash()): ?>
showToast(<?= json_encode((string)$flash['type']) ?>, <?= json_encode((string)$flash['msg']) ?>);
<?php endif; ?>
</script>
</body>
</html>
