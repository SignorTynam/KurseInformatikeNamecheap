<?php
// promotion_details.php — Landing Page (HOME v2 style, user-friendly copy)
declare(strict_types=1);
session_start();
require_once __DIR__ . '/database.php';

/* ================= Helpers ================= */
function h(?string $s): string { return htmlspecialchars($s ?? '', ENT_QUOTES, 'UTF-8'); }
function money(?float $v): string {
  if ($v === null) return '';
  if ((float)$v <= 0) return 'Falas';
  return '€' . number_format((float)$v, 0);
}
function levelText(?string $lvl): string {
  return match (strtoupper((string)$lvl)) {
    'BEGINNER'     => 'Fillestar',
    'INTERMEDIATE' => 'Mesatar',
    'ADVANCED'     => 'I avancuar',
    'ALL'          => 'Për të gjithë',
    default        => '—',
  };
}

function promoPhotoUrl(?string $photo): string {
  // Base path i aplikacionit (p.sh. "" ose "/portal")
  $base = str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? ''));
  $base = rtrim($base, '/');
  if ($base === '.' || $base === '/') $base = '';

  $fallback = $base . '/virtuale/uploads/promotions/course_placeholder.jpg';

  $p = trim((string)$photo);
  if ($p === '') return $fallback;

  // URL absolute (https://...)
  if (preg_match('~^https?://~i', $p)) return $p;

  $p = str_replace('\\', '/', $p);

  // e bëjmë relative ndaj portalit (heq slash-in në fillim nëse ekziston)
  $p = ltrim($p, '/');

  // Nëse në DB është ruajtur "virtuale/promotions/..."
  if (str_starts_with($p, 'virtuale/uploads/promotions/')) return $base . '/' . $p;

  // Nëse në DB është ruajtur "promotions/..."
  if (str_starts_with($p, 'uploads/promotions/')) return $base . '/virtuale/' . $p;

  // Nëse në DB është ruajtur vetëm filename ose path tjetër
  return $base . '/virtuale/uploads/promotions/' . basename($p);
}

function set_flash(string $msg, string $type='success'): void { $_SESSION['flash'] = ['msg'=>$msg, 'type'=>$type]; }
function get_flash(): ?array { if (!empty($_SESSION['flash'])) { $f=$_SESSION['flash']; unset($_SESSION['flash']); return $f; } return null; }
function human_eta(int $seconds): string {
  $seconds = max(0, $seconds);
  $d = (int)floor($seconds / 86400);
  $h = (int)floor(($seconds % 86400) / 3600);
  $m = (int)floor(($seconds % 3600) / 60);
  if ($d > 0) return $d.' ditë'.($h ? ' '.$h.' orë' : '');
  if ($h > 0) return $h.' orë'.($m ? ' '.$m.' min' : '');
  return $m.' min';
}

/* ================= Config ================= */
$COOLDOWN_HOURS = 48;                 // (mbrojtje në prapaskenë)
$MIN_SECONDS_BEFORE_SUBMIT = 3;       // anti-bot
$SUPPORT_WHATSAPP = '+393274691197';
$SUPPORT_EMAIL = 'info@kurseinformatike.com';

/* ================= CSRF + Anti-bot (FIXED) ================= */
if (empty($_SESSION['csrf_token'])) $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
$CSRF = $_SESSION['csrf_token'];

/*
  FIX:
  - hp_field dhe form_issued_at NUK duhet të rifreskohen në çdo request,
    sidomos jo para validimit të POST, përndryshe time-trap bëhet gjithmonë false.
*/
if (empty($_SESSION['hp_field'])) $_SESSION['hp_field'] = 'hp_' . bin2hex(random_bytes(6));
$HP_FIELD = $_SESSION['hp_field'];

if (empty($_SESSION['form_issued_at']) && $_SERVER['REQUEST_METHOD'] !== 'POST') {
  $_SESSION['form_issued_at'] = time();
}
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
  $_SESSION['form_issued_at'] = time(); // vetëm në GET/refresh
}

/* ================= Load promotion ================= */
if (!isset($_GET['id']) || !ctype_digit((string)$_GET['id'])) { http_response_code(404); die('Promocioni nuk u gjet.'); }
$promoId = (int)$_GET['id'];

if (!isset($pdo) || !($pdo instanceof PDO)) { http_response_code(500); die('DB jo e disponueshme.'); }

try {
  $st = $pdo->prepare("SELECT * FROM promoted_courses WHERE id=:id LIMIT 1");
  $st->execute([':id'=>$promoId]);
  $promo = $st->fetch(PDO::FETCH_ASSOC);
  if (!$promo) { http_response_code(404); die('Promocioni nuk u gjet.'); }
} catch (Throwable $e) {
  http_response_code(500); die('Gabim gjatë leximit.');
}

/* ================= Open/Closed logic (opsionale) ================= */
$applicationsOpen = true;
if (isset($promo['status']) && trim((string)$promo['status']) !== '') {
  $applicationsOpen = strtoupper(trim((string)$promo['status'])) === 'ACTIVE';
}
$deadlineFields = ['apply_until','deadline','expires_at','valid_until','end_at','application_deadline'];
$applyUntil = null;
foreach ($deadlineFields as $f) { if (!empty($promo[$f])) { $applyUntil = (string)$promo[$f]; break; } }
if ($applyUntil) {
  try { if ((new DateTime($applyUntil)) < new DateTime('now')) $applicationsOpen = false; } catch (Throwable $t) {}
}

