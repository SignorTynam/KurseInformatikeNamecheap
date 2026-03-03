<?php
// payments_student.php — Revamp UI (Student) unified with payments.php (Admin) theme
declare(strict_types=1);
session_start();
require_once __DIR__ . '/lib/database.php';

/* ------------------------------- Helpers ------------------------------- */
function h(?string $s): string { return htmlspecialchars($s ?? '', ENT_QUOTES, 'UTF-8'); }

/* -------------------------------- RBAC -------------------------------- */
if (empty($_SESSION['user']) || (string)($_SESSION['user']['role'] ?? '') !== 'Student') {
  header('Location: login.php'); exit;
}
$ME_ID = (int)($_SESSION['user']['id'] ?? 0);

/* ----------------------------- GET Filters ----------------------------- */
$q         = trim((string)($_GET['q'] ?? ''));
$statusRaw = strtoupper(trim((string)($_GET['status'] ?? 'ALL'))); // ALL|COMPLETED|FAILED
$date_from = trim((string)($_GET['date_from'] ?? ''));
$date_to   = trim((string)($_GET['date_to'] ?? ''));

$sort     = (string)($_GET['sort'] ?? 'date_desc');
$per_page = (int)($_GET['per_page'] ?? 12);
$page     = max(1, (int)($_GET['page'] ?? 1));
$per_page = min(max($per_page, 6), 60);
$offset   = ($page - 1) * $per_page;

// validime
if (!in_array($statusRaw, ['ALL','COMPLETED','FAILED'], true)) $statusRaw = 'ALL';
if ($date_from !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $date_from)) $date_from = '';
if ($date_to   !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $date_to))   $date_to   = '';

$ORDER_BY = [
  'date_desc'   => 'p.payment_date DESC, p.id DESC',
  'date_asc'    => 'p.payment_date ASC, p.id ASC',
  'amount_desc' => 'p.amount DESC, p.payment_date DESC',
  'amount_asc'  => 'p.amount ASC, p.payment_date DESC',
  'id_desc'     => 'p.id DESC',
  'id_asc'      => 'p.id ASC',
];
$orderBy = $ORDER_BY[$sort] ?? $ORDER_BY['date_desc'];

/* ------------------------------ WHERE (common) ------------------------- */
$where = [];
$params = [':me' => $ME_ID];

// vetëm pagesat e mia
$where[] = "p.user_id = :me";

if ($q !== '') {
  $where[] = "(c.title LIKE :q OR l.title LIKE :q OR CAST(p.id AS CHAR) LIKE :q)";
  $params[':q'] = "%{$q}%";
}
if ($date_from !== '') {
  $where[] = "DATE(p.payment_date) >= :df";
  $params[':df'] = $date_from;
}
if ($date_to !== '') {
  $where[] = "DATE(p.payment_date) <= :dt";
  $params[':dt'] = $date_to;
}

$whereCommon = $where ? ('WHERE ' . implode(' AND ', $where)) : 'WHERE p.user_id = :me';

/* ------------------------------- Tab stats ----------------------------- */
$tabStats = ['total'=>0,'completed_cnt'=>0,'failed_cnt'=>0,'completed_sum'=>0.0,'failed_sum'=>0.0];
try {
  $sqlTabs = "
    SELECT
      COUNT(*) AS total,
      SUM(CASE WHEN p.payment_status='COMPLETED' THEN 1 ELSE 0 END) AS completed_cnt,
      SUM(CASE WHEN p.payment_status='FAILED' THEN 1 ELSE 0 END) AS failed_cnt,
      SUM(CASE WHEN p.payment_status='COMPLETED' THEN p.amount ELSE 0 END) AS completed_sum,
      SUM(CASE WHEN p.payment_status='FAILED' THEN p.amount ELSE 0 END) AS failed_sum
    FROM payments p
    LEFT JOIN courses c ON c.id = p.course_id
    LEFT JOIN lessons l ON l.id = p.lesson_id
    {$whereCommon}
      AND p.payment_status IN ('COMPLETED','FAILED')
  ";
  $stTabs = $pdo->prepare($sqlTabs);
  foreach ($params as $k=>$v) $stTabs->bindValue($k, $v);
  $stTabs->execute();
  $row = $stTabs->fetch(PDO::FETCH_ASSOC);
  if ($row) {
    $tabStats['total'] = (int)($row['total'] ?? 0);
    $tabStats['completed_cnt'] = (int)($row['completed_cnt'] ?? 0);
    $tabStats['failed_cnt']    = (int)($row['failed_cnt'] ?? 0);
    $tabStats['completed_sum'] = (float)($row['completed_sum'] ?? 0);
    $tabStats['failed_sum']    = (float)($row['failed_sum'] ?? 0);
  }
} catch (PDOException $e) {}

