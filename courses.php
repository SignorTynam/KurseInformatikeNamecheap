<?php
// promotions_public.php — Revamp KI v2 (match index.php)
// - Kategoritë = LABEL-at e promocioneve
// - Link: promotion_details.php?id=...
declare(strict_types=1);
session_start();

function h(?string $s): string { return htmlspecialchars($s ?? '', ENT_QUOTES, 'UTF-8'); }

// ====== Lexo parametra filtri ======
$q        = trim($_GET['q'] ?? '');
$cat      = trim($_GET['category'] ?? '');              // label
$level    = trim($_GET['level'] ?? '');                 // beginner|intermediate|advanced|all
$price    = trim($_GET['price'] ?? '');                 // free|paid|0-100|100-250|250+
$sort     = trim($_GET['sort'] ?? 'new');               // new|low-high|high-low|hours|name
$page     = max(1, (int)($_GET['page'] ?? 1));
$perPage  = 12;

$hasDB = false; $pdo = null;
if (file_exists(__DIR__ . '/database.php')) {
  require_once __DIR__ . '/database.php';
  if (isset($pdo) && $pdo instanceof PDO) $hasDB = true;
}

// Ndihmës: querystring pa disa param
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

// Helper për level label
function levelText(?string $lvl): string {
  $lvl = strtoupper($lvl ?? '');
  return match($lvl){
    'BEGINNER'     => 'Fillestar',
    'INTERMEDIATE' => 'Mesatar',
    'ADVANCED'     => 'I avancuar',
    default        => 'Për të gjithë'
  };
}
function money(?float $v): string {
  if ($v === null) return '—';
  if ($v <= 0) return 'Falas';
  return '€' . number_format($v, 0);
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


// ==================== Kategoritë (chips) nga label ====================
$chips = ['NEW','HOT','CERTIFIKUAR','FLASH','EARLY BIRD','-25%','-40%','BUNDLE','INTENSIV'];
if ($hasDB) {
  try {
    $sql = "SELECT label, COUNT(*) cnt FROM promoted_courses
            WHERE label IS NOT NULL AND label <> ''
            GROUP BY label ORDER BY cnt DESC, label ASC LIMIT 16";
    $rows = $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC) ?: [];
    if ($rows) $chips = array_map(fn($r) => (string)$r['label'], $rows);
  } catch (Throwable $e) { /* fallback chips */ }
}

// ==================== Lexo promocionet ====================
$total = 0; $items = [];

if ($hasDB) {
  try {
    $where = []; $p = [];

    if ($q !== '') {
      $where[] = "(name LIKE :q OR short_desc LIKE :q)";
      $p[':q'] = '%'.$q.'%';
    }
    if ($cat !== '') {
      $where[] = "label = :label";
      $p[':label'] = $cat;
    }
    if ($level !== '') {
      $lvlMap = ['beginner'=>'BEGINNER','intermediate'=>'INTERMEDIATE','advanced'=>'ADVANCED','all'=>'ALL'];
      $lvlVal = $lvlMap[strtolower($level)] ?? strtoupper($level);
      $where[] = "level = :lvl";
      $p[':lvl'] = $lvlVal;
    }
    if ($price !== '') {
      if ($price === 'free') { $where[] = "price = 0"; }
      elseif ($price === 'paid') { $where[] = "price > 0"; }
      elseif (preg_match('~^(\d+)-(\d+)$~', $price, $m)) { $where[] = "price BETWEEN :p1 AND :p2"; $p[':p1']=(int)$m[1]; $p[':p2']=(int)$m[2]; }
      elseif ($price === '250+') { $where[] = "price >= 250"; }
    }
    $whereSql = $where ? 'WHERE '.implode(' AND ', $where) : '';

    // Renditja
    $order = "ORDER BY created_at DESC";
    switch ($sort) {
      case 'low-high':  $order = "ORDER BY price IS NULL, price ASC, created_at DESC"; break;
      case 'high-low':  $order = "ORDER BY price IS NULL, price DESC, created_at DESC"; break;
      case 'hours':     $order = "ORDER BY hours_total DESC, created_at DESC"; break;
      case 'name':      $order = "ORDER BY name ASC"; break;
      case 'new': default: $order = "ORDER BY created_at DESC"; break;
    }

    // Count
    $stmtC = $pdo->prepare("SELECT COUNT(*) FROM promoted_courses $whereSql");
    $stmtC->execute($p);
    $total = (int)$stmtC->fetchColumn();

    $offset = ($page-1)*$perPage;
    $sel = "id, name, short_desc, label, badge_color, hours_total, price, old_price, level, photo, created_at";
    $stmt = $pdo->prepare("SELECT $sel FROM promoted_courses $whereSql $order LIMIT :lim OFFSET :off");
    foreach ($p as $k=>$v) $stmt->bindValue($k, $v);
    $stmt->bindValue(':lim', $perPage, PDO::PARAM_INT);
    $stmt->bindValue(':off', $offset, PDO::PARAM_INT);
    $stmt->execute();
    $items = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
  } catch (Throwable $e) {
    $hasDB = false;
  }
}

