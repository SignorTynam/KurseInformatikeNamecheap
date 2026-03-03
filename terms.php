<?php
// terms.php — Termat & Kushtet e Përdorimit (KI v2)
declare(strict_types=1);
session_start();

function h(?string $s): string { return htmlspecialchars($s ?? '', ENT_QUOTES, 'UTF-8'); }

// ===================== Konfigurime (përshtati) =====================
$SITE_NAME  = 'kurseinformatike.com';
$BRAND_NAME = 'KURSEINFORMATIKE';
$SITE_URL   = 'https://kurseinformatike.com';

$OPERATOR_NAME = 'KURSEINFORMATIKE / kurseinformatike.com';
$ADDRESS       = 'Rruga Bilal Konxolli, Tiranë, Shqipëri';
$EMAIL         = 'info@kurseinformatike.com';
$PHONE_DISPLAY = '+39 327 469 1197';

$LAST_UPDATED = '2026-01-07';

// Juridiksioni / ligji i zbatueshëm (përshtate sipas rastit)
$GOVERNING_LAW = 'Ligjet e Republikës së Shqipërisë';
$VENUE_CITY    = 'Tiranë';

// Linket
$PRIVACY_PAGE = file_exists(__DIR__ . '/privacy.php') ? 'privacy.php' : '#';
$COOKIE_PAGE  = file_exists(__DIR__ . '/cookie.php') ? 'cookie.php' : '#';
?>
<!doctype html>
<html lang="sq">
<head>
  <meta charset="utf-8" />
  <meta name="viewport" content="width=device-width,initial-scale=1" />
  <title>Termat & Kushtet — <?= h($SITE_NAME) ?></title>
  <meta name="description" content="Termat dhe kushtet e përdorimit për <?= h($SITE_NAME) ?>." />
  <link rel="icon" href="image/favicon.ico" type="image/x-icon" />

  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
  <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css" rel="stylesheet">
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@600;700;800;900&family=Roboto:wght@400;500;700&display=swap" rel="stylesheet">

  <script>document.documentElement.classList.add('js');</script>

  <style>
    body.ki-terms{
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

    body.ki-terms{
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

    .ki-doc{ padding: 18px; }
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
      border: 1px solid rgba(42,75,124,.18);
      background: rgba(255,255,255,.45);
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

<body class="ki-terms">

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
            <i class="fa-regular fa-file-lines"></i>
            <span>Termat & Kushtet</span>
          </div>

          <h1 class="ki-h1 mt-3">Termat dhe Kushtet e Përdorimit</h1>
          <p class="ki-lead mt-3">
            Duke përdorur <?= h($SITE_NAME) ?>, ju pranoni këto terma. Nëse nuk pajtoheni, ju lutemi mos e përdorni shërbimin.
          </p>

          <div class="mt-3 d-flex gap-2 flex-wrap">
            <span class="ki-pill"><i class="fa-regular fa-calendar"></i> Përditësuar: <?= h($LAST_UPDATED) ?></span>
            <?php if ($PRIVACY_PAGE !== '#'): ?>
              <a class="ki-btn" href="<?= h($PRIVACY_PAGE) ?>"><i class="fa-solid fa-shield-halved"></i> Privatësia</a>
            <?php endif; ?>
            <a class="ki-btn primary" href="contact.php"><i class="fa-solid fa-paper-plane"></i> Na kontakto</a>
          </div>

          <div class="ki-callout mt-3">
            Këta terma janë tekst standard informues. Nëse keni kërkesa specifike (p.sh. rimbursime, abonime, certifikime zyrtare),
            rekomandohet t’i përshtatni me modelin tuaj të biznesit dhe rregullat ligjore përkatëse.
          </div>
        </div>

        <!-- TOC -->
        <aside class="ki-glass ki-toc ki-reveal" style="transition-delay:.08s;">
          <div class="mb-2" style="font-family:Poppins;font-weight:900;color:var(--ki-ink);">
            Përmbajtja
          </div>
          <a href="#about"><i class="fa-solid fa-circle-info"></i> <span>Përshkrimi i shërbimit</span></a>
          <a href="#account"><i class="fa-solid fa-user"></i> <span>Llogaritë & përgjegjësitë</span></a>
          <a href="#courses"><i class="fa-solid fa-graduation-cap"></i> <span>Kurse, përmbajtje, certifikata</span></a>
          <a href="#events"><i class="fa-regular fa-calendar-days"></i> <span>Eventet</span></a>
          <a href="#payments"><i class="fa-solid fa-credit-card"></i> <span>Pagesat & rimbursimet</span></a>
          <a href="#conduct"><i class="fa-solid fa-handshake"></i> <span>Sjellja e lejuar</span></a>
          <a href="#ip"><i class="fa-solid fa-copyright"></i> <span>Pronësia intelektuale</span></a>
          <a href="#availability"><i class="fa-solid fa-server"></i> <span>Disponueshmëria</span></a>
          <a href="#liability"><i class="fa-solid fa-triangle-exclamation"></i> <span>Kufizim përgjegjësie</span></a>
          <a href="#privacy"><i class="fa-solid fa-shield-halved"></i> <span>Privatësia & cookies</span></a>
          <a href="#termination"><i class="fa-solid fa-ban"></i> <span>Pezullimi/ndërprerja</span></a>
          <a href="#law"><i class="fa-solid fa-scale-balanced"></i> <span>Ligji & mosmarrëveshjet</span></a>
          <a href="#contact"><i class="fa-regular fa-envelope"></i> <span>Kontakt</span></a>
          <small class="d-block mt-2">Version i përgjithshëm, i përshtatshëm për shumicën e platformave edukative.</small>
        </aside>

      </div>
    </div>
  </section>

  <!-- ================= DOC ================= -->
  <section class="pb-4">
    <div class="ki-wrap">
      <div class="ki-glass ki-doc ki-reveal">

        <section id="about" class="ki-sec">
          <h2 class="ki-h2"><i class="fa-solid fa-circle-info me-2"></i>Përshkrimi i shërbimit</h2>
          <p class="mt-2 mb-2">
            <?= h($BRAND_NAME) ?> ofron një platformë online për kurse/trajnime, promocione, materiale mësimore dhe evente.
            Përmbajtja mund të përditësohet, riorganizohet ose zëvendësohet në çdo kohë për të përmirësuar cilësinë e shërbimit.
          </p>
          <ul>
            <li>Shërbimi mund të përfshijë akses në video, dokumente, detyra, teste dhe komunikim me instruktorë.</li>
            <li>Disponueshmëria e disa funksioneve mund të varet nga plani/lloji i kursit ose nga periudha e ofertës.</li>
          </ul>
        </section>

        <section id="account" class="ki-sec">
          <h2 class="ki-h2"><i class="fa-solid fa-user me-2"></i>Llogaritë & përgjegjësitë e përdoruesit</h2>
          <ul class="mt-2">
            <li>Ju jeni përgjegjës për saktësinë e të dhënave që vendosni gjatë regjistrimit.</li>
            <li>Ruani konfidenciale kredencialet; mos ndani fjalëkalimin me persona të tjerë.</li>
            <li>Ju pranoni të mos përdorni shërbimin për qëllime të paligjshme ose abuzive.</li>
            <li>Ne mund të refuzojmë regjistrimin ose të pezullojmë llogarinë në rast shkeljesh.</li>
          </ul>
        </section>

        <section id="courses" class="ki-sec">
          <h2 class="ki-h2"><i class="fa-solid fa-graduation-cap me-2"></i>Kurse, përmbajtje dhe certifikata</h2>
          <ul class="mt-2">
            <li>Përmbajtja e kurseve (video, dokumente, ushtrime) ofrohet për përdorim personal nga pjesëmarrësi.</li>
            <li>Afatet, oraret, dhe mënyra e zhvillimit (online/live/recorded) përshkruhen në faqet përkatëse të kursit.</li>
            <li>Certifikatat (kur ofrohen) mund të kërkojnë plotësimin e kritereve si: prezencë, detyra, test final, projekt.</li>
            <li><?= h($BRAND_NAME) ?> mund të ndryshojë strukturën e kursit për arsye pedagogjike pa cenuar objektivat kryesore.</li>
          </ul>
        </section>

        <section id="events" class="ki-sec">
          <h2 class="ki-h2"><i class="fa-regular fa-calendar-days me-2"></i>Eventet</h2>
          <ul class="mt-2">
            <li>Eventet mund të kenë vende të kufizuara dhe regjistrimi pranohet sipas radhës.</li>
            <li>Data/ora/vendi mund të ndryshojnë për arsye organizative; do të njoftohet sa të jetë e mundur.</li>
            <li>Për evente të caktuara mund të aplikohen rregulla shtesë (p.sh. kode sjelljeje, orare hyrje/dalje).</li>
          </ul>
        </section>

        <section id="payments" class="ki-sec">
          <h2 class="ki-h2"><i class="fa-solid fa-credit-card me-2"></i>Pagesat, çmimet dhe rimbursimet</h2>
          <p class="mt-2 mb-2">
            Çmimet dhe ofertat shfaqen në faqet përkatëse të kurseve/promocioneve. Nëse aplikohet zbritje, ajo shfaqet në momentin e blerjes.
          </p>
          <ul>
            <li>Pagesat përpunohen përmes kanaleve/partnerëve të pagesave (kur aplikohen).</li>
            <li>Ne zakonisht nuk ruajmë të dhëna të kartave; ato përpunohen nga ofruesit e pagesave.</li>
            <li><strong>Rimbursimet:</strong> nëse ofroni rimbursime, përcaktoni rregullat (afatet, kushtet). Në mungesë të një politike të veçantë,
              vlen praktika e arsyeshme sipas natyrës së shërbimit digjital dhe detyrimeve ligjore.</li>
            <li>Nëse një kurs shtyhet/anulohet për arsye nga organizatori, do të ofrohet zgjidhje (transferim, kredi, ose rimbursim) sipas rastit.</li>
          </ul>
          <div class="mt-2" style="font-weight:800;color:rgba(11,18,32,.70);">
            Sugjerim: Nëse ke rregull të qartë, mund ta lidhësh edhe si “Refund Policy” te footer/faqe më vete.
          </div>
        </section>

        <section id="conduct" class="ki-sec">
          <h2 class="ki-h2"><i class="fa-solid fa-handshake me-2"></i>Sjellja e lejuar dhe ndalimet</h2>
          <ul class="mt-2">
            <li>Ndalohen spam, fyerje, gjuhë urrejtjeje, kërcënime ose përmbajtje diskriminuese.</li>
            <li>Ndalohen tentativat për të thyer sigurinë, scraping masiv, ose ndërhyrje në shërbim.</li>
            <li>Ndalohen shpërndarja e materialeve të kursit pa leje dhe ndarja e llogarive.</li>
            <li>Ne mund të marrim masa (paralajmërim, pezullim, mbyllje llogarie) në rast shkeljesh.</li>
          </ul>
        </section>

        <section id="ip" class="ki-sec">
          <h2 class="ki-h2"><i class="fa-solid fa-copyright me-2"></i>Pronësia intelektuale</h2>
          <ul class="mt-2">
            <li>Materialet (video, tekste, dizajn, logo, kod, dokumente) janë pronë e <?= h($BRAND_NAME) ?> ose partnerëve të tij, përveç kur thuhet ndryshe.</li>
            <li>Lejohet përdorimi personal për qëllime mësimore; ndalohet rishitja, ripublikimi ose shpërndarja pa leje.</li>
            <li>Nëse përdoruesi ngarkon përmbajtje (p.sh. komente, detyra), ai ruan të drejtat mbi të, por na jep leje ta përdorim për funksionimin e shërbimit.</li>
          </ul>
        </section>

        <section id="availability" class="ki-sec">
          <h2 class="ki-h2"><i class="fa-solid fa-server me-2"></i>Disponueshmëria e shërbimit</h2>
          <ul class="mt-2">
            <li>Synojmë disponueshmëri të lartë, por mund të ketë ndërprerje për mirëmbajtje ose për arsye teknike.</li>
            <li>Ne mund të përditësojmë sistemin pa njoftim paraprak kur është e nevojshme për siguri/stabilitet.</li>
          </ul>
        </section>

        <section id="liability" class="ki-sec">
          <h2 class="ki-h2"><i class="fa-solid fa-triangle-exclamation me-2"></i>Kufizim përgjegjësie</h2>
          <ul class="mt-2">
            <li>Shërbimi ofrohet “siç është”; ne nuk garantojmë rezultate specifike punësimi ose fitimi.</li>
            <li><?= h($BRAND_NAME) ?> nuk mban përgjegjësi për humbje indirekte, dëme të rastësishme, ose humbje të të dhënave përtej kontrollit të arsyeshëm.</li>
            <li>Përdoruesi është përgjegjës për pajisjet, internetin dhe konfigurimin e vet.</li>
          </ul>
        </section>

        <section id="privacy" class="ki-sec">
          <h2 class="ki-h2"><i class="fa-solid fa-shield-halved me-2"></i>Privatësia & cookies</h2>
          <p class="mt-2 mb-2">
            Për mënyrën se si përpunojmë të dhënat personale, ju lutemi shihni Politikën e Privatësisë.
          </p>
          <div class="d-flex gap-2 flex-wrap">
            <?php if ($PRIVACY_PAGE !== '#'): ?>
              <a class="ki-btn primary" href="<?= h($PRIVACY_PAGE) ?>"><i class="fa-solid fa-shield-halved"></i> Privacy</a>
            <?php endif; ?>
            <?php if ($COOKIE_PAGE !== '#'): ?>
              <a class="ki-btn" href="<?= h($COOKIE_PAGE) ?>"><i class="fa-solid fa-cookie-bite"></i> Cookies</a>
            <?php endif; ?>
          </div>
        </section>

        <section id="termination" class="ki-sec">
          <h2 class="ki-h2"><i class="fa-solid fa-ban me-2"></i>Pezullimi ose ndërprerja e llogarisë</h2>
          <ul class="mt-2">
            <li>Ne mund të pezullojmë/mbyllim llogarinë në rast shkeljesh të këtyre termave ose për arsye sigurie.</li>
            <li>Ju mund të kërkoni mbylljen e llogarisë duke na kontaktuar (ose nga paneli nëse e ofroni).</li>
            <li>Disa të dhëna mund të ruhen për detyrime ligjore (p.sh. kontabilitet) sipas Politikës së Privatësisë.</li>
          </ul>
        </section>

        <section id="law" class="ki-sec">
          <h2 class="ki-h2"><i class="fa-solid fa-scale-balanced me-2"></i>Ligji i zbatueshëm & zgjidhja e mosmarrëveshjeve</h2>
          <ul class="mt-2">
            <li>Këto terma rregullohen nga: <strong><?= h($GOVERNING_LAW) ?></strong>.</li>
            <li>Për mosmarrëveshje, palët do të përpiqen fillimisht të zgjidhin çështjen miqësisht.</li>
            <li>Nëse nuk arrihet zgjidhje, kompetenca i takon gjykatave në <strong><?= h($VENUE_CITY) ?></strong>, përveç kur ligji parashikon ndryshe.</li>
          </ul>
        </section>

        <section id="contact" class="ki-sec">
          <h2 class="ki-h2"><i class="fa-regular fa-envelope me-2"></i>Kontakt</h2>
          <p class="mt-2 mb-2">
            Për pyetje mbi këto terma:
          </p>
          <ul>
            <li><strong>Operatori:</strong> <?= h($OPERATOR_NAME) ?></li>
            <li><strong>Email:</strong> <?= h($EMAIL) ?></li>
            <li><strong>Telefon:</strong> <?= h($PHONE_DISPLAY) ?></li>
            <li><strong>Adresa:</strong> <?= h($ADDRESS) ?></li>
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
