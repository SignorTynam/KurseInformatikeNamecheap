<?php
// payments.php — Revamp UI (unifikuar me courses/users) + CSRF + CRUD + chart
declare(strict_types=1);
session_start();
require_once __DIR__ . '/lib/database.php';

/* ------------------------------- Helpers ------------------------------- */
function h(?string $s): string { return htmlspecialchars($s ?? '', ENT_QUOTES, 'UTF-8'); }

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

function current_url(string $base = 'payments.php'): string {
  $qs = $_GET;
  return $base . (empty($qs) ? '' : ('?' . http_build_query($qs)));
}
function safe_return_url(string $fallback='payments.php'): string {
  $ret = (string)($_POST['return'] ?? '');
  // prano vetëm relative te payments.php (shmang open redirect)
  if ($ret === '') return $fallback;
  if (!preg_match('/^payments\.php(\?.*)?$/', $ret)) return $fallback;
  return $ret;
}

/* ------------------------------- Auth ---------------------------------- */
if (!isset($_SESSION['user']) || (string)($_SESSION['user']['role'] ?? '') !== 'Administrator') {
  header("Location: login.php"); exit;
}

/* ------------------------------- CSRF ---------------------------------- */
if (empty($_SESSION['csrf_token'])) {
  $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$CSRF = (string)$_SESSION['csrf_token'];

/* ------------------------------ AJAX API --------------------------------
   GET payments.php?ajax=lessons&course_id=123
---------------------------------------------------------------------------- */
if (isset($_GET['ajax']) && (string)$_GET['ajax'] === 'lessons') {
  header('Content-Type: application/json; charset=utf-8');

  $cid = (int)($_GET['course_id'] ?? 0);
  $out = [];

  if ($cid > 0) {
    try {
      $st = $pdo->prepare("SELECT id, title FROM lessons WHERE course_id = ? ORDER BY uploaded_at DESC, id DESC");
      $st->execute([$cid]);
      $out = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (PDOException $e) {
      echo json_encode(['lessons'=>[], 'error'=>true]); exit;
    }
  }

  echo json_encode(['lessons'=>$out]); exit;
}

/* ----------------------------- POST Actions ---------------------------- */
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
  $csrf = (string)($_POST['csrf'] ?? '');
  $back = safe_return_url('payments.php');

  if ($csrf === '' || !hash_equals($CSRF, $csrf)) {
    set_flash('Seancë e pasigurt (CSRF). Rifresko faqen dhe provo sërish.', 'danger');
    header("Location: {$back}"); exit;
  }

  $act = (string)($_POST['action'] ?? '');

  try {
    if ($act === 'create_payment') {
      $user_id   = (int)($_POST['user_id'] ?? 0);
      $course_id = (int)($_POST['course_id'] ?? 0);
      $lesson_id = (string)($_POST['lesson_id'] ?? '') !== '' ? (int)$_POST['lesson_id'] : null;

      $status = strtoupper(trim((string)($_POST['payment_status'] ?? 'FAILED')));
      if (!in_array($status, ['COMPLETED','FAILED'], true)) $status = 'FAILED';

      $amountRaw = str_replace(',', '.', (string)($_POST['amount'] ?? '0'));
      $amount    = (float)$amountRaw;
      if ($amount < 0) $amount = 0.0;

      $dtInput = trim((string)($_POST['payment_date'] ?? ''));
      $ts      = $dtInput !== '' ? strtotime($dtInput) : time();
      $dtSql   = date('Y-m-d H:i:s', $ts);

      if ($user_id <= 0 || $course_id <= 0) {
        throw new RuntimeException('Përdoruesi dhe kursi janë të detyrueshëm.');
      }

      // Sigurohu që kursi ekziston
      $stc = $pdo->prepare("SELECT 1 FROM courses WHERE id=? LIMIT 1");
      $stc->execute([$course_id]);
      if (!$stc->fetchColumn()) {
        throw new RuntimeException('Kursi i zgjedhur nuk ekziston.');
      }

      // Nëse lesson_id jepet, sigurohu që i përket kursit
      if ($lesson_id !== null) {
        $stl = $pdo->prepare("SELECT 1 FROM lessons WHERE id=? AND course_id=? LIMIT 1");
        $stl->execute([$lesson_id, $course_id]);
        if (!$stl->fetchColumn()) {
          throw new RuntimeException('Leksioni i zgjedhur nuk i përket kursit.');
        }
      }

      $sql = "INSERT INTO payments (course_id, lesson_id, user_id, amount, payment_status, payment_date, created_at, updated_at)
              VALUES (?,?,?,?,?,?, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)";
      $st = $pdo->prepare($sql);
      $st->execute([$course_id, $lesson_id, $user_id, $amount, $status, $dtSql]);

      set_flash('Pagesa u shtua me sukses.');
      header("Location: {$back}"); exit;
    }

    if ($act === 'update_payment') {
      $id        = (int)($_POST['payment_id'] ?? 0);
      $user_id   = (int)($_POST['user_id'] ?? 0);
      $course_id = (int)($_POST['course_id'] ?? 0);
      $lesson_id = (string)($_POST['lesson_id'] ?? '') !== '' ? (int)$_POST['lesson_id'] : null;

      $status = strtoupper(trim((string)($_POST['payment_status'] ?? 'FAILED')));
      if (!in_array($status, ['COMPLETED','FAILED'], true)) $status = 'FAILED';

      $amountRaw = str_replace(',', '.', (string)($_POST['amount'] ?? '0'));
      $amount    = (float)$amountRaw;
      if ($amount < 0) $amount = 0.0;

      $dtInput = trim((string)($_POST['payment_date'] ?? ''));
      $ts      = $dtInput !== '' ? strtotime($dtInput) : time();
      $dtSql   = date('Y-m-d H:i:s', $ts);

      if ($id <= 0) throw new RuntimeException('ID pagesës mungon.');
      if ($user_id <= 0 || $course_id <= 0) throw new RuntimeException('Përdoruesi dhe kursi janë të detyrueshëm.');

      // Sigurohu që kursi ekziston
      $stc = $pdo->prepare("SELECT 1 FROM courses WHERE id=? LIMIT 1");
      $stc->execute([$course_id]);
      if (!$stc->fetchColumn()) {
        throw new RuntimeException('Kursi i zgjedhur nuk ekziston.');
      }

      // Nëse lesson_id jepet, sigurohu që i përket kursit
      if ($lesson_id !== null) {
        $stl = $pdo->prepare("SELECT 1 FROM lessons WHERE id=? AND course_id=? LIMIT 1");
        $stl->execute([$lesson_id, $course_id]);
        if (!$stl->fetchColumn()) {
          throw new RuntimeException('Leksioni i zgjedhur nuk i përket kursit.');
        }
      }

      $sql = "UPDATE payments
              SET course_id=?, lesson_id=?, user_id=?, amount=?, payment_status=?, payment_date=?, updated_at=CURRENT_TIMESTAMP
              WHERE id=?";
      $st = $pdo->prepare($sql);
      $st->execute([$course_id, $lesson_id, $user_id, $amount, $status, $dtSql, $id]);

      set_flash('Pagesa u përditësua.');
      header("Location: {$back}"); exit;
    }

    if ($act === 'delete_payment') {
      $id = (int)($_POST['payment_id'] ?? 0);
      if ($id <= 0) throw new RuntimeException('ID pagesës mungon.');

      $st = $pdo->prepare("DELETE FROM payments WHERE id=?");
      $st->execute([$id]);

      set_flash('Pagesa u fshi.');
      header("Location: {$back}"); exit;
    }

    if ($act === 'set_status') {
      $id     = (int)($_POST['payment_id'] ?? 0);
      $status = strtoupper(trim((string)($_POST['new_status'] ?? '')));

      if ($id <= 0 || !in_array($status, ['COMPLETED','FAILED'], true)) {
        throw new RuntimeException('Kërkesë e pavlefshme për status.');
      }

      $st = $pdo->prepare("UPDATE payments SET payment_status=?, updated_at=CURRENT_TIMESTAMP WHERE id=?");
      $st->execute([$status, $id]);

      set_flash('Statusi u përditësua.');
      header("Location: {$back}"); exit;
    }

    // unknown action
    throw new RuntimeException('Veprim i panjohur.');

  } catch (Throwable $e) {
    set_flash('Gabim: ' . $e->getMessage(), 'danger');
    header("Location: {$back}"); exit;
  }
}

/* ----------------------------- GET Filters ---------------------------- */
$q         = trim((string)($_GET['q'] ?? ''));
$statusRaw = strtoupper(trim((string)($_GET['status'] ?? 'ALL'))); // ALL | COMPLETED | FAILED
$date_from = trim((string)($_GET['date_from'] ?? ''));
$date_to   = trim((string)($_GET['date_to'] ?? ''));
$user_id_f = (int)($_GET['user_id'] ?? 0);
$course_id_f = (int)($_GET['course_id'] ?? 0);

$sort     = (string)($_GET['sort'] ?? 'date_desc');
$per_page = (int)($_GET['per_page'] ?? 12);
$page     = max(1, (int)($_GET['page'] ?? 1));
$per_page = min(max($per_page, 6), 60);
$offset   = ($page - 1) * $per_page;

// validime
if (!in_array($statusRaw, ['ALL','COMPLETED','FAILED'], true)) $statusRaw = 'ALL';
if ($date_from !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $date_from)) $date_from = '';
if ($date_to   !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $date_to))   $date_to   = '';