/* ================= Cooldown check ================= */
function within_cooldown(PDO $pdo, int $promoId, string $email, ?string $phone, int $cooldownHours): array {
  $cooldownSeconds = $cooldownHours * 3600;
  $email = strtolower(trim($email));
  $phoneN = $phone ? preg_replace('~\s+~','', trim($phone)) : null;

  $lastTs = null;

  try {
    $q = $pdo->prepare("SELECT created_at FROM promoted_course_enrollments
                        WHERE promotion_id=:p AND LOWER(email)=:e
                        ORDER BY created_at DESC LIMIT 1");
    $q->execute([':p'=>$promoId, ':e'=>$email]);
    $row = $q->fetchColumn();
    if ($row) $lastTs = strtotime((string)$row) ?: null;
  } catch (Throwable $e) {}

  if ($phoneN) {
    try {
      $q2 = $pdo->prepare("SELECT created_at FROM promoted_course_enrollments
                           WHERE promotion_id=:p AND phone=:ph
                           ORDER BY created_at DESC LIMIT 1");
      $q2->execute([':p'=>$promoId, ':ph'=>$phoneN]);
      $row2 = $q2->fetchColumn();
      if ($row2) {
        $ts2 = strtotime((string)$row2) ?: null;
        if ($ts2 && (!$lastTs || $ts2 > $lastTs)) $lastTs = $ts2;
      }
    } catch (Throwable $e) {}
  }

  if (!$lastTs) return [false, 0];

  $diff = time() - $lastTs;
  if ($diff < $cooldownSeconds) return [true, $cooldownSeconds - $diff];
  return [false, 0];
}

/* ================= POST: Apply ================= */
$errors = [];
$old = ['first_name'=>'','last_name'=>'','email'=>'','phone'=>'','note'=>'','consent'=>'1'];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'apply') {
  $old['first_name'] = trim((string)($_POST['first_name'] ?? ''));
  $old['last_name']  = trim((string)($_POST['last_name'] ?? ''));
  $old['email']      = trim((string)($_POST['email'] ?? ''));
  $old['phone']      = trim((string)($_POST['phone'] ?? ''));
  $old['note']       = trim((string)($_POST['note'] ?? ''));
  $old['consent']    = (string)($_POST['consent'] ?? '0');

  if (!$applicationsOpen) $errors[] = 'Ky kurs aktualisht nuk pranon aplikime.';

  // CSRF
  if (!hash_equals($CSRF, (string)($_POST['csrf'] ?? ''))) $errors[] = 'Rifresko faqen dhe provo sërish.';

  // Honeypot
  $hpVal = trim((string)($_POST[$HP_FIELD] ?? ''));
  if ($hpVal !== '') $errors[] = 'Kërkesa u bllokua. Provo sërish.';

  // Time-trap
  $issued = (int)($_SESSION['form_issued_at'] ?? 0);
  if ($issued > 0 && (time() - $issued) < $MIN_SECONDS_BEFORE_SUBMIT) {
    $errors[] = 'Provo sërish pas pak sekondash.';
  }

  $first = $old['first_name'];
  $last  = $old['last_name'];
  $email = strtolower(trim($old['email']));
  $phone = $old['phone'] !== '' ? preg_replace('~\s+~','', $old['phone']) : null;
  $note  = $old['note'] !== '' ? $old['note'] : null;
  $consent = ($old['consent'] === '1');

  if ($first === '' || mb_strlen($first,'UTF-8') < 2) $errors[] = 'Shkruaj emrin (min. 2 shkronja).';
  if ($last  === '' || mb_strlen($last,'UTF-8')  < 2) $errors[] = 'Shkruaj mbiemrin (min. 2 shkronja).';
  if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) $errors[] = 'Shkruaj një email të saktë.';
  if (!$consent) $errors[] = 'Duhet të pranosh termat dhe privatësinë.';

  if (!$errors) {
    [$blocked, $remaining] = within_cooldown($pdo, $promoId, $email, $phone, $COOLDOWN_HOURS);
    if ($blocked) $errors[] = 'Ke aplikuar së fundmi për këtë kurs. Provo sërish pas: '.human_eta($remaining).'.';
  }

  if (!$errors) {
    try {
      $stmt = $pdo->prepare("
        INSERT INTO promoted_course_enrollments
          (promotion_id, user_id, first_name, last_name, email, phone, note, consent)
        VALUES
          (:p, NULL, :f, :l, :e, :ph, :n, :c)
      ");
      $stmt->execute([
        ':p'  => $promoId,
        ':f'  => $first,
        ':l'  => $last,
        ':e'  => $email,
        ':ph' => ($phone !== null && $phone !== '') ? $phone : null,
        ':n'  => $note,
        ':c'  => $consent ? 1 : 0,
      ]);

      set_flash('Faleminderit! Aplikimi u dërgua. Do të të kontaktojmë së shpejti.', 'success');
      header('Location: promotion_details.php?id='.(int)$promoId.'#apply');
      exit;
    } catch (Throwable $e) {
      set_flash('Nuk u arrit dërgimi. Provo sërish pak më vonë.', 'danger');
      header('Location: promotion_details.php?id='.(int)$promoId.'#apply');
      exit;
    }
  }
}

/* ================= Derived promo fields ================= */
$name  = (string)($promo['name'] ?? 'Kurs');
$short = (string)($promo['short_desc'] ?? '');
$desc  = (string)($promo['description'] ?? '');

$hours = (int)($promo['hours_total'] ?? 0);
$level = levelText((string)($promo['level'] ?? ''));

$price = ($promo['price'] ?? null) !== null ? (float)$promo['price'] : null;
$oldP  = ($promo['old_price'] ?? null) !== null ? (float)$promo['old_price'] : null;
$hasDisc = ($price !== null && $oldP !== null && $oldP > 0 && $price >= 0 && $price < $oldP);
$discPct = $hasDisc ? (int)round((($oldP - $price) / $oldP) * 100) : 0;

$label = (string)($promo['label'] ?? '');
$badgeColor = (string)($promo['badge_color'] ?? '#F0B323');

$photo = promoPhotoUrl($promo['photo'] ?? null);
$videoUrl = !empty($promo['video_url']) && filter_var((string)$promo['video_url'], FILTER_VALIDATE_URL) ? (string)$promo['video_url'] : '';

$waDigits = preg_replace('~\D+~','', (string)$SUPPORT_WHATSAPP);
$waText = rawurlencode("Përshëndetje! Dua informacion për kursin: ".$name." (ID: ".$promoId.")");
$waLink = $waDigits ? ("https://wa.me/".$waDigits."?text=".$waText) : '';

?>
<!doctype html>
<html lang="sq">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <title><?= h($name) ?> — kurseinformatike.com</title>
  <meta name="description" content="<?= h($short !== '' ? $short : 'Detaje dhe aplikim i shpejtë për kursin') ?>">
  <link rel="icon" href="image/favicon.ico" type="image/x-icon">

  <!-- Bootstrap & Icons -->
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
  <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css" rel="stylesheet">

  <!-- Fonts (si HOME v2) -->
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@600;700;800;900&family=Roboto:wght@400;500;700&display=swap" rel="stylesheet">

  <style>
    /* ====== Design system (HOME v2) ====== */
    body.ki-promo{
      --ki-primary:#2A4B7C;
      --ki-primary-2:#1d3a63;
      --ki-secondary:#F0B323;
      --ki-accent:#FF6B6B;

      --ki-ink:#0b1220;
      --ki-text:#0f172a;
      --ki-muted:#6b7280;
      --ki-line: rgba(15, 23, 42, .12);

      --ki-sand:#fbfaf7;
      --ki-mist:#f2f6ff;
      --ki-ice:#f7fbff;
      --ki-night:#0b1220;

      --ki-r: 22px;
      --ki-r2: 28px;
      --ki-wrap: 1180px;

      --ki-shadow: 0 24px 60px rgba(11, 18, 32, .16);
      --ki-shadow-soft: 0 18px 44px rgba(11, 18, 32, .10);
    }
    body.ki-promo{
      margin:0;
      font-family: Roboto, system-ui, -apple-system, Segoe UI, Arial, sans-serif;
      color: var(--ki-text);
      background: radial-gradient(900px 600px at 10% 0%, rgba(42,75,124,.12), transparent 55%),
                  radial-gradient(900px 600px at 90% 10%, rgba(240,179,35,.14), transparent 55%),
                  linear-gradient(180deg, var(--ki-ice), var(--ki-sand));
    }
    a{ color: inherit; text-decoration: none; }
    a:hover{ text-decoration: none; }
    .ki-wrap{ width: min(var(--ki-wrap), calc(100% - 32px)); margin-inline: auto; }

    /* Navbar slim */
    .navbar{
      padding-top: .45rem;
      padding-bottom: .45rem;
      background: rgba(255,255,255,.55) !important;
      border-bottom: 1px solid rgba(15,23,42,.10);
      backdrop-filter: blur(10px);
    }
    .navbar .nav-link{ padding-top:.35rem; padding-bottom:.35rem; }
    .navbar .navbar-brand{ font-weight: 900; letter-spacing: .2px; }

    /* Type */
    .ki-h1{ font-family:Poppins,system-ui,sans-serif; font-weight:900; letter-spacing:.1px; line-height:1.05; margin:0; color:#fff; }
    .ki-h2{ font-family:Poppins,system-ui,sans-serif; font-weight:900; letter-spacing:.1px; line-height:1.1; margin:0; color:var(--ki-ink); }
    .ki-lead{ font-size:1.05rem; color:rgba(255,255,255,.84); line-height:1.55; margin:0; }
    .ki-muted{ color: rgba(11,18,32,.68); font-weight:800; }

    /* Buttons */
    .ki-btn{
      display:inline-flex; align-items:center; justify-content:center; gap:10px;
      padding: 12px 14px;
      border-radius: 14px;
      border: 1px solid rgba(15,23,42,.12);
      font-weight: 900;
      letter-spacing: .1px;
      transition: transform .15s ease, background .15s ease, border-color .15s ease;
      user-select: none;
      white-space: nowrap;
    }
    .ki-btn:hover{ transform: translateY(-1px); }
    .ki-btn.primary{ background: linear-gradient(135deg, var(--ki-secondary), #ffd36a); border-color: rgba(240,179,35,.55); color:#111827; }
    .ki-btn.dark{ background: rgba(11,18,32,.92); border-color: rgba(11,18,32,.92); color:#fff; }
    .ki-btn.ghost{ background: rgba(255,255,255,.25); backdrop-filter: blur(12px); border-color: rgba(255,255,255,.22); color: rgba(255,255,255,.92); }

    /* Sections */
    .ki-section{ padding: 56px 0; }
    .ki-section.tight{ padding: 40px 0; }

    /* Hero split */
    .ki-hero{ padding: 26px 0 18px; }
    .ki-hero-grid{ display:grid; grid-template-columns: 1.05fr .95fr; gap: 16px; align-items: stretch; }
    @media (max-width: 992px){ .ki-hero-grid{ grid-template-columns: 1fr; } }

    .ki-hero-left{
      border-radius: var(--ki-r2);
      overflow:hidden;
      position: relative;
      border: 1px solid rgba(15,23,42,.10);
      box-shadow: var(--ki-shadow);
      background: #0b1220;
      min-height: 440px;
    }
    .ki-hero-left img{ width:100%; height:100%; object-fit: cover; display:block; transform: scale(1.02); filter:saturate(1.02) contrast(1.02); }
    .ki-hero-left:after{
      content:"";
      position:absolute; inset:0;
      background: linear-gradient(180deg, rgba(11,18,32,.18) 0%, rgba(11,18,32,.78) 70%, rgba(11,18,32,.92) 100%);
      pointer-events:none;
    }
    .ki-hero-overlay{ position:absolute; inset:0; padding:18px; display:flex; flex-direction:column; justify-content:flex-end; z-index:2; }

    .ki-pill{
      display:inline-flex; align-items:center; gap:8px;
      padding:7px 10px; border-radius:999px;
      border:1px solid rgba(255,255,255,.18);
      background: rgba(255,255,255,.10);
      backdrop-filter: blur(10px);
      font-weight:900; color: rgba(255,255,255,.92);
      width: fit-content;
    }
    .ki-pill .dot{
      width:8px; height:8px; border-radius:50%;
      background: var(--ki-secondary);
      box-shadow: 0 0 0 6px rgba(240,179,35,.18);
    }

    .ki-bullets{ margin: 12px 0 0; padding:0; list-style:none; display:grid; gap:10px; }
    .ki-bullets li{
      display:flex; gap:10px; align-items:flex-start;
      padding: 10px 12px;
      border-radius: 18px;
      border: 1px solid rgba(255,255,255,.14);
      background: rgba(255,255,255,.08);
      color: rgba(255,255,255,.86);
      font-weight: 800;
    }
    .ki-bullets i{ margin-top:3px; color: var(--ki-secondary); }

    .ki-hero-right{
      border-radius: var(--ki-r2);
      border: 1px solid rgba(15,23,42,.10);
      box-shadow: var(--ki-shadow-soft);
      background: linear-gradient(180deg, rgba(255,255,255,.35), rgba(255,255,255,.14));
      backdrop-filter: blur(14px);
      padding: 18px;
      display:flex; flex-direction:column; gap: 14px;
    }

    .ki-pricebox{
      border-radius: 18px;
      border: 1px dashed rgba(15,23,42,.16);
      background: linear-gradient(180deg, rgba(255,255,255,.26), rgba(255,255,255,.12));
      padding: 14px;
    }
    .ki-pricebox .t{ color: rgba(11,18,32,.68); font-weight: 800; font-size: .92rem; }
    .ki-pricebox .v{
      font-family: Poppins, system-ui, sans-serif;
      font-weight: 900;
      font-size: 1.55rem;
      letter-spacing: .1px;
      color: var(--ki-ink);
      margin-top: 6px;
      line-height: 1;
    }
    .ki-pricebox .old{ color: rgba(11,18,32,.55); font-weight: 900; text-decoration: line-through; margin-top: 8px; }
    .ki-pricebox .disc{
      display:inline-flex; align-items:center; gap:8px;
      margin-top: 10px;
      padding: 7px 10px;
      border-radius: 999px;
      border: 1px solid rgba(15,23,42,.10);
      background: rgba(255,255,255,.22);
      font-weight: 900;
      color: rgba(11,18,32,.80);
      width: fit-content;
    }

    .ki-kpis{ display:grid; grid-template-columns: 1fr 1fr; gap: 10px; }
    .ki-kpi{
      border-radius: 18px;
      border: 1px solid rgba(15,23,42,.10);
      background: rgba(255,255,255,.18);
      padding: 12px;
    }
    .ki-kpi .k{ color: rgba(11,18,32,.62); font-weight: 900; font-size: .85rem; }
    .ki-kpi .v{ color: var(--ki-ink); font-weight: 900; font-size: 1rem; margin-top:4px; }

    /* Subnav sticky */
    .ki-subnav{ position: sticky; top: 74px; z-index: 20; padding: 10px 0; }
    @media (max-width: 992px){ .ki-subnav{ top: 64px; } }
    .ki-subnav-inner{
      border-radius: 999px;
      border: 1px solid rgba(15,23,42,.10);
      background: rgba(255,255,255,.45);
      backdrop-filter: blur(14px);
      box-shadow: var(--ki-shadow-soft);
      padding: 8px;
      display:flex; gap:8px; flex-wrap:wrap;
      justify-content: center;
    }
    .ki-subnav a{
      padding: 9px 12px;
      border-radius: 999px;
      font-weight: 900;
      border: 1px solid rgba(15,23,42,.10);
      background: rgba(255,255,255,.18);
      color: rgba(11,18,32,.84);
      transition: transform .15s ease, background .15s ease;
    }
    .ki-subnav a:hover{ transform: translateY(-1px); background: rgba(255,255,255,.32); }
    .ki-subnav a.active{ box-shadow: 0 0 0 6px rgba(240,179,35,.18); border-color: rgba(240,179,35,.45); }

    /* Content grid */
    .ki-grid{ display:grid; grid-template-columns: 1fr 390px; gap: 16px; margin-top: 14px; align-items: start; }
    @media (max-width: 992px){ .ki-grid{ grid-template-columns: 1fr; } }

    .ki-block{
      border-radius: var(--ki-r2);
      border: 1px solid rgba(15,23,42,.10);
      box-shadow: var(--ki-shadow-soft);
      background: linear-gradient(180deg, rgba(255,255,255,.35), rgba(255,255,255,.14));
      backdrop-filter: blur(14px);
      padding: 18px;
    }
    .ki-mini{ font-weight: 900; letter-spacing: .2px; color: rgba(11,18,32,.70); text-transform: uppercase; font-size: .78rem; }

    .ki-list{ margin: 12px 0 0; padding: 0; list-style: none; display:grid; gap: 10px; }
    .ki-li{
      display:flex; gap:12px; align-items:flex-start;
      padding: 12px 12px;
      border-radius: 18px;
      background: rgba(255,255,255,.18);
      border: 1px solid rgba(15,23,42,.10);
    }
    .ki-ic{
      width: 38px; height: 38px; border-radius: 14px;
      display:flex; align-items:center; justify-content:center;
      background: rgba(42,75,124,.12);
      color: var(--ki-primary-2);
      flex: 0 0 auto;
    }

    /* Markdown */
    .ki-md{ margin-top: 12px; font-size: 1.02rem; line-height: 1.72; color: rgba(11,18,32,.90); }
    .ki-md h1,.ki-md h2,.ki-md h3{ font-family:Poppins,system-ui,sans-serif; font-weight:900; color: var(--ki-ink); margin-top: 1rem; }
    .ki-md p, .ki-md li{ color: rgba(11,18,32,.78); font-weight: 600; }
    .ki-md blockquote{ border-left: 4px solid rgba(42,75,124,.35); padding-left: .9rem; color: rgba(11,18,32,.78); margin: 1rem 0; }
    .ki-md pre{ background:#0b1220; color: rgba(255,255,255,.90); padding: .9rem; border-radius: 18px; overflow:auto; box-shadow: var(--ki-shadow-soft); border: 1px solid rgba(255,255,255,.10); }
    .ki-md code{ background: rgba(15,23,42,.06); padding: .12rem .38rem; border-radius: 10px; font-weight: 900; color: rgba(11,18,32,.88); }
    .ki-md a{ color: var(--ki-primary); text-decoration: underline; }

    /* Apply sticky */
    .ki-sticky{ position: sticky; top: 142px; }
    @media (max-width: 992px){ .ki-sticky{ position: static; top:auto; } }

    .form-control, .form-select{
      border-radius: 16px;
      border-color: rgba(15,23,42,.14);
      background: rgba(255,255,255,.40);
    }
    .form-control:focus, .form-select:focus{
      box-shadow: 0 0 0 6px rgba(240,179,35,.18);
      border-color: rgba(240,179,35,.45);
      background: rgba(255,255,255,.55);
    }
    .form-label{ font-weight: 900; color: rgba(11,18,32,.86); }
    .required:after{ content:" *"; color:#ef4444; }

    .ki-note{ color: rgba(11,18,32,.62); font-weight: 800; font-size: .95rem; }
    .ki-closed{
      border-radius: 18px; border: 1px dashed rgba(15,23,42,.18);
      background: rgba(255,255,255,.18); padding: 14px; text-align: center;
      color: rgba(11,18,32,.74); font-weight: 900;
    }

    /* Accordion */
    .accordion-item{ border-radius: 18px !important; overflow:hidden; border: 1px solid rgba(15,23,42,.10); background: rgba(255,255,255,.18); }
    .accordion-button{ font-weight: 900; background: rgba(255,255,255,.20); }
    .accordion-button:focus{ box-shadow: 0 0 0 6px rgba(240,179,35,.18); }
    .accordion-button:not(.collapsed){ background: rgba(240,179,35,.16); color: rgba(11,18,32,.88); }

    /* Reveal */
    .ki-reveal{ opacity:0; transform: translateY(10px); transition: all .45s ease; }
    .ki-reveal.show{ opacity:1; transform:none; }
    @media (prefers-reduced-motion: reduce){
      .ki-reveal{ opacity:1; transform:none; transition:none; }
      .ki-btn{ transition:none; }
    }

    /* Toast */
    #kiToastZone{ position: fixed; right: 16px; bottom: 16px; z-index: 999; }
    .toast.ki{ border-radius: 18px; border: 1px solid rgba(15,23,42,.12); box-shadow: var(--ki-shadow-soft); overflow:hidden; background: rgba(255,255,255,.85); backdrop-filter: blur(12px); }
    .toast.ki .toast-header{ background: rgba(11,18,32,.92); color:#fff; border:0; }
    .toast.ki .btn-close{ filter: invert(1); }
  </style>
</head>

<body class="ki-promo">
<?php include __DIR__ . '/navbar.php'; ?>

<main>

  <!-- ================= HERO (sales-first) ================= -->
  <section class="ki-hero">
    <div class="ki-wrap">
      <div class="ki-hero-grid">

        <div class="ki-hero-left ki-reveal">
          <img src="<?= h($photo) ?>" alt="<?= h($name) ?>" loading="lazy" decoding="async"
               onerror="this.onerror=null;this.src='<?= h(promoPhotoUrl(null)) ?>';">
          <div class="ki-hero-overlay">
            <div class="ki-pill">
              <span class="dot" style="background: <?= h($badgeColor) ?>;"></span>
              <span><?= $label ? h($label) : 'Kurs i hapur' ?></span>
              <?php if ($hasDisc): ?>
                <span style="margin-left:10px; font-weight:900; color:#fff;">Ulje <?= (int)$discPct ?>%</span>
              <?php endif; ?>
            </div>

            <h1 class="ki-h1 mt-3"><?= h($name) ?></h1>

            <p class="ki-lead mt-2">
              <?= h($short !== '' ? $short : 'Mëso në mënyrë të thjeshtë, me praktikë dhe udhëzim hap pas hapi.') ?>
            </p>

            <ul class="ki-bullets">
              <li><i class="fa-solid fa-circle-check"></i> Mësim i qartë, pa konfuzion dhe pa “teori të tepërt”.</li>
              <li><i class="fa-solid fa-circle-check"></i> Praktikë + detyra që të ndihmojnë të ecësh shpejt.</li>
              <li><i class="fa-solid fa-circle-check"></i> Mbështetje gjatë kursit dhe këshilla për vazhdimin.</li>
            </ul>

            <div class="d-flex gap-2 flex-wrap mt-3">
              <a class="ki-btn primary" href="#apply"><i class="fa-solid fa-paper-plane"></i> Apliko tani</a>
              <a class="ki-btn ghost" href="#benefits"><i class="fa-solid fa-gift"></i> Çfarë përfiton</a>
              <?php if ($waLink): ?>
                <a class="ki-btn ghost" href="<?= h($waLink) ?>" target="_blank" rel="noopener">
                  <i class="fa-brands fa-whatsapp"></i> Pyet në WhatsApp
                </a>
              <?php endif; ?>
            </div>
          </div>
        </div>

        <div class="ki-hero-right ki-reveal" style="transition-delay:.06s">
          <div class="ki-mini">Oferta</div>
          <h2 class="ki-h2 mt-2">Regjistrohu në 30 sekonda</h2>
          <div class="ki-note mt-2">Ne të kontaktojmë për të sqaruar orarin dhe mënyrën e zhvillimit.</div>

          <div class="ki-pricebox">
            <div class="t">Çmimi</div>
            <div class="v"><?= $price === null ? 'Sipas marrëveshjes' : h(money($price)) ?></div>
            <?php if ($hasDisc): ?>
              <div class="old"><?= h(money($oldP)) ?></div>
              <div class="disc"><i class="fa-solid fa-tag"></i> Kursen <?= h(money(max(0, (float)$oldP - (float)$price))) ?></div>
            <?php endif; ?>
          </div>

          <div class="ki-kpis">
            <div class="ki-kpi">
              <div class="k">Kohëzgjatja</div>
              <div class="v"><?= (int)$hours ?> orë</div>
            </div>
            <div class="ki-kpi">
              <div class="k">Niveli</div>
              <div class="v"><?= h($level) ?></div>
            </div>
            <div class="ki-kpi">
              <div class="k">Për kë është</div>
              <div class="v">Edhe pa eksperiencë</div>
            </div>
            <div class="ki-kpi">
              <div class="k">Qëllimi</div>
              <div class="v">Praktikë & rezultate</div>
            </div>
          </div>

          <div class="d-flex gap-2 flex-wrap">
            <a class="ki-btn dark" href="#apply"><i class="fa-solid fa-clipboard-check"></i> Plotëso formularin</a>
            <?php if ($videoUrl): ?>
              <a class="ki-btn primary" href="<?= h($videoUrl) ?>" target="_blank" rel="noopener">
                <i class="fa-solid fa-circle-play"></i> Shiko videon
              </a>
            <?php else: ?>
              <a class="ki-btn primary" href="contact.php"><i class="fa-solid fa-envelope"></i> Kontakto</a>
            <?php endif; ?>
          </div>

          <?php if (!$applicationsOpen): ?>
            <div class="ki-closed">
              <i class="fa-regular fa-circle-xmark me-1"></i> Ky kurs aktualisht nuk pranon aplikime.
            </div>
          <?php endif; ?>
        </div>

      </div>
    </div>
  </section>

  <!-- ================= SUBNAV ================= -->
  <div class="ki-subnav">
    <div class="ki-wrap">
      <div class="ki-subnav-inner ki-reveal" style="transition-delay:.10s" id="kiSubnav">
        <a href="#benefits"><i class="fa-solid fa-gift me-1"></i> Përfitimet</a>
        <a href="#how"><i class="fa-solid fa-route me-1"></i> Si zhvillohet</a>
        <a href="#details"><i class="fa-solid fa-circle-info me-1"></i> Detajet</a>
        <a href="#faq"><i class="fa-regular fa-circle-question me-1"></i> Pyetje</a>
        <a href="#apply"><i class="fa-solid fa-paper-plane me-1"></i> Apliko</a>
      </div>
    </div>
  </div>

  <!-- ================= CONTENT ================= -->
  <section class="ki-section tight">
    <div class="ki-wrap">

      <div class="ki-grid">

        <!-- LEFT: marketing content -->
        <div class="ki-reveal">

          <div class="ki-block" id="benefits">
            <div class="ki-mini">Çfarë përfiton</div>
            <h2 class="ki-h2 mt-2">Çfarë merr nga ky kurs</h2>

            <ul class="ki-list">
              <li class="ki-li">
                <div class="ki-ic"><i class="fa-solid fa-bullseye"></i></div>
                <div>
                  <div style="font-weight:900;color:var(--ki-ink)">Mësim i qartë, hap-pas-hapi</div>
                  <div class="ki-muted">E shpjegojmë thjesht, që ta kuptosh edhe nëse je fillestar.</div>
                </div>
              </li>
              <li class="ki-li">
                <div class="ki-ic"><i class="fa-solid fa-hammer"></i></div>
                <div>
                  <div style="font-weight:900;color:var(--ki-ink)">Praktikë gjatë gjithë kursit</div>
                  <div class="ki-muted">Ushtrime dhe shembuj realë, jo vetëm teori.</div>
                </div>
              </li>
              <li class="ki-li">
                <div class="ki-ic"><i class="fa-solid fa-comments"></i></div>
                <div>
                  <div style="font-weight:900;color:var(--ki-ink)">Mbështetje dhe udhëzim</div>
                  <div class="ki-muted">Na shkruan kur të kesh nevojë dhe të ndihmojmë të ecësh përpara.</div>
                </div>
              </li>
            </ul>
          </div>

          <div class="ki-block mt-3" id="how">
            <div class="ki-mini">Si zhvillohet</div>
            <h2 class="ki-h2 mt-2">3 hapa të thjeshtë</h2>

            <ul class="ki-list">
              <li class="ki-li">
                <div class="ki-ic"><i class="fa-solid fa-paper-plane"></i></div>
                <div>
                  <div style="font-weight:900;color:var(--ki-ink)">1) Apliko</div>
                  <div class="ki-muted">Plotëso formularin dhe lër kontaktin.</div>
                </div>
              </li>
              <li class="ki-li">
                <div class="ki-ic"><i class="fa-solid fa-phone"></i></div>
                <div>
                  <div style="font-weight:900;color:var(--ki-ink)">2) Ne të kontaktojmë</div>
                  <div class="ki-muted">Sqarojmë orarin, formatin dhe nisjen e kursit.</div>
                </div>
              </li>
              <li class="ki-li">
                <div class="ki-ic"><i class="fa-solid fa-play"></i></div>
                <div>
                  <div style="font-weight:900;color:var(--ki-ink)">3) Fillon mësimin</div>
                  <div class="ki-muted">Hap pas hapi, me praktikë dhe mbështetje.</div>
                </div>
              </li>
            </ul>
          </div>

          <div class="ki-block mt-3" id="details">
            <div class="ki-mini">Detajet</div>
            <h2 class="ki-h2 mt-2">Çfarë do mësosh</h2>
            <div class="ki-md" id="mdRendered"></div>
            <textarea id="mdSource" class="d-none"><?= h($desc) ?></textarea>

            <noscript>
              <div class="alert alert-warning mt-3">Javascript është i çaktivizuar; përshkrimi nuk mund të shfaqet si duhet.</div>
              <pre><?= h($desc) ?></pre>
            </noscript>
          </div>

          <div class="ki-block mt-3" id="faq">
            <div class="ki-mini">Pyetje të shpeshta</div>
            <h2 class="ki-h2 mt-2">FAQ</h2>

            <div class="accordion mt-3" id="faqAcc">
              <div class="accordion-item mb-2">
                <h2 class="accordion-header" id="q1">
                  <button class="accordion-button" type="button" data-bs-toggle="collapse" data-bs-target="#a1" aria-expanded="true" aria-controls="a1">
                    A mund ta ndjek kursin edhe nëse jam fillestar?
                  </button>
                </h2>
                <div id="a1" class="accordion-collapse collapse show" aria-labelledby="q1" data-bs-parent="#faqAcc">
                  <div class="accordion-body">
                    Po. Kursi është i përshtatshëm edhe për ata që nisin nga zero. E shpjegojmë thjesht dhe me praktikë.
                  </div>
                </div>
              </div>

              <div class="accordion-item mb-2">
                <h2 class="accordion-header" id="q2">
                  <button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#a2" aria-expanded="false" aria-controls="a2">
                    Si e di nëse ky kurs më përshtatet?
                  </button>
                </h2>
                <div id="a2" class="accordion-collapse collapse" aria-labelledby="q2" data-bs-parent="#faqAcc">
                  <div class="accordion-body">
                    Plotëso aplikimin dhe na thuaj çfarë kërkon të arrish. Ne të udhëzojmë nëse është kursi i duhur për ty.
                  </div>
                </div>
              </div>

              <div class="accordion-item mb-2">
                <h2 class="accordion-header" id="q3">
                  <button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#a3" aria-expanded="false" aria-controls="a3">
                    Sa shpejt më kontaktoni?
                  </button>
                </h2>
                <div id="a3" class="accordion-collapse collapse" aria-labelledby="q3" data-bs-parent="#faqAcc">
                  <div class="accordion-body">
                    Zakonisht brenda 24 orësh (ose sa më shpejt të jetë e mundur).
                  </div>
                </div>
              </div>

              <div class="accordion-item">
                <h2 class="accordion-header" id="q4">
                  <button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#a4" aria-expanded="false" aria-controls="a4">
                    A mund të pyes direkt në WhatsApp?
                  </button>
                </h2>
                <div id="a4" class="accordion-collapse collapse" aria-labelledby="q4" data-bs-parent="#faqAcc">
                  <div class="accordion-body">
                    Po. Mund të na shkruash direkt në WhatsApp ose në email.
                  </div>
                </div>
              </div>
            </div>

          </div>

        </div>

        <!-- RIGHT: apply -->
        <div class="ki-sticky ki-reveal" style="transition-delay:.06s" id="apply">
          <div class="ki-block">
            <div class="ki-mini">Aplikimi</div>
            <h2 class="ki-h2 mt-2">Lër kontaktin</h2>
            <div class="ki-note mt-2">Plotëso fushat dhe ne të kontaktojmë për hapat e radhës.</div>

            <?php if (!$applicationsOpen): ?>
              <div class="ki-closed mt-3">
                <i class="fa-regular fa-circle-xmark me-1"></i> Ky kurs aktualisht nuk pranon aplikime.
              </div>
            <?php else: ?>

              <?php if ($errors): ?>
                <div class="alert alert-danger mt-3">
                  <div class="fw-bold mb-1">Ju lutem kontrollo këto:</div>
                  <ul class="mb-0"><?php foreach ($errors as $e): ?><li><?= h($e) ?></li><?php endforeach; ?></ul>
                </div>
              <?php endif; ?>

              <form method="post" id="applyForm" class="mt-3" novalidate>
                <input type="hidden" name="action" value="apply">
                <input type="hidden" name="csrf" value="<?= h($CSRF) ?>">

                <!-- Honeypot -->
                <input type="text"
                       name="<?= h($HP_FIELD) ?>"
                       id="<?= h($HP_FIELD) ?>"
                       autocomplete="new-password"
                       tabindex="-1"
                       style="position:absolute; left:-10000px; width:1px; height:1px; overflow:hidden;"
                       aria-hidden="true">

                <div class="row g-2">
                  <div class="col-6">
                    <label class="form-label required" for="first_name">Emri</label>
                    <input class="form-control" id="first_name" name="first_name" type="text"
                           minlength="2" maxlength="80" required
                           value="<?= h($old['first_name']) ?>" placeholder="p.sh. Ardit">
                  </div>
                  <div class="col-6">
                    <label class="form-label required" for="last_name">Mbiemri</label>
                    <input class="form-control" id="last_name" name="last_name" type="text"
                           minlength="2" maxlength="80" required
                           value="<?= h($old['last_name']) ?>" placeholder="p.sh. Pasha">
                  </div>
                </div>

                <div class="mt-2">
                  <label class="form-label required" for="email">Email</label>
                  <input class="form-control" id="email" name="email" type="email"
                         maxlength="120" required
                         value="<?= h($old['email']) ?>" placeholder="ju@example.com">
                </div>

                <div class="mt-2">
                  <label class="form-label" for="phone">Telefon (opsional)</label>
                  <input class="form-control" id="phone" name="phone" type="text"
                         maxlength="40"
                         value="<?= h($old['phone']) ?>" placeholder="+39 3xx xxx xxxx">
                  <div class="ki-note mt-1">Nëse do kontakt më të shpejtë, lër edhe telefonin.</div>
                </div>

                <div class="mt-2">
                  <label class="form-label" for="note">Mesazh (opsional)</label>
                  <textarea class="form-control" id="note" name="note" rows="3" maxlength="1200"
                            placeholder="p.sh. Orari i preferuar, çfarë kërkon të arrish..."><?= h($old['note']) ?></textarea>
                  <div class="d-flex justify-content-between mt-1">
                    <span class="ki-note">Opsionale</span>
                    <span class="ki-note" id="noteCount">0/1200</span>
                  </div>
                </div>

                <div class="form-check mt-2">
                  <input class="form-check-input" type="checkbox" name="consent" id="consent" value="1"
                         <?= ($old['consent'] === '1') ? 'checked' : '' ?>>
                  <label class="form-check-label" for="consent" style="font-weight:800; color: rgba(11,18,32,.78);">
                    Pajtohem me <a href="terms.php" style="text-decoration:underline;">Termat</a> dhe
                    <a href="privacy.php" style="text-decoration:underline;">Privatësinë</a>.
                  </label>
                </div>

                <div class="d-grid gap-2 mt-3">
                  <button class="ki-btn primary" type="submit">
                    <i class="fa-solid fa-paper-plane"></i> Dërgo
                  </button>
                  <?php if ($waLink): ?>
                    <a class="ki-btn dark" href="<?= h($waLink) ?>" target="_blank" rel="noopener">
                      <i class="fa-brands fa-whatsapp"></i> Shkruaj në WhatsApp
                    </a>
                  <?php else: ?>
                    <a class="ki-btn dark" href="contact.php">
                      <i class="fa-solid fa-envelope"></i> Kontakt
                    </a>
                  <?php endif; ?>
                </div>

                <div class="ki-note mt-3">
                  <i class="fa-regular fa-circle-check me-1"></i>
                  Ne ruajmë kontaktin vetëm për të të kthyer përgjigje.
                </div>

                <div class="ki-note mt-2">
                  Email: <a href="mailto:<?= h($SUPPORT_EMAIL) ?>" style="text-decoration:underline;"><?= h($SUPPORT_EMAIL) ?></a>
                </div>

              </form>

            <?php endif; ?>
          </div>
        </div>

      </div>
    </div>
  </section>

</main>

<?php include __DIR__ . '/footer.php'; ?>

<div id="kiToastZone" aria-live="polite" aria-atomic="true"></div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/marked/marked.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/dompurify/dist/purify.min.js"></script>

<script>
  // Reveal
  const reduceMotion = window.matchMedia('(prefers-reduced-motion: reduce)').matches;
  const reveals = document.querySelectorAll('.ki-reveal');
  if (!reduceMotion) {
    const io = new IntersectionObserver((entries)=>{
      entries.forEach(e=>{
        if (e.isIntersecting){ e.target.classList.add('show'); io.unobserve(e.target); }
      });
    }, {threshold:.12});
    reveals.forEach(el=>io.observe(el));
  } else {
    reveals.forEach(el=>el.classList.add('show'));
  }

  // Markdown render (pa e quajtur “Markdown” në UI)
  if (window.marked) { marked.setOptions({ breaks: true }); }
  const src = document.getElementById('mdSource')?.value || '';
  const html = window.marked ? marked.parse(src) : src;
  const out = document.getElementById('mdRendered');
  if (out) out.innerHTML = window.DOMPurify ? DOMPurify.sanitize(html) : html;

  // Note counter
  (function(){
    const ta = document.getElementById('note');
    const cnt = document.getElementById('noteCount');
    if (!ta || !cnt) return;
    const upd = ()=>{ cnt.textContent = (ta.value.length||0) + '/1200'; };
    ta.addEventListener('input', upd); upd();
  })();

  // Subnav active
  (function(){
    const nav = document.getElementById('kiSubnav');
    if (!nav || !('IntersectionObserver' in window)) return;

    const links = Array.from(nav.querySelectorAll('a[href^="#"]'));
    const items = links
      .map(a => ({ a, id: (a.getAttribute('href')||'').slice(1) }))
      .filter(x => x.id && document.getElementById(x.id));

    function setActive(id){
      links.forEach(a => a.classList.toggle('active', a.getAttribute('href') === '#'+id));
    }

    const io = new IntersectionObserver((entries)=>{
      entries.forEach(e=>{
        if (e.isIntersecting) setActive(e.target.id);
      });
    }, {threshold:.35, rootMargin:'-25% 0px -55% 0px'});

    items.forEach(x => io.observe(document.getElementById(x.id)));
  })();

  // Toast
  function toastIcon(type){
    if (type==='success') return '<i class="fa-solid fa-circle-check me-2"></i>';
    if (type==='danger' || type==='error') return '<i class="fa-solid fa-triangle-exclamation me-2"></i>';
    if (type==='warning') return '<i class="fa-solid fa-circle-exclamation me-2"></i>';
    return '<i class="fa-solid fa-circle-info me-2"></i>';
  }
  function showToast(type, msg){
    const zone = document.getElementById('kiToastZone');
    if (!zone) return;
    const el = document.createElement('div');
    el.className = 'toast ki align-items-center';
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
    new bootstrap.Toast(el, { delay: 4200, autohide: true }).show();
  }

  <?php if ($fl = get_flash()): ?>
    showToast(<?= json_encode($fl['type']) ?>, <?= json_encode($fl['msg']) ?>);
  <?php endif; ?>
</script>
</body>
</html>