/* ------------------------------- WHERE (list) -------------------------- */
$whereList = $where;
if ($statusRaw === 'COMPLETED') {
  $whereList[] = "p.payment_status = 'COMPLETED'";
} elseif ($statusRaw === 'FAILED') {
  $whereList[] = "p.payment_status = 'FAILED'";
} else {
  $whereList[] = "p.payment_status IN ('COMPLETED','FAILED')";
}
$whereListSql = 'WHERE ' . implode(' AND ', $whereList);

/* ------------------------------- Count for pagination ------------------ */
$totalFiltered = 0;
try {
  $sqlCount = "
    SELECT COUNT(*)
    FROM payments p
    LEFT JOIN courses c ON c.id = p.course_id
    LEFT JOIN lessons l ON l.id = p.lesson_id
    {$whereListSql}
  ";
  $stCount = $pdo->prepare($sqlCount);
  foreach ($params as $k=>$v) $stCount->bindValue($k, $v);
  $stCount->execute();
  $totalFiltered = (int)$stCount->fetchColumn();
} catch (PDOException $e) { $totalFiltered = 0; }

/* ------------------------------- Fetch rows (paged) -------------------- */
$payments = [];
try {
  $sql = "
    SELECT
      p.id, p.course_id, p.lesson_id, p.amount, p.payment_status, p.payment_date,
      c.title AS course_title,
      l.title AS lesson_title
    FROM payments p
    LEFT JOIN courses c ON c.id = p.course_id
    LEFT JOIN lessons l ON l.id = p.lesson_id
    {$whereListSql}
    ORDER BY {$orderBy}
    LIMIT :lim OFFSET :off
  ";
  $st = $pdo->prepare($sql);
  foreach ($params as $k=>$v) $st->bindValue($k, $v);
  $st->bindValue(':lim', $per_page, PDO::PARAM_INT);
  $st->bindValue(':off', $offset, PDO::PARAM_INT);
  $st->execute();
  $payments = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
} catch (PDOException $e) {
  die("Gabim gjatë marrjes së pagesave: " . h($e->getMessage()));
}

/* ------------------------------- Chart last 7 days (student + current filters) --- */
$last7 = [];
for ($i=6; $i>=0; $i--) {
  $d = date('Y-m-d', strtotime("-{$i} days"));
  $last7[$d] = ['completed'=>0.0, 'failed'=>0.0];
}
try {
  $sqlChart = "
    SELECT DATE(p.payment_date) AS d,
           SUM(CASE WHEN p.payment_status='COMPLETED' THEN p.amount ELSE 0 END) AS completed,
           SUM(CASE WHEN p.payment_status='FAILED' THEN p.amount ELSE 0 END) AS failed
    FROM payments p
    LEFT JOIN courses c ON c.id = p.course_id
    LEFT JOIN lessons l ON l.id = p.lesson_id
    {$whereListSql}
      AND p.payment_date >= DATE_SUB(CURDATE(), INTERVAL 6 DAY)
    GROUP BY DATE(p.payment_date)
  ";
  $stC = $pdo->prepare($sqlChart);
  foreach ($params as $k=>$v) $stC->bindValue($k, $v);
  $stC->execute();
  $rows = $stC->fetchAll(PDO::FETCH_ASSOC) ?: [];
  foreach ($rows as $r) {
    $d = (string)($r['d'] ?? '');
    if (!isset($last7[$d])) continue;
    $last7[$d]['completed'] = (float)($r['completed'] ?? 0);
    $last7[$d]['failed']    = (float)($r['failed'] ?? 0);
  }
} catch (PDOException $e) {}

