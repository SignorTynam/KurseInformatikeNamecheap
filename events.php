<?php
// events.php — KI v2 (Revamp) • Evente publike me filtra, kërkim, renditje & pagination
declare(strict_types=1);
session_start();
require_once __DIR__ . '/database.php';

/* ---------- Helpers ---------- */
function h(?string $s): string { return htmlspecialchars($s ?? '', ENT_QUOTES, 'UTF-8'); }

function safe_trim(string $text, int $max=140): string {
  $plain = strip_tags($text);
  if (function_exists('mb_strimwidth')) return mb_strimwidth($plain, 0, $max, '…', 'UTF-8');
  return (strlen($plain) > $max) ? substr($plain, 0, $max - 3) . '...' : $plain;
}

function initials_from_name(?string $name): string {
  $name = trim($name ?? '');
  if ($name === '') return 'KI';
  $parts = preg_split('~\s+~', $name);
  $a = $parts[0] ?? 'K';
  $b = $parts[1] ?? 'I';
  if (function_exists('mb_substr')) {
    return strtoupper(mb_substr($a,0,1,'UTF-8') . mb_substr($b,0,1,'UTF-8'));
  }
  return strtoupper(substr($a,0,1) . substr($b,0,1));
}

/** Ndërton src të fotos së eventit në mënyrë të sigurt */
function build_event_img(?string $photo): string {
  $placeholder = 'image/event_placeholder.jpg';
  $photo = trim((string)($photo ?? ''));
  if ($photo === '') return $placeholder;

  if (preg_match('~^https?://~i', $photo)) return $photo;   // URL absolute
  if ($photo[0] === '/') return $photo;                     // absolute path

  if (preg_match('~^(uploads/events/|uploads/|images/)~i', $photo)) return $photo;
  return 'virtuale/uploads/events/' . ltrim($photo, '/');
}

function qsWithout(array $skip): string {
  $p = $_GET;
  foreach ($skip as $k) unset($p[$k]);
  return http_build_query($p);
}
function qsMerge(array $add): string {
  $p = $_GET;
  foreach ($add as $k=>$v) $p[$k] = $v;
  return http_build_query($p);
}

/* ---------- Parametrat e filtrave ---------- */
$q        = trim($_GET['search'] ?? '');
$cat      = trim($_GET['category'] ?? '');
$dateF    = trim($_GET['date'] ?? 'upcoming'); // upcoming|week|month
$sort     = trim($_GET['sort'] ?? 'soonest');  // soonest|popular|newest
$page     = max(1, (int)($_GET['page'] ?? 1));
$perPage  = 9;

$nowDT = new DateTime('now');
$now   = $nowDT->format('Y-m-d H:i:s');

/* ---------- Kategoritë (chips/select) ---------- */
$catRows = [];
try {
  $stmt = $pdo->query("SELECT DISTINCT category FROM events WHERE category IS NOT NULL AND category <> '' ORDER BY category");
  $catRows = $stmt->fetchAll(PDO::FETCH_COLUMN) ?: [];
} catch (Throwable $e) {
  $catRows = ['Workshop','Konferencë','Seminar','Networking','Trajnim'];
}

/* ---------- Filtrim & total ---------- */
$where  = ["e.status = 'ACTIVE'"];
$params = [];

if ($q !== '') {
  $where[] = "(e.title LIKE :q OR e.description LIKE :q OR e.category LIKE :q OR e.location LIKE :q)";
  $params[':q'] = '%'.$q.'%';
}
if ($cat !== '') {
  $where[] = "e.category = :cat";
  $params[':cat'] = $cat;
}

switch ($dateF) {
  case 'week':
    $start = $nowDT->format('Y-m-d H:i:s');
    $end   = (new DateTime('+7 days'))->format('Y-m-d H:i:s');
    $where[] = "(e.event_datetime >= :d1 AND e.event_datetime < :d2)";
    $params[':d1'] = $start;
    $params[':d2'] = $end;
    break;

  case 'month':
    // nga tani deri në fund të muajit
    $start = $nowDT->format('Y-m-d H:i:s');
    $end   = (new DateTime('last day of this month 23:59:59'))->format('Y-m-d H:i:s');
    $where[] = "(e.event_datetime >= :d1 AND e.event_datetime <= :d2)";
    $params[':d1'] = $start;
    $params[':d2'] = $end;
    break;

  case 'upcoming':
  default:
    $where[] = "e.event_datetime >= :now";
    $params[':now'] = $now;
    break;
}

