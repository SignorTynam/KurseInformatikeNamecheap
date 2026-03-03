<?php
declare(strict_types=1);
session_start();
require_once __DIR__ . '/lib/database.php';

/* ------------------------------- RBAC ------------------------------- */
if (!isset($_SESSION['user'])) { header('Location: login.php'); exit; }
$ROLE = (string)($_SESSION['user']['role'] ?? '');

// Vetëm Administrator
if ($ROLE !== 'Administrator') {
  header('Location: course.php');
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

/* ------------------------------- Helpers ---------------------------- */
function h(?string $s): string { return htmlspecialchars($s ?? '', ENT_QUOTES, 'UTF-8'); }

function norm_user_status(?string $s): string {
  $v = strtoupper(trim((string)$s));
  return match ($v) {
    'APROVUAR' => 'APPROVED',
    'REFUZUAR' => 'REJECTED',
    'PENDING', 'NE SHQYRTIM', 'NË SHQYRTIM' => 'PENDING',
    default => 'PENDING',
  };
}
function status_label(?string $s): string {
  return match (norm_user_status($s)) {
    'APPROVED' => 'Aprovuar',
    'REJECTED' => 'Refuzuar',
    default    => 'Në pritje',
  };
}
function status_pill_class(?string $s): string {
  return match (norm_user_status($s)) {
    'APPROVED' => 'user-status-approved',
    'REJECTED' => 'user-status-rejected',
    default    => 'user-status-pending',
  };
}

/* ----------------------------- Input-e ------------------------------ */
$search    = trim((string)($_GET['q'] ?? ''));
$role      = trim((string)($_GET['role'] ?? ''));
$status    = strtoupper(trim((string)($_GET['status'] ?? ''))); // "", APPROVED, PENDING, REJECTED
$date_from = trim((string)($_GET['date_from'] ?? ''));
$date_to   = trim((string)($_GET['date_to'] ?? ''));
$domain    = trim((string)($_GET['domain'] ?? ''));
$phone     = trim((string)($_GET['phone'] ?? ''));
$sort      = (string)($_GET['sort'] ?? 'created_desc');

$per_page  = (int)($_GET['per_page'] ?? 12);
$page      = max(1, (int)($_GET['page'] ?? 1));
$per_page  = min(max($per_page, 6), 60);
$offset    = ($page - 1) * $per_page;

// View (client-side edhe ruhet në localStorage, por ruajmë edhe param në URL nëse do)
$view      = (string)($_GET['view'] ?? 'grid'); // grid | list

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
if ($status !== '' && !in_array($status, ['APPROVED','PENDING','REJECTED'], true)) $status = '';
if ($date_from !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $date_from)) $date_from = '';
if ($date_to   !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $date_to))   $date_to   = '';

/* --------------------------- ORDER BY Map --------------------------- */
$ORDER_BY_MAP = [
  'created_desc' => 'u.created_at DESC, u.id DESC',
  'created_asc'  => 'u.created_at ASC, u.id ASC',
  'name_asc'     => 'u.full_name ASC, u.id DESC',
  'name_desc'    => 'u.full_name DESC, u.id DESC',
  'role_asc'     => 'u.role ASC, u.full_name ASC',
  'role_desc'    => 'u.role DESC, u.full_name ASC',
];
$orderBy = $ORDER_BY_MAP[$sort] ?? $ORDER_BY_MAP['created_desc'];

/* ------------------------------ WHERE ------------------------------- */
$where  = [];
$params = [];

