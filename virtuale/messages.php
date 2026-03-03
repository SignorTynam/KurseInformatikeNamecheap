<?php
declare(strict_types=1);
session_start();
require_once __DIR__ . '/lib/database.php';

/* ------------------------------- RBAC ------------------------------- */
if (!isset($_SESSION['user'])) { header('Location: login.php'); exit; }
$ROLE = (string)($_SESSION['user']['role'] ?? '');
if ($ROLE !== 'Administrator') { header('Location: course.php'); exit; }

/* ------------------------------- CSRF ------------------------------- */
/* Kompatibilitet: ruajmë edhe $_SESSION['csrf'] (nëse e ke përdorur diku) */
if (empty($_SESSION['csrf_token'])) {
  $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$_SESSION['csrf'] = $_SESSION['csrf_token'];
$CSRF = (string)$_SESSION['csrf_token'];

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

function is_read_sql_expr(): string {
  // read_status mund të jetë 1/0, TRUE/FALSE, string, etj.
  return "(m.read_status IS NOT NULL AND m.read_status <> '' AND m.read_status <> 0)";
}
function is_unread_sql_expr(): string {
  return "(m.read_status IS NULL OR m.read_status = '' OR m.read_status = 0)";
}
function msg_status_label($read_status): string {
  return !empty($read_status) ? 'Lexuar' : 'Pa lexuar';
}
function msg_status_class($read_status): string {
  return !empty($read_status) ? 'msg-status-read' : 'msg-status-unread';
}

/* ----------------------------- Input-e ------------------------------ */
$search    = trim((string)($_GET['q'] ?? ''));
$status    = strtoupper(trim((string)($_GET['status'] ?? ''))); // '', READ, UNREAD
$date_from = trim((string)($_GET['date_from'] ?? ''));
$date_to   = trim((string)($_GET['date_to'] ?? ''));
$domain    = trim((string)($_GET['domain'] ?? ''));
$nameLike  = trim((string)($_GET['name'] ?? ''));
$sort      = (string)($_GET['sort'] ?? 'created_desc');

$per_page  = (int)($_GET['per_page'] ?? 12);
$page      = max(1, (int)($_GET['page'] ?? 1));
$per_page  = min(max($per_page, 6), 60);
$offset    = ($page - 1) * $per_page;

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
if ($status !== '' && !in_array($status, ['READ','UNREAD'], true)) $status = '';
if ($date_from !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $date_from)) $date_from = '';
if ($date_to   !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $date_to))   $date_to   = '';
if (!in_array($view, ['grid','list'], true)) $view = 'grid';

/* --------------------------- ORDER BY Map --------------------------- */
$ORDER_BY_MAP = [
  'created_desc'        => 'm.created_at DESC, m.id DESC',
  'created_asc'         => 'm.created_at ASC,  m.id ASC',
  'subject_asc'         => 'm.subject ASC,     m.created_at DESC',
  'subject_desc'        => 'm.subject DESC,    m.created_at DESC',
  'name_asc'            => 'm.name ASC,        m.created_at DESC',
  'name_desc'           => 'm.name DESC,       m.created_at DESC',
  'status_unread_first' => (is_unread_sql_expr() . " DESC, m.created_at DESC, m.id DESC"),
];
$orderBy = $ORDER_BY_MAP[$sort] ?? $ORDER_BY_MAP['created_desc'];

/* ------------------------------ WHERE ------------------------------- */
$where  = [];
$params = [];

if ($search !== '') {
  $where[] = "(m.subject LIKE :q OR m.message LIKE :q OR m.name LIKE :q OR m.email LIKE :q)";
  $params[':q'] = "%{$search}%";
}
if ($status === 'READ') {
  $where[] = is_read_sql_expr();
} elseif ($status === 'UNREAD') {
  $where[] = is_unread_sql_expr();
}
if ($domain !== '') {
  $where[] = "m.email LIKE :dom";
  $params[':dom'] = "%{$domain}%";
}
if ($nameLike !== '') {
  $where[] = "m.name LIKE :nm";
  $params[':nm'] = "%{$nameLike}%";
}
if ($date_from !== '') {
  $where[] = "DATE(m.created_at) >= :df";
  $params[':df'] = $date_from;
}
if ($date_to !== '') {
  $where[] = "DATE(m.created_at) <= :dt";
  $params[':dt'] = $date_to;
}