$labels = array_map(fn($d)=>date('d M', strtotime($d)), array_keys($last7));
$seriesCompleted = array_map(fn($x)=>(float)$x['completed'], array_values($last7));
$seriesFailed    = array_map(fn($x)=>(float)$x['failed'], array_values($last7));
$total7days = array_sum($seriesCompleted) + array_sum($seriesFailed);

/* ------------------------------- URL helper ---------------------------- */
function build_url(array $patch): string {
  $qs = $_GET;
  foreach ($patch as $k=>$v) {
    if ($v === null) unset($qs[$k]);
    else $qs[$k] = $v;
  }
  return 'payments_student.php' . (empty($qs) ? '' : ('?' . http_build_query($qs)));
}
?>
<!DOCTYPE html>
<html lang="sq">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <title>Pagesat e mia — Virtuale</title>

  <link rel="icon" href="image/favicon.ico" type="image/x-icon" />
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
  <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css" rel="stylesheet">

  <!-- Unifikim UI me admin -->
  <link rel="stylesheet" href="css/courses.css?v=1">
  <link rel="stylesheet" href="css/payments.css?v=1">
  <!-- Student overrides (opsional, por i pastër) -->
  <link rel="stylesheet" href="css/payments_student.css?v=1">

  <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js"></script>
</head>

<body class="course-body">
<?php include __DIR__ . '/navbar_logged_student.php'; ?>

<!-- HERO -->
<header class="course-hero">
  <div class="container">
    <div class="d-flex flex-column flex-lg-row align-items-start align-items-lg-center justify-content-between gap-3">
      <div>
        <div class="course-breadcrumb">
          <i class="fa-solid fa-house me-1"></i> Paneli / Pagesat
        </div>
        <h1>Pagesat e mia</h1>
        <p>Shiko historikun e pagesave dhe gjendjen financiare.</p>
      </div>

      <div class="d-flex flex-wrap gap-2">
        <div class="course-stat">
          <div class="icon"><i class="fa-solid fa-circle-check"></i></div>
          <div>
            <div class="label">Suksesshme</div>
            <div class="value"><?= number_format((float)$tabStats['completed_sum'], 2) ?>€ • <?= (int)$tabStats['completed_cnt'] ?></div>
          </div>
        </div>
        <div class="course-stat">
          <div class="icon"><i class="fa-solid fa-circle-xmark"></i></div>
          <div>
            <div class="label">Të dështuara</div>
            <div class="value"><?= number_format((float)$tabStats['failed_sum'], 2) ?>€ • <?= (int)$tabStats['failed_cnt'] ?></div>
          </div>
        </div>
      </div>
    </div>
  </div>
</header>