/* --------------------------- ORDER BY Map ---------------------------- */
$ORDER_BY = [
  'date_desc'   => 'p.payment_date DESC, p.id DESC',
  'date_asc'    => 'p.payment_date ASC, p.id ASC',
  'amount_desc' => 'p.amount DESC, p.payment_date DESC',
  'amount_asc'  => 'p.amount ASC, p.payment_date DESC',
  'id_desc'     => 'p.id DESC',
  'id_asc'      => 'p.id ASC',
];
$orderBy = $ORDER_BY[$sort] ?? $ORDER_BY['date_desc'];

/* ------------------------------- Lookups ----------------------------- */
try {
  $users   = $pdo->query("SELECT id, full_name FROM users ORDER BY full_name ASC LIMIT 1000")->fetchAll(PDO::FETCH_ASSOC) ?: [];
  $courses = $pdo->query("SELECT id, title FROM courses ORDER BY title ASC")->fetchAll(PDO::FETCH_ASSOC) ?: [];
} catch (PDOException $e) {
  $users = $courses = [];
}

/* ------------------------------ WHERE (common) ----------------------- */
$where = [];
$params = [];

if ($q !== '') {
  $where[] = "(u.full_name LIKE :q OR u.email LIKE :q OR c.title LIKE :q OR l.title LIKE :q OR CAST(p.id AS CHAR) LIKE :q)";
  $params[':q'] = "%{$q}%";
}
if ($user_id_f > 0) {
  $where[] = "p.user_id = :uid";
  $params[':uid'] = $user_id_f;
}
if ($course_id_f > 0) {
  $where[] = "p.course_id = :cid";
  $params[':cid'] = $course_id_f;
}
if ($date_from !== '') {
  $where[] = "DATE(p.payment_date) >= :df";
  $params[':df'] = $date_from;
}
if ($date_to !== '') {
  $where[] = "DATE(p.payment_date) <= :dt";
  $params[':dt'] = $date_to;
}

