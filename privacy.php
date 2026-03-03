<?php
// privacy.php — Politika e Privatësisë (KI v2)
declare(strict_types=1);
session_start();

function h(?string $s): string { return htmlspecialchars($s ?? '', ENT_QUOTES, 'UTF-8'); }

// ===================== Konfigurime (përshtati) =====================
$SITE_NAME   = 'kurseinformatike.com';
$BRAND_NAME  = 'KURSEINFORMATIKE';
$SITE_URL    = 'https://kurseinformatike.com';

$CONTROLLER_NAME = 'KURSEINFORMATIKE / kurseinformatike.com'; // Titull/Emër biznesi
$ADDRESS         = 'Rruga Bilal Konxolli, Tiranë, Shqipëri';
$EMAIL           = 'info@kurseinformatike.com';
$PHONE_DISPLAY   = '+39 327 469 1197';

$LAST_UPDATED = '2026-01-07'; // përditësoje kur ndryshon politika

// Nëse ke faqe cookie, vendos "cookie.php" ose "#"
$COOKIE_PAGE = file_exists(__DIR__ . '/cookie.php') ? 'cookie.php' : '#';

// Nëse ke faqe terms, vendos "terms.php" ose "#"
$TERMS_PAGE = file_exists(__DIR__ . '/terms.php') ? 'terms.php' : '#';

// Opsionale: Link unsubscribe (nëse e ke)
$UNSUBSCRIBE_INFO = 'Mund të çabonohesh në çdo kohë nga link-u “unsubscribe” brenda emailit.';