if ($search !== '') {
  $where[] = "(u.full_name LIKE :q OR u.email LIKE :q OR u.phone_number LIKE :q)";
  $params[':q'] = "%{$search}%";
}
if ($role !== '') {
  $where[] = "u.role = :role";
  $params[':role'] = $role;
}
if ($domain !== '') {
  $where[] = "u.email LIKE :dom";
  $params[':dom'] = "%{$domain}%";
}
if ($phone !== '') {
  $where[] = "u.phone_number LIKE :ph";
  $params[':ph'] = "%{$phone}%";
}
if ($status !== '') {
  if ($status === 'APPROVED') {
    $where[] = "UPPER(TRIM(COALESCE(u.status,''))) = 'APROVUAR'";
  } elseif ($status === 'REJECTED') {
    $where[] = "UPPER(TRIM(COALESCE(u.status,''))) = 'REFUZUAR'";
  } else { // PENDING
    $where[] = "UPPER(TRIM(COALESCE(u.status,''))) NOT IN ('APROVUAR','REFUZUAR')";
  }
}
if ($date_from !== '') {
  $where[] = "DATE(u.created_at) >= :df";
  $params[':df'] = $date_from;
}
if ($date_to !== '') {
  $where[] = "DATE(u.created_at) <= :dt";
  $params[':dt'] = $date_to;
}

$where_sql = $where ? ('WHERE ' . implode(' AND ', $where)) : '';

