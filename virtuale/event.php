<?php
declare(strict_types=1);
session_start();
require_once __DIR__ . '/lib/bootstrap.php';

/* ------------------------------ Helpers ------------------------------ */
function h(?string $s): string { return htmlspecialchars($s ?? '', ENT_QUOTES, 'UTF-8'); }

function set_flash(string $msg, string $type='success'): void {
  $_SESSION['flash'] = ['msg'=>$msg,'type'=>$type];
}
function get_flash(): ?array {
  if (!empty($_SESSION['flash'])) { $f=$_SESSION['flash']; unset($_SESSION['flash']); return $f; }
  return null;
}

/** Unifiko CSRF me faqet e tjera (csrf_token) */
function csrf_token(): string {
  if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
  }
  return (string)$_SESSION['csrf_token'];
}

function base_url(): string {
  // Nëse app është në /qta, kthen "/qta"; nëse është në root, kthen ""
  $base = str_replace('\\', '/', rtrim(dirname($_SERVER['SCRIPT_NAME'] ?? ''), '/'));
  return ($base === '/' ? '' : $base);
}

function safe_event_photo(?string $photo): string {
  $base = base_url();

  $raw = trim((string)$photo);
  if ($raw === '') {
    return $base . '/assets/img/event_placeholder.jpg';
  }

  $path = parse_url($raw, PHP_URL_PATH) ?: $raw;
  $file = basename($path);

  if ($file === '') {
    return $base . '/assets/img/event_placeholder.jpg';
  }

  $disk = __DIR__ . '/uploads/events/' . $file;
  if (!is_file($disk)) {
    return $base . '/assets/img/event_placeholder.jpg';
  }

  return $base . '/uploads/events/' . rawurlencode($file);
}

/* ------------------------------ Auth --------------------------------- */
if (!isset($_SESSION['user']) || (($_SESSION['user']['role'] ?? '') !== 'Administrator')) {
  header('Location: login.php'); exit;
}
$user = $_SESSION['user'];