$whereCommon = $where ? ('WHERE ' . implode(' AND ', $where)) : '';

/* ------------------------------- Tabs stats (within other filters) --- */
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
    LEFT JOIN users   u ON u.id = p.user_id
    LEFT JOIN courses c ON c.id = p.course_id
    LEFT JOIN lessons l ON l.id = p.lesson_id
    {$whereCommon}
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

/* ------------------------------- WHERE (list) ------------------------ */
$whereList = $where;
if ($statusRaw === 'COMPLETED') {
  $whereList[] = "p.payment_status = 'COMPLETED'";
} elseif ($statusRaw === 'FAILED') {
  $whereList[] = "p.payment_status = 'FAILED'";
} else {
  $whereList[] = "p.payment_status IN ('COMPLETED','FAILED')";
}
$whereListSql = $whereList ? ('WHERE ' . implode(' AND ', $whereList)) : 'WHERE p.payment_status IN (\'COMPLETED\',\'FAILED\')';

/* ------------------------------- Count for pagination ---------------- */
$totalFiltered = 0;
try {
  $sqlCount = "
    SELECT COUNT(*)
    FROM payments p
    LEFT JOIN users   u ON u.id = p.user_id
    LEFT JOIN courses c ON c.id = p.course_id
    LEFT JOIN lessons l ON l.id = p.lesson_id
    {$whereListSql}
  ";
  $stCount = $pdo->prepare($sqlCount);
  foreach ($params as $k=>$v) $stCount->bindValue($k, $v);
  $stCount->execute();
  $totalFiltered = (int)$stCount->fetchColumn();
} catch (PDOException $e) { $totalFiltered = 0; }