// ===================== Stili (KI v2, light slim) =====================
?>
<!doctype html>
<html lang="sq">
<head>
  <meta charset="utf-8" />
  <meta name="viewport" content="width=device-width,initial-scale=1" />
  <title>Politika e privatësisë — <?= h($SITE_NAME) ?></title>
  <meta name="description" content="Politika e privatësisë dhe mbrojtjes së të dhënave për <?= h($SITE_NAME) ?>." />
  <link rel="icon" href="image/favicon.ico" type="image/x-icon" />

  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
  <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css" rel="stylesheet">
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@600;700;800;900&family=Roboto:wght@400;500;700&display=swap" rel="stylesheet">

  <script>document.documentElement.classList.add('js');</script>

  <style>
    body.ki-privacy{
      --ki-primary:#2A4B7C;
      --ki-primary-2:#1d3a63;
      --ki-secondary:#F0B323;

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

    body.ki-privacy{
      margin:0;
      font-family: Roboto, system-ui, -apple-system, Segoe UI, Arial, sans-serif;
      color: var(--ki-text);
      background:
        radial-gradient(900px 600px at 10% 0%, rgba(42,75,124,.10), transparent 55%),
        radial-gradient(900px 600px at 90% 10%, rgba(240,179,35,.12), transparent 55%),
        linear-gradient(180deg, var(--ki-ice), var(--ki-sand));
    }

    .ki-wrap{ width: min(var(--ki-wrap), calc(100% - 32px)); margin-inline:auto; }
    .ki-h1,.ki-h2,.ki-h3{
      font-family:Poppins, system-ui, sans-serif;
      font-weight: 900;
      letter-spacing:.1px;
      line-height:1.1;
      margin:0;
      color: var(--ki-ink);
    }
    .ki-h1{ font-size: clamp(1.8rem, 2.6vw, 2.6rem); }
    .ki-h2{ font-size: 1.25rem; }
    .ki-h3{ font-size: 1.05rem; }

    .ki-lead{ color: var(--ki-muted); line-height:1.6; margin:0; }
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
      padding: 11px 14px;
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
    }
    .ki-btn:hover{ transform: translateY(-1px); background: rgba(255,255,255,.55); }
    .ki-btn.primary{
      background: linear-gradient(135deg, var(--ki-secondary), #ffd36a);
      border-color: rgba(240,179,35,.55);
      color:#111827;
      backdrop-filter:none;
    }

    .ki-hero{ padding: 34px 0 14px; }
    .ki-hero-card{ padding: 18px; }

    .ki-grid{
      display:grid;
      grid-template-columns: .9fr 1.1fr;
      gap: 16px;
      align-items: start;
    }
    @media (max-width: 992px){ .ki-grid{ grid-template-columns: 1fr; } }

    .ki-toc{
      position: sticky;
      top: 14px;
      padding: 14px;
    }
    .ki-toc a{
      display:flex; gap:10px; align-items:center;
      padding: 10px 10px;
      border-radius: 14px;
      text-decoration:none;
      font-weight: 900;
      color: rgba(11,18,32,.82);
      border: 1px solid rgba(15,23,42,.10);
      background: rgba(255,255,255,.28);
      margin-bottom: 8px;
    }
    .ki-toc a:hover{ background: rgba(255,255,255,.50); }
    .ki-toc small{ color: rgba(11,18,32,.62); font-weight: 800; }

    .ki-doc{
      padding: 18px;
    }
    .ki-sec{
      padding: 14px;
      border-radius: var(--ki-r2);
      border: 1px solid rgba(15,23,42,.10);
      background: rgba(255,255,255,.34);
      margin-bottom: 12px;
    }
    .ki-sec p, .ki-sec li{ color: rgba(11,18,32,.74); font-weight: 700; line-height:1.65; }
    .ki-sec ul{ margin-bottom: 0; }

    .ki-pill{
      display:inline-flex; align-items:center; gap:8px;
      padding: 8px 12px;
      border-radius: 999px;
      border: 1px solid rgba(15,23,42,.10);
      background: rgba(255,255,255,.35);
      font-weight: 900;
      color: rgba(11,18,32,.80);
    }

    .ki-callout{
      border-radius: var(--ki-r2);
      border: 1px solid rgba(240,179,35,.35);
      background: rgba(255, 248, 220, .55);
      padding: 14px;
      color: rgba(11,18,32,.76);
      font-weight: 800;
      line-height:1.55;
    }

    .ki-reveal{ opacity:1; transform:none; transition: all .45s ease; }
    .js .ki-reveal{ opacity:0; transform: translateY(10px); }
    .ki-reveal.show{ opacity:1 !important; transform:none !important; }
    @media (prefers-reduced-motion: reduce){
      .js .ki-reveal{ opacity:1; transform:none; transition:none; }
      .ki-btn{ transition:none; }
    }
  </style>
</head>

<body class="ki-privacy">

<?php
// Navbar
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
      <div class="ki-grid">

        <div class="ki-reveal">
          <div class="ki-kicker">
            <i class="fa-solid fa-shield-halved"></i>
            <span>Privatësi & Mbrojtje e të dhënave</span>
          </div>

          <h1 class="ki-h1 mt-3">Politika e privatësisë</h1>
          <p class="ki-lead mt-3">
            Kjo politikë shpjegon se si <?= h($BRAND_NAME) ?> (“ne”) mbledh, përdor dhe mbron të dhënat personale
            kur ju përdorni <?= h($SITE_NAME) ?>.
          </p>

          <div class="mt-3 d-flex gap-2 flex-wrap">
            <span class="ki-pill"><i class="fa-regular fa-calendar"></i> Përditësuar: <?= h($LAST_UPDATED) ?></span>
            <a class="ki-btn primary" href="contact.php"><i class="fa-solid fa-paper-plane"></i> Na kontakto</a>
            <?php if ($TERMS_PAGE !== '#'): ?>
              <a class="ki-btn" href="<?= h($TERMS_PAGE) ?>"><i class="fa-regular fa-file-lines"></i> Termat</a>
            <?php endif; ?>
          </div>

          <div class="ki-callout mt-3">
            Nëse keni pyetje për privatësinë ose dëshironi të ushtroni të drejtat tuaja (akses, korrigjim, fshirje, etj.),
            na shkruani në <strong><?= h($EMAIL) ?></strong>.
          </div>
        </div>

        <!-- TOC -->
        <aside class="ki-glass ki-toc ki-reveal" style="transition-delay:.08s;">
          <div class="mb-2" style="font-family:Poppins;font-weight:900;color:var(--ki-ink);">
            Përmbajtja
          </div>
          <a href="#controller"><i class="fa-solid fa-building"></i> <span>Kontrolluesi i të dhënave</span></a>
          <a href="#data"><i class="fa-solid fa-database"></i> <span>Çfarë të dhënash mbledhim</span></a>
          <a href="#purposes"><i class="fa-solid fa-bullseye"></i> <span>Pse i përdorim</span></a>
          <a href="#legal"><i class="fa-solid fa-scale-balanced"></i> <span>Bazat ligjore</span></a>
          <a href="#sharing"><i class="fa-solid fa-share-nodes"></i> <span>Ndarja me palë të treta</span></a>
          <a href="#cookies"><i class="fa-solid fa-cookie-bite"></i> <span>Cookies</span></a>
          <a href="#retention"><i class="fa-regular fa-clock"></i> <span>Ruajtja e të dhënave</span></a>
          <a href="#rights"><i class="fa-solid fa-user-shield"></i> <span>Të drejtat tuaja</span></a>
          <a href="#security"><i class="fa-solid fa-lock"></i> <span>Siguria</span></a>
          <a href="#children"><i class="fa-solid fa-child"></i> <span>Të miturit</span></a>
          <a href="#changes"><i class="fa-solid fa-pen-to-square"></i> <span>Ndryshimet</span></a>
          <a href="#contact"><i class="fa-regular fa-envelope"></i> <span>Kontakt</span></a>
          <small class="d-block mt-2">Kjo faqe nuk është këshillë ligjore; është tekst standard informues.</small>
        </aside>

      </div>
    </div>
  </section>

  <!-- ================= DOC ================= -->
  <section class="pb-4">
    <div class="ki-wrap">
      <div class="ki-glass ki-doc ki-reveal">

        <section id="controller" class="ki-sec">
          <h2 class="ki-h2"><i class="fa-solid fa-building me-2"></i>Kontrolluesi i të dhënave</h2>
          <p class="mt-2 mb-2">
            Kontrolluesi i të dhënave për <?= h($SITE_NAME) ?> është:
          </p>
          <ul>
            <li><strong>Emri:</strong> <?= h($CONTROLLER_NAME) ?></li>
            <li><strong>Adresa:</strong> <?= h($ADDRESS) ?></li>
            <li><strong>Email:</strong> <?= h($EMAIL) ?></li>
            <li><strong>Telefon:</strong> <?= h($PHONE_DISPLAY) ?></li>
          </ul>
        </section>

        <section id="data" class="ki-sec">
          <h2 class="ki-h2"><i class="fa-solid fa-database me-2"></i>Çfarë të dhënash personale mund të mbledhim</h2>
          <ul class="mt-2">
            <li><strong>Të dhëna identifikimi/llogarie:</strong> emër, email, (dhe të dhëna të tjera që jepni gjatë regjistrimit).</li>
            <li><strong>Të dhëna kontakti:</strong> mesazhet që dërgoni nga formulari i kontaktit, subjekt, përmbajtje mesazhi.</li>
            <li><strong>Të dhëna kursesh/eventesh:</strong> regjistrime në kurse/promocione, regjistrime në evente, progres (nëse ofrohet në “Virtuale”).</li>
            <li><strong>Të dhëna pagesash:</strong> vetëm në masën e nevojshme për transaksion; zakonisht përpunohen nga ofruesit e pagesave (ne nuk ruajmë numrat e kartave).</li>
            <li><strong>Të dhëna teknike:</strong> IP, lloji i pajisjes/shfletuesit, logje sigurie, cookies (shih seksionin Cookies).</li>
          </ul>
        </section>

        <section id="purposes" class="ki-sec">
          <h2 class="ki-h2"><i class="fa-solid fa-bullseye me-2"></i>Pse i përdorim të dhënat</h2>
          <ul class="mt-2">
            <li>Për të ofruar shërbimet: akses në kurse, platformë mësimore, evente, certifikime.</li>
            <li>Për administrim dhe suport: përgjigje ndaj kërkesave, asistencë teknike, komunikime operative.</li>
            <li>Për përmirësim të faqes dhe siguri: analiza anonime/trend, parandalim abuzimesh dhe spam.</li>
            <li>Për marketing (vetëm kur lejohet): newsletter, oferta dhe njoftime për kurse/promocione.</li>
          </ul>
        </section>

        <section id="legal" class="ki-sec">
          <h2 class="ki-h2"><i class="fa-solid fa-scale-balanced me-2"></i>Bazat ligjore të përpunimit</h2>
          <ul class="mt-2">
            <li><strong>Kontrata:</strong> kur krijoni llogari / regjistroheni në kurs, për të ofruar shërbimin.</li>
            <li><strong>Pëlqimi:</strong> p.sh. për newsletter/marketing (mund të tërhiqet në çdo kohë).</li>
            <li><strong>Interes i ligjshëm:</strong> siguria e platformës, parandalimi i mashtrimit, përmirësimi i shërbimit.</li>
            <li><strong>Detyrim ligjor:</strong> kur kërkohet nga legjislacioni (p.sh. fatura/kontabilitet).</li>
          </ul>
        </section>

        <section id="sharing" class="ki-sec">
          <h2 class="ki-h2"><i class="fa-solid fa-share-nodes me-2"></i>Ndarja e të dhënave me palë të treta</h2>
          <p class="mt-2 mb-2">
            Ne mund të ndajmë të dhëna vetëm kur është e nevojshme për shërbimin, p.sh.:
          </p>
          <ul>
            <li><strong>Ofrues shërbimesh teknike</strong> (hosting, email, ruajtje), vetëm me akses të kufizuar.</li>
            <li><strong>Ofrues pagesash</strong> për përpunim transaksionesh (nëse përdorni pagesa online).</li>
            <li><strong>Google reCAPTCHA</strong> për anti-spam në formularë.</li>
          </ul>
          <p class="mt-2 mb-0">
            Ne nuk shesim të dhënat tuaja personale.
          </p>
        </section>

        <section id="cookies" class="ki-sec">
          <h2 class="ki-h2"><i class="fa-solid fa-cookie-bite me-2"></i>Cookies & teknologji të ngjashme</h2>
          <p class="mt-2 mb-2">
            Përdorim cookies për funksionimin bazë të faqes (p.sh. sesione/logim), siguri dhe (nëse aktivizohet) analitikë.
          </p>
          <ul>
            <li><strong>Cookies të domosdoshme:</strong> për funksionimin e platformës dhe autentikimin.</li>
            <li><strong>Cookies funksionale:</strong> p.sh. preferenca të përdoruesit (nëse aplikohen).</li>
            <li><strong>Cookies analitike/marketing:</strong> vetëm nëse aktivizohen dhe sipas konfigurimit tuaj.</li>
          </ul>
          <?php if ($COOKIE_PAGE !== '#'): ?>
            <p class="mt-2 mb-0">Detaje: <a href="<?= h($COOKIE_PAGE) ?>">Politika e Cookies</a>.</p>
          <?php endif; ?>
        </section>

        <section id="retention" class="ki-sec">
          <h2 class="ki-h2"><i class="fa-regular fa-clock me-2"></i>Sa kohë i ruajmë të dhënat</h2>
          <ul class="mt-2">
            <li>Të dhënat e llogarisë ruhen sa kohë llogaria është aktive.</li>
            <li>Të dhënat e kontaktit ruhen aq sa duhet për trajtimin e kërkesës dhe arsye administrative.</li>
            <li>Të dhënat financiare ruhen sipas kërkesave ligjore/kontabilitetit.</li>
            <li>Logjet e sigurisë ruhen për një periudhë të arsyeshme për parandalim abuzimesh.</li>
          </ul>
        </section>

        <section id="rights" class="ki-sec">
          <h2 class="ki-h2"><i class="fa-solid fa-user-shield me-2"></i>Të drejtat tuaja</h2>
          <p class="mt-2 mb-2">
            Ju keni të drejtë të kërkoni:
          </p>
          <ul>
            <li>Akses në të dhënat tuaja</li>
            <li>Korrigjim të të dhënave të pasakta</li>
            <li>Fshirje (“e drejta për t’u harruar”), kur aplikohet</li>
            <li>Kufizim ose kundërshtim të përpunimit, kur aplikohet</li>
            <li>Portabilitet të të dhënave (kur përpunimi bazohet në pëlqim/kontratë)</li>
            <li>Tërheqje të pëlqimit (p.sh. marketing)</li>
          </ul>
          <p class="mt-2 mb-0">
            Për kërkesa, na kontaktoni në: <strong><?= h($EMAIL) ?></strong>.
          </p>
        </section>

        <section id="security" class="ki-sec">
          <h2 class="ki-h2"><i class="fa-solid fa-lock me-2"></i>Siguria</h2>
          <ul class="mt-2">
            <li>Përdorim masa teknike dhe organizative për të mbrojtur të dhënat.</li>
            <li>Mund të përdorim mekanizma anti-spam (p.sh. reCAPTCHA) dhe logje sigurie.</li>
            <li>Asnjë sistem nuk është 100% i sigurt; megjithatë, ne minimizojmë rrezikun me praktika standarde.</li>
          </ul>
        </section>

        <section id="children" class="ki-sec">
          <h2 class="ki-h2"><i class="fa-solid fa-child me-2"></i>Të miturit</h2>
          <p class="mt-2 mb-0">
            Shërbimi ynë nuk synon qëllimisht të miturit pa pëlqimin e prindit/kujdestarit ligjor,
            aty ku kërkohet nga ligji.
          </p>
        </section>

        <section id="changes" class="ki-sec">
          <h2 class="ki-h2"><i class="fa-solid fa-pen-to-square me-2"></i>Ndryshime në këtë politikë</h2>
          <p class="mt-2 mb-0">
            Mund ta përditësojmë këtë politikë herë pas here. Data e fundit e përditësimit shfaqet në krye të faqes.
          </p>
        </section>

        <section id="contact" class="ki-sec">
          <h2 class="ki-h2"><i class="fa-regular fa-envelope me-2"></i>Kontakt</h2>
          <p class="mt-2 mb-2">
            Për çdo pyetje mbi privatësinë:
          </p>
          <ul>
            <li>Email: <strong><?= h($EMAIL) ?></strong></li>
            <li>Telefon: <strong><?= h($PHONE_DISPLAY) ?></strong></li>
            <li>Adresa: <strong><?= h($ADDRESS) ?></strong></li>
          </ul>

          <div class="mt-2 d-flex gap-2 flex-wrap">
            <a class="ki-btn primary" href="contact.php"><i class="fa-solid fa-paper-plane"></i> Formular kontakti</a>
            <a class="ki-btn" href="<?= h($SITE_URL) ?>"><i class="fa-solid fa-house"></i> Kryefaqja</a>
          </div>
        </section>

      </div>
    </div>
  </section>

</main>

<?php include __DIR__ . '/footer.php'; ?>

<script>
  // Reveal
  (function(){
    const reduceMotion = window.matchMedia('(prefers-reduced-motion: reduce)').matches;
    const els = Array.from(document.querySelectorAll('.ki-reveal'));
    if (reduceMotion || !('IntersectionObserver' in window)) {
      els.forEach(el => el.classList.add('show'));
      return;
    }
    const io = new IntersectionObserver((entries)=>{
      entries.forEach(e=>{
        if (e.isIntersecting) { e.target.classList.add('show'); io.unobserve(e.target); }
      });
    }, {threshold:.12});
    els.forEach(el => io.observe(el));
  })();

  // Smooth scroll
  document.querySelectorAll('a[href^="#"]').forEach(a=>{
    a.addEventListener('click', (e)=>{
      const id = a.getAttribute('href');
      const t = document.querySelector(id);
      if (!t) return;
      e.preventDefault();
      window.scrollTo({ top: t.getBoundingClientRect().top + window.pageYOffset - 90, behavior:'smooth' });
    });
  });
</script>

</body>
</html>
