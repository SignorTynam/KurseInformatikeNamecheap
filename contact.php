<?php
// contact.php — KI v2 • Kontakt (Bootstrap 5) + reCAPTCHA v2 + honeypot + CSRF + time-trap
declare(strict_types=1);
session_start();

/* ===================== Security bootstraps ===================== */
// CSRF token
if (empty($_SESSION['csrf_token'])) {
  $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

// Honeypot: emër i rastësishëm për çdo ngarkim + timestamp për time-trap
$_SESSION['hp_field'] = 'hp_' . bin2hex(random_bytes(6));
$_SESSION['form_issued_at'] = time();
$HP_FIELD = $_SESSION['hp_field'];

function h(?string $s): string { return htmlspecialchars($s ?? '', ENT_QUOTES, 'UTF-8'); }

// reCAPTCHA v2 (checkbox)
$RECAPTCHA_SITE_KEY = getenv('RECAPTCHA_SITE_KEY') ?: '6LcT_OErAAAAAG4HfoB8xebJ4adrFRhED_sRGtc8';

// Opsionale: të dhëna kontakti (vendosi sipas realitetit)
$PHONE_DISPLAY   = '+39 327 469 1197';
$PHONE_TEL       = '+393274691197';
$EMAIL_SUPPORT   = 'info@kurseinformatike.com';
$ADDRESS_DISPLAY = 'Rruga Bilal Konxolli, Tiranë';
$WHATSAPP_LINK   = 'https://wa.me/393274691197?text=P%C3%ABrsh%C3%ABndetje%2C%20dua%20info%20p%C3%ABr%20kurset.';

?>
<!doctype html>
<html lang="sq">
<head>
  <meta charset="utf-8" />
  <meta name="viewport" content="width=device-width,initial-scale=1" />
  <title>Kontakt — kurseinformatike.com</title>
  <meta name="description" content="Na kontaktoni për çdo pyetje. Ekipi ynë ju ndihmon me regjistrime, kurse, evente dhe suport." />
  <link rel="icon" href="image/favicon.ico" type="image/x-icon" />

  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
  <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css" rel="stylesheet">
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@600;700;800;900&family=Roboto:wght@400;500;700&display=swap" rel="stylesheet">

  <script>document.documentElement.classList.add('js');</script>
  <script src="https://www.google.com/recaptcha/api.js" async defer></script>

  <style>
    /* ==========================================================
       KI v2 Contact — match index/promotions/events (full-bleed, glass, marketing-first)
    ========================================================== */
    body.ki-contact{
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

    body.ki-contact{
      margin:0;
      font-family: Roboto, system-ui, -apple-system, Segoe UI, Arial, sans-serif;
      color: var(--ki-text);
      background:
        radial-gradient(900px 600px at 10% 0%, rgba(42,75,124,.10), transparent 55%),
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

    /* Hero band */
    .ki-hero{ padding: 34px 0 18px; }
    .ki-hero-grid{
      display:grid;
      grid-template-columns: 1.1fr .9fr;
      gap: 16px;
      align-items: stretch;
    }
    @media (max-width: 992px){ .ki-hero-grid{ grid-template-columns:1fr; } }

    .ki-hero-card{ padding: 18px; }
    .ki-hero-actions{ display:flex; gap:10px; flex-wrap:wrap; margin-top: 14px; }
    .ki-hero-note{
      margin-top: 12px;
      color: rgba(11,18,32,.66);
      font-weight: 800;
      line-height:1.45;
    }

    /* Content band */
    .ki-band{
      border-top: 1px solid rgba(15,23,42,.08);
      border-bottom: 1px solid rgba(15,23,42,.08);
      background: linear-gradient(180deg, rgba(42,75,124,.05), rgba(240,179,35,.04));
      padding: 44px 0;
    }

    .ki-grid{
      display:grid;
      grid-template-columns: 1.2fr .8fr;
      gap: 16px;
      align-items: start;
    }
    @media (max-width: 992px){ .ki-grid{ grid-template-columns: 1fr; } }

    /* Form */
    .ki-form-wrap{ padding: 18px; }
    .ki-form-head{
      display:flex; align-items:flex-start; justify-content:space-between; gap: 14px;
      margin-bottom: 10px;
    }
    .ki-form-title{
      font-family:Poppins, system-ui, sans-serif;
      font-weight: 900;
      color: var(--ki-ink);
      margin: 0;
      line-height: 1.12;
      font-size: 1.25rem;
    }
    .ki-form-sub{ color: rgba(11,18,32,.68); font-weight: 800; margin: 4px 0 0; line-height:1.45; }

    .ki-label{ font-weight: 900; color: rgba(11,18,32,.76); }
    .ki-required:after{ content:" *"; color:#ef4444; }

    .ki-input, .ki-select, .ki-textarea{
      border-radius: 14px;
      border: 1px solid rgba(15,23,42,.12);
      background: rgba(255,255,255,.70);
      font-weight: 800;
      box-shadow:none !important;
    }
    .ki-textarea{ min-height: 170px; }
    .ki-help{ color: rgba(11,18,32,.60); font-weight: 800; font-size: .92rem; }

    /* Alerts */
    .ki-alert{
      border-radius: 18px;
      padding: 12px 14px;
      display:flex;
      gap: 10px;
      align-items:flex-start;
      border: 1px solid rgba(15,23,42,.12);
      background: rgba(255,255,255,.50);
    }
    .ki-alert.success{ border-color: rgba(16,185,129,.22); background: rgba(236,253,245,.80); color:#065f46; }
    .ki-alert.error{ border-color: rgba(239,68,68,.22); background: rgba(254,242,242,.85); color:#991b1b; }

    /* Info tiles */
    .ki-info{ display:grid; gap: 12px; }
    .ki-tile{
      padding: 14px;
      border-radius: var(--ki-r2);
      border: 1px solid rgba(15,23,42,.12);
      background: rgba(255,255,255,.30);
      backdrop-filter: blur(12px);
      -webkit-backdrop-filter: blur(12px);
      box-shadow: var(--ki-shadow-soft);
    }
    .ki-tile h3{
      font-family:Poppins, system-ui, sans-serif;
      font-weight: 900;
      margin: 0 0 8px;
      font-size: 1.05rem;
      color: var(--ki-ink);
    }
    .ki-row{ display:flex; gap: 12px; align-items:flex-start; }
    .ki-ico{
      width: 44px; height: 44px;
      border-radius: 14px;
      display:flex; align-items:center; justify-content:center;
      background: rgba(255,255,255,.55);
      border: 1px solid rgba(15,23,42,.10);
      color: rgba(11,18,32,.78);
      flex: 0 0 auto;
    }
    .ki-tile a{ text-decoration:none; font-weight: 900; color: rgba(11,18,32,.88); }
    .ki-tile a:hover{ text-decoration: underline; }

    .ki-map{
      border:0; width:100%; height: 240px;
      border-radius: var(--ki-r2);
      box-shadow: var(--ki-shadow-soft);
      overflow:hidden;
    }

    /* Reveal */
    .ki-reveal{ opacity:1; transform:none; transition: all .45s ease; }
    .js .ki-reveal{ opacity:0; transform: translateY(10px); }
    .ki-reveal.show{ opacity:1 !important; transform:none !important; }
    @media (prefers-reduced-motion: reduce){
      .js .ki-reveal{ opacity:1; transform:none; transition:none; }
      .ki-btn{ transition:none; }
    }
  </style>
</head>

<body class="ki-contact">

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
  <section class="ki-hero" id="top">
    <div class="ki-wrap">
      <div class="ki-hero-grid">

        <div class="ki-reveal">
          <div class="ki-kicker">
            <i class="fa-solid fa-headset"></i>
            <span>Suport • Regjistrime • Pyetje për kurset</span>
          </div>

          <h1 class="ki-h1 mt-3">Na kontaktoni</h1>
          <p class="ki-lead mt-3">
            Dërgoni mesazh për informacione mbi kurset, oraret, çmimet, certifikimet dhe eventet.
            Zakonisht përgjigjemi brenda 24 orëve.
          </p>

          <div class="ki-hero-actions">
            <a class="ki-btn primary" href="#contact"><i class="fa-solid fa-paper-plane"></i> Dërgo mesazh</a>
            <a class="ki-btn dark" href="<?= h($WHATSAPP_LINK) ?>" target="_blank" rel="noopener"><i class="fa-brands fa-whatsapp"></i> WhatsApp</a>
            <a class="ki-btn" href="tel:<?= h($PHONE_TEL) ?>"><i class="fa-solid fa-phone"></i> Telefon</a>
            <a class="ki-btn" href="mailto:<?= h($EMAIL_SUPPORT) ?>"><i class="fa-regular fa-envelope"></i> Email</a>
          </div>

          <div class="ki-hero-note">
            Tip: nëse na shkruani në WhatsApp, përfshini “kursin” që kërkoni (p.sh. Python, Excel, Web).
          </div>
        </div>

        <div class="ki-reveal" style="transition-delay:.08s;">
          <div class="ki-glass ki-hero-card">
            <h2 class="ki-h2" style="font-size:1.25rem;">Pse të na shkruani?</h2>
            <p class="ki-lead mt-2" style="font-size:1rem;">
              Përgjigje të shpejta, udhëzim për regjistrim, këshillim kursi sipas nivelit, dhe suport teknik për “Virtuale”.
            </p>

            <div class="mt-3 d-grid gap-2">
              <a class="ki-btn primary" href="promotions_public.php"><i class="fa-solid fa-tag"></i> Shiko promocionet</a>
              <a class="ki-btn" href="courses.php"><i class="fa-solid fa-book"></i> Shiko kurset</a>
              <a class="ki-btn" href="events.php"><i class="fa-regular fa-calendar-days"></i> Shiko eventet</a>
            </div>

            <div class="mt-3" style="color: rgba(11,18,32,.66); font-weight: 800; line-height:1.45;">
              <i class="fa-regular fa-circle-check me-1" style="color:#16a34a;"></i>
              Formulari ka mbrojtje kundër spam-it (reCAPTCHA).
            </div>
          </div>
        </div>

      </div>
    </div>
  </section>

  <!-- ================= CONTENT ================= -->
  <section class="ki-band" id="contact">
    <div class="ki-wrap">
      <div class="ki-grid">

        <!-- Form -->
        <div class="ki-glass ki-form-wrap ki-reveal" aria-live="polite">
          <div class="ki-form-head">
            <div>
              <div class="ki-form-title">Na dërgo një mesazh</div>
              <div class="ki-form-sub">Plotësoni fushat dhe ne ju kontaktojmë sa më shpejt.</div>
            </div>
            <a class="ki-btn" href="#top"><i class="fa-solid fa-arrow-up"></i></a>
          </div>

          <!-- Flash messages -->
          <?php if (isset($_SESSION['success_message'])): ?>
            <div class="ki-alert success mt-3">
              <i class="fa-solid fa-circle-check mt-1"></i>
              <div><strong>Sukses!</strong> <?= h($_SESSION['success_message']); ?></div>
            </div>
            <?php unset($_SESSION['success_message']); ?>
          <?php endif; ?>

          <?php if (isset($_SESSION['error_message'])): ?>
            <div class="ki-alert error mt-3">
              <i class="fa-solid fa-circle-exclamation mt-1"></i>
              <div><strong>Kujdes!</strong> <?= h($_SESSION['error_message']); ?></div>
            </div>
            <?php unset($_SESSION['error_message']); ?>
          <?php endif; ?>

          <form class="mt-3" action="contact_form.php#contact" method="POST" novalidate id="contactForm">
            <!-- Honeypot (random name) -->
            <input type="text"
              name="<?= h($HP_FIELD) ?>"
              id="<?= h($HP_FIELD) ?>"
              autocomplete="new-password"
              tabindex="-1"
              style="position:absolute; left:-10000px; top:auto; width:1px; height:1px; overflow:hidden;"
              aria-hidden="true">

            <!-- CSRF + time trap -->
            <input type="hidden" name="csrf_token" value="<?= h($_SESSION['csrf_token']) ?>">
            <input type="hidden" name="form_ts" value="<?= (int)$_SESSION['form_issued_at'] ?>">

            <div class="row g-3">
              <div class="col-12 col-md-6">
                <label for="name" class="form-label ki-label ki-required">Emri juaj</label>
                <input type="text" class="form-control ki-input" id="name" name="name"
                  placeholder="p.sh. Ardit Pasha"
                  value="<?= h($_SESSION['old_name'] ?? '') ?>"
                  minlength="2" maxlength="100" required>
              </div>

              <div class="col-12 col-md-6">
                <label for="email" class="form-label ki-label ki-required">Email adresa</label>
                <input type="email" class="form-control ki-input" id="email" name="email"
                  placeholder="ju@example.com"
                  value="<?= h($_SESSION['old_email'] ?? '') ?>"
                  inputmode="email" maxlength="100" required>
              </div>

              <div class="col-12">
                <label for="subject" class="form-label ki-label ki-required">Subjekti</label>
                <input type="text" class="form-control ki-input" id="subject" name="subject"
                  placeholder="p.sh. Info për kursin Python / Orari / Çmimi"
                  value="<?= h($_SESSION['old_subject'] ?? '') ?>"
                  minlength="3" maxlength="255" required>
              </div>

              <div class="col-12">
                <label for="message" class="form-label ki-label ki-required">Mesazhi</label>
                <textarea class="form-control ki-textarea" id="message" name="message" rows="6"
                  placeholder="Shkruani mesazhin tuaj këtu..."
                  minlength="10" maxlength="2000" required><?= h($_SESSION['old_message'] ?? '') ?></textarea>
                <div class="d-flex justify-content-between mt-1 ki-help">
                  <span>Min. 10 karaktere</span>
                  <span><span id="charCount">0</span>/2000</span>
                </div>
              </div>

              <div class="col-12">
                <div class="g-recaptcha" data-sitekey="<?= h($RECAPTCHA_SITE_KEY) ?>"></div>
                <div class="ki-help mt-2">
                  Nëse reCAPTCHA nuk shfaqet, rifreskoni faqen.
                </div>
              </div>

              <div class="col-12 d-flex gap-2 flex-wrap mt-2">
                <button type="submit" class="ki-btn primary" style="min-width:190px;">
                  <i class="fa-solid fa-paper-plane"></i> Dërgo mesazhin
                </button>
                <a href="<?= h($WHATSAPP_LINK) ?>" target="_blank" rel="noopener" class="ki-btn dark" style="min-width:190px;">
                  <i class="fa-brands fa-whatsapp"></i> Shkruaj në WhatsApp
                </a>
                <a href="index.php" class="ki-btn" style="min-width:160px;">
                  <i class="fa-solid fa-house"></i> Kryefaqja
                </a>
              </div>

              <div class="col-12">
                <div class="ki-help mt-2">
                  Duke dërguar këtë formular, ju pranoni
                  <a href="<?= file_exists(__DIR__.'/privacy.php') ? 'privacy.php' : '#' ?>" class="text-decoration-underline" style="font-weight:900;color:rgba(11,18,32,.88);">
                    politikat e privatësisë
                  </a>.
                </div>
              </div>
            </div>
          </form>
        </div>

        <!-- Info -->
        <aside class="ki-info ki-reveal" style="transition-delay:.08s;">
          <div class="ki-tile">
            <div class="ki-row">
              <div class="ki-ico"><i class="fa-solid fa-location-dot"></i></div>
              <div>
                <h3>Adresa</h3>
                <div style="color:rgba(11,18,32,.74); font-weight:800; line-height:1.45;">
                  <?= h($ADDRESS_DISPLAY) ?>
                </div>
                <div class="mt-2 d-flex gap-2 flex-wrap">
                  <a class="ki-btn" target="_blank" rel="noopener"
                     href="https://maps.google.com/?q=<?= rawurlencode($ADDRESS_DISPLAY) ?>">
                    <i class="fa-solid fa-map"></i> Harta
                  </a>
                  <a class="ki-btn" href="events.php"><i class="fa-regular fa-calendar-days"></i> Evente</a>
                </div>
              </div>
            </div>

            <div class="mt-3">
              <iframe class="ki-map"
                src="https://www.google.com/maps?q=<?= rawurlencode($ADDRESS_DISPLAY) ?>&output=embed"
                loading="lazy" referrerpolicy="no-referrer-when-downgrade"></iframe>
            </div>
          </div>

          <div class="ki-tile">
            <div class="ki-row">
              <div class="ki-ico"><i class="fa-solid fa-phone"></i></div>
              <div>
                <h3>Kontakt i shpejtë</h3>
                <div style="color:rgba(11,18,32,.74); font-weight:800; line-height:1.55;">
                  Telefon: <a href="tel:<?= h($PHONE_TEL) ?>"><?= h($PHONE_DISPLAY) ?></a><br>
                  Email: <a href="mailto:<?= h($EMAIL_SUPPORT) ?>"><?= h($EMAIL_SUPPORT) ?></a>
                </div>
                <div class="mt-2 d-flex gap-2 flex-wrap">
                  <a class="ki-btn dark" target="_blank" rel="noopener" href="<?= h($WHATSAPP_LINK) ?>">
                    <i class="fa-brands fa-whatsapp"></i> WhatsApp
                  </a>
                  <a class="ki-btn" href="mailto:<?= h($EMAIL_SUPPORT) ?>"><i class="fa-regular fa-envelope"></i> Email</a>
                </div>
              </div>
            </div>
          </div>

          <div class="ki-tile">
            <div class="ki-row">
              <div class="ki-ico"><i class="fa-regular fa-clock"></i></div>
              <div>
                <h3>Orari</h3>
                <div style="color:rgba(11,18,32,.74); font-weight:800; line-height:1.55;">
                  Hënë–Premte: 08:00–20:00<br>
                  Shtunë: 09:00–14:00<br>
                  E diel: mbyllur
                </div>
                <div class="mt-2 d-flex gap-2 flex-wrap">
                  <a class="ki-btn" href="promotions_public.php"><i class="fa-solid fa-tag"></i> Promocione</a>
                  <a class="ki-btn" href="courses.php"><i class="fa-solid fa-book"></i> Kurse</a>
                </div>
              </div>
            </div>
          </div>
        </aside>

      </div>
    </div>
  </section>

  <section class="ki-band" id="contact">
    <div class="ki-wrap">
        <div class="ki-tile">
          <div class="ki-row">
            <div class="ki-ico"><i class="fa-solid fa-share-nodes"></i></div>
            <div>
              <h3>Na ndiqni</h3>
              <div style="color:rgba(11,18,32,.70); font-weight:800; line-height:1.55;">
                Ndiqni për njoftime, evente dhe oferta.
              </div>
              <div class="mt-2 d-flex gap-2 flex-wrap">
                <a class="ki-btn" href="#" target="_blank" rel="noopener"><i class="fa-brands fa-instagram"></i> Instagram</a>
                <a class="ki-btn" href="#" target="_blank" rel="noopener"><i class="fa-brands fa-facebook-f"></i> Facebook</a>
                <a class="ki-btn" href="#" target="_blank" rel="noopener"><i class="fa-brands fa-youtube"></i> YouTube</a>
              </div>
            </div>
          </div>
        </div>
    </div>
  </section>
</main>

<?php include __DIR__ . '/footer.php'; ?>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
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

  // Smooth scroll for anchors
  document.querySelectorAll('a[href^="#"]').forEach(a=>{
    a.addEventListener('click', (e)=>{
      const id = a.getAttribute('href');
      const t = document.querySelector(id);
      if (!t) return;
      e.preventDefault();
      window.scrollTo({ top: t.getBoundingClientRect().top + window.pageYOffset - 90, behavior:'smooth' });
    });
  });

  // Basic bootstrap validation
  const form = document.getElementById('contactForm');
  if (form){
    form.addEventListener('submit', function(e){
      if (!form.checkValidity()){
        e.preventDefault();
        e.stopPropagation();
      }
      form.classList.add('was-validated');
    });
  }

  // Character counter
  const msg = document.getElementById('message');
  const cc  = document.getElementById('charCount');
  function upd(){ if (msg && cc) cc.textContent = String((msg.value || '').length); }
  if (msg){ msg.addEventListener('input', upd); upd(); }
</script>

</body>
</html>