<main class="course-main">
  <div class="container">

    <!-- Quick actions (student) -->
    <section class="course-quick row g-3 mb-3">
      <div class="col-sm-6 col-lg-6">
        <a class="course-quick-card" href="courses_student.php">
          <div class="icon-wrap"><i class="fa-solid fa-book"></i></div>
          <div>
            <div class="title">Kurset e mia</div>
            <div class="subtitle">Shko te lista e kurseve</div>
          </div>
        </a>
      </div>
      <div class="col-sm-6 col-lg-6">
        <a class="course-quick-card" href="myAssignments.php">
          <div class="icon-wrap"><i class="fa-solid fa-list-check"></i></div>
          <div>
            <div class="title">Detyrat e mia</div>
            <div class="subtitle">Dorëzo & ndiq progresin</div>
          </div>
        </a>
      </div>
    </section>

    <!-- Toolbar (same pattern as admin) -->
    <section class="course-toolbar mb-3">
      <form class="row g-2 align-items-center" method="get">
        <div class="col-12 col-md-6">
          <div class="input-group">
            <span class="input-group-text bg-white border-end-0"><i class="fa-solid fa-search"></i></span>
            <input type="text" class="form-control border-start-0"
                   name="q" value="<?= h($q) ?>"
                   placeholder="Kërko: kurs, leksion, #ID…">
          </div>
        </div>

        <div class="col-6 col-md-3">
          <select class="form-select" name="sort">
            <option value="date_desc"   <?= $sort==='date_desc'?'selected':'' ?>>Më të rejat</option>
            <option value="date_asc"    <?= $sort==='date_asc'?'selected':'' ?>>Më të vjetrat</option>
            <option value="amount_desc" <?= $sort==='amount_desc'?'selected':'' ?>>Shuma ↓</option>
            <option value="amount_asc"  <?= $sort==='amount_asc'?'selected':'' ?>>Shuma ↑</option>
            <option value="id_desc"     <?= $sort==='id_desc'?'selected':'' ?>>ID ↓</option>
            <option value="id_asc"      <?= $sort==='id_asc'?'selected':'' ?>>ID ↑</option>
          </select>
        </div>

        <div class="col-6 col-md-1">
          <select class="form-select" name="per_page">
            <?php foreach([6,12,18,24,36,48,60] as $pp): ?>
              <option value="<?= $pp ?>" <?= $per_page===$pp?'selected':'' ?>><?= $pp ?></option>
            <?php endforeach; ?>
          </select>
        </div>

        <div class="col-12 col-md-2 d-flex gap-2 justify-content-md-end">
          <button class="btn btn-outline-secondary course-btn-ghost d-lg-none" type="button"
                  data-bs-toggle="offcanvas" data-bs-target="#filtersCanvas">
            <i class="fa-solid fa-filter me-1"></i> Filtra
          </button>

          <input type="hidden" name="status"    value="<?= h($statusRaw) ?>">
          <input type="hidden" name="date_from" value="<?= h($date_from) ?>">
          <input type="hidden" name="date_to"   value="<?= h($date_to) ?>">

          <button class="btn btn-primary course-btn-main" type="submit">
            <i class="fa-solid fa-magnifying-glass me-1"></i> Kërko
          </button>
        </div>
      </form>

      <!-- Chips -->
      <div class="mt-2 d-flex align-items-center flex-wrap gap-2">
        <span class="course-chip">
          <i class="fa-regular fa-folder-open"></i>
          Rezultate: <strong><?= (int)$totalFiltered ?></strong>
        </span>

        <?php if ($statusRaw !== 'ALL'): ?>
          <span class="course-chip"><i class="fa-solid fa-signal"></i> <?= h($statusRaw) ?></span>
        <?php endif; ?>
        <?php if ($date_from): ?>
          <span class="course-chip"><i class="fa-regular fa-calendar"></i> nga <?= h($date_from) ?></span>
        <?php endif; ?>
        <?php if ($date_to): ?>
          <span class="course-chip"><i class="fa-regular fa-calendar"></i> deri <?= h($date_to) ?></span>
        <?php endif; ?>
        <?php if ($q): ?>
          <span class="course-chip"><i class="fa-solid fa-magnifying-glass"></i> “<?= h($q) ?>”</span>
        <?php endif; ?>

        <?php
          $hasFilters = ($statusRaw !== 'ALL') || ($date_from !== '') || ($date_to !== '') || ($q !== '');
          if ($hasFilters):
        ?>
          <a class="course-chip text-decoration-none" href="payments_student.php">
            <i class="fa-solid fa-eraser"></i> Pastro filtrat
          </a>
        <?php endif; ?>
      </div>
    </section>

    <!-- Status tabs -->
    <?php
      $tabs = [
        'ALL'       => ['Të gjitha',  (int)$tabStats['total']],
        'COMPLETED' => ['Suksesshme', (int)$tabStats['completed_cnt']],
        'FAILED'    => ['Dështuara',  (int)$tabStats['failed_cnt']],
      ];
    ?>
    <ul class="nav nav-pills course-status-tabs mb-3" role="tablist" aria-label="Status tabs">
      <?php foreach ($tabs as $k=>$meta):
        [$label,$cnt] = $meta;
        $tabUrl = build_url(['status'=>$k, 'page'=>1]);
        $active = ($statusRaw === $k) ? 'active' : '';
      ?>
        <li class="nav-item">
          <a class="nav-link <?= $active ?>" href="<?= h($tabUrl) ?>">
            <?= h($label) ?> <span class="badge text-bg-light ms-1"><?= (int)$cnt ?></span>
          </a>
        </li>
      <?php endforeach; ?>
    </ul>

    <!-- View toggle -->
    <div class="pay-viewbar mb-3">
      <div class="pay-viewtabs">
        <button type="button" class="pay-viewbtn" id="viewGridBtn"><i class="fa-solid fa-table-cells-large me-1"></i> Grid</button>
        <button type="button" class="pay-viewbtn" id="viewListBtn"><i class="fa-solid fa-list me-1"></i> Listë</button>
      </div>

      <div class="pay-mini text-muted">
        7 ditët e fundit: <strong><?= number_format((float)$total7days, 2) ?>€</strong>
      </div>
    </div>

    <!-- Chart -->
    <section class="pay-chart course-toolbar mb-3">
      <div class="d-flex align-items-center justify-content-between flex-wrap gap-2">
        <div>
          <div class="pay-chart-title">Statistikat e pagesave</div>
          <div class="pay-chart-sub">Sipas filtrave aktualë, për 7 ditët e fundit.</div>
        </div>
      </div>
      <div class="pay-chart-canvas">
        <canvas id="paymentsChart" height="280" aria-label="Grafiku i pagesave"></canvas>
      </div>
    </section>

    <!-- GRID VIEW (cards) -->
    <section class="pay-grid" id="payGrid">
      <?php if (!$payments): ?>
        <div class="course-empty">
          <div class="icon"><i class="fa-regular fa-face-smile-beam"></i></div>
          <div class="title">S’u gjet asnjë pagesë me këto filtra.</div>
          <div class="subtitle">Provo të ndryshosh filtrat ose kërkimin.</div>
        </div>
      <?php else: ?>
        <div class="row g-3">
          <?php foreach ($payments as $p):
            $pid    = (int)($p['id'] ?? 0);
            $course = (string)($p['course_title'] ?? '');
            $lesson = (string)($p['lesson_title'] ?? '');
            $status = (string)($p['payment_status'] ?? 'FAILED');
            $amount = (float)($p['amount'] ?? 0);

            $dtTs   = strtotime((string)($p['payment_date'] ?? 'now'));
            $dtView = date('d.m.Y H:i', $dtTs);

            $pillCls = ($status === 'COMPLETED') ? 'pay-status-ok' : 'pay-status-bad';
            $pillTxt = ($status === 'COMPLETED') ? 'Suksesshme' : 'Dështuara';

            // "avatar" student: përdor ikonë statike (student view)
            $avatar = '<i class="fa-solid fa-receipt"></i>';

            $courseUrl = !empty($p['course_id'])
              ? ('course_details_student.php?course_id='.(int)$p['course_id'])
              : '#';
          ?>
            <div class="col-12 col-sm-6 col-xl-4">
              <article class="pay-card h-100">
                <div class="pay-card-top">
                  <div class="pay-avatar" title="#PAY-<?= $pid ?>"><?= $avatar ?></div>

                  <div class="flex-grow-1">
                    <div class="pay-title text-truncate">#PAY-<?= $pid ?></div>
                    <div class="pay-sub text-truncate">
                      <i class="fa-regular fa-clock me-1"></i><?= h($dtView) ?>
                    </div>
                  </div>

                  <span class="pay-status <?= h($pillCls) ?>">
                    <i class="fa-solid fa-signal me-1"></i><?= h($pillTxt) ?>
                  </span>
                </div>

                <div class="pay-card-mid">
                  <div class="pay-line">
                    <div class="pay-k"><i class="fa-solid fa-book-open me-1"></i>Kursi</div>
                    <div class="pay-v text-truncate" title="<?= h($course ?: '—') ?>">
                      <?php if ($courseUrl !== '#'): ?>
                        <a class="pay-link" href="<?= h($courseUrl) ?>"><?= h($course ?: '—') ?></a>
                      <?php else: ?>
                        <?= h($course ?: '—') ?>
                      <?php endif; ?>
                    </div>
                  </div>

                  <div class="pay-line">
                    <div class="pay-k"><i class="fa-regular fa-circle-play me-1"></i>Leksioni</div>
                    <div class="pay-v text-truncate" title="<?= h($lesson ?: '—') ?>"><?= h($lesson ?: '—') ?></div>
                  </div>

                  <div class="pay-line">
                    <div class="pay-k"><i class="fa-solid fa-euro-sign me-1"></i>Shuma</div>
                    <div class="pay-v fw-semibold"><?= number_format($amount, 2) ?>€</div>
                  </div>
                </div>

                <div class="pay-card-id">#PAY-<?= $pid ?></div>
              </article>
            </div>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>
    </section>

    <!-- LIST VIEW -->
    <section class="pay-list d-none" id="payList">
      <?php if ($payments): ?>
        <div class="table-responsive users-tablewrap">
          <table class="table align-middle users-table">
            <thead>
              <tr>
                <th style="width:90px;">ID</th>
                <th>Kursi</th>
                <th>Leksioni</th>
                <th style="width:160px;">Statusi</th>
                <th style="width:170px;">Data</th>
                <th style="width:140px;" class="text-end">Shuma</th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($payments as $p):
                $pid    = (int)($p['id'] ?? 0);
                $course = (string)($p['course_title'] ?? '');
                $lesson = (string)($p['lesson_title'] ?? '');
                $status = (string)($p['payment_status'] ?? 'FAILED');
                $amount = (float)($p['amount'] ?? 0);

                $dtTs   = strtotime((string)($p['payment_date'] ?? 'now'));
                $dtView = date('d.m.Y H:i', $dtTs);

                $pillCls = ($status === 'COMPLETED') ? 'pay-status-ok' : 'pay-status-bad';
                $pillTxt = ($status === 'COMPLETED') ? 'Suksesshme' : 'Dështuara';

                $courseUrl = !empty($p['course_id'])
                  ? ('course_details_student.php?course_id='.(int)$p['course_id'])
                  : '#';
              ?>
                <tr>
                  <td class="text-muted fw-semibold">#<?= $pid ?></td>
                  <td class="fw-semibold">
                    <?php if ($courseUrl !== '#'): ?>
                      <a class="pay-link" href="<?= h($courseUrl) ?>"><?= h($course ?: '—') ?></a>
                    <?php else: ?>
                      <?= h($course ?: '—') ?>
                    <?php endif; ?>
                  </td>
                  <td class="text-muted"><?= h($lesson ?: '—') ?></td>
                  <td><span class="pay-status <?= h($pillCls) ?>"><?= h($pillTxt) ?></span></td>
                  <td class="text-muted fw-semibold"><?= h($dtView) ?></td>
                  <td class="text-end fw-semibold"><?= number_format($amount, 2) ?>€</td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      <?php else: ?>
        <div class="course-empty">
          <div class="icon"><i class="fa-regular fa-file-lines"></i></div>
          <div class="title">Nuk u gjetën pagesa</div>
          <div class="subtitle">Provo të ndryshosh filtrat ose kërkimin.</div>
        </div>
      <?php endif; ?>
    </section>

    <!-- Pagination -->
    <?php
      $pages = $per_page > 0 ? (int)ceil($totalFiltered / $per_page) : 1;
      $pages = max(1, $pages);
      if ($pages > 1):
        $qsPg = $_GET; unset($qsPg['page']);
        $base = 'payments_student.php' . (empty($qsPg) ? '' : ('?' . http_build_query($qsPg)));
        $base .= (str_contains($base,'?') ? '&' : '?');
    ?>
      <nav class="mt-3" aria-label="Faqëzimi">
        <ul class="pagination pagination-sm">
          <li class="page-item <?= $page<=1?'disabled':'' ?>">
            <a class="page-link" href="<?= h($base.'page=1') ?>">&laquo;&laquo;</a>
          </li>
          <li class="page-item <?= $page<=1?'disabled':'' ?>">
            <a class="page-link" href="<?= h($base.'page='.max(1,$page-1)) ?>">&laquo;</a>
          </li>
          <?php
            $start = max(1, $page-2);
            $end   = min($pages, $page+2);
            for ($i=$start; $i<=$end; $i++):
          ?>
            <li class="page-item <?= $i===$page?'active':'' ?>">
              <a class="page-link" href="<?= h($base.'page='.$i) ?>"><?= $i ?></a>
            </li>
          <?php endfor; ?>
          <li class="page-item <?= $page>=$pages?'disabled':'' ?>">
            <a class="page-link" href="<?= h($base.'page='.min($pages,$page+1)) ?>">&raquo;</a>
          </li>
          <li class="page-item <?= $page>=$pages?'disabled':'' ?>">
            <a class="page-link" href="<?= h($base.'page='.$pages) ?>">&raquo;&raquo;</a>
          </li>
        </ul>
      </nav>
    <?php endif; ?>

  </div>
