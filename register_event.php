<?php
// register_event.php — REVAMP (2026) • UI moderne + CSRF + honeypot + prefill + 48h cooldown + toast
declare(strict_types=1);

session_start();
require_once __DIR__ . '/database.php';

function h(?string $s): string { return htmlspecialchars($s ?? '', ENT_QUOTES, 'UTF-8'); }

function split_full_name(string $full): array {
  $full = trim(preg_replace('~\s+~',' ', $full));
  if ($full === '') return ['', ''];
  $parts = explode(' ', $full, 2);
  return [$parts[0], $parts[1] ?? ''];
}

function short_text(string $txt, int $max = 260): string {
  $txt = trim(strip_tags($txt));
  if ($txt === '') return '';
  if (function_exists('mb_strimwidth')) return mb_strimwidth($txt, 0, $max, '…', 'UTF-8');
  return (strlen($txt) > $max) ? (substr($txt, 0, $max-1) . '…') : $txt;
}

function format_remaining(int $seconds): string {
  $seconds = max(0, $seconds);
  $h = intdiv($seconds, 3600);
  $m = intdiv($seconds % 3600, 60);
  if ($h <= 0) return $m . ' min';
  return $h . 'h ' . $m . 'm';
}

/* ------------------ Toast (flash) ------------------ */
$toast = null;
if (!empty($_SESSION['toast']) && is_array($_SESSION['toast'])) {
  $toast = $_SESSION['toast'];
  unset($_SESSION['toast']);
}

/* ------------------ 1) event_id ------------------ */
if (!isset($_GET['event_id']) || !ctype_digit((string)$_GET['event_id'])) {
  http_response_code(400);
  exit('Eventi nuk është specifikuar.');
}
$event_id = (int)$_GET['event_id'];

/* ------------------ 2) Eventi + krijuesi + pjesëmarrësit ------------------ */
$event = null;
$participantCount = 0;

