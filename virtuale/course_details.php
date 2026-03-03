<?php
declare(strict_types=1);
session_start();

require_once __DIR__ . '/lib/database.php';
require_once __DIR__ . '/lib/Parsedown.php';

/* ------------------------------- RBAC ------------------------------- */
if (!isset($_SESSION['user']) || !in_array($_SESSION['user']['role'] ?? '', ['Administrator','Instruktor'], true)) {
  header('Location: login.php'); 
  exit;
}
$ROLE  = $_SESSION['user']['role'];
$ME_ID = (int)($_SESSION['user']['id'] ?? 0);

/* ------------------------------- CSRF ------------------------------- */
if (empty($_SESSION['csrf_token'])) {
  $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$CSRF = $_SESSION['csrf_token'];

/* ------------------------------ Input ------------------------------- */
if (!isset($_GET['course_id']) || !is_numeric($_GET['course_id'])) {
  die('Kursi nuk është specifikuar.');
}
$course_id = (int)$_GET['course_id'];
$activeTab = isset($_GET['tab']) ? (string)$_GET['tab'] : '';

// Nëse vjen një tab i pavlefshëm (p.sh. nga bookmark i vjetër), kthehu te Përmbledhja
$validTabs = ['', 'overview', 'materials', 'forum', 'people', 'payments'];
if (!in_array($activeTab, $validTabs, true)) {
  $activeTab = '';
}

// Normalizo "overview" në default (''), për kompatibilitet me linket ekzistuese
if ($activeTab === 'overview') {
  $activeTab = '';
}

function h(?string $s): string {
  return htmlspecialchars($s ?? '', ENT_QUOTES, 'UTF-8');
}

/* ----------------------------- Kursi -------------------------------- */
try {
  /** @var PDO $pdo */
  $stmt = $pdo->prepare("
    SELECT c.*, u.full_name AS creator_name, u.id AS creator_id
    FROM courses c
    LEFT JOIN users u ON c.id_creator = u.id
    WHERE c.id = ?
  ");
  $stmt->execute([$course_id]);
  $course = $stmt->fetch(PDO::FETCH_ASSOC);

  if (!$course) {
    die('Kursi nuk u gjet.');
  }
  if ($ROLE === 'Instruktor' && (int)$course['creator_id'] !== $ME_ID) {
    die('Nuk keni akses në këtë kurs.');
  }
} catch (PDOException $e) {
  die('Gabim: ' . h($e->getMessage()));
}

/* ---------------------------- Parsedown ----------------------------- */
$Parsedown = new Parsedown();
if (method_exists($Parsedown, 'setSafeMode')) {
  $Parsedown->setSafeMode(true);
}

/* --------------------------- Flash mesazhe -------------------------- */
$flash = $_SESSION['flash'] ?? null;
unset($_SESSION['flash']);
$flashMsg  = '';
$flashType = '';
if (is_array($flash)) {
  $flashMsg  = (string)($flash['msg'] ?? '');
  $flashType = (string)($flash['type'] ?? 'info');
  if ($flashType === 'error') { $flashType = 'danger'; }
}

/* ------------------------ Statistika kursi -------------------------- */
$totalStudents = null;
try {
  $stmtCount = $pdo->prepare("SELECT COUNT(*) AS total FROM enroll WHERE course_id = ?");
  $stmtCount->execute([$course_id]);
  $rowCount = $stmtCount->fetch(PDO::FETCH_ASSOC);
  $totalStudents = (int)($rowCount['total'] ?? 0);
} catch (PDOException $e) {
  $totalStudents = null;
}

?>
<!DOCTYPE html>
<html lang="sq" data-bs-theme="light">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title><?= h($course['title']) ?> — Detajet e Kursit | kurseinformatike.com</title>

  <!-- Ikona & Ikonat -->
  <link rel="icon" href="image/favicon.ico" type="image/x-icon" />
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css">
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css">

  <!-- Bootstrap & highlight.js CSS -->
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css">
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/highlight.js/11.7.0/styles/github.min.css">

  <!-- CSS brand i kësaj faqeje -->
  <link rel="stylesheet" href="css/course_panel.css?v=1">
  <link rel="stylesheet" href="css/course_overview.css?v=1">
  <link rel="stylesheet" href="css/km-materials.css?v=1">
  <link rel="stylesheet" href="css/km-course-tabs.css?v=2">

</head>
<body class="course-body">
<?php
  if ($ROLE === 'Administrator') {
    include __DIR__ . '/navbar_logged_administrator.php';
  } else {
    include __DIR__ . '/navbar_logged_instructor.php';
  }
?>

<header class="course-hero">
  <div class="container-fluid px-3 px-lg-4">
    <div class="row g-3 align-items-start">
      <!-- Kolona majtas: breadcrumb + titull + meta -->
      <div class="col-lg-7">
        <div class="course-breadcrumb">
          <a href="course.php">
            <i class="bi bi-arrow-left-short me-1"></i> Të gjitha kurset
          </a>
          <span class="sep">/</span>
          <span class="current"><?= h($course['title']) ?></span>
        </div>
        <h1><?= h($course['title']) ?></h1>
        <p>
          Krijuar nga <strong><?= h($course['creator_name']) ?></strong>
          • <?= date('d.m.Y H:i', strtotime((string)$course['created_at'])) ?>
          • Kategoria:
          <span class="course-tag"><?= h($course['category'] ?? 'TJETRA') ?></span>
        </p>
      </div>

      <!-- Kolona djathtas: actions + stat cards -->
      <div class="col-lg-5">
        <div class="course-hero-actions d-flex flex-wrap justify-content-lg-end gap-2 mb-2">
          <a href="admin/edit_course.php?course_id=<?= (int)$course_id ?>" class="btn btn-sm course-action-primary">
            <i class="bi bi-pencil me-1"></i> Modifiko kursin
          </a>
          <button class="btn btn-sm course-action-outline" type="button" data-bs-toggle="modal" data-bs-target="#addStudentModal">
            <i class="bi bi-person-plus me-1"></i> Shto student
          </button>
        </div>

        <div class="course-hero-stats">
          <div class="course-stat">
            <div class="icon">
              <i class="bi bi-people"></i>
            </div>
            <div>
              <div class="label">Pjesëmarrës</div>
              <div class="value"><?= $totalStudents !== null ? (int)$totalStudents : '—' ?></div>
            </div>
          </div>

          <div class="course-stat">
            <div class="icon">
              <i class="bi bi-activity"></i>
            </div>
            <div>
              <div class="label">Statusi</div>
              <div class="value"><?= h($course['status'] ?? 'ACTIVE') ?></div>
            </div>
          </div>

          <div class="course-stat d-none d-md-flex">
            <div class="icon">
              <i class="bi bi-hash"></i>
            </div>
            <div>
              <div class="label">ID Kursi</div>
              <div class="value">#<?= (int)$course_id ?></div>
            </div>
          </div>
        </div>
      </div>
    </div>
  </div>
</header>

<main class="course-main">
  <div class="container-fluid px-3 px-lg-4">
    <!-- Tabs + info anësore -->
    <div class="course-nav-wrapper">
      <ul class="nav course-tabs" role="tablist">
        <li class="nav-item">
          <a class="nav-link <?= $activeTab===''?'active':'' ?>" data-bs-toggle="tab" data-bs-target="#overview" href="course_details.php?course_id=<?= (int)$course_id ?>" role="tab">
            <i class="bi bi-layout-text-sidebar"></i>
            <span>Përmbledhje</span>
          </a>
        </li>
        <li class="nav-item">
          <a class="nav-link <?= $activeTab==='materials'?'active':'' ?>" data-bs-toggle="tab" data-bs-target="#materials" href="course_details.php?course_id=<?= (int)$course_id ?>&tab=materials" role="tab">
            <i class="bi bi-layers"></i>
            <span>Materialet</span>
          </a>
        </li>
        <li class="nav-item">
          <a class="nav-link <?= $activeTab==='forum'?'active':'' ?>" data-bs-toggle="tab" data-bs-target="#forum" href="course_details.php?course_id=<?= (int)$course_id ?>&tab=forum" role="tab">
            <i class="bi bi-chat-dots"></i>
            <span>Forumi</span>
          </a>
        </li>
        <li class="nav-item">
          <a class="nav-link <?= $activeTab==='people'?'active':'' ?>" data-bs-toggle="tab" data-bs-target="#people" href="course_details.php?course_id=<?= (int)$course_id ?>&tab=people" role="tab">
            <i class="bi bi-people"></i>
            <span>Pjesëmarrës</span>
          </a>
        </li>
        <li class="nav-item">
          <a class="nav-link <?= $activeTab==='payments'?'active':'' ?>" data-bs-toggle="tab" data-bs-target="#payments" href="course_details.php?course_id=<?= (int)$course_id ?>&tab=payments" role="tab">
            <i class="bi bi-currency-dollar"></i>
            <span>Pagesat</span>
          </a>
        </li>
      </ul>

      <div class="course-nav-extra d-none d-md-block">
        ID i kursit: <strong>#<?= (int)$course_id ?></strong>
      </div>
    </div>

    <!-- Karta e brendshme me përmbajtjen e tab-eve -->
    <div class="course-shell">
      <div class="tab-content">
        <div class="tab-pane fade <?= $activeTab===''?'show active':'' ?>" id="overview" role="tabpanel">
          <?php include __DIR__ . '/tabs/course_overview.php'; ?>
        </div>

        <div class="tab-pane fade <?= $activeTab==='materials'?'show active':'' ?>" id="materials" role="tabpanel">
          <?php include __DIR__ . '/tabs/course_materials.php'; ?>
        </div>

        <div class="tab-pane fade <?= $activeTab==='labs'?'show active':'' ?>" id="labs" role="tabpanel">
          <?php include __DIR__ . '/tabs/course_lab.php'; ?>
        </div>

        <div class="tab-pane fade <?= $activeTab==='forum'?'show active':'' ?>" id="forum" role="tabpanel">
          <?php include __DIR__ . '/tabs/course_forum.php'; ?>
        </div>

        <div class="tab-pane fade <?= $activeTab==='people'?'show active':'' ?>" id="people" role="tabpanel">
          <?php include __DIR__ . '/tabs/course_people.php'; ?>
        </div>

        <div class="tab-pane fade <?= $activeTab==='payments'?'show active':'' ?>" id="payments" role="tabpanel">
          <?php include __DIR__ . '/tabs/course_payments.php'; ?>
        </div>
      </div>
    </div>
  </div>
</main>

<!-- Toast container -->
<div id="toastZone" aria-live="polite" aria-atomic="true"></div>

<!-- Modal: Shto student -->
<div class="modal fade" id="addStudentModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog">
    <form class="modal-content" method="POST" action="enroll_student.php">
      <div class="modal-header">
        <h5 class="modal-title">Shto student në kurs</h5>
        <button class="btn-close" data-bs-dismiss="modal" type="button"></button>
      </div>
      <div class="modal-body">
        <input type="hidden" name="csrf" value="<?= h($CSRF) ?>">
        <input type="hidden" name="course_id" value="<?= (int)$course_id ?>">
        <div class="mb-3">
          <label class="form-label">Zgjidh studentin</label>
          <select class="form-select" name="student_id" required>
            <option value="">—</option>
            <?php
            try {
              $stmtStudents = $pdo->prepare("
                SELECT u.id, u.full_name
                FROM users u
                LEFT JOIN enroll e ON e.user_id = u.id AND e.course_id = ?
                WHERE u.role = 'Student' AND e.user_id IS NULL
                ORDER BY u.full_name
              ");
              $stmtStudents->execute([$course_id]);
              foreach ($stmtStudents as $st) {
                echo '<option value="'.(int)$st['id'].'">'.h($st['full_name']).'</option>';
              }
            } catch (PDOException $e) {
              // Mund të log-osh gabimin
            }
            ?>
          </select>
        </div>
      </div>
      <div class="modal-footer">
        <button class="btn btn-secondary" type="button" data-bs-dismiss="modal">Anulo</button>
        <button class="btn btn-moodle" type="submit">Shto</button>
      </div>
    </form>
  </div>
</div>

<!-- Modal: Temë e re në forum -->
<div class="modal fade" id="newThreadModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog">
    <form class="modal-content" method="POST" action="threads/thread_create.php" enctype="multipart/form-data">
      <div class="modal-header">
        <h5 class="modal-title">Temë e re në forum</h5>
        <button class="btn-close" data-bs-dismiss="modal" type="button"></button>
      </div>
      <div class="modal-body">
        <input type="hidden" name="csrf" value="<?= h($CSRF) ?>">
        <input type="hidden" name="course_id" value="<?= (int)$course_id ?>">
        <div class="mb-3">
          <label class="form-label">Titulli</label>
          <input class="form-control" name="title" required maxlength="255">
        </div>
        <div class="mb-3">
          <label class="form-label">Përshkrimi</label>
          <textarea class="form-control" name="content" rows="4" required placeholder="Përshkrim i detajuar i temës…"></textarea>
        </div>
        <div class="mb-3">
          <label class="form-label">Skedar (opsional)</label>
          <input type="file" class="form-control" name="attachment">
        </div>
      </div>
      <div class="modal-footer">
        <button class="btn btn-secondary" type="button" data-bs-dismiss="modal">Anulo</button>
        <button class="btn btn-moodle" type="submit">
          <i class="bi bi-send me-1"></i>Krijo
        </button>
      </div>
    </form>
  </div>
</div>

<!-- Modal: Shto pagesë -->
<div class="modal fade" id="addPaymentModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog">
    <form class="modal-content" method="POST" action="process_payment.php">
      <div class="modal-header">
        <h5 class="modal-title">Shto pagesë</h5>
        <button class="btn-close" data-bs-dismiss="modal" type="button"></button>
      </div>
      <div class="modal-body">
        <input type="hidden" name="csrf" value="<?= h($CSRF) ?>">
        <input type="hidden" name="action" value="create">
        <input type="hidden" name="course_id" value="<?= (int)$course_id ?>">

        <div class="mb-3">
          <label class="form-label">Studenti</label>
          <select class="form-select" name="user_id" required>
            <option value="">—</option>
            <?php
            try {
              $stmtEnroll = $pdo->prepare("
                SELECT e.user_id, u.full_name
                FROM enroll e
                LEFT JOIN users u ON u.id = e.user_id
                WHERE e.course_id = ?
                ORDER BY u.full_name
              ");
              $stmtEnroll->execute([$course_id]);
              foreach ($stmtEnroll as $st) {
                echo '<option value="'.(int)$st['user_id'].'">'.h($st['full_name']).'</option>';
              }
            } catch (PDOException $e) {
              // Mund të log-osh gabimin
            }
            ?>
          </select>
        </div>

        <div class="mb-3">
          <label class="form-label">Shuma (€)</label>
          <input class="form-control" type="number" name="amount" min="0" step="0.01" required>
        </div>

        <div class="mb-3">
          <label class="form-label">Statusi</label>
          <select class="form-select" name="payment_status">
            <option value="COMPLETED" selected>COMPLETED</option>
            <option value="FAILED">FAILED</option>
          </select>
        </div>

        <div class="mb-3">
          <label class="form-label">Data pagesës</label>
          <input class="form-control" type="datetime-local" name="payment_date" value="<?= date('Y-m-d\TH:i') ?>">
        </div>
      </div>
      <div class="modal-footer">
        <button class="btn btn-secondary" type="button" data-bs-dismiss="modal">Mbyll</button>
        <button class="btn btn-moodle" type="submit">
          <i class="bi bi-save me-1"></i>Ruaj
        </button>
      </div>
    </form>
  </div>
</div>

<!-- Modal: QR i meeting-ut -->
<div class="modal fade" id="qrModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-sm modal-dialog-centered">
    <div class="modal-content">
      <div class="modal-header">
        <h6 class="modal-title mb-0">QR për takimin</h6>
        <button class="btn-close" data-bs-dismiss="modal" type="button"></button>
      </div>
      <div class="modal-body text-center">
        <img id="qrImg" alt="QR" class="img-fluid" />
        <div class="small text-muted mt-2" id="qrLinkText"></div>
      </div>
    </div>
  </div>
</div>

<!-- JS -->
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>

<script>
(function(){
  // Fallback për tabs nëse CDN/Bootstrap JS s’ngarkohet.
  // Fokus vetëm te course tabs (Përmbledhje/Materialet/...).
  function showTab(link){
    const targetSel = link.getAttribute('data-bs-target') || link.getAttribute('href');
    if (!targetSel || !targetSel.startsWith('#')) return;

    const tabRoot = link.closest('.course-nav-wrapper') || document;
    tabRoot.querySelectorAll('.course-tabs .nav-link').forEach(a => a.classList.remove('active'));
    link.classList.add('active');

    const shell = document.querySelector('.course-shell');
    if (!shell) return;
    shell.querySelectorAll('.tab-pane').forEach(p => p.classList.remove('show','active'));
    const pane = document.querySelector(targetSel);
    if (pane) pane.classList.add('show','active');
  }

  document.addEventListener('click', function(ev){
    const link = ev.target && ev.target.closest ? ev.target.closest('.course-tabs [data-bs-toggle="tab"]') : null;
    if (!link) return;

    // Mos lejo scroll në #id kur JS i tabs është aktiv
    ev.preventDefault();

    // Update URL (pa reload) që refresh/share të ruajë tab-in aktual.
    try {
      const href = link.getAttribute('href');
      if (href && !href.startsWith('#')) {
        const nextUrl = new URL(href, window.location.href);
        window.history.pushState({ tab: link.getAttribute('data-bs-target') || '' }, '', nextUrl.toString());
      }
    } catch (e) {}

    try {
      if (window.bootstrap && bootstrap.Tab) {
        bootstrap.Tab.getOrCreateInstance(link).show();
        return;
      }
    } catch (e) {}
    showTab(link);
  }, true);
})();
</script>

<script>
(function(){
  function toastIcon(type){
    if (type === 'success') return '<i class="fa-solid fa-circle-check me-2"></i>';
    if (type === 'danger' || type === 'error') return '<i class="fa-solid fa-triangle-exclamation me-2"></i>';
    if (type === 'warning') return '<i class="fa-solid fa-circle-exclamation me-2"></i>';
    return '<i class="fa-solid fa-circle-info me-2"></i>';
  }

  // Singleton toast për gjithë faqen (vetëm #toastZone)
  window.showToast = function(type, msg){
    let zone = document.getElementById('toastZone');
    if (!zone){
      zone = document.createElement('div');
      zone.id = 'toastZone';
      zone.setAttribute('aria-live','polite');
      zone.setAttribute('aria-atomic','true');
      document.body.appendChild(zone);
    }
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
  };

  // Flash nga session
  <?php if ($flashMsg !== ''): ?>
  window.addEventListener('DOMContentLoaded', function(){
    showToast(<?= json_encode($flashType) ?>, <?= json_encode($flashMsg) ?>);
  });
  <?php endif; ?>
})();
</script>

<?php require_once 'footer2.php'; ?>
</body>
</html>