$whereSql = $where ? 'WHERE '.implode(' AND ', $where) : '';

/* ---------- Renditja ---------- */
$order = 'ORDER BY e.event_datetime ASC';
switch ($sort) {
  case 'newest':
    $order = 'ORDER BY e.created_at DESC';
    break;
  case 'popular':
    $order = 'ORDER BY COALESCE(p.participants,0) DESC, e.event_datetime ASC';
    break;
  case 'soonest':
  default:
    $order = 'ORDER BY e.event_datetime ASC';
    break;
}

/* ---------- Numri total ---------- */
$total = 0;
try {
  $stmtC = $pdo->prepare("SELECT COUNT(*) FROM events e $whereSql");
  $stmtC->execute($params);
  $total = (int)$stmtC->fetchColumn();
} catch (Throwable $e) {
  $total = 0;
}

/* ---------- Pagination ---------- */
$pages  = max(1, (int)ceil($total / $perPage));
$page   = max(1, min($page, $pages));
$offset = ($page - 1) * $perPage;

/* ---------- Marrja e eventeve (lista) ---------- */
$events = [];
try {
  $limitClause = "LIMIT $offset, $perPage";
  $sql = "
    SELECT
      e.id, e.title, e.description, e.category, e.photo, e.location, e.event_datetime, e.created_at, e.status,
      u.full_name AS creator_name,
      COALESCE(p.participants,0) AS participant_count
    FROM events e
    LEFT JOIN users u ON u.id = e.id_creator
    LEFT JOIN (
      SELECT event_id, COUNT(*) AS participants
      FROM enroll_events
      GROUP BY event_id
    ) p ON p.event_id = e.id
    $whereSql
    $order
    $limitClause
  ";
  $stmt = $pdo->prepare($sql);
  foreach ($params as $k=>$v) $stmt->bindValue($k, $v);
  $stmt->execute();
  $events = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
} catch (Throwable $e) {
  error_log('[events.php] SELECT failed: '.$e->getMessage());
  $events = [];
}

/* ---------- Spotlight (eventi kryesor për reklamim) ---------- */
$spot = null;
if ($total > 0) {
  try {
    $sqlSpot = "
      SELECT
        e.id, e.title, e.description, e.category, e.photo, e.location, e.event_datetime, e.created_at,
        u.full_name AS creator_name,
        COALESCE(p.participants,0) AS participant_count
      FROM events e
      LEFT JOIN users u ON u.id = e.id_creator
      LEFT JOIN (
        SELECT event_id, COUNT(*) AS participants
        FROM enroll_events
        GROUP BY event_id
      ) p ON p.event_id = e.id
      $whereSql
      $order
      LIMIT 1
    ";
    $stmtS = $pdo->prepare($sqlSpot);
    foreach ($params as $k=>$v) $stmtS->bindValue($k, $v);
    $stmtS->execute();
    $spot = $stmtS->fetch(PDO::FETCH_ASSOC) ?: null;
  } catch (Throwable $e) { $spot = null; }
}