try {
  $stmt = $pdo->prepare("
    SELECT e.*, u.full_name AS creator_name
    FROM events e
    LEFT JOIN users u ON u.id = e.id_creator
    WHERE e.id = ?
    LIMIT 1
  ");
  $stmt->execute([$event_id]);
  $event = $stmt->fetch(PDO::FETCH_ASSOC);
  if (!$event) { http_response_code(404); exit('Eventi nuk u gjet.'); }

  $stmt2 = $pdo->prepare("SELECT COUNT(*) FROM enroll_events WHERE event_id = ?");
  $stmt2->execute([$event_id]);
  $participantCount = (int)$stmt2->fetchColumn();
} catch (Throwable $e) {
  http_response_code(500);
  exit('Gabim gjatë leximit të eventit.');
}

/* ------------------ 3) Statuset ------------------ */
$now  = new DateTime('now');
$evDT = new DateTime((string)($event['event_datetime'] ?? 'now'));

$isPast   = $evDT < $now;
$isActive = strtoupper((string)($event['status'] ?? '')) === 'ACTIVE';

$capacity = array_key_exists('capacity', $event) ? (int)$event['capacity'] : null;
$isFull   = $capacity !== null && $capacity > 0 && $participantCount >= $capacity;

/* ------------------ 4) CSRF ------------------ */
if (empty($_SESSION['csrf_token'])) {
  $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$csrf = (string)$_SESSION['csrf_token'];

/* ------------------ 5) Prefill ------------------ */
$prefFirst = $prefLast = $prefEmail = $prefPhone = '';

if (!empty($_SESSION['user_id'])) {
  try {
    $u = $pdo->prepare("SELECT full_name, email, phone_number FROM users WHERE id = ? LIMIT 1");
    $u->execute([(int)$_SESSION['user_id']]);
    if ($row = $u->fetch(PDO::FETCH_ASSOC)) {
      [$prefFirst, $prefLast] = split_full_name((string)($row['full_name'] ?? ''));
      $prefEmail = (string)($row['email'] ?? '');
      $prefPhone = (string)($row['phone_number'] ?? '');
    }
  } catch (Throwable $e) { /* ignore */ }
} else {
  if (!empty($_SESSION['full_name'])) { [$prefFirst, $prefLast] = split_full_name((string)$_SESSION['full_name']); }
  if (!empty($_SESSION['email'])) { $prefEmail = (string)$_SESSION['email']; }
  if (!empty($_SESSION['phone_number'])) { $prefPhone = (string)$_SESSION['phone_number']; }
}

/* ------------------ 6) Form init ------------------ */
$first_name = (string)($_POST['first_name'] ?? $prefFirst);
$last_name  = (string)($_POST['last_name']  ?? $prefLast);
$email      = (string)($_POST['email']      ?? $prefEmail);
$phone      = (string)($_POST['phone']      ?? $prefPhone);

$errors = [];

/* ------------------ 6.1) Detect time column ------------------ */
$timeCol = null;
$timeCandidates = ['created_at','registered_at','enrolled_at','created_on','inserted_at','added_at','uploaded_at','created','date_created'];

try {
  $cols = $pdo->query("SHOW COLUMNS FROM enroll_events")->fetchAll(PDO::FETCH_ASSOC) ?: [];
  $fields = [];
  foreach ($cols as $c) { if (!empty($c['Field'])) $fields[] = (string)$c['Field']; }
  foreach ($timeCandidates as $cand) {
    if (in_array($cand, $fields, true)) { $timeCol = $cand; break; }
  }
} catch (Throwable $e) {
  $timeCol = null;
}

/* ------------------ 6.2) Cooldown/Already checks (GET + POST) ------------------ */
$cooldownSeconds = 48 * 3600;

$identityEmail = trim((string)$email);
$identityPhone = trim((string)$phone);

$alreadyRegistered = false;
$alreadyAtTs = null;

$cooldownActive = false;
$cooldownRemaining = 0;
$cooldownUntilTs = null;
$cooldownLastEventId = null;
$cooldownLastAtTs = null;

// Kontrollo vetëm nëse kemi të paktën email ose phone
if ($identityEmail !== '' || $identityPhone !== '') {
  // 1) A është regjistruar NË KËTË EVENT?
  try {
    if ($timeCol) {
      $sql = "SELECT event_id, {$timeCol} AS t FROM enroll_events
              WHERE event_id = ? AND (email = ? OR phone = ?)
              ORDER BY {$timeCol} DESC
              LIMIT 1";
      $st = $pdo->prepare($sql);
      $st->execute([$event_id, $identityEmail, $identityPhone]);
      $row = $st->fetch(PDO::FETCH_ASSOC);
      if ($row) {
        $alreadyRegistered = true;
        $alreadyAtTs = !empty($row['t']) ? strtotime((string)$row['t']) : null;
      }
    } else {
      // fallback (pa kohë) – të paktën blloko regjistrimin e dytë
      $st = $pdo->prepare("SELECT 1 FROM enroll_events WHERE event_id = ? AND (email = ? OR phone = ?) LIMIT 1");
      $st->execute([$event_id, $identityEmail, $identityPhone]);
      if ($st->fetchColumn()) $alreadyRegistered = true;
    }
  } catch (Throwable $e) { /* ignore */ }

  // 2) Cooldown 48h në çdo event (vetëm nëse kemi kolonë kohe)
  if ($timeCol) {
    try {
      $sql = "SELECT event_id, {$timeCol} AS t
              FROM enroll_events
              WHERE (email = ? OR phone = ?)
              ORDER BY {$timeCol} DESC
              LIMIT 1";
      $st = $pdo->prepare($sql);
      $st->execute([$identityEmail, $identityPhone]);
      $row = $st->fetch(PDO::FETCH_ASSOC);
      if ($row && !empty($row['t'])) {
        $cooldownLastEventId = (int)($row['event_id'] ?? 0);
        $cooldownLastAtTs = strtotime((string)$row['t']);

        if ($cooldownLastAtTs) {
          $cooldownUntilTs = $cooldownLastAtTs + $cooldownSeconds;
          $cooldownRemaining = max(0, $cooldownUntilTs - time());
          $cooldownActive = $cooldownRemaining > 0;
        }
      }
    } catch (Throwable $e) { /* ignore */ }
  }
}

/* ------------------ 6.3) Can register? ------------------ */
$canRegisterBase = $isActive && !$isPast && !$isFull;
$blockedByPolicy = $alreadyRegistered || $cooldownActive;

$canRegister = $canRegisterBase && !$blockedByPolicy;

/* ------------------ 7) POST: validime + insert ------------------ */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {

  // Honeypot
  if (!empty($_POST['hp_email'] ?? '')) {
    $errors[] = 'Kërkesa u refuzua.';
  }

  // CSRF
  if (!hash_equals($_SESSION['csrf_token'] ?? '', (string)($_POST['csrf_token'] ?? ''))) {
    $errors[] = 'Seanca e pavlefshme. Rifresko faqen dhe provo përsëri.';
  }

  // Fusha
  $first_name = trim((string)$first_name);
  $last_name  = trim((string)$last_name);
  $email      = trim((string)$email);
  $phone      = trim((string)$phone);

  if ($first_name === '') $errors[] = 'Emri është i detyrueshëm.';
  if ($last_name  === '') $errors[] = 'Mbiemri është i detyrueshëm.';
  if ($phone      === '') $errors[] = 'Numri i telefonit është i detyrueshëm.';
  if ($email      === '') $errors[] = 'Email është i detyrueshëm.';
  elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) $errors[] = 'Email nuk është i vlefshëm.';

  // Gjendja e eventit
  if (!$isActive) { $errors[] = 'Ky event nuk është aktiv.'; }
  if ($isPast)    { $errors[] = 'Ky event ka përfunduar.'; }
  if ($isFull)    { $errors[] = 'Ky event është i plotësuar.'; }

  // Politika 48h / already
  // (Rilogo me inputet e POST-it në mënyrë që të funksionojë edhe kur s’ka prefill)
  if (empty($errors)) {
    $identityEmail = $email;
    $identityPhone = $phone;

    // 1) already per këtë event
    try {
      if ($timeCol) {
        $st = $pdo->prepare("SELECT {$timeCol} AS t FROM enroll_events WHERE event_id=? AND (email=? OR phone=?) ORDER BY {$timeCol} DESC LIMIT 1");
        $st->execute([$event_id, $identityEmail, $identityPhone]);
        $t = $st->fetchColumn();
        if ($t) {
          $errors[] = 'Jeni tashmë të regjistruar në këtë event.';
        }
      } else {
        $st = $pdo->prepare("SELECT 1 FROM enroll_events WHERE event_id=? AND (email=? OR phone=?) LIMIT 1");
        $st->execute([$event_id, $identityEmail, $identityPhone]);
        if ($st->fetchColumn()) {
          $errors[] = 'Jeni tashmë të regjistruar në këtë event.';
        }
      }
    } catch (Throwable $e) {
      $errors[] = 'Gabim gjatë verifikimit të regjistrimit ekzistues.';
    }

    // 2) cooldown 48h global
    if (empty($errors) && $timeCol) {
      try {
        $st = $pdo->prepare("SELECT event_id, {$timeCol} AS t FROM enroll_events WHERE (email=? OR phone=?) ORDER BY {$timeCol} DESC LIMIT 1");
        $st->execute([$identityEmail, $identityPhone]);
        $row = $st->fetch(PDO::FETCH_ASSOC);

        if ($row && !empty($row['t'])) {
          $lastTs = strtotime((string)$row['t']);
          if ($lastTs) {
            $untilTs = $lastTs + $cooldownSeconds;
            if (time() < $untilTs) {
              $remain = $untilTs - time();
              $errors[] = 'Keni bërë një regjistrim së fundmi. Provoni përsëri pas ' . format_remaining((int)$remain) . '.';
            }
          }
        }
      } catch (Throwable $e) {
        $errors[] = 'Gabim gjatë verifikimit të kufizimit 48-orësh.';
      }
    }
  }

  // Insert
  if (empty($errors)) {
    try {
      $ins = $pdo->prepare("INSERT INTO enroll_events (event_id, first_name, last_name, email, phone) VALUES (?, ?, ?, ?, ?)");
      $ins->execute([$event_id, $first_name, $last_name, $email, $phone]);

      // Toast + PRG (kthim te e njëjta faqe)
      $_SESSION['toast'] = [
        'type'  => 'success',
        'title' => 'Regjistrimi u krye',
        'msg'   => 'U regjistruat me sukses për “' . (string)($event['title'] ?? 'eventin') . '”.'
      ];

      header('Location: register_event.php?event_id=' . $event_id);
      exit;
    } catch (Throwable $e) {
      $errors[] = 'Gabim gjatë regjistrimit. Provo përsëri.';
    }
  }
}

/* ------------------ 8) Prezantimi ------------------ */
$muaj = [1=>'Jan','Shk','Mar','Pri','Maj','Qer','Kor','Gus','Sht','Tet','Nën','Dhj'];
$day  = $evDT->format('d');
$mon  = $muaj[(int)$evDT->format('n')] ?? $evDT->format('M');
$time = $evDT->format('H:i');

$cover = 'image/event_placeholder.jpg';
if (!empty($event['photo'])) {
  $cover = preg_match('~^https?://~', (string)$event['photo'])
    ? (string)$event['photo']
    : 'virtuale/uploads/events/' . ltrim((string)$event['photo'], '/');
}

$title    = (string)($event['title'] ?? 'Event');
$creator  = trim((string)($event['creator_name'] ?? 'Organizator'));
$category = trim((string)($event['category'] ?? 'Event'));
$location = trim((string)($event['location'] ?? ''));

$descShort = short_text((string)($event['description'] ?? ''), 360);

/* ------------------ 9) State text (hero) ------------------ */
$stateTone = 'warn';
$stateText = 'Regjistrimi është i mbyllur';

if ($alreadyRegistered) {
  $stateTone = 'ok';
  $stateText = 'Tashmë jeni regjistruar';
} elseif ($cooldownActive) {
  $stateTone = 'warn';
  $stateText = 'Kufizim 48h aktiv';
} else {
  if ($canRegisterBase) {
    $stateTone = 'ok';
    $stateText = 'Regjistrimi është i hapur';
  } else {
    $stateTone = 'warn';
    $stateText = !$isActive ? 'Eventi nuk është aktiv' : ($isPast ? 'Eventi ka përfunduar' : 'Eventi është i plotësuar');
  }
}
?>
<!DOCTYPE html>
<html lang="sq">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Regjistrohu — <?= h($title) ?></title>

  <link rel="icon" href="image/favicon.ico" type="image/x-icon" />
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css">
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css">
  <link rel="stylesheet" href="virtuale/css/register_event.css?v=2">
</head>

<body class="ev-body">

<?php
  if (file_exists(__DIR__ . '/navbar.php')) {
    include __DIR__ . '/navbar.php';
  }
?>

<header class="ev-hero">
  <div class="container">
    <div class="ev-hero-grid">

      <div class="ev-hero-media">
        <div class="ev-cover">
          <img src="<?= h($cover) ?>" alt="<?= h($title) ?>">
          <div class="ev-cover-overlay"></div>

          <div class="ev-cover-top">
            <?php if ($category !== ''): ?>
              <span class="ev-chip ev-chip-dark">
                <i class="fa-regular fa-folder me-1"></i><?= h($category) ?>
              </span>
            <?php endif; ?>

            <span class="ev-chip ev-chip-light">
              <i class="fa-regular fa-clock me-1"></i><?= h($day . ' ' . $mon . ' • ' . $time) ?>
            </span>
          </div>

          <div class="ev-cover-bottom">
            <div class="ev-state ev-state-<?= h($stateTone) ?>">
              <span class="dot"></span><?= h($stateText) ?>
            </div>
          </div>
        </div>
      </div>

      <div class="ev-hero-content">
        <div class="ev-breadcrumb">
          <i class="fa-solid fa-house me-1"></i> Evente / <span>Regjistrim</span>
        </div>

        <h1 class="ev-title"><?= h($title) ?></h1>

        <div class="ev-submeta">
          <?php if ($location !== ''): ?>
            <span><i class="fa-solid fa-location-dot me-1"></i><?= h($location) ?></span>
          <?php endif; ?>
          <span><i class="fa-regular fa-user me-1"></i><?= h($creator !== '' ? $creator : 'Organizator') ?></span>
        </div>

        <div class="ev-stats">
          <div class="ev-stat">
            <div class="icon"><i class="fa-solid fa-users"></i></div>
            <div>
              <div class="k">Pjesëmarrës</div>
              <div class="v"><?= (int)$participantCount ?><?= $capacity ? ' / ' . (int)$capacity : '' ?></div>
            </div>
          </div>

          <div class="ev-stat">
            <div class="icon"><i class="fa-regular fa-calendar"></i></div>
            <div>
              <div class="k">Data</div>
              <div class="v"><?= h($evDT->format('d.m.Y')) ?></div>
            </div>
          </div>

          <div class="ev-stat">
            <div class="icon"><i class="fa-solid fa-shield-halved"></i></div>
            <div>
              <div class="k">Status</div>
              <div class="v"><?= $isActive ? 'Aktiv' : 'Jo aktiv' ?></div>
            </div>
          </div>
        </div>

        <?php if ($descShort !== ''): ?>
          <p class="ev-desc"><?= h($descShort) ?></p>
        <?php endif; ?>

        <div class="ev-hero-actions">
          <a class="btn btn-outline-secondary btn-sm" href="events.php">
            <i class="fa-solid fa-arrow-left me-1"></i> Kthehu te eventet
          </a>

          <?php if (!$canRegisterBase): ?>
            <span class="ev-warn-pill">
              <i class="fa-regular fa-circle-xmark me-1"></i>
              <?= !$isActive ? 'Eventi nuk është aktiv.' : ($isPast ? 'Eventi ka përfunduar.' : 'Eventi është i plotësuar.') ?>
            </span>
          <?php endif; ?>
        </div>

      </div>
    </div>
  </div>
</header>

<main class="ev-main">
  <div class="container">
    <div class="row g-3">

      <aside class="col-12 col-lg-5">
        <div class="ev-card ev-card-sticky">
          <div class="ev-card-head">
            <div class="title">
              <i class="fa fa-info-circle me-2"></i> Detaje
            </div>
            <div class="small text-muted">
              ID: <strong>#<?= (int)$event_id ?></strong>
            </div>
          </div>

          <div class="ev-kv">
            <div class="row g-2">
              <div class="col-6">
                <div class="ev-kv-item">
                  <div class="k">Data</div>
                  <div class="v"><?= h($evDT->format('d.m.Y')) ?></div>
                </div>
              </div>
              <div class="col-6">
                <div class="ev-kv-item">
                  <div class="k">Ora</div>
                  <div class="v"><?= h($time) ?></div>
                </div>
              </div>
              <div class="col-6">
                <div class="ev-kv-item">
                  <div class="k">Pjesëmarrës</div>
                  <div class="v"><?= (int)$participantCount ?><?= $capacity ? ' / ' . (int)$capacity : '' ?></div>
                </div>
              </div>
              <div class="col-6">
                <div class="ev-kv-item">
                  <div class="k">Regjistrimi</div>
                  <div class="v"><?= $canRegister ? 'Hapur' : 'Mbyllur' ?></div>
                </div>
              </div>
            </div>
          </div>

          <div class="ev-divider"></div>

          <div class="ev-note">
            <div class="t">
              <i class="fa-solid fa-circle-info me-2"></i>Shënim
            </div>
            <div class="b">
              Duke klikuar “Regjistrohu”, pranoni kontaktimin për detaje të eventit.
            </div>
          </div>
        </div>
      </aside>

      <section class="col-12 col-lg-7">
        <div class="ev-card">
          <div class="ev-card-head">
            <div class="title">
              <i class="fa-regular fa-pen-to-square me-2"></i>Regjistrimi
            </div>
            <div class="small text-muted">Plotëso të dhënat e tua</div>
          </div>

          <?php if ($alreadyRegistered || $cooldownActive): ?>
            <div class="ev-banner <?= $alreadyRegistered ? 'ev-banner-ok' : 'ev-banner-warn' ?>">
              <div class="t">
                <i class="fa-solid <?= $alreadyRegistered ? 'fa-circle-check' : 'fa-clock' ?> me-2"></i>
                <?= $alreadyRegistered ? 'Jeni tashmë të regjistruar' : 'Kufizim 48-orësh aktiv' ?>
              </div>

              <div class="b">
                <?php if ($alreadyRegistered): ?>
                  <?= $alreadyAtTs ? ('Regjistrimi u bë më: <strong>' . h(date('d.m.Y H:i', $alreadyAtTs)) . '</strong>.') : 'Regjistrimi është kryer më parë.' ?>
                  <div class="mt-1">Nuk keni nevojë të plotësoni sërish formularin.</div>
                <?php else: ?>
                  Ju keni bërë një regjistrim së fundmi. Mund të regjistroheni përsëri pas:
                  <strong><?= h(format_remaining((int)$cooldownRemaining)) ?></strong>.
                  <?php if ($cooldownUntilTs): ?>
                    <div class="mt-1 text-muted small">Hapet më: <?= h(date('d.m.Y H:i', (int)$cooldownUntilTs)) ?></div>
                  <?php endif; ?>
                <?php endif; ?>
              </div>

              <div class="actions">
                <a class="btn btn-outline-secondary btn-sm" href="events.php">
                  <i class="fa-solid fa-arrow-left me-1"></i> Kthehu te eventet
                </a>
                <a class="btn btn-outline-primary btn-sm" href="register_event.php?event_id=<?= (int)$event_id ?>">
                  <i class="fa-solid fa-rotate me-1"></i> Rifresko
                </a>
              </div>
            </div>

          <?php else: ?>

            <form method="post" class="ev-form" novalidate>
              <input type="hidden" name="csrf_token" value="<?= h($csrf) ?>">
              <input type="text" name="hp_email" tabindex="-1" autocomplete="off" class="ev-hp">

              <div class="row g-2">
                <div class="col-12 col-md-6">
                  <label class="form-label ev-label">Emri</label>
                  <div class="ev-input">
                    <span class="ico"><i class="fa-regular fa-user"></i></span>
                    <input type="text" class="form-control" name="first_name" value="<?= h($first_name) ?>" placeholder="p.sh. Enis" required>
                  </div>
                </div>

                <div class="col-12 col-md-6">
                  <label class="form-label ev-label">Mbiemri</label>
                  <div class="ev-input">
                    <span class="ico"><i class="fa-regular fa-id-card"></i></span>
                    <input type="text" class="form-control" name="last_name" value="<?= h($last_name) ?>" placeholder="p.sh. Beqiri" required>
                  </div>
                </div>
              </div>

              <div class="row g-2 mt-1">
                <div class="col-12 col-md-6">
                  <label class="form-label ev-label">Email</label>
                  <div class="ev-input">
                    <span class="ico"><i class="fa-regular fa-envelope"></i></span>
                    <input type="email" class="form-control" name="email" value="<?= h($email) ?>" placeholder="p.sh. email@domain.com" required>
                  </div>
                </div>

                <div class="col-12 col-md-6">
                  <label class="form-label ev-label">Numri i telefonit</label>
                  <div class="ev-input">
                    <span class="ico"><i class="fa-solid fa-phone"></i></span>
                    <input type="text" class="form-control" name="phone" value="<?= h($phone) ?>" placeholder="p.sh. +355..." required>
                  </div>
                </div>
              </div>

              <div class="ev-form-hint">
                <i class="fa-regular fa-lock me-1"></i>Të dhënat përdoren vetëm për këtë event.
              </div>

              <div class="ev-form-actions">
                <button class="btn btn-primary btn-lg w-100" type="submit" <?= $canRegisterBase ? '' : 'disabled' ?>>
                  <i class="fa-solid fa-ticket me-2"></i>Regjistrohu
                </button>

                <?php if (!$canRegisterBase): ?>
                  <div class="ev-disabled-note">
                    <i class="fa-regular fa-circle-xmark me-1"></i>
                    <?= !$isActive ? 'Eventi nuk është aktiv.' : ($isPast ? 'Ky event ka përfunduar.' : 'Eventi është i plotësuar.') ?>
                  </div>
                <?php endif; ?>
              </div>

              <?php if (!$timeCol): ?>
                <div class="ev-banner ev-banner-warn mt-3">
                  <div class="t"><i class="fa-solid fa-triangle-exclamation me-2"></i>Vërejtje teknike</div>
                  <div class="b">
                    Për kufizimin 48h rekomandohet të keni një kolonë kohore (p.sh. <code>created_at</code>) në tabelën <code>enroll_events</code>.
                  </div>
                </div>
              <?php endif; ?>

            </form>

          <?php endif; ?>

        </div>
      </section>

    </div>
  </div>
</main>

<?php
  if (file_exists(__DIR__ . '/footer.php')) {
    include __DIR__ . '/footer.php';
  }
?>

<!-- Toast zone -->
<?php if ($toast && !empty($toast['msg'])): ?>
  <div id="toastZone">
    <div id="liveToast" class="toast ev-toast" role="alert" aria-live="assertive" aria-atomic="true" data-bs-delay="4200">
      <div class="toast-header">
        <strong class="me-auto">
          <i class="fa-solid <?= (($toast['type'] ?? '') === 'success') ? 'fa-circle-check' : 'fa-circle-info' ?> me-1"></i>
          <?= h((string)($toast['title'] ?? 'Njoftim')) ?>
        </strong>
        <small><?= h(date('H:i')) ?></small>
        <button type="button" class="btn-close ms-2 mb-1" data-bs-dismiss="toast" aria-label="Close"></button>
      </div>
      <div class="toast-body">
        <?= h((string)$toast['msg']) ?>
      </div>
    </div>
  </div>
<?php endif; ?>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script>
document.addEventListener('DOMContentLoaded', function () {
  var t = document.getElementById('liveToast');
  if (t && window.bootstrap) {
    try { new bootstrap.Toast(t).show(); } catch (e) {}
  }
});
</script>
</body>
</html>