/* ------------------------------- Fetch rows (paged) ------------------ */
$payments = [];
try {
  $sql = "
    SELECT
      p.id, p.course_id, p.lesson_id, p.user_id, p.amount, p.payment_status, p.payment_date,
      u.full_name AS student_name, u.email AS student_email,
      c.title AS course_title,
      l.title AS lesson_title
    FROM payments p
    LEFT JOIN users   u ON u.id = p.user_id
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

/* ------------------------------- Chart last 7 days ------------------- */
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
    LEFT JOIN users   u ON u.id = p.user_id
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

/* ------------------------------- URL helper -------------------------- */
function build_url(array $patch): string {
  $qs = $_GET;
  foreach ($patch as $k=>$v) {
    if ($v === null) unset($qs[$k]);
    else $qs[$k] = $v;
  }
  return 'payments.php?' . http_build_query($qs);
}

?>
<!DOCTYPE html>
<html lang="sq">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <title>Pagesat — Virtuale</title>

  <link rel="icon" href="image/favicon.ico" type="image/x-icon" />
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
  <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css" rel="stylesheet">

  <!-- Unifikim UI -->
  <link rel="stylesheet" href="css/courses.css?v=1">
  <link rel="stylesheet" href="css/payments.css?v=1">

  <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js"></script>
</head>

<body class="course-body">

<?php include __DIR__ . '/navbar_logged_administrator.php'; ?>

<!-- HERO -->
<header class="course-hero">
  <div class="container">
    <div class="d-flex flex-column flex-lg-row align-items-start align-items-lg-center justify-content-between gap-3">
      <div>
        <div class="course-breadcrumb">
          <i class="fa-solid fa-house me-1"></i> Paneli / Pagesat
        </div>
        <h1>Menaxhimi i pagesave</h1>
        <p>Filtro, modifiko dhe monitoro transaksionet me një UI të pastër dhe të shpejtë.</p>
      </div>

      <div class="d-flex flex-wrap gap-2">
        <div class="course-stat">
          <div class="icon"><i class="fa-solid fa-receipt"></i></div>
          <div>
            <div class="label">Transaksione</div>
            <div class="value"><?= (int)$tabStats['total'] ?></div>
          </div>
        </div>
        <div class="course-stat">
          <div class="icon"><i class="fa-solid fa-circle-check"></i></div>
          <div>
            <div class="label">Suksesshme</div>
            <div class="value"><?= number_format((float)$tabStats['completed_sum'], 2) ?>€</div>
          </div>
        </div>
        <div class="course-stat">
          <div class="icon"><i class="fa-solid fa-circle-xmark"></i></div>
          <div>
            <div class="label">Dështuara</div>
            <div class="value"><?= number_format((float)$tabStats['failed_sum'], 2) ?>€</div>
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
      <div class="col-sm-6 col-lg-3">
        <button type="button" class="course-quick-card w-100 text-start" data-bs-toggle="modal" data-bs-target="#addPaymentModal">
          <div class="icon-wrap"><i class="fa-solid fa-plus"></i></div>
          <div>
            <div class="title">Shto pagesë</div>
            <div class="subtitle">Regjistro transaksion të ri</div>
          </div>
        </button>
      </div>
      <div class="col-sm-6 col-lg-3">
        <a class="course-quick-card" href="admin/payments_export.php">
          <div class="icon-wrap"><i class="fa-solid fa-file-arrow-down"></i></div>
          <div>
            <div class="title">Eksporto CSV</div>
            <div class="subtitle">Eksporto listën aktuale</div>
          </div>
        </a>
      </div>
      <div class="col-sm-6 col-lg-3">
        <a class="course-quick-card" href="#" aria-disabled="true">
          <div class="icon-wrap"><i class="fa-solid fa-chart-line"></i></div>
          <div>
            <div class="title">Raporte</div>
            <div class="subtitle">Statistika mujore (ops.)</div>
          </div>
        </a>
      </div>
      <div class="col-sm-6 col-lg-3">
        <a class="course-quick-card" href="#" data-bs-toggle="offcanvas" data-bs-target="#filtersCanvas">
          <div class="icon-wrap"><i class="fa-solid fa-filter"></i></div>
          <div>
            <div class="title">Filtra</div>
            <div class="subtitle">Hap filtrat (mobile)</div>
          </div>
        </a>
      </div>
    </section>

    <div class="row course-layout">

      <!-- SIDEBAR FILTRASH (desktop) -->
      <aside class="col-lg-3 d-none d-lg-block">
        <div class="course-sidebar">
          <div class="course-sidebar-title">
            <span><i class="fa-solid fa-filter me-1"></i> Filtra</span>
            <a href="payments.php" class="btn-link-reset">
              <i class="fa-solid fa-eraser me-1"></i> Reseto
            </a>
          </div>

          <form method="get" class="vstack gap-3">
            <input type="hidden" name="q"        value="<?= h($q) ?>">
            <input type="hidden" name="sort"     value="<?= h($sort) ?>">
            <input type="hidden" name="per_page" value="<?= (int)$per_page ?>">

            <div>
              <label class="form-label">Statusi</label>
              <select class="form-select form-select-sm" name="status">
                <option value="ALL"       <?= $statusRaw==='ALL'?'selected':'' ?>>Të gjitha</option>
                <option value="COMPLETED" <?= $statusRaw==='COMPLETED'?'selected':'' ?>>Suksesshme</option>
                <option value="FAILED"    <?= $statusRaw==='FAILED'?'selected':'' ?>>Dështuara</option>
              </select>
            </div>

            <div class="row g-2">
              <div class="col-6">
                <label class="form-label">Nga data</label>
                <input type="date" class="form-control form-control-sm" name="date_from" value="<?= h($date_from) ?>">
              </div>
              <div class="col-6">
                <label class="form-label">Deri më</label>
                <input type="date" class="form-control form-control-sm" name="date_to" value="<?= h($date_to) ?>">
              </div>
            </div>

            <div>
              <label class="form-label">Përdoruesi</label>
              <select class="form-select form-select-sm" name="user_id">
                <option value="0">Të gjithë</option>
                <?php foreach ($users as $u): ?>
                  <option value="<?= (int)$u['id'] ?>" <?= $user_id_f===(int)$u['id']?'selected':'' ?>>
                    <?= h((string)$u['full_name']) ?>
                  </option>
                <?php endforeach; ?>
              </select>
            </div>

            <div>
              <label class="form-label">Kursi</label>
              <select class="form-select form-select-sm" name="course_id">
                <option value="0">Të gjithë</option>
                <?php foreach ($courses as $c): ?>
                  <option value="<?= (int)$c['id'] ?>" <?= $course_id_f===(int)$c['id']?'selected':'' ?>>
                    <?= h((string)$c['title']) ?>
                  </option>
                <?php endforeach; ?>
              </select>
            </div>

            <div class="d-grid mt-1">
              <button class="btn btn-sm btn-primary course-btn-main" type="submit">
                <i class="fa-solid fa-rotate me-1"></i> Zbato filtrat
              </button>
            </div>
          </form>
        </div>
      </aside>

      <!-- MAIN -->
      <div class="col-12 col-lg-9">

        <!-- Toolbar -->
        <section class="course-toolbar mb-3">
          <form class="row g-2 align-items-center" method="get">
            <div class="col-12 col-md-5">
              <div class="input-group">
                <span class="input-group-text bg-white border-end-0"><i class="fa-solid fa-search"></i></span>
                <input type="text" class="form-control border-start-0"
                       name="q" value="<?= h($q) ?>"
                       placeholder="Kërko: emër/email, kurs, leksion, #ID…">
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

            <div class="col-6 col-md-2">
              <select class="form-select" name="per_page">
                <?php foreach([6,12,18,24,36,48,60] as $pp): ?>
                  <option value="<?= $pp ?>" <?= $per_page===$pp?'selected':'' ?>><?= $pp ?>/faqe</option>
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
              <input type="hidden" name="user_id"   value="<?= (int)$user_id_f ?>">
              <input type="hidden" name="course_id" value="<?= (int)$course_id_f ?>">
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
            <?php if ($user_id_f > 0): ?>
              <span class="course-chip"><i class="fa-regular fa-user"></i> user #<?= (int)$user_id_f ?></span>
            <?php endif; ?>
            <?php if ($course_id_f > 0): ?>
              <span class="course-chip"><i class="fa-regular fa-bookmark"></i> course #<?= (int)$course_id_f ?></span>
            <?php endif; ?>
            <?php if ($q): ?>
              <span class="course-chip"><i class="fa-solid fa-magnifying-glass"></i> “<?= h($q) ?>”</span>
            <?php endif; ?>

            <?php if (!empty($_GET) && (count($_GET) > (isset($_GET['page'])?1:0))): ?>
              <a class="course-chip text-decoration-none" href="payments.php">
                <i class="fa-solid fa-eraser"></i> Pastro filtrat
              </a>
            <?php endif; ?>

            <button class="course-chip pay-chip-cta" type="button" data-bs-toggle="modal" data-bs-target="#addPaymentModal">
              <i class="fa-solid fa-plus"></i> Shto pagesë
            </button>
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

        <div class="pay-list-head mb-3">
          <div class="pay-mini text-muted">
            Totali (7 ditët e fundit): <strong><?= number_format((float)$total7days, 2) ?>€</strong>
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

        <section class="pay-list" id="payList">
          <?php if ($payments): ?>
            <div class="table-responsive pay-tablewrap">
              <table class="table align-middle pay-table">
                <thead>
                  <tr>
                    <th style="width:90px;">ID</th>
                    <th>Përdoruesi</th>
                    <th>Kursi</th>
                    <th style="width:160px;">Statusi</th>
                    <th style="width:170px;">Data</th>
                    <th style="width:140px;" class="text-end">Shuma</th>
                    <th style="width:140px;" class="text-end">Veprime</th>
                  </tr>
                </thead>
                <tbody>
                  <?php foreach ($payments as $p):
                    $pid    = (int)($p['id'] ?? 0);
                    $name   = (string)($p['student_name'] ?? '');
                    $email  = (string)($p['student_email'] ?? '');
                    $course = (string)($p['course_title'] ?? '');
                    $lesson = (string)($p['lesson_title'] ?? '');
                    $status = (string)($p['payment_status'] ?? 'FAILED');
                    $amount = (float)($p['amount'] ?? 0);
                    $dtTs   = strtotime((string)($p['payment_date'] ?? 'now'));
                    $dtView = date('d.m.Y H:i', $dtTs);
                    $dtEdit = date('Y-m-d\TH:i', $dtTs);
                    $avatar = strtoupper(mb_substr($name !== '' ? $name : ($email !== '' ? $email : 'U'), 0, 1, 'UTF-8'));

                    $pillCls = ($status === 'COMPLETED') ? 'pay-status-ok' : 'pay-status-bad';
                    $pillTxt = ($status === 'COMPLETED') ? 'Suksesshme' : 'Dështuara';
                  ?>
                    <tr data-payment-row
                        data-pid="<?= $pid ?>"
                        data-user-id="<?= (int)($p['user_id'] ?? 0) ?>"
                        data-course-id="<?= (int)($p['course_id'] ?? 0) ?>"
                        data-lesson-id="<?= (int)($p['lesson_id'] ?? 0) ?>"
                        data-status="<?= h($status) ?>"
                        data-amount="<?= h((string)$amount) ?>"
                        data-payment-date="<?= h($dtEdit) ?>">
                      <td class="text-muted fw-semibold">#<?= $pid ?></td>
                      <td>
                        <div class="d-flex align-items-center gap-2 pay-user-cell">
                          <div class="pay-avatar pay-avatar-sm"><?= h($avatar) ?></div>
                          <div>
                            <div class="fw-semibold"><?= h($name ?: '—') ?></div>
                            <div class="text-muted small"><i class="fa-regular fa-at me-1"></i><?= h($email ?: '—') ?></div>
                          </div>
                        </div>
                      </td>
                      <td>
                        <div class="fw-semibold"><?= h($course ?: '—') ?></div>
                        <div class="text-muted small"><?= h($lesson ?: '—') ?></div>
                      </td>
                      <td><span class="pay-status <?= h($pillCls) ?>"><?= h($pillTxt) ?></span></td>
                      <td class="text-muted fw-semibold"><?= h($dtView) ?></td>
                      <td class="text-end fw-semibold"><?= number_format($amount, 2) ?>€</td>
                      <td class="text-end">
                        <div class="btn-group btn-group-sm">
                          <button class="btn btn-outline-secondary pay-btn-edit"
                                  type="button" title="Modifiko"
                                  data-bs-toggle="modal" data-bs-target="#editPaymentModal">
                            <i class="fa-regular fa-pen-to-square"></i>
                          </button>
                          <button class="btn btn-outline-success pay-btn-status" type="button" title="Suksesshme" data-status="COMPLETED">
                            <i class="fa-solid fa-check"></i>
                          </button>
                          <button class="btn btn-outline-warning pay-btn-status" type="button" title="Dështuara" data-status="FAILED">
                            <i class="fa-solid fa-xmark"></i>
                          </button>
                          <button class="btn btn-outline-danger"
                                  type="button" title="Fshi"
                                  data-bs-toggle="modal" data-bs-target="#deletePaymentModal">
                            <i class="fa-regular fa-trash-can"></i>
                          </button>
                        </div>
                      </td>
                    </tr>
                  <?php endforeach; ?>
                </tbody>
              </table>
            </div>
          <?php else: ?>
            <div class="course-empty">
              <div class="icon"><i class="fa-regular fa-face-smile-beam"></i></div>
              <div class="title">S’u gjet asnjë pagesë me këto filtra.</div>
              <div class="subtitle">Provo të ndryshosh filtrat ose shto një pagesë të re.</div>
              <button class="btn btn-primary course-btn-main" type="button" data-bs-toggle="modal" data-bs-target="#addPaymentModal">
                <i class="fa-solid fa-plus me-1"></i> Shto pagesë
              </button>
            </div>
          <?php endif; ?>
        </section>

        <!-- Pagination -->
        <?php
          $pages = $per_page > 0 ? (int)ceil($totalFiltered / $per_page) : 1;
          $pages = max(1, $pages);
          if ($pages > 1):
            $qsPg = $_GET; unset($qsPg['page']);
            $base = 'payments.php?' . http_build_query($qsPg);
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

        <br>
      </div>
    </div>
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
      <input type="hidden" name="q"        value="<?= h($q) ?>">
      <input type="hidden" name="sort"     value="<?= h($sort) ?>">
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

      <div>
        <label class="form-label">Përdoruesi</label>
        <select class="form-select" name="user_id">
          <option value="0">Të gjithë</option>
          <?php foreach ($users as $u): ?>
            <option value="<?= (int)$u['id'] ?>" <?= $user_id_f===(int)$u['id']?'selected':'' ?>>
              <?= h((string)$u['full_name']) ?>
            </option>
          <?php endforeach; ?>
        </select>
      </div>

      <div>
        <label class="form-label">Kursi</label>
        <select class="form-select" name="course_id">
          <option value="0">Të gjithë</option>
          <?php foreach ($courses as $c): ?>
            <option value="<?= (int)$c['id'] ?>" <?= $course_id_f===(int)$c['id']?'selected':'' ?>>
              <?= h((string)$c['title']) ?>
            </option>
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
      <a class="btn btn-outline-secondary" href="payments.php">
        <i class="fa-solid fa-eraser me-1"></i> Pastro filtrat
      </a>
    </div>
  </div>
</div>

<!-- Modal: Shto pagesë -->
<div class="modal fade" id="addPaymentModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-lg">
    <form class="modal-content" method="POST" autocomplete="off" novalidate>
      <div class="modal-header">
        <h5 class="modal-title"><i class="fa-solid fa-plus me-2"></i>Shto pagesë</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Mbyll"></button>
      </div>
      <div class="modal-body vstack gap-3">
        <input type="hidden" name="csrf" value="<?= h($CSRF) ?>">
        <input type="hidden" name="action" value="create_payment">
        <input type="hidden" name="return" value="<?= h(current_url()) ?>">

        <div class="row g-3">
          <div class="col-md-6">
            <label class="form-label">Përdoruesi</label>
            <select class="form-select" name="user_id" required>
              <option value="">— zgjidh —</option>
              <?php foreach ($users as $u): ?>
                <option value="<?= (int)$u['id'] ?>"><?= h((string)$u['full_name']) ?></option>
              <?php endforeach; ?>
            </select>
          </div>

          <div class="col-md-6">
            <label class="form-label">Kursi</label>
            <select class="form-select" name="course_id" id="add_course_id" required>
              <option value="">— zgjidh —</option>
              <?php foreach ($courses as $c): ?>
                <option value="<?= (int)$c['id'] ?>"><?= h((string)$c['title']) ?></option>
              <?php endforeach; ?>
            </select>
          </div>

          <div class="col-md-6">
            <label class="form-label">Leksioni (opsional)</label>
            <select class="form-select" name="lesson_id" id="add_lesson_id">
              <option value="">— asnjë —</option>
            </select>
          </div>

          <div class="col-md-3">
            <label class="form-label">Statusi</label>
            <select class="form-select" name="payment_status">
              <option value="COMPLETED">COMPLETED</option>
              <option value="FAILED">FAILED</option>
            </select>
          </div>

          <div class="col-md-3">
            <label class="form-label">Shuma (€)</label>
            <input type="number" step="0.01" min="0" class="form-control" name="amount" value="0.00" required>
          </div>

          <div class="col-md-6">
            <label class="form-label">Data & Ora</label>
            <input type="datetime-local" class="form-control" name="payment_date" value="<?= h(date('Y-m-d\TH:i')) ?>">
          </div>
        </div>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Anulo</button>
        <button type="submit" class="btn btn-primary course-btn-main">
          <i class="fa-solid fa-floppy-disk me-1"></i> Ruaj
        </button>
      </div>
    </form>
  </div>
</div>

<!-- Modal: Edit pagesë -->
<div class="modal fade" id="editPaymentModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-lg">
    <form class="modal-content" method="POST" autocomplete="off" novalidate>
      <div class="modal-header">
        <h5 class="modal-title"><i class="fa-regular fa-pen-to-square me-2"></i>Modifiko pagesë</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Mbyll"></button>
      </div>
      <div class="modal-body vstack gap-3">
        <input type="hidden" name="csrf" value="<?= h($CSRF) ?>">
        <input type="hidden" name="action" value="update_payment">
        <input type="hidden" name="payment_id" id="edit_payment_id">
        <input type="hidden" name="return" value="<?= h(current_url()) ?>">

        <div class="row g-3">
          <div class="col-md-6">
            <label class="form-label">Përdoruesi</label>
            <select class="form-select" name="user_id" id="edit_user_id" required>
              <option value="">— zgjidh —</option>
              <?php foreach ($users as $u): ?>
                <option value="<?= (int)$u['id'] ?>"><?= h((string)$u['full_name']) ?></option>
              <?php endforeach; ?>
            </select>
          </div>

          <div class="col-md-6">
            <label class="form-label">Kursi</label>
            <select class="form-select" name="course_id" id="edit_course_id" required>
              <option value="">— zgjidh —</option>
              <?php foreach ($courses as $c): ?>
                <option value="<?= (int)$c['id'] ?>"><?= h((string)$c['title']) ?></option>
              <?php endforeach; ?>
            </select>
          </div>

          <div class="col-md-6">
            <label class="form-label">Leksioni (opsional)</label>
            <select class="form-select" name="lesson_id" id="edit_lesson_id">
              <option value="">— asnjë —</option>
            </select>
          </div>

          <div class="col-md-3">
            <label class="form-label">Statusi</label>
            <select class="form-select" name="payment_status" id="edit_payment_status">
              <option value="COMPLETED">COMPLETED</option>
              <option value="FAILED">FAILED</option>
            </select>
          </div>

          <div class="col-md-3">
            <label class="form-label">Shuma (€)</label>
            <input type="number" step="0.01" min="0" class="form-control" name="amount" id="edit_amount" required>
          </div>

          <div class="col-md-6">
            <label class="form-label">Data & Ora</label>
            <input type="datetime-local" class="form-control" name="payment_date" id="edit_payment_date">
          </div>
        </div>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Anulo</button>
        <button type="submit" class="btn btn-primary course-btn-main">
          <i class="fa-solid fa-floppy-disk me-1"></i> Ruaj ndryshimet
        </button>
      </div>
    </form>
  </div>
</div>

<!-- Modal: Fshi pagesë -->
<div class="modal fade" id="deletePaymentModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog">
    <form class="modal-content" method="POST" autocomplete="off" novalidate>
      <div class="modal-header">
        <h5 class="modal-title"><i class="fa-solid fa-triangle-exclamation me-2"></i>Konfirmo fshirjen</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Mbyll"></button>
      </div>
      <div class="modal-body">
        <p>Jeni të sigurt që doni të fshini pagesën <strong id="delPaymentLabel"></strong>?</p>
        <p class="text-danger small mb-0"><i class="fa-solid fa-circle-exclamation me-1"></i>Ky veprim është i pakthyeshëm.</p>
      </div>
      <div class="modal-footer">
        <input type="hidden" name="csrf" value="<?= h($CSRF) ?>">
        <input type="hidden" name="action" value="delete_payment">
        <input type="hidden" name="payment_id" id="delete_payment_id">
        <input type="hidden" name="return" value="<?= h(current_url()) ?>">
        <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Anulo</button>
        <button type="submit" class="btn btn-danger"><i class="fa-regular fa-trash-can me-1"></i> Fshij</button>
      </div>
    </form>
  </div>
</div>

<!-- Hidden form: quick status -->
<form id="statusForm" method="POST" class="d-none">
  <input type="hidden" name="csrf" value="<?= h($CSRF) ?>">
  <input type="hidden" name="action" value="set_status">
  <input type="hidden" name="payment_id" id="status_payment_id">
  <input type="hidden" name="new_status"  id="status_new_status">
  <input type="hidden" name="return"      value="<?= h(current_url()) ?>">
</form>

<!-- Toast container (si course.php/users.php) -->
<div id="toastZone" aria-live="polite" aria-atomic="true"></div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script>
// ==================== Toast (i njëjtë me users.php) ====================
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

<?php if ($flash = get_flash()): ?>
  showToast(<?= json_encode($flash['type']) ?>, <?= json_encode($flash['msg']) ?>);
<?php endif; ?>

// ==================== Chart.js (pa ngjyra custom) ====================
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

// ==================== Modals + Actions ====================
const $ = (sel, root=document) => root.querySelector(sel);

function rowFromTrigger(el){
  return el?.closest('[data-payment-row]') || null;
}

// Quick status buttons
document.addEventListener('click', (e)=>{
  const btn = e.target.closest('.pay-btn-status');
  if (!btn) return;

  const row = rowFromTrigger(btn);
  const pid = row?.getAttribute('data-pid');
  const st  = btn.getAttribute('data-status');
  if (!pid || !st) return;

  $('#status_payment_id').value = pid;
  $('#status_new_status').value = st;
  $('#statusForm').submit();
});

// Delete modal populate
const delModal = document.getElementById('deletePaymentModal');
delModal?.addEventListener('show.bs.modal', (ev)=>{
  const btn = ev.relatedTarget;
  const row = rowFromTrigger(btn);
  const pid = row?.getAttribute('data-pid') || '';
  $('#delete_payment_id').value = pid;
  $('#delPaymentLabel').textContent = '#PAY-' + pid;
});

// Edit modal populate
const editModal = document.getElementById('editPaymentModal');
editModal?.addEventListener('show.bs.modal', async (ev)=>{
  const btn = ev.relatedTarget;
  const row = rowFromTrigger(btn);
  if (!row) return;

  const pid = row.getAttribute('data-pid') || '';
  const uid = row.getAttribute('data-user-id') || '';
  const cid = row.getAttribute('data-course-id') || '';
  const lid = row.getAttribute('data-lesson-id') || '';
  const st  = row.getAttribute('data-status') || 'FAILED';
  const amt = row.getAttribute('data-amount') || '0';
  const dt  = row.getAttribute('data-payment-date') || '';

  $('#edit_payment_id').value = pid;
  $('#edit_user_id').value = uid;
  $('#edit_course_id').value = cid;
  $('#edit_payment_status').value = st;
  $('#edit_amount').value = amt;
  $('#edit_payment_date').value = dt;

  await loadLessons(cid, 'edit_lesson_id', (lid && lid !== '0') ? lid : '');
});

// Add: load lessons on course change
$('#add_course_id')?.addEventListener('change', (e)=>{
  loadLessons(e.target.value || '', 'add_lesson_id', '');
});
// Edit: load lessons on course change
$('#edit_course_id')?.addEventListener('change', (e)=>{
  loadLessons(e.target.value || '', 'edit_lesson_id', '');
});

async function loadLessons(courseId, selectId, selectedLessonId){
  const sel = document.getElementById(selectId);
  if (!sel) return;

  sel.innerHTML = '<option value="">— duke ngarkuar… —</option>';
  if (!courseId) { sel.innerHTML = '<option value="">— asnjë —</option>'; return; }

  try {
    const resp = await fetch(`payments.php?ajax=lessons&course_id=${encodeURIComponent(courseId)}`);
    const data = await resp.json();
    const lessons = data.lessons || [];

    sel.innerHTML = '<option value="">— asnjë —</option>';
    lessons.forEach(ls=>{
      const opt = document.createElement('option');
      opt.value = ls.id;
      opt.textContent = ls.title;
      sel.appendChild(opt);
    });

    if (selectedLessonId) sel.value = selectedLessonId;
  } catch(err) {
    sel.innerHTML = '<option value="">— asnjë —</option>';
  }
}
</script>

</body>
</html>