/* --------------------------- Fetch events ---------------------------- */
try {
  $stmt = $pdo->query("
    SELECT e.*, u.full_name AS creator_name
    FROM events e
    LEFT JOIN users u ON u.id = e.id_creator
    ORDER BY e.created_at DESC
  ");
  $events = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
} catch (PDOException $e) {
  http_response_code(500);
  die('Gabim në kërkesë: ' . h($e->getMessage()));
}

/* ----------- Participants (single batched query, no N+1) ------------- */
$participantMap = [];
$ids = array_map(fn($e) => (int)($e['id'] ?? 0), $events);
$ids = array_values(array_filter($ids, fn($x)=>$x>0));

if ($ids) {
  $in = implode(',', array_fill(0, count($ids), '?'));
  $q  = $pdo->prepare("
    SELECT event_id, COUNT(*) AS cnt
    FROM enroll_events
    WHERE event_id IN ($in)
    GROUP BY event_id
  ");
  $q->execute($ids);
  foreach (($q->fetchAll(PDO::FETCH_ASSOC) ?: []) as $r) {
    $participantMap[(int)$r['event_id']] = (int)$r['cnt'];
  }
}

/* ----------------------------- Stats --------------------------------- */
$totalEvents   = count($events);
$activeCount   = 0;
$inactiveCount = 0;
$archivedCount = 0;

foreach ($events as $ev) {
  $st = strtoupper((string)($ev['status'] ?? 'INACTIVE'));
  if ($st === 'ACTIVE')   $activeCount++;
  if ($st === 'INACTIVE') $inactiveCount++;
  if ($st === 'ARCHIVED') $archivedCount++;
}

/* ------------------------- Unique categories ------------------------- */
$uniqueCategories = [];
foreach ($events as $e) {
  $cat = trim((string)($e['category'] ?? ''));
  if ($cat !== '') $uniqueCategories[] = $cat;
}
$uniqueCategories = array_values(array_unique($uniqueCategories));
sort($uniqueCategories, SORT_NATURAL | SORT_FLAG_CASE);

$CSRF = csrf_token();
?>
<!DOCTYPE html>
<html lang="sq" data-bs-theme="light">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Evente — Panel Administrimi</title>
  <link rel="icon" href="image/favicon.ico" type="image/x-icon" />

  <!-- CSS / Ikona -->
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css">
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css">
  <link rel="stylesheet" href="css/courses.css?v=1">
</head>
<body class="course-body">

<?php include __DIR__ . '/navbar_logged_administrator.php'; ?>

<!-- HERO -->
<section class="course-hero">
  <div class="container">
    <div class="course-breadcrumb">
      <i class="fa-solid fa-house me-1"></i>
      <a href="dashboard_admin.php" class="text-decoration-none text-muted">Paneli</a> / Evente
    </div>

    <div class="d-flex flex-column flex-lg-row align-items-start align-items-lg-center justify-content-between gap-3">
      <div>
        <h1>Eventet</h1>
        <p>Menaxho eventet: kërko, filtro, rendit dhe shiko statistikat e pjesëmarrësve.</p>
      </div>

      <div class="d-flex flex-wrap gap-2">
        <div class="course-stat" title="Evente total">
          <div class="icon"><i class="fa-solid fa-layer-group"></i></div>
          <div>
            <div class="label">Evente total</div>
            <div class="value"><?= (int)$totalEvents ?></div>
          </div>
        </div>
        <div class="course-stat" title="Aktive">
          <div class="icon"><i class="fa-solid fa-circle-check"></i></div>
          <div>
            <div class="label">Aktive</div>
            <div class="value"><?= (int)$activeCount ?></div>
          </div>
        </div>
        <div class="course-stat" title="Joaktive">
          <div class="icon"><i class="fa-solid fa-circle-pause"></i></div>
          <div>
            <div class="label">Joaktive</div>
            <div class="value"><?= (int)$inactiveCount ?></div>
          </div>
        </div>
        <div class="course-stat" title="Arkivuara">
          <div class="icon"><i class="fa-solid fa-box-archive"></i></div>
          <div>
            <div class="label">Arkivuara</div>
            <div class="value"><?= (int)$archivedCount ?></div>
          </div>
        </div>
      </div>
    </div>
  </div>
</section>

<main class="course-main">
  <div class="container course-layout">
    <div class="row g-3">

      <!-- LEFT: quick actions + sidebar filters -->
      <div class="col-lg-3">
        <div class="d-flex flex-column gap-2 mb-3">
          <a href="admin/add_event.php" class="course-quick-card" aria-label="Krijo event të ri">
            <div class="icon-wrap"><i class="fa-solid fa-plus"></i></div>
            <div>
              <div class="title">Krijo event</div>
              <div class="subtitle">Shto event të ri në platformë</div>
            </div>
          </a>

          <a href="#" class="course-quick-card" aria-label="Kalendari i eventeve">
            <div class="icon-wrap"><i class="fa-solid fa-calendar-check"></i></div>
            <div>
              <div class="title">Kalendari</div>
              <div class="subtitle">Pamje kalendarike (opsionale)</div>
            </div>
          </a>

          <a href="#" class="course-quick-card" aria-label="Statistika">
            <div class="icon-wrap"><i class="fa-solid fa-chart-line"></i></div>
            <div>
              <div class="title">Statistika</div>
              <div class="subtitle">Raporte & trende (opsionale)</div>
            </div>
          </a>
        </div>

        <aside class="course-sidebar">
          <div class="course-sidebar-title">
            <span><i class="fa-solid fa-sliders me-1"></i> Filtrat</span>
            <button type="button" class="btn-link-reset" id="clearFilters">
              <i class="fa-solid fa-eraser"></i> Pastro
            </button>
          </div>

          <div class="mb-2">
            <label class="form-label">Kategoria</label>
            <select id="categoryFilter" class="form-select">
              <option value="">Të gjitha</option>
              <?php foreach ($uniqueCategories as $cat): ?>
                <option value="<?= h(mb_strtolower($cat, 'UTF-8')) ?>"><?= h($cat) ?></option>
              <?php endforeach; ?>
            </select>
          </div>

          <hr class="my-2">

          <div class="mb-2">
            <label class="form-label">Data (nga)</label>
            <input type="date" id="dateFrom" class="form-control">
          </div>

          <div class="mb-2">
            <label class="form-label">Data (deri)</label>
            <input type="date" id="dateTo" class="form-control">
          </div>

          <div class="mb-2">
            <label class="form-label">Vendndodhja përmban</label>
            <input type="text" id="locationLike" class="form-control" placeholder="p.sh. Tirana, Online…">
          </div>
        </aside>
      </div>

      <!-- RIGHT: toolbar + grid -->
      <div class="col-lg-9">

        <div class="course-toolbar mb-3">
          <form class="row g-2 align-items-end" onsubmit="return false;">
            <div class="col-12 col-md-6">
              <label class="form-label small text-muted mb-1">Kërko</label>
              <div class="input-group">
                <span class="input-group-text bg-white"><i class="fa-solid fa-magnifying-glass"></i></span>
                <input id="searchInput" type="text" class="form-control" placeholder="Titull, vendndodhje, përshkrim…">
              </div>
            </div>

            <div class="col-6 col-md-3">
              <label class="form-label small text-muted mb-1">Rendit</label>
              <select id="sortSelect" class="form-select">
                <option value="created_desc" selected>Më të rejat (krijuar)</option>
                <option value="created_asc">Më të vjetrat (krijuar)</option>
                <option value="event_asc">Data e eventit (↑)</option>
                <option value="event_desc">Data e eventit (↓)</option>
                <option value="title_asc">Titulli A→Z</option>
                <option value="title_desc">Titulli Z→A</option>
                <option value="participants_desc">Pjesëmarrës (↓)</option>
                <option value="participants_asc">Pjesëmarrës (↑)</option>
              </select>
            </div>

            <div class="col-6 col-md-3 d-flex gap-2 justify-content-end flex-wrap">
              <button type="button" class="btn btn-outline-secondary d-lg-none" data-bs-toggle="offcanvas" data-bs-target="#filtersCanvas">
                <i class="fa-solid fa-filter me-1"></i> Filtra
              </button>

              <a href="admin/add_event.php" class="btn btn-primary course-btn-main">
                <i class="fa-solid fa-plus me-1"></i> I ri
              </a>
            </div>
          </form>

          <ul class="nav course-status-tabs mt-2" id="statusTabs">
            <li class="nav-item"><button type="button" class="nav-link active" data-status="">Të gjitha</button></li>
            <li class="nav-item"><button type="button" class="nav-link" data-status="active">Aktive</button></li>
            <li class="nav-item"><button type="button" class="nav-link" data-status="inactive">Joaktive</button></li>
            <li class="nav-item"><button type="button" class="nav-link" data-status="archived">Arkivuara</button></li>
          </ul>

          <div id="chipsRow" class="mt-2 d-flex align-items-center flex-wrap gap-2">
            <span class="course-chip">
              <i class="fa-regular fa-folder-open"></i>
              Gjithsej: <strong><?= (int)$totalEvents ?></strong>
            </span>
          </div>
        </div>

        <?php if ($events): ?>
          <div class="row g-3 course-grid" id="eventsGrid">
            <?php foreach ($events as $event):
              $id = (int)($event['id'] ?? 0);

              $eventCategory = trim((string)($event['category'] ?? '')) ?: 'Event';
              $imgPath = safe_event_photo((string)($event['photo'] ?? ''));

              $whenTs       = !empty($event['event_datetime']) ? strtotime((string)$event['event_datetime']) : 0;
              $whenHuman    = $whenTs ? date('d.m.Y H:i', $whenTs) : '—';

              $createdTs    = !empty($event['created_at']) ? strtotime((string)$event['created_at']) : 0;
              $createdHuman = $createdTs ? date('d.m.Y', $createdTs) : '—';

              $statusRaw   = strtoupper((string)($event['status'] ?? 'INACTIVE'));
              $statusLower = strtolower($statusRaw);

              $statusLabel = match ($statusRaw) {
                'ACTIVE'   => 'Aktive',
                'ARCHIVED' => 'Arkivuar',
                'INACTIVE' => 'Joaktive',
                default    => 'Joaktive',
              };

              $statusClass = match ($statusRaw) {
                'ACTIVE'   => 'course-status-active',
                'ARCHIVED' => 'course-status-archived',
                'INACTIVE' => 'course-status-inactive',
                default    => 'course-status-inactive',
              };

              $title    = (string)($event['title'] ?? '');
              $desc     = (string)($event['description'] ?? '');
              $location = (string)($event['location'] ?? 'Online');

              $participants   = $participantMap[$id] ?? 0;

              $creatorName    = (string)($event['creator_name'] ?? '');
              $creatorInitial = strtoupper(mb_substr($creatorName !== '' ? $creatorName : 'A', 0, 1, 'UTF-8'));

              $searchBlob = mb_strtolower(trim($title.' '.$location.' '.$desc.' '.$eventCategory), 'UTF-8');
            ?>
              <div class="col-12 col-md-6 col-xl-4">
                <article class="course-card course-card-event"
                         data-search="<?= h($searchBlob) ?>"
                         data-category="<?= h(mb_strtolower($eventCategory,'UTF-8')) ?>"
                         data-status="<?= h($statusLower) ?>"
                         data-when="<?= (int)$whenTs ?>"
                         data-created="<?= (int)$createdTs ?>"
                         data-participants="<?= (int)$participants ?>"
                         data-location="<?= h(mb_strtolower($location,'UTF-8')) ?>">
                  <div class="thumb">
                    <?php $fallback = base_url() . '/assets/img/event_placeholder.jpg'; ?>
                    <img src="<?= h($imgPath) ?>"
                         alt="Foto e eventit <?= h($title) ?>"
                         loading="lazy"
                         onerror="this.onerror=null;this.src='<?= h($fallback) ?>';">
                    <span class="cat-badge"><i class="fa-regular fa-folder me-1"></i><?= h($eventCategory) ?></span>
                    <div class="selbox">
                      <span class="course-status-pill <?= h($statusClass) ?>"><?= h($statusLabel) ?></span>
                    </div>
                  </div>

                  <div class="card-body">
                    <h3 class="course-title">
                      <a href="event_details.php?event_id=<?= $id ?>"><?= h($title) ?></a>
                    </h3>

                    <p class="course-desc">
                      <?= h(mb_strimwidth($desc, 0, 150, '…', 'UTF-8')) ?>
                    </p>

                    <div class="course-meta mb-2">
                      <span><i class="fa-regular fa-calendar me-1"></i><?= h($whenHuman) ?></span>
                      <span><i class="fa-solid fa-location-dot me-1"></i><?= h($location) ?></span>
                      <span><i class="fa-solid fa-chair me-1"></i><?= h((string)($event['capacity'] ?? 'Pa limit')) ?> vende</span>
                      <span><i class="fa-solid fa-users me-1"></i><?= (int)$participants ?></span>
                    </div>

                    <div class="d-flex align-items-center justify-content-between mt-2">
                      <div class="d-flex align-items-center gap-2">
                        <span class="course-avatar" title="<?= h($creatorName ?: '—') ?>"><?= h($creatorInitial) ?></span>
                        <div class="small">
                          <div class="fw-semibold"><?= h($creatorName ?: '—') ?></div>
                          <div class="text-muted">Krijuar më <?= h($createdHuman) ?></div>
                        </div>
                      </div>

                      <div class="btn-group btn-group-sm">
                        <a class="btn btn-outline-primary"
                           href="edit_event.php?event_id=<?= $id ?>"
                           title="Modifiko">
                          <i class="fa-regular fa-pen-to-square"></i>
                        </a>

                        <!-- DELETE: modal trigger (jo submit direkt) -->
                        <button type="button"
                                class="btn btn-outline-danger"
                                title="Fshi"
                                data-bs-toggle="modal"
                                data-bs-target="#deleteEventModal"
                                data-event-id="<?= $id ?>"
                                data-event-title="<?= h($title) ?>">
                          <i class="fa-regular fa-trash-can"></i>
                        </button>
                      </div>
                    </div>
                  </div>
                </article>
              </div>
            <?php endforeach; ?>
          </div>
        <?php else: ?>
          <div class="course-empty">
            <div class="icon"><i class="fa-regular fa-calendar-xmark"></i></div>
            <div class="title">Asnjë event i regjistruar</div>
            <div class="subtitle">Krijo eventin e parë dhe do shfaqet këtu.</div>
            <a href="admin/add_event.php" class="btn btn-primary course-btn-main mt-2">
              <i class="fa-solid fa-plus me-1"></i> Krijo event
            </a>
          </div>
        <?php endif; ?>

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
    <div class="vstack gap-3">
      <div>
        <label class="form-label">Kategoria</label>
        <select id="categoryFilterM" class="form-select">
          <option value="">Të gjitha</option>
          <?php foreach ($uniqueCategories as $cat): ?>
            <option value="<?= h(mb_strtolower($cat, 'UTF-8')) ?>"><?= h($cat) ?></option>
          <?php endforeach; ?>
        </select>
      </div>

      <div class="row g-2">
        <div class="col-6">
          <label class="form-label">Nga</label>
          <input type="date" id="dateFromM" class="form-control">
        </div>
        <div class="col-6">
          <label class="form-label">Deri</label>
          <input type="date" id="dateToM" class="form-control">
        </div>
      </div>

      <div>
        <label class="form-label">Vendndodhja</label>
        <input type="text" id="locationLikeM" class="form-control" placeholder="p.sh. Tirana, Online…">
      </div>

      <div class="d-grid gap-2">
        <button class="btn btn-primary course-btn-main" type="button" id="applyMobileFilters">
          <i class="fa-solid fa-check me-1"></i> Zbato
        </button>
        <button class="btn btn-outline-secondary" type="button" id="clearMobileFilters">
          <i class="fa-solid fa-eraser me-1"></i> Pastro
        </button>
      </div>
    </div>
  </div>
</div>

<!-- Modal: Delete -->
<div class="modal fade" id="deleteEventModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog">
    <form class="modal-content" method="POST" action="admin/delete_event.php">
      <div class="modal-header">
        <h5 class="modal-title"><i class="fa-solid fa-triangle-exclamation me-2"></i>Konfirmo fshirjen</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Mbyll"></button>
      </div>
      <div class="modal-body">
        <p>Jeni të sigurt që doni të fshini <strong id="delEventTitle"></strong>?</p>
        <p class="text-danger small mb-0"><i class="fa-solid fa-circle-exclamation me-1"></i>Ky veprim është i pakthyeshëm.</p>
      </div>
      <div class="modal-footer">
        <input type="hidden" name="event_id" id="delEventId">
        <input type="hidden" name="csrf" value="<?= h($CSRF) ?>">
        <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Anulo</button>
        <button type="submit" class="btn btn-danger"><i class="fa-regular fa-trash-can me-1"></i> Fshij</button>
      </div>
    </form>
  </div>
</div>

<!-- Toast zone -->
<div id="toastZone" aria-live="polite" aria-atomic="true"></div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script>
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

/* ---------------- Delete modal populate ---------------- */
const delModal = document.getElementById('deleteEventModal');
delModal?.addEventListener('show.bs.modal', (ev)=>{
  const btn = ev.relatedTarget;
  const id = btn.getAttribute('data-event-id') || '';
  const title = btn.getAttribute('data-event-title') || '';
  delModal.querySelector('#delEventId').value = id;
  delModal.querySelector('#delEventTitle').textContent = title;
});

/* ---------------- Filters + sorting ---------------- */
(function(){
  const qs  = (sel, root=document) => root.querySelector(sel);
  const qsa = (sel, root=document) => Array.from(root.querySelectorAll(sel));

  const searchEl   = qs('#searchInput');
  const catEl      = qs('#categoryFilter');
  const sortEl     = qs('#sortSelect');

  const dateFromEl = qs('#dateFrom');
  const dateToEl   = qs('#dateTo');
  const locEl      = qs('#locationLike');
  const clearBtn   = qs('#clearFilters');
  const chipsRow   = qs('#chipsRow');
  const statusTabs = qsa('#statusTabs .nav-link');

  // mobile controls
  const catM  = qs('#categoryFilterM');
  const dfM   = qs('#dateFromM');
  const dtM   = qs('#dateToM');
  const locM  = qs('#locationLikeM');
  const applyM = qs('#applyMobileFilters');
  const clearM = qs('#clearMobileFilters');

  let currentStatus = '';

  function escapeHtml(s){
    return (s||'').replace(/[&<>"']/g, m => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[m]));
  }

  function getColsAndCards(){
    const grid = qs('#eventsGrid');
    if (!grid) return {grid:null,cards:[],cols:[]};
    const cards = qsa('.course-card-event', grid);
    const cols  = cards.map(c => c.closest('.col-12,.col-md-6,.col-xl-4')).filter(Boolean);
    return {grid,cards,cols};
  }

  function filterCards(){
    const q   = (searchEl?.value || '').toLowerCase().trim();
    const cat = (catEl?.value || '').toLowerCase().trim();
    const st  = currentStatus;

    const df  = dateFromEl?.value ? new Date(dateFromEl.value + 'T00:00:00').getTime()/1000 : null;
    const dt  = dateToEl?.value   ? new Date(dateToEl.value   + 'T23:59:59').getTime()/1000 : null;
    const loc = (locEl?.value || '').toLowerCase().trim();

    const {grid,cards} = getColsAndCards();
    cards.forEach(card => {
      const blob    = (card.dataset.search || '');
      const cat2    = (card.dataset.category || '');
      const status2 = (card.dataset.status || '');
      const when    = parseInt(card.dataset.when || '0', 10);
      const locData = (card.dataset.location || '');

      const okSearch = !q   || blob.includes(q);
      const okCat    = !cat || cat2.includes(cat);
      const okStatus = !st  || status2 === st;
      const okFrom   = !df  || (when && when >= df);
      const okTo     = !dt  || (when && when <= dt);
      const okLoc    = !loc || locData.includes(loc);

      const col = card.closest('.col-12,.col-md-6,.col-xl-4');
      if (!col) return;
      col.style.display = (okSearch && okCat && okStatus && okFrom && okTo && okLoc) ? '' : 'none';
    });

    // dynamic empty
    if (grid) {
      const visible = qsa('.course-card-event', grid)
        .map(c => c.closest('.col-12,.col-md-6,.col-xl-4'))
        .filter(col => col && col.style.display !== 'none').length;

      let emptyBlock = qs('.course-empty.dynamic-empty', grid.parentElement || grid);

      if (visible === 0) {
        if (!emptyBlock) {
          emptyBlock = document.createElement('div');
          emptyBlock.className = 'course-empty dynamic-empty mt-3';
          emptyBlock.innerHTML = `
            <div class="icon"><i class="fa-regular fa-face-frown"></i></div>
            <div class="title">Nuk u gjet asnjë event</div>
            <div class="subtitle">Provoni të ndryshoni filtrat ose statusin.</div>`;
          (grid.parentElement || grid).appendChild(emptyBlock);
        }
      } else if (emptyBlock) emptyBlock.remove();
    }

    syncChips();
  }

  function sortCards(){
    const mode = sortEl?.value || 'created_desc';
    const {grid,cards} = getColsAndCards();
    if (!grid) return;

    const getKey = (el) => {
      switch (mode) {
        case 'created_desc':      return -Number(el.dataset.created || 0);
        case 'created_asc':       return  Number(el.dataset.created || 0);
        case 'event_desc':        return -Number(el.dataset.when || 0);
        case 'event_asc':         return  Number(el.dataset.when || 0);
        case 'title_asc':         return (el.dataset.search || ''); // fillon me titullin
        case 'title_desc':        return '\uffff' + (el.dataset.search || '');
        case 'participants_desc': return -Number(el.dataset.participants || 0);
        case 'participants_asc':  return  Number(el.dataset.participants || 0);
        default:                  return -Number(el.dataset.created || 0);
      }
    };

    const sorted = cards.slice().sort((a,b)=>{
      const ka = getKey(a), kb = getKey(b);
      if (typeof ka === 'string' || typeof kb === 'string') {
        return (''+ka).localeCompare(''+kb,'sq',{sensitivity:'base'});
      }
      return ka - kb;
    });

    const colMap = new Map();
    cards.forEach(card => {
      const col = card.closest('.col-12,.col-md-6,.col-xl-4');
      if (col) colMap.set(card, col);
    });

    sorted.forEach(card => {
      const col = colMap.get(card);
      if (col) grid.appendChild(col);
    });
  }

  function syncChips(){
    if (!chipsRow) return;

    const q   = (searchEl?.value || '').trim();
    const cat = (catEl?.value || '').trim();
    const df  = dateFromEl?.value || '';
    const dt  = dateToEl?.value || '';
    const loc = (locEl?.value || '').trim();

    const statusMap = {'':'Të gjitha','active':'Aktive','inactive':'Joaktive','archived':'Arkivuara'};
    const stLabel = statusMap[currentStatus] || '';

    const {cards} = getColsAndCards();
    const total = cards.length;
    const visible = cards
      .map(c => c.closest('.col-12,.col-md-6,.col-xl-4'))
      .filter(col => col && col.style.display !== 'none').length;

    const chips = [];
    if (currentStatus) chips.push(`<span class="course-chip"><i class="fa-solid fa-signal"></i> ${escapeHtml(stLabel)}</span>`);
    if (q)   chips.push(`<span class="course-chip"><i class="fa-solid fa-magnifying-glass"></i> “${escapeHtml(q)}”</span>`);
    if (cat) chips.push(`<span class="course-chip"><i class="fa-solid fa-tag"></i> ${escapeHtml(cat.toUpperCase())}</span>`);
    if (df)  chips.push(`<span class="course-chip"><i class="fa-regular fa-calendar"></i> nga ${escapeHtml(df)}</span>`);
    if (dt)  chips.push(`<span class="course-chip"><i class="fa-regular fa-calendar"></i> deri ${escapeHtml(dt)}</span>`);
    if (loc) chips.push(`<span class="course-chip"><i class="fa-solid fa-location-dot"></i> ${escapeHtml(loc)}</span>`);

    const hasAny = (q || cat || currentStatus || df || dt || loc);

    chipsRow.innerHTML = `
      <span class="course-chip"><i class="fa-regular fa-folder-open"></i> Rezultate: <strong>${visible}</strong> / ${total}</span>
      ${chips.join('')}
      ${hasAny ? `<button class="course-chip border-0 bg-transparent" id="chipClear"><i class="fa-solid fa-eraser"></i> Pastro</button>` : ''}
    `;

    const chipClear = qs('#chipClear');
    if (chipClear) chipClear.addEventListener('click', (e)=>{ e.preventDefault(); resetFilters(); });
  }

  function resetFilters(){
    if (searchEl) searchEl.value = '';
    if (catEl) catEl.value = '';
    if (dateFromEl) dateFromEl.value = '';
    if (dateToEl) dateToEl.value = '';
    if (locEl) locEl.value = '';
    currentStatus = '';
    statusTabs.forEach(b => b.classList.remove('active'));
    qs('#statusTabs .nav-link[data-status=""]')?.classList.add('active');
    if (sortEl) sortEl.value = 'created_desc';

    // sync mobile
    if (catM) catM.value = '';
    if (dfM) dfM.value = '';
    if (dtM) dtM.value = '';
    if (locM) locM.value = '';

    sortCards(); filterCards();
  }

  function applyMobileToDesktop(){
    if (catEl && catM) catEl.value = catM.value;
    if (dateFromEl && dfM) dateFromEl.value = dfM.value;
    if (dateToEl && dtM) dateToEl.value = dtM.value;
    if (locEl && locM) locEl.value = locM.value;
    sortCards(); filterCards();
  }

  // listeners desktop
  [searchEl, catEl, dateFromEl, dateToEl, locEl].forEach(el=>{
    if (!el) return;
    const evt = (el.tagName === 'INPUT') ? 'input' : 'change';
    el.addEventListener(evt, ()=>{ sortCards(); filterCards(); });
  });
  sortEl?.addEventListener('change', ()=>{ sortCards(); filterCards(); });
  clearBtn?.addEventListener('click', (e)=>{ e.preventDefault(); resetFilters(); });

  statusTabs.forEach(btn=>{
    btn.addEventListener('click', ()=>{
      statusTabs.forEach(b=>b.classList.remove('active'));
      btn.classList.add('active');
      currentStatus = (btn.dataset.status || '').toLowerCase();
      sortCards(); filterCards();
    });
  });

  // mobile listeners
  applyM?.addEventListener('click', ()=>{
    applyMobileToDesktop();
    bootstrap.Offcanvas.getInstance(document.getElementById('filtersCanvas'))?.hide();
  });
  clearM?.addEventListener('click', ()=>{
    resetFilters();
    bootstrap.Offcanvas.getInstance(document.getElementById('filtersCanvas'))?.hide();
  });

  window.addEventListener('DOMContentLoaded', ()=>{ sortCards(); filterCards(); });
})();
</script>
</body>
</html>