/* ----------------------------- Statistika --------------------------- */
try {
  $statStmt = $pdo->query("
    SELECT
      COUNT(*) AS total,
      SUM(CASE WHEN UPPER(TRIM(COALESCE(status,'')))='APROVUAR' THEN 1 ELSE 0 END) AS approved_cnt,
      SUM(CASE WHEN UPPER(TRIM(COALESCE(status,'')))='REFUZUAR' THEN 1 ELSE 0 END) AS rejected_cnt,
      SUM(CASE WHEN UPPER(TRIM(COALESCE(status,''))) NOT IN ('APROVUAR','REFUZUAR') THEN 1 ELSE 0 END) AS pending_cnt
    FROM users
  ");
  $topStats = $statStmt->fetch(PDO::FETCH_ASSOC) ?: [
    'total'=>0,'approved_cnt'=>0,'pending_cnt'=>0,'rejected_cnt'=>0
  ];
} catch (PDOException $e) {
  $topStats = ['total'=>0,'approved_cnt'=>0,'pending_cnt'=>0,'rejected_cnt'=>0];
}

/* -------------------------- Lista roles (filter) -------------------- */
try {
  $roles = $pdo->query("SELECT DISTINCT role FROM users ORDER BY role")->fetchAll(PDO::FETCH_COLUMN) ?: [];
} catch (PDOException $e) {
  $roles = ['Administrator','Instruktor','Student'];
}

/* -------------------------- Count e filtruar ------------------------ */
try {
  $countSql  = "SELECT COUNT(*) FROM users u {$where_sql}";
  $countStmt = $pdo->prepare($countSql);
  foreach ($params as $k=>$v) $countStmt->bindValue($k, $v);
  $countStmt->execute();
  $totalFiltered = (int)$countStmt->fetchColumn();
} catch (PDOException $e) {
  $totalFiltered = 0;
}

/* ------------------------- SELECT kryesor --------------------------- */
try {
  $sql = "
    SELECT u.id, u.full_name, u.email, u.phone_number, u.role, u.status, u.birth_date, u.created_at
    FROM users u
    {$where_sql}
    ORDER BY {$orderBy}
    LIMIT :lim OFFSET :off
  ";
  $stmt = $pdo->prepare($sql);
  foreach ($params as $k=>$v) $stmt->bindValue($k, $v);
  $stmt->bindValue(':lim', $per_page, PDO::PARAM_INT);
  $stmt->bindValue(':off', $offset,   PDO::PARAM_INT);
  $stmt->execute();
  $users = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
} catch (PDOException $e) {
  die("Gabim në kërkesë: " . h($e->getMessage()));
}

/* ---------------------------- URL helpers --------------------------- */
function build_url(array $patch): string {
  $qs = $_GET;
  foreach ($patch as $k=>$v) {
    if ($v === null) unset($qs[$k]);
    else $qs[$k] = $v;
  }
  return 'users.php?' . http_build_query($qs);
}
?>
<!DOCTYPE html>
<html lang="sq">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <title>Përdoruesit — Virtuale</title>

  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
  <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css" rel="stylesheet">
  <!-- Unifikim: përdor të njëjtin CSS si course.php -->
  <link rel="stylesheet" href="css/courses.css?v=1">
  <!-- Shtesë minimale vetëm për users -->
  <link rel="stylesheet" href="css/users.css?v=1">
  <link rel="icon" href="image/favicon.ico" type="image/x-icon" />
</head>

<body class="course-body">

<?php include __DIR__ . '/navbar_logged_administrator.php'; ?>

<!-- HERO -->
<header class="course-hero">
  <div class="container">
    <div class="d-flex flex-column flex-lg-row align-items-start align-items-lg-center justify-content-between gap-3">
      <div>
        <div class="course-breadcrumb">
          <i class="fa-solid fa-house me-1"></i> Paneli / Përdoruesit
        </div>
        <h1>Menaxhimi i përdoruesve</h1>
        <p>Gjithë përdoruesit në një vend: filtro, kërko dhe vepro shpejt.</p>
      </div>
      <div class="d-flex flex-wrap gap-2">
        <div class="course-stat">
          <div class="icon"><i class="fa-solid fa-users"></i></div>
          <div>
            <div class="label">Total</div>
            <div class="value"><?= (int)$topStats['total'] ?></div>
          </div>
        </div>
        <div class="course-stat">
          <div class="icon"><i class="fa-solid fa-circle-check"></i></div>
          <div>
            <div class="label">Aprovuar</div>
            <div class="value"><?= (int)$topStats['approved_cnt'] ?></div>
          </div>
        </div>
        <div class="course-stat">
          <div class="icon"><i class="fa-solid fa-hourglass-half"></i></div>
          <div>
            <div class="label">Në pritje</div>
            <div class="value"><?= (int)$topStats['pending_cnt'] ?></div>
          </div>
        </div>
        <div class="course-stat">
          <div class="icon"><i class="fa-solid fa-ban"></i></div>
          <div>
            <div class="label">Refuzuar</div>
            <div class="value"><?= (int)$topStats['rejected_cnt'] ?></div>
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
        <a class="course-quick-card" href="admin/add_user.php">
          <div class="icon-wrap"><i class="fa-solid fa-user-plus"></i></div>
          <div>
            <div class="title">Shto përdorues</div>
            <div class="subtitle">Krijo një përdorues të ri</div>
          </div>
        </a>
      </div>
      <div class="col-sm-6 col-lg-3">
        <a class="course-quick-card" href="admin/users_export.php">
          <div class="icon-wrap"><i class="fa-solid fa-file-arrow-down"></i></div>
          <div>
            <div class="title">Eksporto CSV</div>
            <div class="subtitle">Eksporto listën aktuale</div>
          </div>
        </a>
      </div>
      <div class="col-sm-6 col-lg-3">
        <a class="course-quick-card" href="mailto:?bcc=info@kurseinformatike.com">
          <div class="icon-wrap"><i class="fa-solid fa-paper-plane"></i></div>
          <div>
            <div class="title">Dërgo email</div>
            <div class="subtitle">Komunikim i shpejtë</div>
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

    <!-- Layout kryesor: sidebar filtrash + content -->
    <div class="row course-layout">

      <!-- SIDEBAR FILTRASH (desktop) -->
      <aside class="col-lg-3 d-none d-lg-block">
        <div class="course-sidebar">
          <div class="course-sidebar-title">
            <span><i class="fa-solid fa-filter me-1"></i> Filtra</span>
            <a href="users.php" class="btn-link-reset">
              <i class="fa-solid fa-eraser me-1"></i> Reseto
            </a>
          </div>

          <form method="get" class="vstack gap-3">
            <!-- Persisto parametrat kryesorë -->
            <input type="hidden" name="q"        value="<?= h($search) ?>">
            <input type="hidden" name="sort"     value="<?= h($sort) ?>">
            <input type="hidden" name="per_page" value="<?= (int)$per_page ?>">
            <input type="hidden" name="view"     value="<?= h($view) ?>">

            <div>
              <label class="form-label">Roli</label>
              <select class="form-select form-select-sm" name="role">
                <option value="">Të gjithë</option>
                <?php foreach ($roles as $r): ?>
                  <option value="<?= h((string)$r) ?>" <?= $role===(string)$r?'selected':'' ?>>
                    <?= h((string)$r) ?>
                  </option>
                <?php endforeach; ?>
              </select>
            </div>

            <div>
              <label class="form-label">Statusi</label>
              <select class="form-select form-select-sm" name="status">
                <option value="">Të gjithë</option>
                <option value="APPROVED" <?= $status==='APPROVED'?'selected':'' ?>>Aprovuar</option>
                <option value="PENDING"  <?= $status==='PENDING'?'selected':''  ?>>Në pritje</option>
                <option value="REJECTED" <?= $status==='REJECTED'?'selected':'' ?>>Refuzuar</option>
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

            <div>
              <label class="form-label">Email (domain)</label>
              <input type="text" class="form-control form-control-sm"
                     name="domain" value="<?= h($domain) ?>" placeholder="@gmail.com">
            </div>

            <div>
              <label class="form-label">Telefon përmban</label>
              <input type="text" class="form-control form-control-sm"
                     name="phone" value="<?= h($phone) ?>" placeholder="+355">
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

        <!-- Toolbar (search + sort + per_page + view + filtra mobile) -->
        <section class="course-toolbar mb-3">
          <form class="row g-2 align-items-center" method="get">
            <div class="col-12 col-md-5">
              <div class="input-group">
                <span class="input-group-text bg-white border-end-0">
                  <i class="fa-solid fa-search"></i>
                </span>
                <input type="text" class="form-control border-start-0"
                       name="q" value="<?= h($search) ?>"
                       placeholder="Kërko sipas emrit, email-it ose telefonit…">
              </div>
            </div>

            <div class="col-6 col-md-2">
              <select class="form-select" name="sort">
                <option value="created_desc" <?= $sort==='created_desc'?'selected':'' ?>>Më të rejat</option>
                <option value="created_asc"  <?= $sort==='created_asc'?'selected':''  ?>>Më të vjetrat</option>
                <option value="name_asc"     <?= $sort==='name_asc'?'selected':''     ?>>Emri A→Z</option>
                <option value="name_desc"    <?= $sort==='name_desc'?'selected':''    ?>>Emri Z→A</option>
                <option value="role_asc"     <?= $sort==='role_asc'?'selected':''     ?>>Roli A→Z</option>
                <option value="role_desc"    <?= $sort==='role_desc'?'selected':''    ?>>Roli Z→A</option>
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
              <button class="btn btn-outline-secondary course-btn-ghost d-lg-none" type="button"
                      data-bs-toggle="offcanvas" data-bs-target="#filtersCanvas">
                <i class="fa-solid fa-filter me-1"></i> Filtra
              </button>

              <input type="hidden" name="role"      value="<?= h($role) ?>">
              <input type="hidden" name="status"    value="<?= h($status) ?>">
              <input type="hidden" name="date_from" value="<?= h($date_from) ?>">
              <input type="hidden" name="date_to"   value="<?= h($date_to) ?>">
              <input type="hidden" name="domain"    value="<?= h($domain) ?>">
              <input type="hidden" name="phone"     value="<?= h($phone) ?>">
              <input type="hidden" name="view"      value="<?= h($view) ?>">

              <button class="btn btn-primary course-btn-main" type="submit">
                <i class="fa-solid fa-magnifying-glass me-1"></i> Kërko
              </button>
            </div>
          </form>

          <!-- Chips të filtrave aktivë -->
          <div class="mt-2 d-flex align-items-center flex-wrap gap-2">
            <span class="course-chip">
              <i class="fa-regular fa-folder-open"></i>
              Rezultate: <strong><?= (int)$totalFiltered ?></strong>
            </span>

            <?php if ($role): ?>
              <span class="course-chip"><i class="fa-solid fa-user-tag"></i> <?= h($role) ?></span>
            <?php endif; ?>
            <?php if ($status): ?>
              <span class="course-chip"><i class="fa-solid fa-signal"></i> <?= h($status) ?></span>
            <?php endif; ?>
            <?php if ($domain): ?>
              <span class="course-chip"><i class="fa-solid fa-at"></i> <?= h($domain) ?></span>
            <?php endif; ?>
            <?php if ($phone): ?>
              <span class="course-chip"><i class="fa-solid fa-phone"></i> <?= h($phone) ?></span>
            <?php endif; ?>
            <?php if ($date_from): ?>
              <span class="course-chip"><i class="fa-regular fa-calendar"></i> nga <?= h($date_from) ?></span>
            <?php endif; ?>
            <?php if ($date_to): ?>
              <span class="course-chip"><i class="fa-regular fa-calendar"></i> deri <?= h($date_to) ?></span>
            <?php endif; ?>
            <?php if ($search): ?>
              <span class="course-chip"><i class="fa-solid fa-magnifying-glass"></i> “<?= h($search) ?>”</span>
            <?php endif; ?>

            <?php if (!empty($_GET) && (count($_GET) > (isset($_GET['page'])?1:0))): ?>
              <a class="course-chip text-decoration-none" href="users.php">
                <i class="fa-solid fa-eraser"></i> Pastro filtrat
              </a>
            <?php endif; ?>
          </div>
        </section>

        <!-- Status tabs (si course.php) -->
        <?php
          $qsBase = $_GET; unset($qsBase['page']);
          $tabs = [
            ''          => ['Të gjitha', (int)$topStats['total']],
            'APPROVED'  => ['Aprovuar',  (int)$topStats['approved_cnt']],
            'PENDING'   => ['Në pritje', (int)$topStats['pending_cnt']],
            'REJECTED'  => ['Refuzuar',  (int)$topStats['rejected_cnt']],
          ];
        ?>
        <ul class="nav nav-pills course-status-tabs mb-3" role="tablist" aria-label="Status tabs">
          <?php foreach ($tabs as $k=>$meta):
            [$label,$cnt] = $meta;
            $qsTab = $qsBase; $qsTab['status'] = $k; $qsTab['page'] = 1;
            $tabUrl = 'users.php?' . http_build_query($qsTab);
            $active = ($status === $k) ? 'active' : '';
          ?>
            <li class="nav-item">
              <a class="nav-link <?= $active ?>" href="<?= h($tabUrl) ?>">
                <?= h($label) ?> <span class="badge text-bg-light ms-1"><?= (int)$cnt ?></span>
              </a>
            </li>
          <?php endforeach; ?>
        </ul>

        <!-- View toggle (grid / list) -->
        <div class="users-viewbar mb-3">
          <div class="users-viewtabs">
            <button type="button" class="users-viewbtn" id="viewGridBtn">
              <i class="fa-solid fa-table-cells-large me-1"></i> Grid
            </button>
            <button type="button" class="users-viewbtn" id="viewListBtn">
              <i class="fa-solid fa-list me-1"></i> Listë
            </button>
          </div>
        </div>

        <!-- GRID VIEW -->
        <section class="users-grid" id="usersGrid">
          <?php if (!$users): ?>
            <div class="course-empty">
              <div class="icon"><i class="fa-regular fa-face-smile-beam"></i></div>
              <div class="title">S’u gjet asnjë përdorues me këto filtra.</div>
              <div class="subtitle">Provo të ndryshosh filtrat ose shto një përdorues të ri.</div>
              <a class="btn btn-primary course-btn-main" href="add_user.php">
                <i class="fa-solid fa-user-plus me-1"></i> Shto përdorues
              </a>
            </div>
          <?php else: ?>
            <div class="row g-3">
              <?php foreach ($users as $u):
                $id    = (int)($u['id'] ?? 0);
                $name  = (string)($u['full_name'] ?? '');
                $email = (string)($u['email'] ?? '');
                $ph    = (string)($u['phone_number'] ?? '');
                $r     = (string)($u['role'] ?? '');
                $st    = status_label((string)($u['status'] ?? ''));
                $stCls = status_pill_class((string)($u['status'] ?? ''));
                $created = !empty($u['created_at']) ? date('d.m.Y H:i', strtotime((string)$u['created_at'])) : '—';
                $dob = !empty($u['birth_date']) ? date('d.m.Y', strtotime((string)$u['birth_date'])) : '—';
                $avatar = strtoupper(mb_substr($name !== '' ? $name : ($email ?: 'U'), 0, 1, 'UTF-8'));
              ?>
                <div class="col-12 col-sm-6 col-lg-4">
                  <article class="user-card h-100">
                    <div class="user-card-top">
                      <div class="user-avatar" title="<?= h($email ?: '—') ?>"><?= h($avatar) ?></div>
                      <div class="flex-grow-1">
                        <div class="user-name"><?= h($name ?: '—') ?></div>
                        <div class="user-sub"><i class="fa-regular fa-at me-1"></i><?= h($email ?: '—') ?></div>
                      </div>
                      <span class="user-status-pill <?= h($stCls) ?>"><?= h($st) ?></span>
                    </div>

                    <div class="user-card-mid">
                      <div class="user-meta">
                        <span><i class="fa-solid fa-user-tag me-1"></i><?= h($r ?: '—') ?></span>
                        <span><i class="fa-solid fa-phone me-1"></i><?= h($ph ?: '—') ?></span>
                        <span><i class="fa-regular fa-calendar me-1"></i><?= h($dob) ?></span>
                      </div>
                      <div class="user-created text-muted">
                        Regjistruar: <strong><?= h($created) ?></strong>
                      </div>
                    </div>

                    <div class="user-card-actions">
                      <div class="btn-group btn-group-sm">
                        <a class="btn btn-outline-secondary" href="admin/edit_user.php?id=<?= $id ?>" title="Modifiko">
                          <i class="fa-regular fa-pen-to-square"></i>
                        </a>
                        <button class="btn btn-outline-danger"
                                title="Fshij"
                                data-bs-toggle="modal"
                                data-bs-target="#deleteUserModal"
                                data-user-id="<?= $id ?>"
                                data-user-name="<?= h($name ?: $email) ?>">
                          <i class="fa-regular fa-trash-can"></i>
                        </button>
                      </div>
                    </div>
                  </article>
                </div>
              <?php endforeach; ?>
            </div>
          <?php endif; ?>
        </section>

        <!-- LIST VIEW -->
        <section class="users-list d-none" id="usersList">
          <?php if ($users): ?>
            <div class="table-responsive users-tablewrap">
              <table class="table align-middle users-table">
                <thead>
                  <tr>
                    <th style="width:70px;">#</th>
                    <th>Përdoruesi</th>
                    <th style="width:160px;">Roli</th>
                    <th style="width:160px;">Statusi</th>
                    <th style="width:180px;">Telefon</th>
                    <th style="width:200px;">Regjistruar</th>
                    <th style="width:120px;" class="text-end">Veprime</th>
                  </tr>
                </thead>
                <tbody>
                  <?php foreach ($users as $u):
                    $id    = (int)($u['id'] ?? 0);
                    $name  = (string)($u['full_name'] ?? '');
                    $email = (string)($u['email'] ?? '');
                    $ph    = (string)($u['phone_number'] ?? '');
                    $r     = (string)($u['role'] ?? '');
                    $st    = status_label((string)($u['status'] ?? ''));
                    $stCls = status_pill_class((string)($u['status'] ?? ''));
                    $created = !empty($u['created_at']) ? date('d.m.Y H:i', strtotime((string)$u['created_at'])) : '—';
                    $avatar = strtoupper(mb_substr($name !== '' ? $name : ($email ?: 'U'), 0, 1, 'UTF-8'));
                  ?>
                    <tr>
                      <td class="text-muted fw-semibold"><?= $id ?></td>
                      <td>
                        <div class="d-flex align-items-center gap-2">
                          <div class="user-avatar user-avatar-sm"><?= h($avatar) ?></div>
                          <div>
                            <div class="fw-semibold"><?= h($name ?: '—') ?></div>
                            <div class="text-muted small"><i class="fa-regular fa-at me-1"></i><?= h($email ?: '—') ?></div>
                          </div>
                        </div>
                      </td>
                      <td class="text-muted fw-semibold"><?= h($r ?: '—') ?></td>
                      <td><span class="user-status-pill <?= h($stCls) ?>"><?= h($st) ?></span></td>
                      <td class="text-muted fw-semibold"><?= h($ph ?: '—') ?></td>
                      <td class="text-muted fw-semibold"><?= h($created) ?></td>
                      <td class="text-end">
                        <div class="btn-group btn-group-sm">
                          <a class="btn btn-outline-secondary" href="admin/edit_user.php?id=<?= $id ?>" title="Modifiko">
                            <i class="fa-regular fa-pen-to-square"></i>
                          </a>
                          <button class="btn btn-outline-danger"
                                  title="Fshij"
                                  data-bs-toggle="modal"
                                  data-bs-target="#deleteUserModal"
                                  data-user-id="<?= $id ?>"
                                  data-user-name="<?= h($name ?: $email) ?>">
                            <i class="fa-regular fa-trash-can"></i>
                          </button>
                        </div>
                      </td>
                    </tr>
                  <?php endforeach; ?>
                </tbody>
              </table>
            </div>
          <?php endif; ?>
        </section>

        <!-- Pagination (si course.php) -->
        <?php
          $pages = $per_page > 0 ? (int)ceil($totalFiltered / $per_page) : 1;
          $pages = max(1, $pages);
          if ($pages > 1):
            $qs = $_GET; unset($qs['page']);
            $base = 'users.php?' . http_build_query($qs);
            $base .= (str_contains($base, '?') ? '&' : '?');
        ?>
          <nav class="mt-3" aria-label="Faqëzimi">
            <ul class="pagination pagination-sm">
              <li class="page-item <?= $page<=1?'disabled':'' ?>">
                <a class="page-link" href="<?= h($base . 'page=1') ?>" aria-label="E para">&laquo;&laquo;</a>
              </li>
              <li class="page-item <?= $page<=1?'disabled':'' ?>">
                <a class="page-link" href="<?= h($base . 'page=' . max(1,$page-1)) ?>" aria-label="Para">&laquo;</a>
              </li>
              <?php
                $start = max(1, $page-2);
                $end   = min($pages, $page+2);
                for ($i=$start; $i<=$end; $i++):
              ?>
                <li class="page-item <?= $i===$page?'active':'' ?>">
                  <a class="page-link" href="<?= h($base . 'page=' . $i) ?>"><?= $i ?></a>
                </li>
              <?php endfor; ?>
              <li class="page-item <?= $page>=$pages?'disabled':'' ?>">
                <a class="page-link" href="<?= h($base . 'page=' . min($pages,$page+1)) ?>" aria-label="Pas">&raquo;</a>
              </li>
              <li class="page-item <?= $page>=$pages?'disabled':'' ?>">
                <a class="page-link" href="<?= h($base . 'page=' . $pages) ?>" aria-label="E fundit">&raquo;&raquo;</a>
              </li>
            </ul>
          </nav>
        <?php endif; ?>

        <br>
      </div><!-- /.col -->
    </div><!-- /.row -->

  </div>
</main>

<!-- Offcanvas Filters (mobile) -->
<div class="offcanvas offcanvas-end" tabindex="-1" id="filtersCanvas" aria-labelledby="filtersCanvasLabel">
  <div class="offcanvas-header">
    <h5 class="offcanvas-title" id="filtersCanvasLabel">
      <i class="fa-solid fa-filter me-1"></i> Filtra të avancuar
    </h5>
    <button type="button" class="btn-close" data-bs-dismiss="offcanvas" aria-label="Mbyll"></button>
  </div>
  <div class="offcanvas-body">
    <form method="get" class="vstack gap-3">
      <input type="hidden" name="q"        value="<?= h($search) ?>">
      <input type="hidden" name="sort"     value="<?= h($sort) ?>">
      <input type="hidden" name="per_page" value="<?= (int)$per_page ?>">
      <input type="hidden" name="view"     value="<?= h($view) ?>">

      <div>
        <label class="form-label">Roli</label>
        <select class="form-select" name="role">
          <option value="">Të gjithë</option>
          <?php foreach ($roles as $r): ?>
            <option value="<?= h((string)$r) ?>" <?= $role===(string)$r?'selected':'' ?>>
              <?= h((string)$r) ?>
            </option>
          <?php endforeach; ?>
        </select>
      </div>

      <div>
        <label class="form-label">Statusi</label>
        <select class="form-select" name="status">
          <option value="">Të gjithë</option>
          <option value="APPROVED" <?= $status==='APPROVED'?'selected':'' ?>>Aprovuar</option>
          <option value="PENDING"  <?= $status==='PENDING'?'selected':''  ?>>Në pritje</option>
          <option value="REJECTED" <?= $status==='REJECTED'?'selected':'' ?>>Refuzuar</option>
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
        <label class="form-label">Email (domain)</label>
        <input type="text" class="form-control" name="domain" value="<?= h($domain) ?>" placeholder="@gmail.com">
      </div>

      <div>
        <label class="form-label">Telefon përmban</label>
        <input type="text" class="form-control" name="phone" value="<?= h($phone) ?>" placeholder="+355">
      </div>

      <div class="d-grid">
        <button class="btn btn-primary course-btn-main" type="submit">
          <i class="fa-solid fa-rotate me-1"></i> Zbato filtrat
        </button>
      </div>
    </form>

    <hr>
    <div class="d-grid">
      <a class="btn btn-outline-secondary" href="users.php">
        <i class="fa-solid fa-eraser me-1"></i> Pastro filtrat
      </a>
    </div>
  </div>
</div>

<!-- Modal: Fshi përdorues -->
<div class="modal fade" id="deleteUserModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog">
    <form class="modal-content" method="POST" action="admin/delete_user.php">
      <div class="modal-header">
        <h5 class="modal-title">
          <i class="fa-solid fa-triangle-exclamation me-2"></i>Konfirmo fshirjen
        </h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Mbyll"></button>
      </div>
      <div class="modal-body">
        <p>Jeni të sigurt që doni të fshini përdoruesin <strong id="userTitlePlaceholder"></strong>?</p>
        <p class="text-danger small mb-0">
          <i class="fa-solid fa-circle-exclamation me-1"></i>Ky veprim është i pakthyeshëm.
        </p>
      </div>
      <div class="modal-footer">
        <input type="hidden" name="id"   id="deleteUserIdInput">
        <input type="hidden" name="csrf" value="<?= h($CSRF) ?>">
        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Anulo</button>
        <button type="submit" class="btn btn-danger">
          <i class="fa-regular fa-trash-can me-1"></i> Fshij
        </button>
      </div>
    </form>
  </div>
</div>

<!-- Toast container (si course.php) -->
<div id="toastZone" aria-live="polite" aria-atomic="true"></div>

<?php include __DIR__ . '/footer2.php'; ?>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script>
// ==================== Toast (i njëjtë me course.php) ====================
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

// ==================== Delete modal ====================
const delModal = document.getElementById('deleteUserModal');
delModal?.addEventListener('show.bs.modal', (ev)=>{
  const btn = ev.relatedTarget;
  const id  = btn.getAttribute('data-user-id');
  const nm  = btn.getAttribute('data-user-name');
  delModal.querySelector('#deleteUserIdInput').value = id;
  delModal.querySelector('#userTitlePlaceholder').textContent = nm;
});

// ==================== View toggle (grid/list) ====================
const grid = document.getElementById('usersGrid');
const list = document.getElementById('usersList');
const gBtn = document.getElementById('viewGridBtn');
const lBtn = document.getElementById('viewListBtn');

function setView(v){
  localStorage.setItem('users_view', v);
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

const remembered = localStorage.getItem('users_view') || 'grid';
setView(remembered);

// ==================== Flash toast ====================
<?php if ($flashMsg !== ''): ?>
  showToast(<?= json_encode($flashType) ?>, <?= json_encode($flashMsg) ?>);
<?php endif; ?>
</script>
</body>
</html>