$muaj = [1=>'Jan','Shk','Mar','Pri','Maj','Qer','Kor','Gus','Sht','Tet','Nën','Dhj'];
?>
<!doctype html>
<html lang="sq">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <title>Eventet — kurseinformatike.com</title>
  <link rel="icon" href="image/favicon.ico" type="image/x-icon">

  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
  <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css" rel="stylesheet">
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@600;700;800;900&family=Roboto:wght@400;500;700&display=swap" rel="stylesheet">

  <style>
    /* ==========================================================
       KI v2 Events — match index.php/promotions (full-bleed, glass, tiles)
    ========================================================== */
    body.ki-events{
      --ki-primary:#2A4B7C;
      --ki-primary-2:#1d3a63;
      --ki-secondary:#F0B323;
      --ki-accent:#FF6B6B;

      --ki-ink:#0b1220;
      --ki-text:#0f172a;
      --ki-muted:#6b7280;

      --ki-sand:#fbfaf7;
      --ki-ice:#f7fbff;

      --ki-r: 22px;
      --ki-r2: 28px;
      --ki-wrap: 1180px;

      --ki-shadow: 0 24px 60px rgba(11, 18, 32, .16);
      --ki-shadow-soft: 0 18px 44px rgba(11, 18, 32, .10);
    }

    body.ki-events{
      margin:0;
      font-family: Roboto, system-ui, -apple-system, Segoe UI, Arial, sans-serif;
      color: var(--ki-text);
      background: radial-gradient(900px 600px at 10% 0%, rgba(42,75,124,.10), transparent 55%),
                  radial-gradient(900px 600px at 90% 10%, rgba(240,179,35,.12), transparent 55%),
                  linear-gradient(180deg, var(--ki-ice), var(--ki-sand));
    }

    .ki-wrap{ width: min(var(--ki-wrap), calc(100% - 32px)); margin-inline:auto; }

    .ki-h1,.ki-h2{
      font-family:Poppins, system-ui, sans-serif;
      font-weight: 900;
      letter-spacing:.1px;
      line-height:1.05;
      margin:0;
      color: var(--ki-ink);
    }
    .ki-h2{ line-height:1.1; }
    .ki-lead{ color: var(--ki-muted); line-height:1.55; margin:0; font-size:1.03rem; }

    .ki-kicker{
      display:inline-flex; align-items:center; gap:10px;
      padding:8px 12px;
      border-radius:999px;
      border:1px solid rgba(15,23,42,.10);
      background: rgba(255,255,255,.35);
      backdrop-filter: blur(10px);
      -webkit-backdrop-filter: blur(10px);
      font-weight: 900;
      color: rgba(11,18,32,.84);
    }
    .ki-kicker i{ color: var(--ki-secondary); }

    .ki-glass{
      border-radius: var(--ki-r2);
      border: 1px solid rgba(15,23,42,.12);
      background: rgba(255,255,255,.30);
      backdrop-filter: blur(12px);
      -webkit-backdrop-filter: blur(12px);
      box-shadow: var(--ki-shadow-soft);
    }

    .ki-btn{
      display:inline-flex; align-items:center; justify-content:center; gap:10px;
      padding: 12px 14px;
      border-radius: 14px;
      border: 1px solid rgba(15,23,42,.12);
      font-weight: 900;
      transition: transform .15s ease, background .15s ease, border-color .15s ease;
      text-decoration:none;
      color: rgba(11,18,32,.90);
      background: rgba(255,255,255,.35);
      backdrop-filter: blur(10px);
      -webkit-backdrop-filter: blur(10px);
      white-space: nowrap;
      user-select:none;
    }
    .ki-btn:hover{ transform: translateY(-1px); background: rgba(255,255,255,.55); }
    .ki-btn.primary{
      background: linear-gradient(135deg, var(--ki-secondary), #ffd36a);
      border-color: rgba(240,179,35,.55);
      color:#111827;
      backdrop-filter:none;
    }
    .ki-btn.dark{
      background: rgba(11,18,32,.92);
      border-color: rgba(11,18,32,.92);
      color:#fff;
      backdrop-filter:none;
    }

    /* Hero */
    .ki-hero{ padding: 34px 0 18px; }
    .ki-hero-grid{
      display:grid;
      grid-template-columns: 1.15fr .85fr;
      gap: 16px;
      align-items: stretch;
    }
    @media (max-width: 992px){ .ki-hero-grid{ grid-template-columns:1fr; } }

    .ki-search{ margin-top:14px; padding:12px; }
    .ki-search .input-group{
      border-radius: 18px;
      overflow:hidden;
      border: 1px solid rgba(15,23,42,.12);
      background: rgba(255,255,255,.20);
    }
    .ki-search .input-group-text{ background:transparent; border:0; color: rgba(11,18,32,.62); }
    .ki-search .form-control{
      background: transparent;
      border:0;
      box-shadow:none !important;
      font-weight: 800;
      color: rgba(11,18,32,.86);
    }
    .ki-search .form-control::placeholder{ color: rgba(11,18,32,.52); }
    .ki-search .btn{ border:0; background:transparent; font-weight:900; }
    .ki-search .btn:hover{ background: rgba(255,255,255,.40); }

    /* Chips */
    .ki-chiprow{ display:flex; flex-wrap:wrap; gap:10px; margin-top:12px; }
    .ki-chip{
      display:inline-flex; align-items:center; gap:8px;
      padding: 8px 12px;
      border-radius: 999px;
      border: 1px solid rgba(15,23,42,.12);
      background: rgba(255,255,255,.26);
      backdrop-filter: blur(10px);
      -webkit-backdrop-filter: blur(10px);
      font-weight: 900;
      color: rgba(11,18,32,.78);
      text-decoration:none;
      transition: transform .15s ease, background .15s ease;
    }
    .ki-chip:hover{ transform: translateY(-1px); background: rgba(255,255,255,.45); }
    .ki-chip.active{
      background: rgba(240,179,35,.24);
      border-color: rgba(240,179,35,.40);
      color: rgba(11,18,32,.92);
    }

    /* Toolbar */
    .ki-toolbar{
      margin-top: 18px;
      padding: 12px;
      display:flex;
      gap: 10px;
      flex-wrap: wrap;
      align-items:center;
      justify-content: space-between;
    }
    .ki-toolbar .meta{ font-weight: 900; color: rgba(11,18,32,.72); }
    .ki-select{
      border-radius: 14px;
      border: 1px solid rgba(15,23,42,.12);
      background: rgba(255,255,255,.28);
      padding: .55rem .75rem;
      font-weight: 900;
      color: rgba(11,18,32,.84);
      box-shadow:none !important;
    }

    /* Spotlight */
    .ki-spot{
      border-radius: var(--ki-r2);
      overflow:hidden;
      position: relative;
      min-height: 320px;
      box-shadow: var(--ki-shadow);
      border: 1px solid rgba(15,23,42,.10);
      background: #111;
      text-decoration:none;
      display:block;
    }
    .ki-spot img{ width:100%; height:100%; object-fit:cover; display:block; transform: scale(1.02); }
    .ki-spot:after{
      content:""; position:absolute; inset:0;
      background: linear-gradient(180deg, rgba(11,18,32,.10) 0%, rgba(11,18,32,.76) 72%, rgba(11,18,32,.92) 100%);
      pointer-events:none;
    }
    .ki-spot-info{
      position:absolute; inset:0;
      padding: 16px;
      display:flex; flex-direction:column; justify-content:flex-end;
      z-index:2; color:#fff;
    }
    .ki-badges{ display:flex; gap:8px; flex-wrap:wrap; }
    .ki-badge{
      padding:6px 10px;
      border-radius:999px;
      border:1px solid rgba(255,255,255,.18);
      background: rgba(255,255,255,.10);
      color: rgba(255,255,255,.92);
      font-weight: 900;
      font-size: .82rem;
    }
    .ki-spot-title{
      font-family:Poppins, system-ui, sans-serif;
      font-weight: 900;
      letter-spacing:.1px;
      font-size: 1.25rem;
      line-height: 1.12;
      margin: 10px 0 6px;
    }
    .ki-spot-meta{
      display:flex; gap:12px; flex-wrap:wrap;
      color: rgba(255,255,255,.80);
      font-weight: 800;
      font-size: .92rem;
    }

    /* Band + grid */
    .ki-band{
      border-top: 1px solid rgba(15,23,42,.08);
      border-bottom: 1px solid rgba(15,23,42,.08);
      background: linear-gradient(180deg, rgba(42,75,124,.05), rgba(240,179,35,.04));
      padding: 44px 0;
    }

    .ki-grid{
      margin-top: 14px;
      display:grid;
      grid-template-columns: repeat(3, minmax(0,1fr));
      gap: 14px;
    }
    @media (max-width: 992px){ .ki-grid{ grid-template-columns: repeat(2, minmax(0,1fr)); } }
    @media (max-width: 520px){ .ki-grid{ grid-template-columns: 1fr; } }

    .ki-tile{
      border-radius: var(--ki-r2);
      overflow:hidden;
      position: relative;
      border: 1px solid rgba(15,23,42,.10);
      box-shadow: var(--ki-shadow-soft);
      background: #0b1220;
      min-height: 250px;
      text-decoration:none;
      display:block;
      transition: transform .18s ease, box-shadow .18s ease;
    }
    .ki-tile:hover{ transform: translateY(-2px); box-shadow: var(--ki-shadow); }
    .ki-tile img{ width:100%; height:100%; object-fit:cover; display:block; transform: scale(1.02); }
    .ki-tile:after{
      content:""; position:absolute; inset:0;
      background: linear-gradient(180deg, rgba(11,18,32,.06) 0%, rgba(11,18,32,.82) 76%, rgba(11,18,32,.94) 100%);
      pointer-events:none;
    }
    .ki-tile-info{
      position:absolute; inset:0;
      padding: 14px;
      display:flex; flex-direction:column; justify-content:flex-end;
      z-index:2; color:#fff;
    }
    .ki-title{
      font-family:Poppins, system-ui, sans-serif;
      font-weight: 900;
      line-height: 1.14;
      margin: 10px 0 6px;
      font-size: 1.04rem;
    }
    .ki-desc{
      color: rgba(255,255,255,.78);
      font-weight: 700;
      line-height: 1.35;
      font-size: .92rem;
      margin: 0 0 8px;
    }
    .ki-meta{
      display:flex; gap:10px; flex-wrap:wrap;
      color: rgba(255,255,255,.80);
      font-weight: 800;
      font-size: .90rem;
    }

    .ki-creator{
      display:flex; align-items:center; gap:10px;
      margin-top: 10px;
      justify-content: space-between;
    }
    .ki-avatar{
      width: 34px; height: 34px;
      border-radius: 999px;
      background: rgba(255,255,255,.16);
      border: 1px solid rgba(255,255,255,.22);
      display:flex; align-items:center; justify-content:center;
      font-weight: 900;
      color: rgba(255,255,255,.92);
    }
    .ki-mini{
      color: rgba(255,255,255,.82);
      font-weight: 800;
      font-size: .90rem;
    }

    /* Empty */
    .ki-empty{
      margin-top: 14px;
      padding: 18px;
      border-radius: var(--ki-r2);
      border: 1px dashed rgba(15,23,42,.18);
      background: rgba(255,255,255,.28);
      color: rgba(11,18,32,.78);
      font-weight: 800;
    }

    /* Pagination */
    .ki-pager{
      margin-top: 18px;
      display:flex;
      justify-content:center;
      gap: 10px;
      flex-wrap: wrap;
    }
    .ki-page{
      padding: 10px 12px;
      border-radius: 999px;
      border: 1px solid rgba(15,23,42,.12);
      background: rgba(255,255,255,.28);
      font-weight: 900;
      color: rgba(11,18,32,.84);
      text-decoration:none;
    }
    .ki-page:hover{ background: rgba(255,255,255,.50); }
    .ki-page.active{
      background: rgba(240,179,35,.24);
      border-color: rgba(240,179,35,.40);
      color: rgba(11,18,32,.92);
    }
    .ki-page.disabled{ opacity:.45; pointer-events:none; }

    /* Reveal */
    .ki-reveal{ opacity:0; transform: translateY(10px); transition: all .45s ease; }
    .ki-reveal.show{ opacity:1; transform:none; }
    @media (prefers-reduced-motion: reduce){
      .ki-reveal{ opacity:1; transform:none; transition:none; }
      .ki-btn, .ki-chip, .ki-tile{ transition:none; }
    }

    /* Offcanvas */
    .offcanvas.ki-offcanvas{
      border-left: 1px solid rgba(15,23,42,.12);
      background: rgba(255,255,255,.86);
      backdrop-filter: blur(12px);
      -webkit-backdrop-filter: blur(12px);
    }
    .ki-offcanvas .offcanvas-title{
      font-family: Poppins, system-ui, sans-serif;
      font-weight: 900;
      color: var(--ki-ink);
    }
    .ki-form label{ font-weight: 900; color: rgba(11,18,32,.76); }
    .ki-form .form-select, .ki-form .form-control{
      border-radius: 14px;
      border: 1px solid rgba(15,23,42,.12);
      background: rgba(255,255,255,.70);
      font-weight: 800;
      box-shadow:none !important;
    }
  </style>
</head>

<body class="ki-events">

<?php
// Navbar: prefero publiken nëse e ke
if (file_exists(__DIR__ . '/navbar_public.php')) {
  include __DIR__ . '/navbar_public.php';
} else {
  include __DIR__ . '/navbar.php';
}
?>

<main>

  <!-- ================= HERO ================= -->
  <section class="ki-hero">
    <div class="ki-wrap">
      <div class="ki-hero-grid">

        <div class="ki-reveal">
          <div class="ki-kicker">
            <i class="fa-solid fa-calendar-check"></i>
            <span>Evente • Workshop • Networking • Trajnime</span>
          </div>

          <h1 class="ki-h1 mt-3">Zbulo eventin e radhës dhe regjistrohu</h1>
          <p class="ki-lead mt-3">
            Evente të përzgjedhura për komunitetin: njoftime të qarta, foto “premium” dhe CTA direkte.
          </p>

          <div class="ki-glass ki-search ki-reveal" style="transition-delay:.06s;">
            <form method="get" class="input-group" aria-label="Kërko evente">
              <input type="hidden" name="category" value="<?= h($cat) ?>">
              <input type="hidden" name="date" value="<?= h($dateF) ?>">
              <input type="hidden" name="sort" value="<?= h($sort) ?>">
              <span class="input-group-text"><i class="fa-solid fa-magnifying-glass"></i></span>
              <input class="form-control" type="search" name="search" value="<?= h($q) ?>" placeholder="Kërko: Cybersecurity, Excel, Networking..." aria-label="Kërko">
              <button class="btn" type="submit"><i class="fa-solid fa-arrow-right"></i></button>
            </form>

            <div class="ki-chiprow">
              <a class="ki-chip <?= $cat===''?'active':'' ?>" href="?<?= h(qsMerge(['category'=>'','page'=>1])) ?>">
                <i class="fa-solid fa-layer-group"></i> Të gjitha
              </a>
              <?php foreach (array_slice($catRows, 0, 12) as $c): ?>
                <a class="ki-chip <?= ($cat===$c)?'active':'' ?>" href="?<?= h(qsMerge(['category'=>$c,'page'=>1])) ?>">
                  <i class="fa-regular fa-folder"></i><?= h($c) ?>
                </a>
              <?php endforeach; ?>
            </div>
          </div>

          <div class="ki-glass ki-toolbar ki-reveal" style="transition-delay:.10s;">
            <div class="meta">
              <strong><?= number_format($total) ?></strong> evente
              <span class="ms-2" style="color:rgba(11,18,32,.55);font-weight:900;">|</span>
              <span class="ms-2">Faqja <strong><?= (int)$page ?></strong> / <?= (int)$pages ?></span>
            </div>

            <div class="d-flex gap-2 flex-wrap align-items-center">
              <!-- quick date pills -->
              <a class="ki-chip <?= $dateF==='upcoming'?'active':'' ?>" href="?<?= h(qsMerge(['date'=>'upcoming','page'=>1])) ?>"><i class="fa-solid fa-bolt"></i>Të ardhshme</a>
              <a class="ki-chip <?= $dateF==='week'?'active':'' ?>" href="?<?= h(qsMerge(['date'=>'week','page'=>1])) ?>"><i class="fa-regular fa-calendar"></i>Këtë javë</a>
              <a class="ki-chip <?= $dateF==='month'?'active':'' ?>" href="?<?= h(qsMerge(['date'=>'month','page'=>1])) ?>"><i class="fa-regular fa-calendar-days"></i>Këtë muaj</a>

              <select class="ki-select" aria-label="Rendit sipas"
                onchange="location.href='?'+new URLSearchParams({...Object.fromEntries(new URLSearchParams(window.location.search)), sort:this.value, page:1}).toString()">
                <option value="soonest" <?= $sort==='soonest'?'selected':'' ?>>Datës (më të afërtat)</option>
                <option value="popular" <?= $sort==='popular'?'selected':'' ?>>Më të popullarët</option>
                <option value="newest" <?= $sort==='newest'?'selected':'' ?>>Më të rinjtë</option>
              </select>

              <button class="ki-btn dark" type="button" data-bs-toggle="offcanvas" data-bs-target="#filtersCanvas" aria-controls="filtersCanvas">
                <i class="fa-solid fa-sliders"></i> Filtra
              </button>

              <a class="ki-btn" href="events.php"><i class="fa-solid fa-eraser"></i> Reset</a>
            </div>
          </div>
        </div>

        <!-- Spotlight -->
        <div class="ki-reveal" style="transition-delay:.08s;">
          <?php if ($spot):
            try { $dt = new DateTime((string)$spot['event_datetime']); } catch (Throwable $t) { $dt = new DateTime(); }
            $day = $dt->format('d');
            $mon = $muaj[(int)$dt->format('n')] ?? $dt->format('M');
            $time= $dt->format('H:i');
            $img = build_event_img($spot['photo'] ?? null);
            $creator = trim((string)($spot['creator_name'] ?? '')) ?: 'Organizator';
            $participants = (int)($spot['participant_count'] ?? 0);
            $href = "register_event.php?event_id=".(int)$spot['id'];
            $catName = trim((string)($spot['category'] ?? ''));
          ?>
            <a class="ki-spot" href="<?= h($href) ?>" aria-label="Spotlight event">
              <img src="<?= h($img) ?>" alt="<?= h((string)$spot['title']) ?>" loading="lazy" decoding="async"
                   onerror="this.onerror=null;this.src='image/event_placeholder.jpg';">
              <div class="ki-spot-info">
                <div class="ki-badges">
                  <span class="ki-badge"><i class="fa-regular fa-calendar-days me-1"></i><?= h($day.' '.$mon.' · '.$time) ?></span>
                  <?php if ($catName !== ''): ?><span class="ki-badge"><i class="fa-regular fa-folder me-1"></i><?= h($catName) ?></span><?php endif; ?>
                  <span class="ki-badge"><i class="fa-solid fa-users me-1"></i><?= (int)$participants ?> pjesëmarrës</span>
                  <span class="ki-badge"><i class="fa-solid fa-bolt me-1"></i>Spotlight</span>
                </div>

                <div class="ki-spot-title"><?= h((string)$spot['title']) ?></div>

                <div class="ki-spot-meta">
                  <?php if (!empty($spot['location'])): ?>
                    <span><i class="fa-solid fa-location-dot me-1"></i><?= h((string)$spot['location']) ?></span>
                  <?php endif; ?>
                  <span><i class="fa-regular fa-user me-1"></i><?= h($creator) ?></span>
                </div>

                <div class="d-flex gap-2 flex-wrap mt-3">
                  <span class="ki-btn primary"><i class="fa-solid fa-ticket me-1"></i> Regjistrohu</span>
                  <span class="ki-btn"><i class="fa-regular fa-circle-play"></i> Detaje</span>
                </div>
              </div>
            </a>
          <?php else: ?>
            <div class="ki-glass p-3" style="min-height:320px;display:flex;align-items:center;justify-content:center;">
              <div style="text-align:center;color:rgba(11,18,32,.70);font-weight:900;">
                Nuk ka evente për spotlight me filtrat aktualë.
              </div>
            </div>
          <?php endif; ?>
        </div>

      </div>
    </div>
  </section>

  <!-- ================= GRID ================= -->
  <section class="ki-band">
    <div class="ki-wrap">

      <?php if (!$total): ?>
        <div class="ki-empty ki-reveal">
          <i class="fa-regular fa-calendar-xmark me-1"></i>
          Nuk ka evente për këta filtra.
          <div class="mt-3">
            <a class="ki-btn" href="events.php"><i class="fa-solid fa-eraser"></i> Reset filtrat</a>
          </div>
        </div>
      <?php else: ?>
        <div class="ki-grid">
          <?php foreach ($events as $ev):
            try { $dt = new DateTime((string)$ev['event_datetime']); } catch (Throwable $t) { $dt = new DateTime(); }
            $day = $dt->format('d');
            $mon = $muaj[(int)$dt->format('n')] ?? $dt->format('M');
            $time= $dt->format('H:i');

            $img = build_event_img($ev['photo'] ?? null);
            $creator = trim((string)($ev['creator_name'] ?? '')) ?: 'Organizator';
            $initials = initials_from_name($creator);
            $participants = (int)($ev['participant_count'] ?? 0);
            $href = "register_event.php?event_id=".(int)$ev['id'];
            $catName = trim((string)($ev['category'] ?? ''));
            $desc = safe_trim((string)($ev['description'] ?? ''), 120);
          ?>
            <a class="ki-tile ki-reveal" href="<?= h($href) ?>">
              <img src="<?= h($img) ?>" alt="<?= h((string)$ev['title']) ?>" loading="lazy" decoding="async"
                   onerror="this.onerror=null;this.src='image/event_placeholder.jpg';">
              <div class="ki-tile-info">
                <div class="ki-badges">
                  <span class="ki-badge"><i class="fa-regular fa-calendar-days me-1"></i><?= h($day.' '.$mon.' · '.$time) ?></span>
                  <?php if ($catName !== ''): ?><span class="ki-badge"><i class="fa-regular fa-folder me-1"></i><?= h($catName) ?></span><?php endif; ?>
                  <span class="ki-badge"><i class="fa-solid fa-users me-1"></i><?= (int)$participants ?></span>
                </div>

                <div class="ki-title"><?= h((string)$ev['title']) ?></div>

                <?php if ($desc !== ''): ?>
                  <div class="ki-desc"><?= h($desc) ?></div>
                <?php endif; ?>

                <div class="ki-meta">
                  <?php if (!empty($ev['location'])): ?>
                    <span><i class="fa-solid fa-location-dot me-1"></i><?= h((string)$ev['location']) ?></span>
                  <?php endif; ?>
                </div>

                <div class="ki-creator">
                  <div class="d-flex align-items-center gap-2">
                    <div class="ki-avatar"><?= h($initials) ?></div>
                    <div class="ki-mini"><?= h($creator) ?></div>
                  </div>
                  <span class="ki-btn primary" style="padding:10px 12px;border-radius:999px;">
                    <i class="fa-solid fa-ticket"></i> Regjistrohu
                  </span>
                </div>
              </div>
            </a>
          <?php endforeach; ?>
        </div>

        <!-- Pagination -->
        <?php if ($pages > 1): ?>
          <div class="ki-pager ki-reveal">
            <a class="ki-page <?= $page<=1?'disabled':'' ?>" href="?<?= h(qsMerge(['page'=>$page-1])) ?>"><i class="fa-solid fa-chevron-left"></i></a>

            <?php
              $start = max(1, $page-2);
              $end   = min($pages, $page+2);

              if ($start > 1) {
                echo '<a class="ki-page" href="?'.h(qsMerge(['page'=>1])).'">1</a>';
                if ($start > 2) echo '<span class="ki-page disabled">…</span>';
              }

              for ($i=$start; $i<=$end; $i++){
                $cls = ($i === $page) ? 'ki-page active' : 'ki-page';
                echo '<a class="'.$cls.'" href="?'.h(qsMerge(['page'=>$i])).'">'.$i.'</a>';
              }

              if ($end < $pages) {
                if ($end < $pages - 1) echo '<span class="ki-page disabled">…</span>';
                echo '<a class="ki-page" href="?'.h(qsMerge(['page'=>$pages])).'">'.$pages.'</a>';
              }
            ?>

            <a class="ki-page <?= $page>=$pages?'disabled':'' ?>" href="?<?= h(qsMerge(['page'=>$page+1])) ?>"><i class="fa-solid fa-chevron-right"></i></a>
          </div>
        <?php endif; ?>
      <?php endif; ?>

    </div>
  </section>

</main>

<!-- ================= FILTERS OFFCANVAS ================= -->
<div class="offcanvas offcanvas-end ki-offcanvas" tabindex="-1" id="filtersCanvas" aria-labelledby="filtersCanvasLabel">
  <div class="offcanvas-header">
    <h5 class="offcanvas-title" id="filtersCanvasLabel"><i class="fa-solid fa-sliders me-2"></i>Filtrat</h5>
    <button type="button" class="btn-close" data-bs-dismiss="offcanvas" aria-label="Mbyll"></button>
  </div>
  <div class="offcanvas-body">
    <form method="get" class="ki-form">
      <div class="mb-3">
        <label class="form-label">Kërkim</label>
        <input type="text" class="form-control" name="search" value="<?= h($q) ?>" placeholder="p.sh. Cybersecurity, Excel...">
      </div>

      <div class="mb-3">
        <label class="form-label">Kategoria</label>
        <select class="form-select" name="category">
          <option value="">Të gjitha</option>
          <?php foreach ($catRows as $c): ?>
            <option value="<?= h($c) ?>" <?= $cat===$c?'selected':'' ?>><?= h($c) ?></option>
          <?php endforeach; ?>
        </select>
      </div>

      <div class="mb-3">
        <label class="form-label">Data</label>
        <select class="form-select" name="date">
          <option value="upcoming" <?= $dateF==='upcoming'?'selected':'' ?>>Të ardhshme</option>
          <option value="week" <?= $dateF==='week'?'selected':'' ?>>Këtë javë</option>
          <option value="month" <?= $dateF==='month'?'selected':'' ?>>Këtë muaj</option>
        </select>
      </div>

      <div class="mb-3">
        <label class="form-label">Rendit sipas</label>
        <select class="form-select" name="sort">
          <option value="soonest" <?= $sort==='soonest'?'selected':'' ?>>Datës (më të afërtat)</option>
          <option value="popular" <?= $sort==='popular'?'selected':'' ?>>Më të popullarët</option>
          <option value="newest" <?= $sort==='newest'?'selected':'' ?>>Më të rinjtë</option>
        </select>
      </div>

      <input type="hidden" name="page" value="1">

      <div class="d-grid gap-2 mt-2">
        <button class="ki-btn primary" type="submit"><i class="fa-solid fa-check"></i> Apliko</button>
        <a class="ki-btn" href="events.php"><i class="fa-solid fa-eraser"></i> Reset</a>
      </div>

      <div class="mt-3" style="color: rgba(11,18,32,.68); font-weight:800;">
        <i class="fa-regular fa-circle-check me-1" style="color:#16a34a;"></i>
        Tip: përdor foto të forta + tituj të shkurtër për rritje klikimesh.
      </div>
    </form>
  </div>
</div>

<?php include __DIR__ . '/footer.php'; ?>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
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
</script>

</body>
</html>
