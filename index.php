<?php
declare(strict_types=1);
session_start();

/* ================= Helpers ================= */
function h(?string $s): string { return htmlspecialchars($s ?? '', ENT_QUOTES, 'UTF-8'); }
function levelText(?string $lvl): string {
  return match (strtoupper((string)$lvl)) {
    'BEGINNER'     => 'Fillestar',
    'INTERMEDIATE' => 'Mesatar',
    'ADVANCED'     => 'I avancuar',
    'ALL'          => 'Për të gjithë',
    default        => '—',
  };
}
function money(?float $v): string {
  if ($v === null) return '';
  return '€' . number_format((float)$v, 0);
}

function promoPhotoUrl(?string $photo): string {
  $base = str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? ''));
  $base = rtrim($base, '/');
  if ($base === '.' || $base === '/') $base = '';
  $fallback = $base . '/virtuale/uploads/promotions/course_placeholder.jpg';
  $p = trim((string)$photo);
  if ($p === '') return $fallback;
  if (preg_match('~^https?://~i', $p)) return $p;
  $p = str_replace('\\', '/', $p);
  $p = ltrim($p, '/');
  if (str_starts_with($p, 'virtuale/uploads/promotions/')) return $base . '/' . $p;
  if (str_starts_with($p, 'uploads/promotions/')) return $base . '/virtuale/' . $p;
  return $base . '/virtuale/uploads/promotions/' . basename($p);
}

/* ================= Defaults ================= */
$stats = ['courses'=>250, 'users'=>1200, 'hours'=>350, 'success'=>98];
$courses = [];
$hasDB = false;

/* ================= Optional DB ================= */
if (file_exists(__DIR__ . '/database.php')) {
  require_once __DIR__ . '/database.php';
  if (isset($pdo) && $pdo instanceof PDO) $hasDB = true;
}

