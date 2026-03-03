<?php
// event_details.php — Detaje eventi (me Parsedown SafeMode, UI i pastër, përputhje me skemën)
declare(strict_types=1);
session_start();
require_once __DIR__ . '/lib/database.php';
require_once __DIR__ . '/lib/Parsedown.php';

function h(?string $s): string { return htmlspecialchars($s ?? '', ENT_QUOTES, 'UTF-8'); }
function is_digit_str($v): bool { return isset($v) && ctype_digit((string)$v); }

// ==== Auth check (pranon formatet më të zakonshme të sesionit) ====
$sessionUser = $_SESSION['user'] ?? null;
$userId  = $sessionUser['id'] ?? ($_SESSION['user_id'] ?? null);
$userRole = $sessionUser['role'] ?? ($_SESSION['role'] ?? null);

if (!$userId) {
  header('Location: login.php');
  exit;
}

// ==== Validate event_id ====
if (!is_digit_str($_GET['event_id'] ?? null)) {
  http_response_code(400);
  die('Eventi nuk është specifikuar.');
}
$event_id = (int)$_GET['event_id'];

// ==== Lexo eventin, krijuesin, numrin e pjesëmarrësve ====
try {
  $stmt = $pdo->prepare("
    SELECT e.*, u.full_name AS creator_name, u.id AS creator_id
    FROM events e
    JOIN users u ON e.id_creator = u.id
    WHERE e.id = ?
    LIMIT 1
  ");
  $stmt->execute([$event_id]);
  $event = $stmt->fetch(PDO::FETCH_ASSOC);
  if (!$event) { http_response_code(404); die('Eventi nuk u gjet.'); }

  $stmtCnt = $pdo->prepare("SELECT COUNT(*) FROM enroll_events WHERE event_id = ?");
  $stmtCnt->execute([$event_id]);
  $participantCount = (int)$stmtCnt->fetchColumn();
} catch (Throwable $e) {
  http_response_code(500);
  die('Gabim gjatë leximit të të dhënave të eventit.');
}

// ==== Parsedown (SafeMode) për përshkrimin ====
$Parsedown = new Parsedown();
if (method_exists($Parsedown, 'setSafeMode')) {
  $Parsedown->setSafeMode(true); // siguri ndaj HTML/skripteve në përshkrim
}
$descriptionHtml = $Parsedown->text($event['description'] ?? '');

// ==== Formatime / prezantim ====
$formattedDateTime = date('d/m/Y H:i', strtotime((string)$event['event_datetime']));
$category = trim((string)($event['category'] ?? ''));
$location = trim((string)($event['location'] ?? ''));

// Foto: lejo URL absolute ose folderin /events; vendos placeholder në mungesë
$photoRaw = trim((string)($event['photo'] ?? ''));
if ($photoRaw === '') {
  $photoUrl = 'image/event_placeholder.jpg';
} elseif (preg_match('~^https?://~i', $photoRaw)) {
  $photoUrl = $photoRaw;
} else {
  $photoUrl = 'events/' . ltrim($photoRaw, '/');
}

// Status
$isActive = strtoupper((string)($event['status'] ?? '')) === 'ACTIVE';
$isPast   = (new DateTime($event['event_datetime'])) < new DateTime('now');

// Mund të menaxhojë? (admin ose krijuesi i eventit)
$canManage = ($userRole === 'Administrator') || ((int)$event['creator_id'] === (int)$userId);

// ==== Merr listën e eventeve për sidebar ====
try {
  $stmtList = $pdo->query("SELECT id, title FROM events ORDER BY event_datetime ASC");
  $eventList = $stmtList->fetchAll(PDO::FETCH_ASSOC) ?: [];
} catch (Throwable $e) {
  $eventList = [];
}

// ==== Merr regjistrimet (vetëm për admin/owner) ====
$registeredUsers = [];
if ($canManage) {
  try {
    $stmtReg = $pdo->prepare("
      SELECT id, first_name, last_name, email, phone, enrolled_at
      FROM enroll_events
      WHERE event_id = ?
      ORDER BY enrolled_at DESC
    ");
    $stmtReg->execute([$event_id]);
    $registeredUsers = $stmtReg->fetchAll(PDO::FETCH_ASSOC) ?: [];
  } catch (Throwable $e) {
    // Shfaq njoftim më poshtë në UI nëse do
  }
}
?>
<!DOCTYPE html>
<html lang="sq">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title><?= h($event['title']) ?> — Detajet e Eventit</title>
  <link rel="icon" href="image/favicon.ico" type="image/x-icon" />
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.1/font/bootstrap-icons.css" rel="stylesheet">
  <style>
    :root{ --primary:#0f6cbf; --soft:#f8f9fa; --muted:#6c757d; --border:#dee2e6; }
    body{ background-color:var(--soft); }
    .breadcrumb a{ color:var(--primary); text-decoration:none; }
    .breadcrumb a:hover{ text-decoration:underline; }
    .event-header{ background:#fff; padding:1.5rem; border-radius:12px; box-shadow:0 8px 24px rgba(0,0,0,.06); margin-bottom:1.5rem; }
    .event-title{ color:var(--primary); font-weight:700; font-size:1.8rem; margin-bottom:.25rem; }
    .event-meta{ color:var(--muted); font-size:.95rem; }
    .event-photo{ width:100%; max-height:420px; object-fit:cover; border:1px solid var(--border); border-radius:10px; margin:1rem 0; }
    .sidebar .card-header{ background:var(--primary); color:#fff; }
    .btn-cta{ background:var(--primary); color:#fff; border:0; }
    .btn-cta:hover{ filter:brightness(.95); color:#fff; }
    .badge-soft{ background:#eef5ff; color:#0b5ed7; border:1px solid #cfe2ff; }
  </style>
</head>
<body>
<?php
// Navbar sipas rolit, me fallback nëse skedari mungon
$navInc = null;
if ($userRole === 'Administrator' && file_exists(__DIR__.'/navbar_logged_administrator.php')) {
  $navInc = 'navbar_logged_administrator.php';
} elseif ($userRole === 'Instruktor' && file_exists(__DIR__.'/navbar_logged_instructor.php')) {
  $navInc = 'navbar_logged_instructor.php';
} elseif ($userRole === 'Student' && file_exists(__DIR__.'/navbar_logged_student.php')) {
  $navInc = 'navbar_logged_student.php';
}
include $navInc ?: __DIR__ . '/navbar.php';
?>

<div class="container py-4" style="margin-top:56px;">
  <!-- Breadcrumb -->
  <nav aria-label="breadcrumb">
    <ol class="breadcrumb">
      <li class="breadcrumb-item"><a href="events.php">Eventet</a></li>
      <li class="breadcrumb-item active" aria-current="page"><?= h($event['title']) ?></li>
    </ol>
  </nav>

  <div class="row g-3">
    <!-- Main -->
    <div class="col-lg-8">
      <div class="event-header">
        <div class="d-flex align-items-center justify-content-between">
          <h1 class="event-title mb-0"><?= h($event['title']) ?></h1>
          <span class="badge badge-soft rounded-pill px-3 py-2">
            <i class="bi bi-people me-1"></i><?= $participantCount ?> pjesëmarrës
          </span>
        </div>

        <p class="event-meta mt-2 mb-1">
          <i class="bi bi-calendar-event me-1"></i>
          <strong><?= h($formattedDateTime) ?></strong>
          <?php if ($category): ?>
            &nbsp;|&nbsp; <i class="bi bi-tags me-1"></i>Kategoria: <strong><?= h($category) ?></strong>
          <?php endif; ?>
          &nbsp;|&nbsp; <i class="bi bi-person-fill me-1"></i>Krijuar nga: <strong><?= h($event['creator_name'] ?? 'Organizator') ?></strong>
          <?php if ($location): ?>
            &nbsp;|&nbsp; <i class="bi bi-geo-alt me-1"></i>Vendi: <strong><?= h($location) ?></strong>
          <?php endif; ?>
        </p>

        <img src="<?= h($photoUrl) ?>" alt="Foto e eventit" class="event-photo">

        <div class="event-description">
          <?= $descriptionHtml /* HTML nga Parsedown (SafeMode aktiv) */ ?>
        </div>

        <div class="d-flex gap-2">
          <a class="btn btn-outline-secondary" href="events.php">
            <i class="bi bi-arrow-left-short"></i> Kthehu te eventet
          </a>
          <a class="btn btn-cta" href="register_event.php?event_id=<?= (int)$event_id ?>"
             <?= (!$isActive || $isPast) ? 'aria-disabled="true" tabindex="-1" style="pointer-events:none;opacity:.65;"' : '' ?>>
            <i class="bi bi-ticket-perforated"></i>
            <?= (!$isActive ? 'Event jo aktiv' : ($isPast ? 'Event i përfunduar' : 'Regjistrohu')) ?>
          </a>
        </div>
      </div>
    </div>

    <!-- Sidebar -->
    <div class="col-lg-4 sidebar">
      <?php if ($canManage): ?>
        <div class="card mb-3">
          <div class="card-header"><h5 class="mb-0">Mjetet e menaxhimit</h5></div>
          <div class="card-body d-flex flex-wrap gap-2">
            <a href="edit_event.php?event_id=<?= (int)$event['id'] ?>" class="btn btn-outline-secondary btn-sm">
              <i class="bi bi-pencil-square"></i> Modifiko eventin
            </a>
            <form method="post" action="delete_event.php" onsubmit="return confirm('A jeni i sigurt që dëshironi të fshini këtë event?');">
              <input type="hidden" name="event_id" value="<?= (int)$event['id'] ?>">
              <button type="submit" class="btn btn-outline-danger btn-sm">
                <i class="bi bi-trash"></i> Fshi eventin
              </button>
            </form>
          </div>
        </div>

        <div class="card mb-3">
          <div class="card-header"><h5 class="mb-0">Personat e regjistruar</h5></div>
          <div class="card-body">
            <?php if (!empty($registeredUsers)): ?>
              <div class="list-group">
                <?php foreach ($registeredUsers as $ru): ?>
                  <div class="list-group-item d-flex justify-content-between align-items-center">
                    <div>
                      <h6 class="mb-1"><?= h(trim($ru['first_name'].' '.$ru['last_name'])) ?></h6>
                      <div class="small text-muted">
                        <i class="bi bi-telephone me-1"></i><?= h($ru['phone'] ?? '') ?>
                        &nbsp; • &nbsp;
                        <i class="bi bi-envelope me-1"></i><?= h($ru['email'] ?? '') ?>
                      </div>
                      <small class="text-muted">Regjistruar më: <?= h(date('d/m/Y H:i', strtotime((string)$ru['enrolled_at']))) ?></small>
                    </div>
                    <form method="post" action="delete_registration.php"
                          onsubmit="return confirm('A jeni i sigurt që dëshironi të fshini këtë regjistrim?');">
                      <input type="hidden" name="registration_id" value="<?= (int)$ru['id'] ?>">
                      <input type="hidden" name="event_id" value="<?= (int)$event_id ?>">
                      <button type="submit" class="btn btn-danger btn-sm">Fshi</button>
                    </form>
                  </div>
                <?php endforeach; ?>
              </div>
            <?php else: ?>
              <div class="text-center text-muted">Nuk ka përdorues të regjistruar.</div>
            <?php endif; ?>
          </div>
        </div>
      <?php endif; ?>

      <div class="card">
        <div class="card-header"><h5 class="mb-0">Të gjitha eventet</h5></div>
        <div class="card-body p-0">
          <ul class="list-group list-group-flush">
            <?php foreach ($eventList as $ev): ?>
              <?php $active = ((int)$ev['id'] === (int)$event_id); ?>
              <li class="list-group-item <?= $active ? 'active' : '' ?>">
                <a class="<?= $active ? 'text-white' : 'text-dark' ?>"
                   style="text-decoration:none;"
                   href="event_details.php?event_id=<?= (int)$ev['id'] ?>">
                  <?= h($ev['title']) ?>
                </a>
              </li>
            <?php endforeach; ?>
          </ul>
        </div>
      </div>

    </div><!-- /sidebar -->
  </div><!-- /row -->
</div><!-- /container -->

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