// Fallback (pa DB) — demo
if (!$hasDB) {
  $demo = [
    ['id'=>1,'name'=>'Python Intensiv (4 javë)','short_desc'=>'Bootcamp intensiv me projekte','label'=>'HOT','badge_color'=>'#EF4444','hours_total'=>40,'price'=>179,'old_price'=>229,'level'=>'BEGINNER','photo'=>'','created_at'=>date('Y-m-d H:i:s')],
    ['id'=>2,'name'=>'Excel për biznes','short_desc'=>'Formulat, Pivot, Dashboard','label'=>'NEW','badge_color'=>'#22c55e','hours_total'=>24,'price'=>89,'old_price'=>129,'level'=>'ALL','photo'=>'','created_at'=>date('Y-m-d H:i:s')],
    ['id'=>3,'name'=>'Italiane A1','short_desc'=>'Gramatikë & bisedë bazë','label'=>'-25%','badge_color'=>'#F0B323','hours_total'=>30,'price'=>120,'old_price'=>160,'level'=>'BEGINNER','photo'=>'','created_at'=>date('Y-m-d H:i:s')],
    ['id'=>4,'name'=>'Web Full-Stack','short_desc'=>'HTML/CSS/JS + PHP/MySQL','label'=>'BUNDLE','badge_color'=>'#0ea5e9','hours_total'=>80,'price'=>320,'old_price'=>420,'level'=>'INTERMEDIATE','photo'=>'','created_at'=>date('Y-m-d H:i:s')],
  ];

  $arr = array_values(array_filter($demo, function($c) use($q,$cat,$level,$price){
    if ($q && stripos($c['name'].$c['short_desc'], $q) === false) return false;
    if ($cat && strcasecmp($c['label'],$cat)!==0) return false;
    if ($level){
      $map=['beginner'=>'BEGINNER','intermediate'=>'INTERMEDIATE','advanced'=>'ADVANCED','all'=>'ALL'];
      $lv = $map[strtolower($level)] ?? strtoupper($level);
      if (($c['level'] ?? '') !== $lv) return false;
    }
    $pr=$price;
    $v=(float)($c['price'] ?? 0);
    if ($pr==='free' && $v!=0) return false;
    if ($pr==='paid' && $v<=0) return false;
    if (preg_match('~^(\d+)-(\d+)$~',$pr,$m)){ if($v<(int)$m[1]||$v>(int)$m[2]) return false; }
    if ($pr==='250+' && $v<250) return false;
    return true;
  }));
  usort($arr,function($a,$b) use($sort){
    return match($sort){
      'low-high' => ($a['price']??0) <=> ($b['price']??0),
      'high-low' => ($b['price']??0) <=> ($a['price']??0),
      'hours'    => ($b['hours_total']??0) <=> ($a['hours_total']??0),
      'name'     => strcasecmp($a['name'],$b['name']),
      default    => strcmp($b['created_at'],$a['created_at']),
    };
  });
  $total = count($arr);
  $items = array_slice($arr, ($page-1)*$perPage, $perPage);
}

$pages = max(1, (int)ceil($total / $perPage));