$where_sql = $where ? ('WHERE ' . implode(' AND ', $where)) : '';

/* ----------------------------- Statistika --------------------------- */
try {
  $statStmt = $pdo->query("
    SELECT
      COUNT(*) AS total,
      SUM(CASE WHEN " . is_read_sql_expr() . " THEN 1 ELSE 0 END) AS read_cnt,
      SUM(CASE WHEN " . is_unread_sql_expr() . " THEN 1 ELSE 0 END) AS unread_cnt
    FROM messages m
  ");
  $topStats = $statStmt->fetch(PDO::FETCH_ASSOC) ?: ['total'=>0,'read_cnt'=>0,'unread_cnt'=>0];
} catch (PDOException $e) {
  $topStats = ['total'=>0,'read_cnt'=>0,'unread_cnt'=>0];
}

/* -------------------------- Count e filtruar ------------------------ */
try {
  $countSql  = "SELECT COUNT(*) FROM messages m {$where_sql}";
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
    SELECT
      m.id, m.subject, m.name, m.email, m.message, m.read_status, m.created_at
    FROM messages m
    {$where_sql}
    ORDER BY {$orderBy}
    LIMIT :lim OFFSET :off
  ";
  $stmt = $pdo->prepare($sql);
  foreach ($params as $k=>$v) $stmt->bindValue($k, $v);
  $stmt->bindValue(':lim', $per_page, PDO::PARAM_INT);
  $stmt->bindValue(':off', $offset,   PDO::PARAM_INT);
  $stmt->execute();
  $messages = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
} catch (PDOException $e) {
  die("Gabim në kërkesë: " . h($e->getMessage()));
}
?>
<!DOCTYPE html>
<html lang="sq">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <title>Mesazhet — Virtuale</title>

  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
  <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css" rel="stylesheet">
  <!-- Unifikim: bazë e përbashkët -->
  <link rel="stylesheet" href="css/courses.css?v=1">
  <!-- Shtesë vetëm për mesazhet -->
  <link rel="stylesheet" href="css/messages.css?v=1">
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
          <i class="fa-solid fa-house me-1"></i> Paneli / Mesazhet
        </div>
        <h1>Menaxhimi i mesazheve</h1>
        <p>Gjithë mesazhet në një vend: filtro, kërko dhe vepro shpejt.</p>
      </div>
      <div class="d-flex flex-wrap gap-2">
        <div class="course-stat">
          <div class="icon"><i class="fa-solid fa-inbox"></i></div>
          <div>
            <div class="label">Gjithsej</div>
            <div class="value"><?= (int)$topStats['total'] ?></div>
          </div>
        </div>
        <div class="course-stat">
          <div class="icon"><i class="fa-solid fa-circle-check"></i></div>
          <div>
            <div class="label">Lexuara</div>
            <div class="value"><?= (int)$topStats['read_cnt'] ?></div>
          </div>
        </div>
        <div class="course-stat">
          <div class="icon"><i class="fa-solid fa-circle-dot"></i></div>
          <div>
            <div class="label">Pa lexuar</div>
            <div class="value"><?= (int)$topStats['unread_cnt'] ?></div>
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
        <a class="course-quick-card" href="mailto:info@kurseinformatike.com">
          <div class="icon-wrap"><i class="fa-solid fa-paper-plane"></i></div>
          <div>
            <div class="title">Dërgo email</div>
            <div class="subtitle">Hap klientin e email-it</div>
          </div>
        </a>
      </div>
      <div class="col-sm-6 col-lg-3">
        <a class="course-quick-card" href="messages_export.php">
          <div class="icon-wrap"><i class="fa-solid fa-file-arrow-down"></i></div>
          <div>
            <div class="title">Eksporto CSV</div>
            <div class="subtitle">Nëse e ke implementuar</div>
          </div>
        </a>
      </div>
      <div class="col-sm-6 col-lg-3">
        <a class="course-quick-card" href="<?= h('messages.php?status=UNREAD') ?>">
          <div class="icon-wrap"><i class="fa-solid fa-envelope-circle-check"></i></div>
          <div>
            <div class="title">Vetëm pa lexuar</div>
            <div class="subtitle">Hap listën e UNREAD</div>
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

    <!-- Layout: sidebar + content -->
    <div class="row course-layout">

      <!-- SIDEBAR (desktop) -->
      <aside class="col-lg-3 d-none d-lg-block">
        <div class="course-sidebar">
          <div class="course-sidebar-title">
            <span><i class="fa-solid fa-filter me-1"></i> Filtra</span>
            <a href="messages.php" class="btn-link-reset">
              <i class="fa-solid fa-eraser me-1"></i> Reseto
            </a>
          </div>

          <form method="get" class="vstack gap-3">
            <input type="hidden" name="q"        value="<?= h($search) ?>">
            <input type="hidden" name="sort"     value="<?= h($sort) ?>">
            <input type="hidden" name="per_page" value="<?= (int)$per_page ?>">
            <input type="hidden" name="view"     value="<?= h($view) ?>">

            <div>
              <label class="form-label">Statusi</label>
              <select class="form-select form-select-sm" name="status">
                <option value="">Të gjitha</option>
                <option value="UNREAD" <?= $status==='UNREAD'?'selected':'' ?>>Pa lexuar</option>
                <option value="READ"   <?= $status==='READ'?'selected':'' ?>>Lexuar</option>
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
              <label class="form-label">Email (domain)</label>
              <input type="text" class="form-control form-control-sm" name="domain" value="<?= h($domain) ?>" placeholder="@gmail.com">
            </div>

            <div>
              <label class="form-label">Emri përmban</label>
              <input type="text" class="form-control form-control-sm" name="name" value="<?= h($nameLike) ?>" placeholder="p.sh. Ardit">
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
                <span class="input-group-text bg-white border-end-0">
                  <i class="fa-solid fa-search"></i>
                </span>
                <input type="text" class="form-control border-start-0"
                       name="q" value="<?= h($search) ?>"
                       placeholder="Kërko te subjekti, mesazhi, emri ose email…">
              </div>
            </div>

            <div class="col-6 col-md-2">
              <select class="form-select" name="sort">
                <option value="created_desc"        <?= $sort==='created_desc'?'selected':'' ?>>Më të rejat</option>
                <option value="created_asc"         <?= $sort==='created_asc'?'selected':''  ?>>Më të vjetrat</option>
                <option value="subject_asc"         <?= $sort==='subject_asc'?'selected':''  ?>>Subjekti A→Z</option>
                <option value="subject_desc"        <?= $sort==='subject_desc'?'selected':'' ?>>Subjekti Z→A</option>
                <option value="name_asc"            <?= $sort==='name_asc'?'selected':''     ?>>Emri A→Z</option>
                <option value="name_desc"           <?= $sort==='name_desc'?'selected':''    ?>>Emri Z→A</option>
                <option value="status_unread_first" <?= $sort==='status_unread_first'?'selected':'' ?>>Pa lexuar përpara</option>
              </select>
            </div>

            <div class="col-6 col-md-2">
              <select class="form-select" name="per_page">
                <?php foreach([6,12,18,24,36,48,60] as $pp): ?>
                  <option value="<?= $pp ?>" <?= $per_page===$pp?'selected':'' ?>><?= $pp ?>/faqe</option>
                <?php endforeach; ?>
              </select>
            </div>

            <div class="col-12 col-md-3 d-flex gap-2 justify-content-md-end">
              <button class="btn btn-outline-secondary course-btn-ghost d-lg-none" type="button"
                      data-bs-toggle="offcanvas" data-bs-target="#filtersCanvas">
                <i class="fa-solid fa-filter me-1"></i> Filtra
              </button>

              <!-- Persisto filtrat që s’janë në këtë rresht -->
              <input type="hidden" name="status"    value="<?= h($status) ?>">
              <input type="hidden" name="date_from" value="<?= h($date_from) ?>">
              <input type="hidden" name="date_to"   value="<?= h($date_to) ?>">
              <input type="hidden" name="domain"    value="<?= h($domain) ?>">
              <input type="hidden" name="name"      value="<?= h($nameLike) ?>">
              <input type="hidden" name="view"      value="<?= h($view) ?>">

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

            <?php if ($status): ?>
              <span class="course-chip"><i class="fa-solid fa-signal"></i> <?= h($status) ?></span>
            <?php endif; ?>
            <?php if ($domain): ?>
              <span class="course-chip"><i class="fa-solid fa-at"></i> <?= h($domain) ?></span>
            <?php endif; ?>
            <?php if ($nameLike): ?>
              <span class="course-chip"><i class="fa-regular fa-user"></i> <?= h($nameLike) ?></span>
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
              <a class="course-chip text-decoration-none" href="messages.php">
                <i class="fa-solid fa-eraser"></i> Pastro filtrat
              </a>
            <?php endif; ?>
          </div>
        </section>

        <!-- Status tabs -->
        <?php
          $qsBase = $_GET; unset($qsBase['page']);
          $tabs = [
            ''       => ['Të gjitha', (int)$topStats['total']],
            'UNREAD' => ['Pa lexuar', (int)$topStats['unread_cnt']],
            'READ'   => ['Lexuar',    (int)$topStats['read_cnt']],
          ];
        ?>
        <ul class="nav nav-pills course-status-tabs mb-3" role="tablist" aria-label="Status tabs">
          <?php foreach ($tabs as $k=>$meta):
            [$label,$cnt] = $meta;
            $qsTab = $qsBase; $qsTab['status'] = $k; $qsTab['page'] = 1;
            $tabUrl = 'messages.php?' . http_build_query($qsTab);
            $active = ($status === $k) ? 'active' : '';
          ?>
            <li class="nav-item">
              <a class="nav-link <?= $active ?>" href="<?= h($tabUrl) ?>">
                <?= h($label) ?> <span class="badge text-bg-light ms-1"><?= (int)$cnt ?></span>
              </a>
            </li>
          <?php endforeach; ?>
        </ul>

        <!-- View toggle -->
        <div class="messages-viewbar mb-3">
          <div class="messages-viewtabs">
            <button type="button" class="messages-viewbtn" id="viewGridBtn">
              <i class="fa-solid fa-table-cells-large me-1"></i> Grid
            </button>
            <button type="button" class="messages-viewbtn" id="viewListBtn">
              <i class="fa-solid fa-list me-1"></i> Listë
            </button>
          </div>
        </div>

        <!-- GRID -->
        <section class="messages-grid" id="messagesGrid">
          <?php if (!$messages): ?>
            <div class="course-empty">
              <div class="icon"><i class="fa-regular fa-inbox"></i></div>
              <div class="title">S’u gjet asnjë mesazh me këto filtra.</div>
              <div class="subtitle">Provo të ndryshosh filtrat ose kontrollo inbox-in më vonë.</div>
            </div>
          <?php else: ?>
            <div class="row g-3">
              <?php foreach ($messages as $m):
                $id      = (int)($m['id'] ?? 0);
                $subj    = (string)($m['subject'] ?? '');
                $name    = (string)($m['name'] ?? '');
                $email   = (string)($m['email'] ?? '');
                $body    = (string)($m['message'] ?? '');
                $isRead  = !empty($m['read_status']);
                $statusLbl = msg_status_label($m['read_status'] ?? null);
                $statusCls = msg_status_class($m['read_status'] ?? null);

                $created = !empty($m['created_at']) ? date('d.m.Y H:i', strtotime((string)$m['created_at'])) : '—';
                $avatar  = strtoupper(mb_substr($name !== '' ? $name : ($email ?: 'U'), 0, 1, 'UTF-8'));
                $snippet = mb_strimwidth($body, 0, 200, '…', 'UTF-8');
              ?>
                <div class="col-12 col-sm-6 col-lg-4">
                  <article class="message-card h-100 <?= $isRead ? '' : 'unread' ?>">
                    <div class="message-top">
                      <div class="message-avatar" title="<?= h($email ?: '—') ?>"><?= h($avatar) ?></div>
                      <div class="flex-grow-1 min-w-0">
                        <div class="message-subject text-truncate" title="<?= h($subj ?: '— (pa subjekt)') ?>">
                          <i class="fa-solid fa-envelope me-1"></i><?= h($subj ?: '— (pa subjekt)') ?>
                        </div>
                        <div class="message-from text-truncate">
                          <i class="fa-regular fa-user me-1"></i><?= h($name ?: '—') ?>
                          <span class="mx-1 text-muted">•</span>
                          <i class="fa-regular fa-at me-1"></i><?= h($email ?: '—') ?>
                        </div>
                      </div>
                      <span class="msg-status-pill <?= h($statusCls) ?>"><?= h($statusLbl) ?></span>
                    </div>

                    <div class="message-mid">
                      <div class="message-snippet"><?= nl2br(h($snippet)) ?></div>
                      <div class="message-meta">
                        <span><i class="fa-regular fa-clock me-1"></i><?= h($created) ?></span>
                      </div>
                    </div>

                    <div class="message-actions">
                      <div class="btn-group btn-group-sm">
                        <button class="btn btn-outline-primary"
                                data-bs-toggle="offcanvas" data-bs-target="#viewMessage"
                                data-view-subject="<?= h($subj ?: '(pa subjekt)') ?>"
                                data-view-name="<?= h($name ?: '—') ?>"
                                data-view-email="<?= h($email ?: '—') ?>"
                                data-view-created="<?= h($created) ?>"
                                data-view-body="<?= h($body) ?>"
                                title="Shiko">
                          <i class="fa-regular fa-eye"></i>
                        </button>

                        <?php if (!$isRead): ?>
                          <a class="btn btn-outline-success"
                             href="mark_as_read.php?id=<?= $id ?>"
                             title="Shëno si lexuar">
                            <i class="fa-solid fa-check"></i>
                          </a>
                        <?php endif; ?>

                        <a class="btn btn-outline-secondary"
                           href="mailto:<?= h($email ?: 'info@kurseinformatike.com') ?>?subject=<?= rawurlencode('Përgjigje: ' . ($subj ?: '(pa subjekt)')) ?>"
                           title="Përgjigju">
                          <i class="fa-solid fa-reply"></i>
                        </a>

                        <button class="btn btn-outline-danger"
                                data-bs-toggle="modal" data-bs-target="#deleteModal"
                                data-message-id="<?= $id ?>"
                                data-message-subject="<?= h($subj ?: '(pa subjekt)') ?>"
                                title="Fshi">
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

        <!-- LIST -->
        <section class="messages-list d-none" id="messagesList">
          <?php if ($messages): ?>
            <div class="table-responsive messages-tablewrap">
              <table class="table align-middle messages-table">
                <thead>
                  <tr>
                    <th style="width:70px;">#</th>
                    <th>Subjekti</th>
                    <th style="width:260px;">Dërguesi</th>
                    <th style="width:140px;">Statusi</th>
                    <th style="width:190px;">Data</th>
                    <th style="width:160px;" class="text-end">Veprime</th>
                  </tr>
                </thead>
                <tbody>
                  <?php foreach ($messages as $m):
                    $id      = (int)($m['id'] ?? 0);
                    $subj    = (string)($m['subject'] ?? '');
                    $name    = (string)($m['name'] ?? '');
                    $email   = (string)($m['email'] ?? '');
                    $body    = (string)($m['message'] ?? '');
                    $isRead  = !empty($m['read_status']);
                    $statusLbl = msg_status_label($m['read_status'] ?? null);
                    $statusCls = msg_status_class($m['read_status'] ?? null);
                    $created = !empty($m['created_at']) ? date('d.m.Y H:i', strtotime((string)$m['created_at'])) : '—';
                  ?>
                    <tr class="<?= $isRead ? '' : 'messages-row-unread' ?>">
                      <td class="text-muted fw-semibold"><?= $id ?></td>
                      <td>
                        <div class="fw-semibold text-truncate" style="max-width:520px;">
                          <?= h($subj ?: '— (pa subjekt)') ?>
                        </div>
                        <div class="text-muted small text-truncate" style="max-width:520px;">
                          <?= h(mb_strimwidth($body, 0, 120, '…', 'UTF-8')) ?>
                        </div>
                      </td>
                      <td class="text-muted fw-semibold">
                        <?= h($name ?: '—') ?><br>
                        <span class="small text-muted"><?= h($email ?: '—') ?></span>
                      </td>
                      <td><span class="msg-status-pill <?= h($statusCls) ?>"><?= h($statusLbl) ?></span></td>
                      <td class="text-muted fw-semibold"><?= h($created) ?></td>
                      <td class="text-end">
                        <div class="btn-group btn-group-sm">
                          <button class="btn btn-outline-primary"
                                  data-bs-toggle="offcanvas" data-bs-target="#viewMessage"
                                  data-view-subject="<?= h($subj ?: '(pa subjekt)') ?>"
                                  data-view-name="<?= h($name ?: '—') ?>"
                                  data-view-email="<?= h($email ?: '—') ?>"
                                  data-view-created="<?= h($created) ?>"
                                  data-view-body="<?= h($body) ?>"
                                  title="Shiko">
                            <i class="fa-regular fa-eye"></i>
                          </button>

                          <?php if (!$isRead): ?>
                            <a class="btn btn-outline-success" href="mark_as_read.php?id=<?= $id ?>" title="Shëno si lexuar">
                              <i class="fa-solid fa-check"></i>
                            </a>
                          <?php endif; ?>

                          <a class="btn btn-outline-secondary"
                             href="mailto:<?= h($email ?: 'info@kurseinformatike.com') ?>?subject=<?= rawurlencode('Përgjigje: ' . ($subj ?: '(pa subjekt)')) ?>"
                             title="Përgjigju">
                            <i class="fa-solid fa-reply"></i>
                          </a>

                          <button class="btn btn-outline-danger"
                                  data-bs-toggle="modal" data-bs-target="#deleteModal"
                                  data-message-id="<?= $id ?>"
                                  data-message-subject="<?= h($subj ?: '(pa subjekt)') ?>"
                                  title="Fshi">
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

        <!-- Pagination -->
        <?php
          $pages = $per_page > 0 ? (int)ceil($totalFiltered / $per_page) : 1;
          $pages = max(1, $pages);
          if ($pages > 1):
            $qs = $_GET; unset($qs['page']);
            $base = 'messages.php?' . http_build_query($qs);
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
        <label class="form-label">Statusi</label>
        <select class="form-select" name="status">
          <option value="">Të gjitha</option>
          <option value="UNREAD" <?= $status==='UNREAD'?'selected':'' ?>>Pa lexuar</option>
          <option value="READ"   <?= $status==='READ'?'selected':'' ?>>Lexuar</option>
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
        <label class="form-label">Emri përmban</label>
        <input type="text" class="form-control" name="name" value="<?= h($nameLike) ?>" placeholder="p.sh. Ardit">
      </div>

      <div class="d-grid">
        <button class="btn btn-primary course-btn-main" type="submit">
          <i class="fa-solid fa-rotate me-1"></i> Zbato filtrat
        </button>
      </div>
    </form>

    <hr>
    <div class="d-grid">
      <a class="btn btn-outline-secondary" href="messages.php">
        <i class="fa-solid fa-eraser me-1"></i> Pastro filtrat
      </a>
    </div>
  </div>
</div>

<!-- Offcanvas Viewer -->
<div class="offcanvas offcanvas-end" tabindex="-1" id="viewMessage" aria-labelledby="viewMessageLabel">
  <div class="offcanvas-header">
    <div>
      <h5 class="offcanvas-title messages-viewer-title" id="viewMessageLabel">(Subjekti)</h5>
      <div class="small text-muted" id="viewerMeta"></div>
    </div>
    <button type="button" class="btn-close" data-bs-dismiss="offcanvas" aria-label="Mbyll"></button>
  </div>
  <div class="offcanvas-body">
    <div id="viewerBody" class="messages-viewer-body"></div>
  </div>
</div>

<!-- Modal Fshirje (POST + CSRF) -->
<div class="modal fade" id="deleteModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog">
    <form method="POST" action="admin/delete_message.php" class="modal-content">
      <input type="hidden" name="csrf" value="<?= h($CSRF) ?>">
      <input type="hidden" name="id" id="deleteMsgId" value="">
      <div class="modal-header">
        <h5 class="modal-title">
          <i class="fa-solid fa-triangle-exclamation me-2"></i>Konfirmo fshirjen
        </h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Mbyll"></button>
      </div>
      <div class="modal-body">
        <p>Jeni të sigurt që doni të fshini mesazhin: <strong id="deleteMsgSubject">(pa subjekt)</strong>?</p>
        <p class="text-danger small mb-0">
          <i class="fa-solid fa-circle-exclamation me-1"></i>Ky veprim është i pakthyeshëm.
        </p>
      </div>
      <div class="modal-footer">
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
const delModal = document.getElementById('deleteModal');
delModal?.addEventListener('show.bs.modal', (ev)=>{
  const btn = ev.relatedTarget;
  const id  = btn.getAttribute('data-message-id');
  const sbj = btn.getAttribute('data-message-subject') || '(pa subjekt)';
  delModal.querySelector('#deleteMsgId').value = id;
  delModal.querySelector('#deleteMsgSubject').textContent = sbj;
});

// ==================== Viewer offcanvas ====================
function escapeHtml(s){
  return (s||'').replace(/[&<>"']/g, m => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#039;'}[m]));
}
const viewer = document.getElementById('viewMessage');
viewer?.addEventListener('show.bs.offcanvas', (ev)=>{
  const btn = ev.relatedTarget;
  const subject = btn.getAttribute('data-view-subject') || '(pa subjekt)';
  const name    = btn.getAttribute('data-view-name') || '—';
  const email   = btn.getAttribute('data-view-email') || '—';
  const created = btn.getAttribute('data-view-created') || '';
  const body    = btn.getAttribute('data-view-body') || '';

  document.getElementById('viewMessageLabel').textContent = subject;
  document.getElementById('viewerMeta').textContent = `${name} • ${email} • ${created}`;
  document.getElementById('viewerBody').innerHTML = escapeHtml(body).replace(/\n/g,'<br>');
});

// ==================== View toggle (grid/list) ====================
const grid = document.getElementById('messagesGrid');
const list = document.getElementById('messagesList');
const gBtn = document.getElementById('viewGridBtn');
const lBtn = document.getElementById('viewListBtn');

function setView(v){
  localStorage.setItem('messages_view', v);
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

const remembered = localStorage.getItem('messages_view') || <?= json_encode($view) ?> || 'grid';
setView(remembered);

// ==================== Flash toast ====================
<?php if ($flashMsg !== ''): ?>
  showToast(<?= json_encode($flashType) ?>, <?= json_encode($flashMsg) ?>);
<?php endif; ?>
</script>
</body>
</html>
