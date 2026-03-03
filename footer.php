<?php // footer.php — KI v2 Footer (match: HOME v2) ?>

<style>
/* ==========================================================
   Footer — KI v2 Night Band
   Përdor var-ët ki-* kur ekzistojnë (index v2), ka fallback.
========================================================== */
:root{
  --ki-primary:   #2A4B7C;
  --ki-primary-2: #1d3a63;
  --ki-secondary: #F0B323;
  --ki-ink:       #0b1220;
  --ki-muted:     #6b7280;
  --ki-line:      rgba(255,255,255,.12);
  --ki-shadow-soft: 0 18px 44px rgba(11, 18, 32, .10);
  --ki-r: 22px;
  --ki-r2: 28px;
}

/* Full-bleed band */
.ki-footer{
  background:
    radial-gradient(900px 600px at 20% 10%, rgba(240,179,35,.16), transparent 58%),
    radial-gradient(900px 600px at 90% 20%, rgba(42,75,124,.28), transparent 62%),
    linear-gradient(180deg, #0b1220, #0a1020);
  color: rgba(255,255,255,.86);
  border-top: 1px solid rgba(255,255,255,.10);
}

/* Top area */
.ki-footer .ki-foot-top{
  padding: 54px 0 18px;
}

/* Headings */
.ki-footer h5{
  font-family: Poppins, system-ui, sans-serif;
  font-weight: 900;
  letter-spacing: .2px;
  font-size: 1.05rem;
  margin: 0 0 14px;
  color: #fff;
}
.ki-footer .ki-sub{
  color: rgba(255,255,255,.70);
  font-weight: 700;
  line-height: 1.55;
  margin: 0;
}

/* Brand mark */
.ki-footer .ki-brand{
  display:flex;
  align-items:center;
  gap: 10px;
}
.ki-footer .ki-brand .mark{
  width: 42px; height: 42px;
  border-radius: 16px;
  display:flex; align-items:center; justify-content:center;
  background:
    radial-gradient(20px 20px at 35% 30%, rgba(240,179,35,.55), rgba(240,179,35,0) 70%),
    rgba(255,255,255,.06);
  border: 1px solid rgba(255,255,255,.12);
  box-shadow: var(--ki-shadow-soft);
  color: #fff;
}
.ki-footer .ki-brand .name{
  font-family: Poppins, system-ui, sans-serif;
  font-weight: 900;
  letter-spacing: .35px;
  color: #fff;
}

/* Lists & links */
.ki-footer a{
  color: rgba(255,255,255,.78);
  text-decoration: none;
  font-weight: 700;
}
.ki-footer a:hover{
  color: var(--ki-secondary);
}
.ki-footer .ki-list{
  list-style: none;
  padding: 0;
  margin: 0;
  display: grid;
  gap: 10px;
}
.ki-footer .ki-list li{
  display:flex;
  gap: 10px;
  align-items:flex-start;
}
.ki-footer .ki-list i{
  color: rgba(255,255,255,.62);
  margin-top: 2px;
}

/* Social */
.ki-footer .ki-social{
  display:flex;
  gap: 10px;
  flex-wrap: wrap;
  margin-top: 14px;
}
.ki-footer .ki-social a{
  width: 40px;
  height: 40px;
  border-radius: 999px;
  display:inline-flex;
  align-items:center;
  justify-content:center;
  background: rgba(255,255,255,.08);
  border: 1px solid rgba(255,255,255,.12);
  color: rgba(255,255,255,.90);
  transition: transform .15s ease, background .15s ease, border-color .15s ease;
}
.ki-footer .ki-social a:hover{
  transform: translateY(-1px);
  background: rgba(240,179,35,.18);
  border-color: rgba(240,179,35,.35);
  color: #fff;
}

/* Mini card (newsletter/contact highlight) */
.ki-footer .ki-foot-card{
  border-radius: var(--ki-r2);
  border: 1px solid rgba(255,255,255,.12);
  background: rgba(255,255,255,.06);
  backdrop-filter: blur(10px);
  -webkit-backdrop-filter: blur(10px);
  padding: 16px;
}

/* Bottom bar */
.ki-footer .ki-foot-bottom{
  border-top: 1px solid rgba(255,255,255,.10);
  padding: 14px 0;
  color: rgba(255,255,255,.68);
  font-weight: 700;
  font-size: .95rem;
}
.ki-footer .ki-foot-bottom a{
  color: rgba(255,255,255,.72);
}
.ki-footer .ki-foot-bottom a:hover{
  color: var(--ki-secondary);
}

/* Small utility */
.ki-footer .small-note{
  color: rgba(255,255,255,.64);
  font-weight: 700;
}

/* Responsive tweaks */
@media (max-width: 991.98px){
  .ki-footer .ki-foot-top{ padding: 46px 0 14px; }
}
</style>

<footer class="ki-footer mt-auto" role="contentinfo">
  <div class="container">
    <div class="ki-foot-top">
      <div class="row g-4 align-items-start">

        <!-- Brand / about -->
        <div class="col-12 col-lg-4">
          <div class="ki-brand">
            <div class="mark"><i class="fa-solid fa-graduation-cap"></i></div>
            <div class="name">KURSEINFORMATIKE</div>
          </div>

          <p class="ki-sub mt-3">
            Platformë e mësimit online në IT, programim dhe gjuhë të huaja.
            Fokus praktik, projekte reale dhe certifikim.
          </p>

          <div class="ki-social" aria-label="Rrjetet sociale">
            <a href="#" aria-label="Facebook"><i class="fa-brands fa-facebook-f"></i></a>
            <a href="#" aria-label="Instagram"><i class="fa-brands fa-instagram"></i></a>
            <a href="#" aria-label="LinkedIn"><i class="fa-brands fa-linkedin-in"></i></a>
            <a href="#" aria-label="YouTube"><i class="fa-brands fa-youtube"></i></a>
          </div>

          <div class="small-note mt-3">
            <i class="fa-regular fa-circle-check me-1" style="color: rgba(240,179,35,.95);"></i>
            Mbështetje e shpejtë dhe materiale të organizuara.
          </div>
        </div>

        <!-- Menu -->
        <div class="col-6 col-lg-2">
          <h5>Menu</h5>
          <ul class="ki-list">
            <li><i class="fa-solid fa-house"></i><a href="index.php">Kryefaqja</a></li>
            <li><i class="fa-solid fa-book"></i><a href="courses.php">Kurset</a></li>
            <li><i class="fa-regular fa-calendar-days"></i><a href="events.php">Eventet</a></li>
            <li><i class="fa-regular fa-envelope"></i><a href="contact.php">Kontakt</a></li>
          </ul>
        </div>

        <!-- Kurset -->
        <div class="col-6 col-lg-3">
          <h5>Kategoritë</h5>
          <ul class="ki-list">
            <li><i class="fa-solid fa-code"></i><a href="courses.php?q=programim">Programim</a></li>
            <li><i class="fa-solid fa-globe"></i><a href="courses.php?q=web">Web Development</a></li>
            <li><i class="fa-solid fa-table"></i><a href="courses.php?q=excel">Microsoft Office</a></li>
            <li><i class="fa-solid fa-language"></i><a href="courses.php?q=italiane">Gjuhë të huaja</a></li>
            <li><i class="fa-solid fa-shield-halved"></i><a href="courses.php?q=cybersecurity">Cybersecurity</a></li>
          </ul>
        </div>

        <!-- Contact / mini card -->
        <div class="col-12 col-lg-3">
          <div class="ki-foot-card">
            <h5 style="margin-bottom:12px;">Na kontaktoni</h5>
            <ul class="ki-list">
              <li><i class="fa-solid fa-location-dot"></i><span>Rruga Bilal Konxholli, Tiranë</span></li>
              <li><i class="fa-solid fa-phone"></i><span>+39 327 469 1197</span></li>
              <li><i class="fa-solid fa-envelope"></i><span>info@kurseinformatike.com</span></li>
              <li><i class="fa-regular fa-clock"></i><span>Hënë–Premte: 08:00–20:00</span></li>
            </ul>

            <div class="mt-3 d-flex gap-2 flex-wrap">
              <a class="btn btn-sm"
                 href="contact.php"
                 style="border-radius:14px;font-weight:900;background:rgba(255,255,255,.08);border:1px solid rgba(255,255,255,.14);color:rgba(255,255,255,.92);">
                <i class="fa-regular fa-envelope me-1"></i> Dërgo mesazh
              </a>
              <a class="btn btn-sm"
                 href="signup.php"
                 style="border-radius:14px;font-weight:900;background:linear-gradient(135deg, var(--ki-secondary), #ffd36a);border:1px solid rgba(240,179,35,.55);color:#111827;">
                <i class="fa-solid fa-user-plus me-1"></i> Regjistrohu
              </a>
            </div>
          </div>
        </div>

      </div>
    </div>

    <!-- Bottom -->
    <div class="ki-foot-bottom d-flex flex-column flex-md-row justify-content-between align-items-center gap-2">
      <div>© <?= date('Y') ?> kurseinformatike.com. Të gjitha të drejtat e rezervuara.</div>
      <div class="small">
        <a href="privacy.php" class="me-2">Privatësia</a> ·
        <a href="terms.php" class="ms-2">Termat</a>
      </div>
    </div>
  </div>
</footer>