// Spotlight (për reklamim): merr të parin nga faqja aktuale
$spot = $items[0] ?? null;
?>
<!doctype html>
<html lang="sq">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <title>Promocionet — kurseinformatike.com</title>
  <meta name="description" content="Shfleto promocionet e kurseve në kurseinformatike.com dhe regjistrohu menjëherë.">
  <link rel="icon" href="image/favicon.ico" type="image/x-icon">

  <!-- Bootstrap & Icons -->
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
  <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css" rel="stylesheet">

  <!-- Fonts -->
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@600;700;800;900&family=Roboto:wght@400;500;700&display=swap" rel="stylesheet">

  <style>
    /* ==========================================================
       KI v2 Promotions — match index.php
       Full-bleed • Glass • Tiles • Offcanvas filters
    ========================================================== */
    body.ki-promos{
      --ki-primary:#2A4B7C;
      --ki-primary-2:#1d3a63;
      --ki-secondary:#F0B323;
      --ki-accent:#FF6B6B;

      --ki-ink:#0b1220;
      --ki-text:#0f172a;
      --ki-muted:#6b7280;
      --ki-line: rgba(15, 23, 42, .12);

      --ki-sand:#fbfaf7;
      --ki-ice:#f7fbff;
      --ki-night:#0b1220;

      --ki-r: 22px;
      --ki-r2: 28px;
      --ki-wrap: 1180px;

      --ki-shadow: 0 24px 60px rgba(11, 18, 32, .16);
      --ki-shadow-soft: 0 18px 44px rgba(11, 18, 32, .10);
    }

    body.ki-promos{
      margin:0;
      font-family: Roboto, system-ui, -apple-system, Segoe UI, Arial, sans-serif;
      color: var(--ki-text);
      background: radial-gradient(900px 600px at 10% 0%, rgba(42,75,124,.10), transparent 55%),
                  radial-gradient(900px 600px at 90% 10%, rgba(240,179,35,.12), transparent 55%),
                  linear-gradient(180deg, var(--ki-ice), var(--ki-sand));
    }

    .ki-wrap{
      width: min(var(--ki-wrap), calc(100% - 32px));
      margin-inline: auto;
    }

    .ki-h1{
      font-family: Poppins, system-ui, sans-serif;
      font-weight: 900;
      letter-spacing: .1px;
      line-height: 1.05;
      margin: 0;
      color: var(--ki-ink);
    }
    .ki-h2{
      font-family: Poppins, system-ui, sans-serif;
      font-weight: 900;
      letter-spacing: .1px;
      line-height: 1.1;
      margin: 0;
      color: var(--ki-ink);
    }
    .ki-lead{
      color: var(--ki-muted);
      line-height: 1.55;
      margin: 0;
      font-size: 1.03rem;
    }
    .ki-kicker{
      display:inline-flex; align-items:center; gap:10px;
      padding: 8px 12px;
      border-radius: 999px;
      border: 1px solid rgba(15,23,42,.10);
      background: rgba(255,255,255,.35);
      backdrop-filter: blur(10px);
      -webkit-backdrop-filter: blur(10px);
      font-weight: 900;
      color: rgba(11,18,32,.84);
    }
    .ki-kicker i{ color: var(--ki-secondary); }

    .ki-section{ padding: 44px 0; }
    .ki-band{
      border-top: 1px solid rgba(15,23,42,.08);
      border-bottom: 1px solid rgba(15,23,42,.08);
      background: linear-gradient(180deg, rgba(42,75,124,.05), rgba(240,179,35,.04));
    }

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
      text-decoration:none;
      color: rgba(11,18,32,.90);
      background: rgba(255,255,255,.35);
      backdrop-filter: blur(10px);
      -webkit-backdrop-filter: blur(10px);
    }
    .ki-btn:hover{ transform: translateY(-1px); background: rgba(255,255,255,.55); }
    .ki-btn.primary{
      background: linear-gradient(135deg, var(--ki-secondary), #ffd36a);
      border-color: rgba(240,179,35,.55);
      color: #111827;
      backdrop-filter: none;
    }
    .ki-btn.dark{
      background: rgba(11,18,32,.92);
      border-color: rgba(11,18,32,.92);
      color: #fff;
      backdrop-filter: none;
    }

    /* Hero */
    .ki-hero{
      padding: 34px 0 18px;
      position: relative;
    }
    .ki-hero-grid{
      display:grid;
      grid-template-columns: 1.15fr .85fr;
      gap: 16px;
      align-items: stretch;
    }
    @media (max-width: 992px){
      .ki-hero-grid{ grid-template-columns: 1fr; }
    }

    .ki-glass{
      border-radius: var(--ki-r2);
      border: 1px solid rgba(15,23,42,.12);
      background: rgba(255,255,255,.30);
      backdrop-filter: blur(12px);
      -webkit-backdrop-filter: blur(12px);
      box-shadow: var(--ki-shadow-soft);
    }

    .ki-search{
      margin-top: 14px;
      padding: 12px;
    }
    .ki-search .input-group{
      border-radius: 18px;
      overflow:hidden;
      border: 1px solid rgba(15,23,42,.12);
      background: rgba(255,255,255,.20);
    }
    .ki-search .input-group-text{
      background: transparent;
      border: 0;
      color: rgba(11,18,32,.62);
    }
    .ki-search .form-control{
      background: transparent;
      border: 0;
      box-shadow:none !important;
      font-weight: 700;
      color: rgba(11,18,32,.86);
    }
    .ki-search .form-control::placeholder{ color: rgba(11,18,32,.52); }
    .ki-search .btn{
      border:0;
      background: transparent;
      font-weight: 900;
    }
    .ki-search .btn:hover{ background: rgba(255,255,255,.40); }

    /* Chips */
    .ki-chiprow{ display:flex; flex-wrap:wrap; gap:10px; margin-top: 12px; }
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
      transition: transform .15s ease, background .15s ease, border-color .15s ease;
      text-decoration:none;
    }
    .ki-chip:hover{ transform: translateY(-1px); background: rgba(255,255,255,.45); }
    .ki-chip.active{
      background: rgba(240,179,35,.24);
      border-color: rgba(240,179,35,.40);
      color: rgba(11,18,32,.92);
    }

    /* Active filters pills */
    .ki-pillrow{ display:flex; flex-wrap:wrap; gap:10px; margin-top: 12px; }
    .ki-pill{
      display:inline-flex; align-items:center; gap:10px;
      padding: 8px 12px;
      border-radius: 999px;
      border: 1px solid rgba(15,23,42,.12);
      background: rgba(255,255,255,.26);
      font-weight: 900;
      color: rgba(11,18,32,.78);
    }
    .ki-pill a{
      display:inline-flex; align-items:center; justify-content:center;
      width: 28px; height: 28px;
      border-radius: 999px;
      background: rgba(11,18,32,.08);
      color: rgba(11,18,32,.70);
      text-decoration:none;
    }
    .ki-pill a:hover{ background: rgba(240,179,35,.22); }

    /* Spotlight tile (right) */
    .ki-spot{
      border-radius: var(--ki-r2);
      overflow:hidden;
      position: relative;
      min-height: 320px;
      box-shadow: var(--ki-shadow);
      border: 1px solid rgba(15,23,42,.10);
      background: #111;
    }
    .ki-spot img{ width:100%; height:100%; object-fit:cover; display:block; transform: scale(1.02); }
    .ki-spot:after{
      content:"";
      position:absolute; inset:0;
      background: linear-gradient(180deg, rgba(11,18,32,.10) 0%, rgba(11,18,32,.76) 72%, rgba(11,18,32,.92) 100%);
      pointer-events:none;
    }
    .ki-spot-info{
      position:absolute; inset:0;
      padding: 16px;
      display:flex; flex-direction:column; justify-content:flex-end;
      z-index: 2;
      color:#fff;
    }
    .ki-badges{ display:flex; gap:8px; flex-wrap:wrap; }
    .ki-badge{
      padding: 6px 10px;
      border-radius: 999px;
      border: 1px solid rgba(255,255,255,.18);
      background: rgba(255,255,255,.10);
      color: rgba(255,255,255,.92);
      font-weight: 900;
      font-size: .82rem;
    }
    .ki-spot-title{
      font-family: Poppins, system-ui, sans-serif;
      font-weight: 900;
      letter-spacing: .1px;
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
    .ki-toolbar .left, .ki-toolbar .right{ display:flex; gap:10px; flex-wrap:wrap; align-items:center; }
    .ki-toolbar .meta{
      font-weight: 900;
      color: rgba(11,18,32,.72);
    }
    .ki-select{
      border-radius: 14px;
      border: 1px solid rgba(15,23,42,.12);
      background: rgba(255,255,255,.28);
      padding: .55rem .75rem;
      font-weight: 900;
      color: rgba(11,18,32,.84);
      box-shadow:none !important;
    }

    /* Grid tiles */
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
      min-height: 240px;
      text-decoration:none;
      display:block;
      transition: transform .18s ease, box-shadow .18s ease;
    }
    .ki-tile:hover{ transform: translateY(-2px); box-shadow: var(--ki-shadow); }
    .ki-tile img{ width:100%; height:100%; object-fit:cover; display:block; transform: scale(1.02); }
    .ki-tile:after{
      content:"";
      position:absolute; inset:0;
      background: linear-gradient(180deg, rgba(11,18,32,.10) 0%, rgba(11,18,32,.80) 76%, rgba(11,18,32,.94) 100%);
      pointer-events:none;
    }
    .ki-tile-info{
      position:absolute; inset:0;
      padding: 14px;
      display:flex; flex-direction:column; justify-content:flex-end;
      z-index:2;
      color:#fff;
    }
    .ki-title{
      font-family: Poppins, system-ui, sans-serif;
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
      color: rgba(255,255,255,.78);
      font-weight: 800;
      font-size: .90rem;
    }
    .ki-price{
      display:flex; align-items:baseline; gap:10px; justify-content: space-between;
      margin-top: 10px;
      font-weight: 900;
    }
    .ki-price .now{ color: #fff; }
    .ki-price .old{ color: rgba(255,255,255,.60); text-decoration: line-through; font-weight: 800; font-size: .92rem; }

    /* Empty state */
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
    .ki-page.disabled{
      opacity: .45;
      pointer-events:none;
    }

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

    /* HERO fix: Spotlight mbush lartësinë e kolonës */
    .ki-spot{
      height: 100%;
      display: block;
    }
    .ki-spot img{
      height: 100%;
    }
  </style>
</head>

<body class="ki-promos">

<?php
// Navbar — prefero KI public navbar
if (file_exists(__DIR__ . '/navbar_public.php')) {
  include __DIR__ . '/navbar_public.php';
} elseif (file_exists(__DIR__ . '/navbar.php')) {
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
            <i class="fa-solid fa-tag"></i>
            <span>Promocione aktive • Oferta të kufizuara</span>
          </div>

          <h1 class="ki-h1 mt-3">Zgjidh promocionin dhe regjistrohu menjëherë</h1>
          <p class="ki-lead mt-3">
            Filtra të shpejtë, renditje praktike dhe kartela vizuale “premium”.
            Qëllimi: të shesim kursin, jo thjesht ta listojmë.
          </p>

          <div class="ki-glass ki-search ki-reveal" style="transition-delay:.06s;">
            <form method="get" class="input-group" aria-label="Kërko promocione">
              <input type="hidden" name="category" value="<?= h($cat) ?>">
              <input type="hidden" name="level" value="<?= h($level) ?>">
              <input type="hidden" name="price" value="<?= h($price) ?>">
              <input type="hidden" name="sort" value="<?= h($sort) ?>">
              <span class="input-group-text"><i class="fa-solid fa-magnifying-glass"></i></span>
              <input class="form-control" type="search" name="q" value="<?= h($q) ?>" placeholder="Kërko: Python, Excel, -25%, Intensiv..." aria-label="Kërko">
              <button class="btn" type="submit"><i class="fa-solid fa-arrow-right"></i></button>
            </form>

            <div class="ki-chiprow">
              <a class="ki-chip <?= $cat===''?'active':'' ?>" href="?<?= h(qsMerge(['category'=>'','page'=>1])) ?>">
                <i class="fa-solid fa-layer-group"></i> Të gjitha
              </a>
              <?php foreach ($chips as $c): ?>
                <a class="ki-chip <?= ($cat===$c)?'active':'' ?>" href="?<?= h(qsMerge(['category'=>$c,'page'=>1])) ?>">
                  <i class="fa-regular fa-folder"></i><?= h($c) ?>
                </a>
              <?php endforeach; ?>
            </div>

            <?php if($q || $cat || $level || $price): ?>
              <div class="ki-pillrow">
                <?php if($q): ?><span class="ki-pill">Kërkim: “<?= h($q) ?>” <a href="?<?= h(qsWithout(['q','page'])) ?>" aria-label="Hiq kërkimin"><i class="fa-solid fa-xmark"></i></a></span><?php endif; ?>
                <?php if($cat): ?><span class="ki-pill">Kategori: <?= h($cat) ?> <a href="?<?= h(qsWithout(['category','page'])) ?>" aria-label="Hiq kategorinë"><i class="fa-solid fa-xmark"></i></a></span><?php endif; ?>
                <?php if($level): ?><span class="ki-pill">Niveli: <?= h($level) ?> <a href="?<?= h(qsWithout(['level','page'])) ?>" aria-label="Hiq nivelin"><i class="fa-solid fa-xmark"></i></a></span><?php endif; ?>
                <?php if($price): ?><span class="ki-pill">Çmimi: <?= h($price) ?> <a href="?<?= h(qsWithout(['price','page'])) ?>" aria-label="Hiq çmimin"><i class="fa-solid fa-xmark"></i></a></span><?php endif; ?>
              </div>
            <?php endif; ?>
          </div>

          <div class="ki-glass ki-toolbar ki-reveal" style="transition-delay:.10s;">
            <div class="left">
              <div class="meta">
                <strong><?= number_format($total) ?></strong> promocione
                <span class="ms-2" style="color:rgba(11,18,32,.55);font-weight:900;">|</span>
                <span class="ms-2">Faqja <strong><?= (int)$page ?></strong> / <?= (int)$pages ?></span>
              </div>
            </div>

            <div class="right">
              <select class="ki-select" aria-label="Rendit sipas" onchange="location.href='?'+new URLSearchParams({...Object.fromEntries(new URLSearchParams(window.location.search)), sort:this.value, page:1}).toString()">
                <option value="new" <?= $sort==='new'?'selected':'' ?>>Më të rinjtë</option>
                <option value="low-high" <?= $sort==='low-high'?'selected':'' ?>>Çmimi: Ul → Lart</option>
                <option value="high-low" <?= $sort==='high-low'?'selected':'' ?>>Çmimi: Lart → Ul</option>
                <option value="hours" <?= $sort==='hours'?'selected':'' ?>>Orët</option>
                <option value="name" <?= $sort==='name'?'selected':'' ?>>Emri</option>
              </select>

              <button class="ki-btn dark" type="button" data-bs-toggle="offcanvas" data-bs-target="#filtersCanvas" aria-controls="filtersCanvas">
                <i class="fa-solid fa-sliders"></i> Filtra
              </button>

              <a class="ki-btn" href="promotions_public.php">
                <i class="fa-solid fa-eraser"></i> Reset
              </a>
            </div>
          </div>
        </div>

        <!-- Spotlight (reklamim) -->
        <div class="ki-reveal" style="transition-delay:.08s;">
          <?php if ($spot):
            $img = promoPhotoUrl($spot['photo'] ?? null);
            $id    = (int)$spot['id'];
            $href  = "promotion_details.php?id={$id}";
            $priceV= $spot['price'] !== null ? (float)$spot['price'] : null;
            $oldV  = $spot['old_price'] !== null ? (float)$spot['old_price'] : null;
            $hasDisc = ($priceV !== null && $oldV !== null && $oldV>0 && $priceV >=0 && $priceV < $oldV);
            $disc  = $hasDisc ? (int)round((($oldV-$priceV)/$oldV)*100) : null;
            $label = trim((string)($spot['label'] ?? ''));
            $bclr  = trim((string)($spot['badge_color'] ?? '')) ?: '#000000cc';
            $lvlTx = levelText($spot['level'] ?? 'ALL');
            $hours = (int)($spot['hours_total'] ?? 0);
          ?>
            <a class="ki-spot ki-reveal" style="transition-delay:.08s;" href="<?= h($href) ?>" aria-label="Spotlight promocion">
              <img src="<?= h($img) ?>" alt="<?= h((string)$spot['name']) ?>" loading="lazy" decoding="async">
              <div class="ki-spot-info">
                <div class="ki-badges">
                  <?php if ($label): ?>
                    <span class="ki-badge">
                      <span style="display:inline-block;width:8px;height:8px;border-radius:50%;background:<?= h($bclr) ?>;margin-right:6px;"></span>
                      <?= h($label) ?>
                    </span>
                  <?php endif; ?>
                  <?php if ($disc !== null): ?>
                    <span class="ki-badge"><i class="fa-solid fa-percent me-1"></i>-<?= (int)$disc ?>%</span>
                  <?php endif; ?>
                  <span class="ki-badge"><i class="fa-solid fa-bolt me-1"></i>Spotlight</span>
                </div>

                <div class="ki-spot-title"><?= h((string)$spot['name']) ?></div>

                <div class="ki-spot-meta">
                  <span><i class="fa-regular fa-clock me-1"></i><?= $hours ?> orë</span>
                  <span><i class="fa-solid fa-signal me-1"></i><?= h($lvlTx) ?></span>
                  <span><i class="fa-solid fa-tag me-1"></i><?= h(money($priceV)) ?><?php if($hasDisc): ?>
                    <span style="opacity:.75;text-decoration:line-through;margin-left:8px;"><?= h(money($oldV)) ?></span>
                  <?php endif; ?></span>
                </div>

                <div class="d-flex gap-2 flex-wrap mt-3">
                  <span class="ki-btn primary"><i class="fa-solid fa-arrow-right"></i> Shiko detajet</span>
                  <span class="ki-btn"><i class="fa-solid fa-circle-play"></i> Shiko programin</span>
                </div>
              </div>
            </a>
          <?php else: ?>
            <div class="ki-glass p-3 ki-reveal" style="transition-delay:.08s; min-height:320px;display:flex;align-items:center;justify-content:center;">
              <div style="text-align:center;color:rgba(11,18,32,.70);font-weight:900;">
                Nuk ka të dhëna për spotlight.
              </div>
            </div>
          <?php endif; ?>
        </div>

      </div>
    </div>
  </section>

  <!-- ================= GRID ================= -->
  <section class="ki-section ki-band">
    <div class="ki-wrap">
      <?php if (!$total): ?>
        <div class="ki-empty ki-reveal">
          <i class="fa-regular fa-face-meh-blank me-1"></i>
          Nuk u gjet asnjë promocion me këta filtra.
          <div class="mt-3">
            <a class="ki-btn" href="promotions_public.php"><i class="fa-solid fa-eraser"></i> Reset filtrat</a>
          </div>
        </div>
      <?php else: ?>
        <div class="ki-grid">
          <?php foreach ($items as $c):
            $img = promoPhotoUrl($c['photo'] ?? null);
            $id  = (int)$c['id'];
            $href= "promotion_details.php?id={$id}";
            $lvlText = levelText($c['level'] ?? 'ALL');
            $hours   = (int)($c['hours_total'] ?? 0);
            $priceV  = $c['price'] !== null ? (float)$c['price'] : null;
            $oldV    = $c['old_price'] !== null ? (float)$c['old_price'] : null;
            $hasDisc = ($priceV !== null && $oldV !== null && $oldV>0 && $priceV >=0 && $priceV < $oldV);
            $disc    = $hasDisc ? (int)round((($oldV-$priceV)/$oldV)*100) : null;
            $label   = trim((string)($c['label'] ?? ''));
            $bclr    = trim((string)($c['badge_color'] ?? '')) ?: '#000000cc';
            $desc    = trim((string)($c['short_desc'] ?? ''));
            $desc    = $desc !== '' ? mb_strimwidth($desc, 0, 90, '…', 'UTF-8') : '';
          ?>
            <a class="ki-tile ki-reveal" href="<?= h($href) ?>">
              <img src="<?= h($img) ?>" alt="<?= h((string)$c['name']) ?>" loading="lazy" decoding="async">
              <div class="ki-tile-info">
                <div class="ki-badges">
                  <?php if ($label): ?>
                    <span class="ki-badge">
                      <span style="display:inline-block;width:8px;height:8px;border-radius:50%;background:<?= h($bclr) ?>;margin-right:6px;"></span>
                      <?= h($label) ?>
                    </span>
                  <?php endif; ?>
                  <?php if ($disc !== null): ?>
                    <span class="ki-badge"><i class="fa-solid fa-percent me-1"></i>-<?= (int)$disc ?>%</span>
                  <?php endif; ?>
                </div>

                <div class="ki-title"><?= h((string)$c['name']) ?></div>
                <?php if ($desc): ?>
                  <p class="ki-desc"><?= h($desc) ?></p>
                <?php endif; ?>

                <div class="ki-meta">
                  <span><i class="fa-regular fa-clock me-1"></i><?= $hours ?> orë</span>
                  <span><i class="fa-solid fa-signal me-1"></i><?= h($lvlText) ?></span>
                  <span><i class="fa-regular fa-calendar-days me-1"></i><?= h(date('d.m.Y', strtotime((string)$c['created_at']))) ?></span>
                </div>

                <div class="ki-price">
                  <div class="now"><i class="fa-solid fa-tag me-1"></i><?= h(money($priceV)) ?></div>
                  <?php if ($hasDisc): ?>
                    <div class="old"><?= h(money($oldV)) ?></div>
                  <?php endif; ?>
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
    <form method="get" class="ki-form" id="filtersForm">
      <div class="mb-3">
        <label class="form-label">Kërkim</label>
        <input type="text" class="form-control" name="q" value="<?= h($q) ?>" placeholder="p.sh. Python, Excel...">
      </div>

      <div class="mb-3">
        <label class="form-label">Kategori (Label)</label>
        <select class="form-select" name="category">
          <option value="">Të gjitha</option>
          <?php foreach ($chips as $c): ?>
            <option value="<?= h($c) ?>" <?= $cat===$c?'selected':'' ?>><?= h($c) ?></option>
          <?php endforeach; ?>
        </select>
      </div>

      <div class="mb-3">
        <label class="form-label">Niveli</label>
        <select class="form-select" name="level">
          <option value="">Të gjitha</option>
          <option value="beginner" <?= $level==='beginner'?'selected':'' ?>>Fillestar</option>
          <option value="intermediate" <?= $level==='intermediate'?'selected':'' ?>>Mesatar</option>
          <option value="advanced" <?= $level==='advanced'?'selected':'' ?>>I avancuar</option>
          <option value="all" <?= $level==='all'?'selected':'' ?>>Për të gjithë</option>
        </select>
      </div>

      <div class="mb-3">
        <label class="form-label">Çmimi</label>
        <select class="form-select" name="price">
          <option value="">Të gjitha</option>
          <option value="free" <?= $price==='free'?'selected':'' ?>>Falas</option>
          <option value="paid" <?= $price==='paid'?'selected':'' ?>>Të paguara</option>
          <option value="0-100" <?= $price==='0-100'?'selected':'' ?>>€0 - €100</option>
          <option value="100-250" <?= $price==='100-250'?'selected':'' ?>>€100 - €250</option>
          <option value="250+" <?= $price==='250+'?'selected':'' ?>>€250+</option>
        </select>
      </div>

      <div class="mb-3">
        <label class="form-label">Rendit sipas</label>
        <select class="form-select" name="sort">
          <option value="new" <?= $sort==='new'?'selected':'' ?>>Më të rinjtë</option>
          <option value="low-high" <?= $sort==='low-high'?'selected':'' ?>>Çmimi: Ul → Lart</option>
          <option value="high-low" <?= $sort==='high-low'?'selected':'' ?>>Çmimi: Lart → Ul</option>
          <option value="hours" <?= $sort==='hours'?'selected':'' ?>>Orët</option>
          <option value="name" <?= $sort==='name'?'selected':'' ?>>Emri</option>
        </select>
      </div>

      <input type="hidden" name="page" value="1">

      <div class="d-grid gap-2 mt-2">
        <button class="ki-btn primary" type="submit"><i class="fa-solid fa-check"></i> Apliko</button>
        <a class="ki-btn" href="promotions_public.php"><i class="fa-solid fa-eraser"></i> Reset</a>
      </div>

      <div class="mt-3" style="color: rgba(11,18,32,.68); font-weight:800;">
        <i class="fa-regular fa-circle-check me-1" style="color:#16a34a;"></i>
        Tip: përdor LABEL si “HOT”, “NEW”, “-25%” për reklamim më agresiv.
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