if ($hasDB) {
  try {
    $stats['courses'] = (int)($pdo->query("SELECT COUNT(*) FROM promoted_courses")->fetchColumn() ?: $stats['courses']);
    $stats['hours']   = (int)($pdo->query("SELECT COALESCE(SUM(hours_total),0) FROM promoted_courses")->fetchColumn() ?: $stats['hours']);
    try {
      $stats['users'] = (int)($pdo->query("SELECT COUNT(*) FROM users")->fetchColumn() ?: $stats['users']);
    } catch (Throwable $e) { /* ignore */ }
  } catch (Throwable $e) { /* keep defaults */ }

  try {
    $stmt = $pdo->query("
      SELECT
        id, name, short_desc, description, hours_total, price, old_price,
        level, label, badge_color, photo, video_url, created_at
      FROM promoted_courses
      ORDER BY
        (CASE
           WHEN old_price IS NOT NULL AND old_price>0 AND price IS NOT NULL AND price>=0
           THEN (old_price - price) / old_price
           ELSE 0
         END) DESC,
        created_at DESC
      LIMIT 10
    ");
    $courses = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
  } catch (Throwable $e) { $courses = []; }
}

/* ================= Fallback content (pa DB) ================= */
if (!$courses) {
  $courses = [
    [
      'id'=>1,'name'=>'Python nga Zero — Intenziv',
      'short_desc'=>'Fillon sot: bazat, ushtrime, projekt real.',
      'description'=>'',
      'hours_total'=>40,'price'=>180,'old_price'=>250,
      'level'=>'BEGINNER','label'=>'HOT','badge_color'=>'#F97316',
      'photo'=>'https://images.unsplash.com/photo-1515879218367-8466d910aaa4?q=80&w=1400&auto=format&fit=crop',
      'video_url'=>'','created_at'=>date('Y-m-d')
    ],
    [
      'id'=>2,'name'=>'Excel Pro & Dashboards',
      'short_desc'=>'Formula, Pivot, raporte dhe dashboard-e.',
      'description'=>'',
      'hours_total'=>30,'price'=>90,'old_price'=>120,
      'level'=>'INTERMEDIATE','label'=>'NEW','badge_color'=>'#1D4ED8',
      'photo'=>'https://images.unsplash.com/photo-1519389950473-47ba0277781c?q=80&w=1400&auto=format&fit=crop',
      'video_url'=>'','created_at'=>date('Y-m-d')
    ],
    [
      'id'=>3,'name'=>'Cybersecurity Basics',
      'short_desc'=>'Koncepte, praktika dhe siguri bazë.',
      'description'=>'',
      'hours_total'=>25,'price'=>0,'old_price'=>80,
      'level'=>'ALL','label'=>'FREE','badge_color'=>'#059669',
      'photo'=>'https://images.unsplash.com/photo-1556157382-97eda2d62296?q=80&w=1400&auto=format&fit=crop',
      'video_url'=>'','created_at'=>date('Y-m-d')
    ],
    [
      'id'=>4,'name'=>'Italiane A1 → A2',
      'short_desc'=>'Gramatikë, të folur dhe ushtrime praktike.',
      'description'=>'',
      'hours_total'=>35,'price'=>120,'old_price'=>160,
      'level'=>'BEGINNER','label'=>'POPULAR','badge_color'=>'#2A4B7C',
      'photo'=>'https://images.unsplash.com/photo-1529070538774-1843cb3265df?q=80&w=1400&auto=format&fit=crop',
      'video_url'=>'','created_at'=>date('Y-m-d')
    ],
  ];
}

/* ================= User ================= */
$isLoggedIn = !empty($_SESSION['user']);
$fullName   = $_SESSION['user']['full_name'] ?? '';
$hiName     = $fullName ? explode(' ', $fullName)[0] : 'Vizitor';

/* ================= Derived ================= */
$heroCourse = $courses[0] ?? null;
$others = array_slice($courses, 1);

/* Quick search chips */
$chips = [
  ['t'=>'Python', 'q'=>'python'],
  ['t'=>'Excel', 'q'=>'excel'],
  ['t'=>'Web', 'q'=>'web'],
  ['t'=>'Java', 'q'=>'java'],
  ['t'=>'Italiane', 'q'=>'italiane'],
  ['t'=>'Cyber', 'q'=>'cybersecurity'],
];
?>
<!doctype html>
<html lang="sq">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <title>kurseinformatike.com — Mëso, zhvillo, certifikohu</title>
  <meta name="description" content="Kurse moderne në IT, Programim dhe gjuhë të huaja. Online, me projekte reale dhe certifikim.">
  <meta property="og:title" content="kurseinformatike.com — Mëso, zhvillo, certifikohu">
  <meta property="og:description" content="Kurse moderne në teknologji dhe gjuhë të huaja. Komunitet aktiv, mbështetje 24/7.">
  <meta property="og:image" content="https://kurseinformatike.com/image/indexBackground.jpg">
  <meta property="og:url" content="https://kurseinformatike.com">
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
       kurseinformatike.com — HOME v2 (komplet i ri)
       Full-bleed sections • Slim typography • Jo “white + cards”
       Scope: body.ki-home
    ========================================================== */
    body.ki-home{
      /* Brand (të tuat) */
      --ki-primary:#2A4B7C;
      --ki-primary-2:#1d3a63;
      --ki-secondary:#F0B323;
      --ki-accent:#FF6B6B;

      /* Neutrals (të reja) */
      --ki-ink:#0b1220;
      --ki-text:#0f172a;
      --ki-muted:#6b7280;
      --ki-line: rgba(15, 23, 42, .12);

      /* Surfaces (jo gjithmonë të bardha) */
      --ki-sand:#fbfaf7;
      --ki-mist:#f2f6ff;
      --ki-ice:#f7fbff;
      --ki-night:#0b1220;

      /* Radii / spacing (slim) */
      --ki-r: 22px;
      --ki-r2: 28px;
      --ki-wrap: 1180px;

      /* Effects */
      --ki-shadow: 0 24px 60px rgba(11, 18, 32, .16);
      --ki-shadow-soft: 0 18px 44px rgba(11, 18, 32, .10);
    }

    body.ki-home{
      margin:0;
      font-family: Roboto, system-ui, -apple-system, Segoe UI, Arial, sans-serif;
      color: var(--ki-text);
      background: radial-gradient(900px 600px at 10% 0%, rgba(42,75,124,.12), transparent 55%),
                  radial-gradient(900px 600px at 90% 10%, rgba(240,179,35,.14), transparent 55%),
                  linear-gradient(180deg, var(--ki-ice), var(--ki-sand));
    }

    a{ color: inherit; text-decoration: none; }
    a:hover{ text-decoration: none; }

    /* Wrap (nuk përdorim container kudo) */
    .ki-wrap{
      width: min(var(--ki-wrap), calc(100% - 32px));
      margin-inline: auto;
    }

    /* Skip link */
    .ki-skip{
      position:absolute; left:-999px; top:auto; width:1px; height:1px; overflow:hidden;
    }
    .ki-skip:focus{
      left: 16px; top: 16px; width:auto; height:auto; padding:10px 12px;
      background:#fff; border:1px solid var(--ki-line); border-radius: 12px; z-index:9999;
      box-shadow: var(--ki-shadow-soft);
    }

    /* Navbar (slim) — nëse navbar.php përdor .navbar */
    .navbar{
      padding-top: .45rem;
      padding-bottom: .45rem;
      background: rgba(255,255,255,.55) !important;
      border-bottom: 1px solid rgba(15,23,42,.10);
      backdrop-filter: blur(10px);
    }
    .navbar .nav-link{ padding-top:.35rem; padding-bottom:.35rem; }
    .navbar .navbar-brand{ font-weight: 900; letter-spacing: .2px; }

    /* Typography */
    .ki-h1{
      font-family: Poppins, system-ui, sans-serif;
      font-weight: 900;
      letter-spacing: .1px;
      line-height: 1.03;
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
      font-size: 1.05rem;
      color: var(--ki-muted);
      line-height: 1.55;
      margin: 0;
    }
    .ki-kicker{
      display:inline-flex; align-items:center; gap:10px;
      padding: 8px 12px;
      border-radius: 999px;
      border: 1px solid rgba(15,23,42,.10);
      background: rgba(255,255,255,.35);
      backdrop-filter: blur(10px);
      font-weight: 700;
      color: rgba(11,18,32,.85);
    }
    .ki-kicker i{ color: var(--ki-secondary); }

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
    .ki-btn.primary{
      background: linear-gradient(135deg, var(--ki-secondary), #ffd36a);
      border-color: rgba(240,179,35,.55);
      color: #111827;
    }
    .ki-btn.ghost{
      background: rgba(255,255,255,.35);
      backdrop-filter: blur(10px);
    }
    .ki-btn.dark{
      background: rgba(11,18,32,.92);
      border-color: rgba(11,18,32,.92);
      color: #fff;
    }

    /* Sections (full-bleed) */
    .ki-section{ padding: 56px 0; }
    .ki-section.tight{ padding: 40px 0; }
    .ki-band{
      border-top: 1px solid rgba(15,23,42,.08);
      border-bottom: 1px solid rgba(15,23,42,.08);
      background: linear-gradient(180deg, rgba(42,75,124,.06), rgba(240,179,35,.05));
    }
    .ki-night{
      background: radial-gradient(900px 600px at 20% 10%, rgba(240,179,35,.18), transparent 55%),
                  radial-gradient(900px 600px at 90% 20%, rgba(42,75,124,.30), transparent 60%),
                  linear-gradient(180deg, #0b1220, #0a1020);
      color: rgba(255,255,255,.92);
      border-top: 1px solid rgba(255,255,255,.10);
      border-bottom: 1px solid rgba(255,255,255,.10);
    }

    /* HERO — split + full-bleed visual */
    .ki-hero{
      padding: 34px 0 18px;
      position: relative;
    }
    .ki-hero-grid{
      display:grid;
      grid-template-columns: 1.05fr .95fr;
      gap: 18px;
      align-items: stretch;
    }
    @media (max-width: 992px){
      .ki-hero-grid{ grid-template-columns: 1fr; }
    }

    .ki-hero-left{
      padding: 28px 0 18px;
    }

    .ki-search{
      margin-top: 18px;
      border-radius: var(--ki-r);
      border: 1px solid rgba(15,23,42,.12);
      background: rgba(255,255,255,.35);
      backdrop-filter: blur(14px);
      padding: 12px;
    }
    .ki-search .form-control{
      border: 0;
      background: transparent;
      box-shadow: none !important;
      padding-left: 0;
    }
    .ki-search .input-group-text{
      background: transparent;
      border: 0;
      color: rgba(11,18,32,.65);
    }

    .ki-chiprow{
      display:flex; flex-wrap:wrap; gap:10px;
      margin-top: 12px;
    }
    .ki-chip{
      padding: 8px 12px;
      border-radius: 999px;
      border: 1px solid rgba(15,23,42,.12);
      background: rgba(255,255,255,.28);
      backdrop-filter: blur(12px);
      font-weight: 800;
      color: rgba(11,18,32,.82);
      transition: transform .15s ease, background .15s ease;
    }
    .ki-chip:hover{ transform: translateY(-1px); background: rgba(255,255,255,.45); }

    /* Right visual (hero course spotlight) */
    .ki-spot{
      border-radius: var(--ki-r2);
      overflow:hidden;
      position: relative;
      min-height: 380px;
      box-shadow: var(--ki-shadow);
      border: 1px solid rgba(15,23,42,.10);
      background: #111;
    }
    .ki-spot img{
      width:100%; height:100%; object-fit: cover; display:block;
      filter: saturate(1.02) contrast(1.02);
      transform: scale(1.02);
    }
    .ki-spot:after{
      content:"";
      position:absolute; inset:0;
      background: linear-gradient(180deg, rgba(11,18,32,.10) 0%, rgba(11,18,32,.70) 68%, rgba(11,18,32,.92) 100%);
      pointer-events:none;
    }
    .ki-spot-overlay{
      position:absolute; inset:0;
      display:flex; flex-direction:column; justify-content:flex-end;
      padding: 18px;
      z-index: 2;
    }
    .ki-pill{
      display:inline-flex; align-items:center; gap:8px;
      padding: 7px 10px;
      border-radius: 999px;
      border: 1px solid rgba(255,255,255,.18);
      background: rgba(255,255,255,.10);
      backdrop-filter: blur(10px);
      font-weight: 900;
      color: rgba(255,255,255,.92);
      width: fit-content;
    }
    .ki-pill .dot{
      width:8px; height:8px; border-radius:50%;
      background: var(--ki-secondary);
      box-shadow: 0 0 0 6px rgba(240,179,35,.18);
    }
    .ki-spot-title{
      font-family: Poppins, system-ui, sans-serif;
      font-weight: 900;
      letter-spacing: .1px;
      font-size: 1.35rem;
      line-height: 1.15;
      margin: 10px 0 6px;
      color: #fff;
    }
    .ki-spot-meta{
      display:flex; flex-wrap:wrap; gap:12px;
      color: rgba(255,255,255,.78);
      font-weight: 700;
      font-size: .95rem;
    }
    .ki-spot-actions{
      display:flex; gap:10px; flex-wrap:wrap;
      margin-top: 12px;
    }

    /* KPI stripe (jo cards) */
    .ki-kpi-stripe{
      margin-top: 18px;
      display:grid;
      grid-template-columns: repeat(4, minmax(0,1fr));
      gap: 12px;
      align-items: stretch;
    }
    @media (max-width: 992px){
      .ki-kpi-stripe{ grid-template-columns: repeat(2, minmax(0,1fr)); }
    }
    @media (max-width: 520px){
      .ki-kpi-stripe{ grid-template-columns: 1fr; }
    }
    .ki-kpi{
      padding: 14px 14px;
      border-radius: 18px;
      border: 1px dashed rgba(15,23,42,.16);
      background: linear-gradient(180deg, rgba(255,255,255,.30), rgba(255,255,255,.12));
      backdrop-filter: blur(12px);
    }
    .ki-kpi .t{
      color: rgba(11,18,32,.68);
      font-weight: 800;
      font-size: .92rem;
    }
    .ki-kpi .v{
      font-family: Poppins, system-ui, sans-serif;
      font-weight: 900;
      font-size: 1.45rem;
      letter-spacing: .1px;
      color: var(--ki-ink);
      margin-top: 4px;
      line-height: 1;
    }

    /* Course shelf — horizontal scroll (jo cards + container) */
    .ki-shelf{
      margin-top: 18px;
      display:flex;
      gap: 14px;
      overflow:auto;
      padding: 6px 2px 14px;
      scroll-snap-type: x mandatory;
      -webkit-overflow-scrolling: touch;
    }
    .ki-shelf::-webkit-scrollbar{ height: 10px; }
    .ki-shelf::-webkit-scrollbar-thumb{ background: rgba(15,23,42,.18); border-radius: 999px; }
    .ki-tile{
      flex: 0 0 320px;
      scroll-snap-align: start;
      border-radius: var(--ki-r);
      overflow:hidden;
      position: relative;
      border: 1px solid rgba(15,23,42,.10);
      box-shadow: var(--ki-shadow-soft);
      background: #0b1220;
      min-height: 210px;
    }
    @media (max-width: 520px){
      .ki-tile{ flex-basis: 86vw; }
    }
    .ki-tile img{
      width:100%; height:100%; object-fit: cover; display:block;
      filter: saturate(1.02) contrast(1.03);
      transform: scale(1.02);
    }
    .ki-tile:after{
      content:"";
      position:absolute; inset:0;
      background: linear-gradient(180deg, rgba(11,18,32,.08) 0%, rgba(11,18,32,.78) 74%, rgba(11,18,32,.92) 100%);
      pointer-events:none;
    }
    .ki-tile-info{
      position:absolute; inset:0;
      padding: 14px;
      display:flex; flex-direction:column; justify-content:flex-end;
      z-index:2;
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
    .ki-tile-title{
      font-family: Poppins, system-ui, sans-serif;
      font-weight: 900;
      color: #fff;
      margin: 10px 0 6px;
      line-height: 1.12;
      font-size: 1.05rem;
    }
    .ki-tile-meta{
      display:flex; gap:10px; flex-wrap:wrap;
      color: rgba(255,255,255,.78);
      font-weight: 700;
      font-size: .92rem;
    }

    /* Bento section (jo “cards”; janë blocks full-bleed) */
    .ki-bento{
      display:grid;
      grid-template-columns: 1.1fr .9fr;
      gap: 16px;
      margin-top: 18px;
    }
    @media (max-width: 992px){ .ki-bento{ grid-template-columns: 1fr; } }

    .ki-block{
      border-radius: var(--ki-r2);
      overflow:hidden;
      border: 1px solid rgba(15,23,42,.10);
      box-shadow: var(--ki-shadow-soft);
      position: relative;
      background: linear-gradient(180deg, rgba(255,255,255,.35), rgba(255,255,255,.18));
      backdrop-filter: blur(12px);
    }
    .ki-block.pad{ padding: 18px; }
    .ki-block .mini{
      font-weight: 900; letter-spacing: .2px; color: rgba(11,18,32,.70);
      text-transform: uppercase; font-size: .78rem;
    }

    .ki-list{
      margin: 12px 0 0;
      padding: 0;
      list-style: none;
      display:grid;
      gap: 10px;
    }
    .ki-li{
      display:flex; gap:12px; align-items:flex-start;
      padding: 12px 12px;
      border-radius: 18px;
      background: rgba(255,255,255,.25);
      border: 1px solid rgba(15,23,42,.10);
    }
    .ki-ic{
      width: 38px; height: 38px; border-radius: 14px;
      display:flex; align-items:center; justify-content:center;
      background: rgba(42,75,124,.12);
      color: var(--ki-primary-2);
      flex: 0 0 auto;
    }

    /* Night section components */
    .ki-night .ki-h2{ color: #fff; }
    .ki-night .ki-lead{ color: rgba(255,255,255,.78); }
    .ki-night .ki-linebox{
      margin-top: 16px;
      border-radius: var(--ki-r2);
      border: 1px solid rgba(255,255,255,.12);
      background: rgba(255,255,255,.06);
      backdrop-filter: blur(12px);
      padding: 18px;
    }
    .ki-night .ki-quote{
      font-weight: 800;
      font-size: 1.02rem;
      line-height: 1.55;
      color: rgba(255,255,255,.90);
      margin: 0;
    }
    .ki-night .ki-who{ color: rgba(255,255,255,.72); margin-top: 10px; font-weight: 700; }

    /* Reveal */
    .ki-reveal{ opacity:0; transform: translateY(10px); transition: all .45s ease; }
    .ki-reveal.show{ opacity:1; transform:none; }
    @media (prefers-reduced-motion: reduce){
      .ki-reveal{ opacity:1; transform:none; transition:none; }
      .ki-btn{ transition:none; }
    }

    /* Back to top */
    #kiTop{
      position: fixed; right: 16px; bottom: 16px;
      display:none; z-index: 999;
      border-radius: 999px;
      box-shadow: var(--ki-shadow-soft);
    }
  </style>
</head>

<body class="ki-home">
<a class="ki-skip" href="#main">Shko te përmbajtja</a>

<?php include __DIR__ . '/navbar.php'; ?>

<main id="main">

  <!-- ================= HERO (komplet i ri) ================= -->
  <section class="ki-hero">
    <div class="ki-wrap">
      <div class="ki-hero-grid">
        <div class="ki-hero-left ki-reveal">
          <div class="ki-kicker">
            <i class="fa-solid fa-bolt"></i>
            <span>Kurse online • Praktikë reale • Certifikim</span>
            <span class="ms-2" style="color:rgba(11,18,32,.60); font-weight:800;">|</span>
            <span style="font-weight:900;">
              <?= $isLoggedIn ? 'Mirë se erdhe, '.h($hiName) : 'Bashkohu sot' ?>
            </span>
          </div>

          <h1 class="ki-h1 mt-3">
            Mëso shpejt, ndërto portofol, dhe
            <span style="color:var(--ki-primary)">ndrysho karrierë</span>
            me kurseinformatike.com
          </h1>

          <p class="ki-lead mt-3">
            Kurse të strukturuara, laboratorë praktikë dhe mentorim. Fokus në rezultate të matshme:
            detyra reale, projekte, dhe udhëzim hap-pas-hapi.
          </p>

          <div class="ki-search">
            <form action="courses.php" method="get" class="input-group">
              <span class="input-group-text"><i class="fa-solid fa-magnifying-glass"></i></span>
              <input class="form-control" type="search" name="q" placeholder="Kërko: Python, Excel, Java, Italiane..." aria-label="Kërko kurse">
              <button class="btn btn-dark" type="submit" style="border-radius:14px; font-weight:900;">
                Kërko
              </button>
            </form>

            <div class="ki-chiprow">
              <?php foreach ($chips as $ch): ?>
                <a class="ki-chip" href="<?= h('courses.php?q='.$ch['q']) ?>"><?= h($ch['t']) ?></a>
              <?php endforeach; ?>
            </div>
          </div>

          <div class="d-flex gap-2 flex-wrap mt-3">
            <a href="virtuale/signup.php" class="ki-btn primary"><i class="fa-solid fa-user-plus"></i> Krijo llogari falas</a>
            <a href="courses.php" class="ki-btn ghost"><i class="fa-solid fa-layer-group"></i> Shiko promocionet</a>
            <a href="contact.php" class="ki-btn ghost"><i class="fa-solid fa-envelope"></i> Na kontakto</a>
          </div>

          <div class="ki-kpi-stripe ki-reveal" style="transition-delay:.06s">
            <div class="ki-kpi">
              <div class="t">Promocione</div>
              <div class="v" data-count="<?= (int)$stats['courses'] ?>">0</div>
            </div>
            <div class="ki-kpi">
              <div class="t">Përdorues</div>
              <div class="v" data-count="<?= (int)$stats['users'] ?>">0</div>
            </div>
            <div class="ki-kpi">
              <div class="t">Orë materiale</div>
              <div class="v" data-count="<?= (int)$stats['hours'] ?>">0</div>
            </div>
            <div class="ki-kpi">
              <div class="t">Sukses</div>
              <div class="v" data-count="<?= (int)$stats['success'] ?>">0</div>
            </div>
          </div>
        </div>

        <!-- Spotlight -->
        <?php if ($heroCourse): 
          $img = promoPhotoUrl($heroCourse['photo'] ?? null);
          $price = isset($heroCourse['price']) ? (float)$heroCourse['price'] : null;
          $old   = isset($heroCourse['old_price']) ? (float)$heroCourse['old_price'] : null;
          $hasDisc = ($price !== null && $old !== null && $old > 0 && $price >= 0 && $price < $old);
          $discPct = $hasDisc ? (int)round((($old - $price) / $old) * 100) : 0;
          $level = levelText($heroCourse['level'] ?? '');
          $href  = isset($heroCourse['id']) ? ('promotion_details.php?id='.(int)$heroCourse['id']) : 'courses.php';
        ?>
        <div class="ki-spot ki-reveal" style="transition-delay:.08s">
          <img src="<?= h($img) ?>" alt="<?= h((string)($heroCourse['name'] ?? 'Kurs')) ?>" loading="lazy" decoding="async">
          <div class="ki-spot-overlay">
            <div class="ki-pill">
              <span class="dot"></span>
              <span>Spotlight i javës</span>
              <?php if ($hasDisc): ?>
                <span style="margin-left:10px; font-weight:900; color:#fff;">
                  -<?= (int)$discPct ?>%
                </span>
              <?php endif; ?>
            </div>

            <div class="ki-spot-title"><?= h((string)($heroCourse['name'] ?? '')) ?></div>

            <div class="ki-spot-meta">
              <span><i class="fa-regular fa-clock me-1"></i><?= (int)($heroCourse['hours_total'] ?? 0) ?> orë</span>
              <span><i class="fa-solid fa-signal me-1"></i><?= h($level) ?></span>
              <?php if ($price !== null): ?>
                <span><i class="fa-solid fa-tag me-1"></i><?= $price > 0 ? money($price) : 'Falas' ?></span>
              <?php endif; ?>
            </div>

            <div class="ki-spot-actions">
              <a class="ki-btn primary" href="<?= h($href) ?>">
                <i class="fa-solid fa-arrow-right"></i> Shiko detajet
              </a>
              <a class="ki-btn ghost" href="courses.php">
                <i class="fa-solid fa-magnifying-glass"></i> Shfleto
              </a>
            </div>
          </div>
        </div>
        <?php endif; ?>
      </div>
    </div>
  </section>

  <!-- ================= PROMOTIONS SHELF (horizontal, full-bleed feel) ================= -->
  <section class="ki-section tight ki-band">
    <div class="ki-wrap">
      <div class="d-flex align-items-end justify-content-between gap-3 ki-reveal">
        <div>
          <h2 class="ki-h2">Promocione që ja vlejnë</h2>
          <p class="ki-lead mt-2">Shfleto si “shelf” — shpejt, vizual, pa kartat klasike.</p>
        </div>
        <div class="d-flex gap-2 flex-wrap">
          <a class="ki-btn dark" href="courses.php"><i class="fa-solid fa-layer-group"></i> Të gjitha</a>
        </div>
      </div>

      <div class="ki-shelf ki-reveal" style="transition-delay:.06s" aria-label="Promocione">
        <?php foreach ($courses as $c):
          $img = promoPhotoUrl($c['photo'] ?? null);
          $price = isset($c['price']) ? (float)$c['price'] : null;
          $old   = isset($c['old_price']) ? (float)$c['old_price'] : null;
          $hasDisc = ($price !== null && $old !== null && $old > 0 && $price >= 0 && $price < $old);
          $discPct = $hasDisc ? (int)round((($old - $price) / $old) * 100) : 0;
          $level = levelText($c['level'] ?? '');
          $href  = isset($c['id']) ? ('promotion_details.php?id='.(int)$c['id']) : 'courses.php';
          $label = (string)($c['label'] ?? '');
        ?>
          <a class="ki-tile" href="<?= h($href) ?>">
            <img src="<?= h($img) ?>" alt="<?= h((string)($c['name'] ?? 'Kurs')) ?>" loading="lazy" decoding="async">
            <div class="ki-tile-info">
              <div class="ki-badges">
                <?php if ($label): ?>
                  <span class="ki-badge">
                    <i class="fa-solid fa-tag me-1"></i><?= h($label) ?>
                  </span>
                <?php endif; ?>
                <?php if ($hasDisc): ?>
                  <span class="ki-badge">
                    <i class="fa-solid fa-percent me-1"></i>-<?= (int)$discPct ?>%
                  </span>
                <?php endif; ?>
              </div>

              <div class="ki-tile-title"><?= h((string)($c['name'] ?? '')) ?></div>
              <div class="ki-tile-meta">
                <span><i class="fa-regular fa-clock me-1"></i><?= (int)($c['hours_total'] ?? 0) ?> orë</span>
                <span><i class="fa-solid fa-signal me-1"></i><?= h($level) ?></span>
                <?php if ($price !== null): ?>
                  <span><i class="fa-solid fa-tag me-1"></i><?= $price > 0 ? money($price) : 'Falas' ?></span>
                <?php endif; ?>
              </div>
            </div>
          </a>
        <?php endforeach; ?>
      </div>
    </div>
  </section>

  <!-- ================= BENTO / UX (komplet ndryshe nga cards) ================= -->
  <section class="ki-section">
    <div class="ki-wrap">
      <div class="ki-reveal">
        <h2 class="ki-h2">Një mënyrë mësimi që të çon te rezultatet</h2>
        <p class="ki-lead mt-2">Më pak teori e shpërndarë, më shumë strukturë, praktikë dhe feedback.</p>
      </div>

      <div class="ki-bento">
        <div class="ki-block pad ki-reveal" style="transition-delay:.06s">
          <div class="mini">Rruga e shpejtë</div>
          <h3 class="mt-2" style="font-family:Poppins,system-ui,sans-serif;font-weight:900;color:var(--ki-ink);margin:0;">
            3 hapa: Nise • Ndërto • Certifikohu
          </h3>

          <ul class="ki-list">
            <li class="ki-li">
              <div class="ki-ic"><i class="fa-solid fa-play"></i></div>
              <div>
                <div style="font-weight:900;color:var(--ki-ink)">Nise pa konfuzion</div>
                <div style="color:var(--ki-muted)">Strukturë javore + detyra të qarta + progres i dukshëm.</div>
              </div>
            </li>
            <li class="ki-li">
              <div class="ki-ic"><i class="fa-solid fa-hammer"></i></div>
              <div>
                <div style="font-weight:900;color:var(--ki-ink)">Ndërto projekte</div>
                <div style="color:var(--ki-muted)">Portofol real (jo vetëm ushtrime), me feedback.</div>
              </div>
            </li>
            <li class="ki-li">
              <div class="ki-ic"><i class="fa-solid fa-certificate"></i></div>
              <div>
                <div style="font-weight:900;color:var(--ki-ink)">Certifikohu</div>
                <div style="color:var(--ki-muted)">Certifikatë digjitale pas projektit final.</div>
              </div>
            </li>
          </ul>

          <div class="d-flex gap-2 flex-wrap mt-3">
            <a href="signup.php" class="ki-btn primary"><i class="fa-solid fa-user-plus"></i> Filloj tani</a>
            <a href="courses.php" class="ki-btn ghost"><i class="fa-solid fa-layer-group"></i> Shiko kurset</a>
          </div>
        </div>

        <div class="ki-block ki-reveal" style="transition-delay:.10s">
          <div style="position:relative; min-height: 360px;">
            <div style="position:absolute; inset:0; background:
              radial-gradient(520px 340px at 30% 20%, rgba(42,75,124,.28), transparent 60%),
              radial-gradient(520px 340px at 80% 30%, rgba(240,179,35,.30), transparent 62%),
              linear-gradient(180deg, rgba(255,255,255,.22), rgba(255,255,255,.10));
            "></div>

            <div style="position:absolute; inset:0; padding:18px; display:flex; flex-direction:column; justify-content:space-between;">
              <div>
                <div class="mini">Premtim</div>
                <div style="margin-top:10px; font-family:Poppins,system-ui,sans-serif; font-weight:900; color:var(--ki-ink); font-size:1.25rem; line-height:1.15;">
                  Më pak “zallamahi”, më shumë fokus.
                </div>
                <div class="ki-lead mt-2" style="max-width: 44ch;">
                  Materialet janë të organizuara, progresi i matshëm dhe komunikimi i shpejtë.
                </div>
              </div>

              <div style="display:grid; grid-template-columns: 1fr 1fr; gap:12px;">
                <div style="border-radius:18px; border:1px solid rgba(15,23,42,.10); background: rgba(255,255,255,.20); padding:12px;">
                  <div style="font-weight:900;color:var(--ki-ink)">Mbështetje</div>
                  <div style="color:var(--ki-muted); font-weight:800;">24/7</div>
                </div>
                <div style="border-radius:18px; border:1px solid rgba(15,23,42,.10); background: rgba(255,255,255,.20); padding:12px;">
                  <div style="font-weight:900;color:var(--ki-ink)">Praktikë</div>
                  <div style="color:var(--ki-muted); font-weight:800;">Projekte</div>
                </div>
                <div style="border-radius:18px; border:1px solid rgba(15,23,42,.10); background: rgba(255,255,255,.20); padding:12px;">
                  <div style="font-weight:900;color:var(--ki-ink)">Akses</div>
                  <div style="color:var(--ki-muted); font-weight:800;">Web & Mobile</div>
                </div>
                <div style="border-radius:18px; border:1px solid rgba(15,23,42,.10); background: rgba(255,255,255,.20); padding:12px;">
                  <div style="font-weight:900;color:var(--ki-ink)">Certifikim</div>
                  <div style="color:var(--ki-muted); font-weight:800;">Digjital</div>
                </div>
              </div>

              <br>

              <div class="d-flex gap-2 flex-wrap">
                <a href="contact.php" class="ki-btn ghost"><i class="fa-solid fa-envelope"></i> Pyet për kursin</a>
                <a href="courses.php" class="ki-btn dark"><i class="fa-solid fa-arrow-right"></i> Shfleto tani</a>
              </div>
            </div>
          </div>
        </div>
      </div>

    </div>
  </section>

  <!-- ================= NIGHT / Testimonials (jo white) ================= -->
  <section class="ki-section ki-night">
    <div class="ki-wrap">
      <div class="ki-reveal">
        <h2 class="ki-h2">Studentë që flasin me rezultate</h2>
        <p class="ki-lead mt-2">Dëshmi të shkurtra, pa “karta” klasike, në një ambient të errët premium.</p>
      </div>

      <div class="row g-3 mt-2">
        <div class="col-12 col-lg-4 ki-reveal" style="transition-delay:.06s">
          <div class="ki-linebox">
            <p class="ki-quote">“UI e qartë dhe leksione me praktikë. Projekti final më ndihmoi të gjej punë.”</p>
            <div class="ki-who">Ardit P. — Zhvillues Software</div>
          </div>
        </div>
        <div class="col-12 col-lg-4 ki-reveal" style="transition-delay:.10s">
          <div class="ki-linebox">
            <p class="ki-quote">“Excel Pro ishte ‘game changer’. Dashboards + raportim si profesionist.”</p>
            <div class="ki-who">Mentor X. — Analist Financiar</div>
          </div>
        </div>
        <div class="col-12 col-lg-4 ki-reveal" style="transition-delay:.14s">
          <div class="ki-linebox">
            <p class="ki-quote">“Më pëlqen ritmi: detyra të shkurtra, feedback i shpejtë dhe progres i matshëm.”</p>
            <div class="ki-who">Elira K. — Project Manager</div>
          </div>
        </div>
      </div>

      <div class="ki-linebox ki-reveal" style="transition-delay:.18s; margin-top:14px;">
        <div class="d-flex flex-column flex-lg-row align-items-start align-items-lg-center justify-content-between gap-2">
          <div>
            <div style="font-family:Poppins,system-ui,sans-serif;font-weight:900;font-size:1.2rem;color:#fff;">
              Gati për të nisur?
            </div>
            <div class="ki-lead" style="margin-top:6px;">
              Regjistrohu dhe përfito ofertë për regjistrimet e reja.
            </div>
          </div>
          <div class="d-flex gap-2 flex-wrap">
            <a href="signup.php" class="ki-btn primary"><i class="fa-solid fa-user-plus"></i> Regjistrohu</a>
            <a href="courses.php" class="ki-btn ghost"><i class="fa-solid fa-layer-group"></i> Shiko kurset</a>
          </div>
        </div>
      </div>
    </div>
  </section>

  <!-- ================= Newsletter (light but jo white page) ================= -->
  <section class="ki-section">
    <div class="ki-wrap">
      <div class="ki-reveal">
        <h2 class="ki-h2">Qëndro i përditësuar</h2>
        <p class="ki-lead mt-2">Kurse të reja dhe zbritje direkte në email. Pa spam.</p>
      </div>

      <div class="ki-block pad ki-reveal" style="transition-delay:.06s; margin-top: 14px;">
        <form action="subscribe.php" method="post" class="row g-2 align-items-center">
          <div class="col-12 col-lg-8">
            <div class="input-group" style="border-radius:18px; overflow:hidden; border:1px solid rgba(15,23,42,.12); background: rgba(255,255,255,.18);">
              <span class="input-group-text" style="border:0;background:transparent;color:rgba(11,18,32,.65);"><i class="fa-regular fa-paper-plane"></i></span>
              <input type="email" name="email" class="form-control" placeholder="Email-i yt" required
                     style="border:0;background:transparent;box-shadow:none;">
            </div>
          </div>
          <div class="col-12 col-lg-4 d-grid">
            <button class="ki-btn primary" type="submit">
              <i class="fa-solid fa-bell"></i> Abonohu
            </button>
          </div>
          <div class="col-12">
            <div style="color:var(--ki-muted); font-weight:700; font-size:.95rem;">
              <i class="fa-regular fa-circle-check me-1" style="color:#16a34a;"></i>
              Mund të çabonohesh kur të duash.
            </div>
          </div>
        </form>
      </div>
    </div>
  </section>

</main>

<?php include __DIR__ . '/footer.php'; ?>

<button id="kiTop" class="btn btn-dark"><i class="fa-solid fa-arrow-up"></i></button>

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

  // Counter animation (KPI)
  function animateCount(el){
    const to = Number(el.getAttribute('data-count') || '0');
    const isPercent = (el.textContent || '').includes('%');
    const duration = 900;
    const start = performance.now();
    const from = 0;

    function tick(now){
      const p = Math.min(1, (now - start) / duration);
      const eased = 1 - Math.pow(1 - p, 3);
      const val = Math.round(from + (to - from) * eased);
      el.textContent = (val >= 1000 ? val.toLocaleString() : String(val)) + (isPercent ? '%' : '');
      if (p < 1) requestAnimationFrame(tick);
    }
    requestAnimationFrame(tick);
  }

  // run counts when KPI stripe is visible
  const kpiEls = document.querySelectorAll('.ki-kpi .v[data-count]');
  if (!reduceMotion && kpiEls.length) {
    const kio = new IntersectionObserver((entries)=>{
      entries.forEach(e=>{
        if (e.isIntersecting){
          kpiEls.forEach(animateCount);
          kio.disconnect();
        }
      });
    }, {threshold:.25});
    const target = document.querySelector('.ki-kpi-stripe');
    if (target) kio.observe(target);
  } else {
    kpiEls.forEach(el => el.textContent = (Number(el.getAttribute('data-count')||'0')).toLocaleString());
  }

  // Back to top
  const topBtn = document.getElementById('kiTop');
  window.addEventListener('scroll', ()=>{
    topBtn.style.display = (window.scrollY > 520) ? 'inline-flex' : 'none';
  });
  topBtn.addEventListener('click', ()=>window.scrollTo({top:0, behavior:'smooth'}));
</script>
</body>
</html>