</main>

<?php include __DIR__ . '/footer2.php'; ?>

<!-- Offcanvas Filters (mobile) -->
<div class="offcanvas offcanvas-end" tabindex="-1" id="filtersCanvas" aria-labelledby="filtersCanvasLabel">
  <div class="offcanvas-header">
    <h5 class="offcanvas-title" id="filtersCanvasLabel"><i class="fa-solid fa-filter me-1"></i> Filtra</h5>
    <button type="button" class="btn-close" data-bs-dismiss="offcanvas" aria-label="Mbyll"></button>
  </div>
  <div class="offcanvas-body">
    <form method="get" class="vstack gap-3">
      <input type="hidden" name="q" value="<?= h($q) ?>">
      <input type="hidden" name="sort" value="<?= h($sort) ?>">
      <input type="hidden" name="per_page" value="<?= (int)$per_page ?>">

      <div>
        <label class="form-label">Statusi</label>
        <select class="form-select" name="status">
          <option value="ALL"       <?= $statusRaw==='ALL'?'selected':'' ?>>Të gjitha</option>
          <option value="COMPLETED" <?= $statusRaw==='COMPLETED'?'selected':'' ?>>Suksesshme</option>
          <option value="FAILED"    <?= $statusRaw==='FAILED'?'selected':'' ?>>Dështuara</option>
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
      <a class="btn btn-outline-secondary" href="payments_student.php">
        <i class="fa-solid fa-eraser me-1"></i> Pastro filtrat
      </a>
    </div>
  </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script>
// ==================== View toggle (grid/list) ====================
const grid = document.getElementById('payGrid');
const list = document.getElementById('payList');
const gBtn = document.getElementById('viewGridBtn');
const lBtn = document.getElementById('viewListBtn');

function setView(v){
  localStorage.setItem('payments_student_view', v);
  if (v === 'list') {
    grid?.classList.add('d-none');
    list?.classList.remove('d-none');
    gBtn?.classList.remove('active');
    lBtn?.classList.add('active');
  } else {
    list?.classList.add('d-none');
    grid?.classList.remove('d-none');
    lBtn?.classList.remove('active');
    gBtn?.classList.add('active');
  }
}
gBtn?.addEventListener('click', ()=> setView('grid'));
lBtn?.addEventListener('click', ()=> setView('list'));
setView(localStorage.getItem('payments_student_view') || 'grid');

// ==================== Chart.js (same style as admin: no custom colors) ====================
document.addEventListener('DOMContentLoaded', function () {
  const ctx = document.getElementById('paymentsChart');
  if (!ctx) return;

  const data = {
    labels: <?= json_encode($labels) ?>,
    datasets: [
      { label: 'Suksesshme', data: <?= json_encode($seriesCompleted) ?>, tension: 0.35, fill: false, borderWidth: 2 },
      { label: 'Dështuara',  data: <?= json_encode($seriesFailed) ?>,    tension: 0.35, fill: false, borderDash:[6,4], borderWidth: 2 },
    ]
  };

  new Chart(ctx, {
    type: 'line',
    data,
    options: {
      responsive: true,
      interaction: { mode:'index', intersect:false },
      plugins: { legend: { position:'top' } },
      scales: { y: { beginAtZero: true } }
    }
  });
});
</script>
</body>
</html>
