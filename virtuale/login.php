<?php
// login.php — KI v2 Auth (glass) + CSRF + Remember Me (hash) + redirect sipas rolit + AppToast
declare(strict_types=1);
session_start();
require_once __DIR__ . '/lib/database.php';

function h(?string $s): string { return htmlspecialchars($s ?? '', ENT_QUOTES, 'UTF-8'); }

/* -------------------- Flash helpers (toasts) -------------------- */
function set_flash(string $msg, string $type='info'): void {
  $_SESSION['flash'] = ['msg'=>$msg,'type'=>$type];
}
function get_flash(): ?array {
  if (!empty($_SESSION['flash'])) { $f = $_SESSION['flash']; unset($_SESSION['flash']); return $f; }
  return null;
}

/* -------------------- Config -------------------- */
$REMEMBER_COOKIE_DAYS = 30;
$IS_HTTPS = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || (($_SERVER['SERVER_PORT'] ?? '') == 443);

/* -------------------- Helper: Redirect by role -------------------- */
function redirect_by_role(string $role): void {
  switch ($role) {
    case 'Administrator': header('Location: dashboard_admin.php'); break;
    case 'Instruktor':    header('Location: dashboard_instruktor.php'); break;
    case 'Student':       header('Location: dashboard_student.php'); break;
    default:              header('Location: index.php'); break;
  }
  exit;
}

/* -------------------- Auto-login nga cookie (nëse s’ka seancë) -------------------- */
if (empty($_SESSION['user']) && isset($_COOKIE['ru'], $_COOKIE['rt'])) {
  $ru = $_COOKIE['ru']; // user id
  $rt = $_COOKIE['rt']; // raw token

  if (ctype_digit((string)$ru) && is_string($rt) && strlen($rt) === 64 && ctype_xdigit($rt)) {
    try {
      /** @var PDO $pdo */
      $stmt = $pdo->prepare("SELECT * FROM users WHERE id = ?");
      $stmt->execute([(int)$ru]);
      $u = $stmt->fetch(PDO::FETCH_ASSOC);
      if ($u) {
        $expected = $u['remember_token'] ?? null; // ruajtur si hash (sha256)
        $rt_hash  = hash('sha256', $rt);
        if (hash_equals((string)$expected, $rt_hash)) {
          session_regenerate_id(true);
          $_SESSION['user'] = [
            'id'        => (int)$u['id'],
            'email'     => $u['email'],
            'role'      => $u['role'],
            'full_name' => $u['full_name'],
            'status'    => $u['status'],
          ];
          set_flash('Mirë se erdhe, '.($u['full_name'] ?: 'përdorues').'!', 'success');
          redirect_by_role($u['role']);
        }
      }
    } catch (Throwable $e) {
      // injoro auto-login gabimet
    }
  }
}

/* -------------------- Nëse tashmë i futur, dërgo në dashboard -------------------- */
if (!empty($_SESSION['user']['role'])) {
  redirect_by_role((string)$_SESSION['user']['role']);
}

/* -------------------- CSRF -------------------- */
if (empty($_SESSION['csrf_token'])) {
  $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$csrf = $_SESSION['csrf_token'];

/* -------------------- Rate limit i thjeshtë -------------------- */
$_SESSION['login_failures']     = $_SESSION['login_failures']     ?? 0;
$_SESSION['login_locked_until'] = $_SESSION['login_locked_until'] ?? 0;
$locked_until = $_SESSION['login_locked_until'];
$now_ts = time();
$LOCK_WINDOW_SECS = 60; // 1 minutë bllok pas >5 dështimesh
$MAX_FAILS = 5;

$response = '';          // alert fallback (mbetet)
$toastNow = null;        // ['type'=>'success|error|warning|info','msg'=>'...']
$oldEmail = '';          // për ta ruajtur në form

/* -------------------- POST: Login -------------------- */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  // CSRF
  if (!hash_equals($csrf, $_POST['csrf_token'] ?? '')) {
    $response = '<div class="alert alert-danger">Seanca e pavlefshme. Ju lutem rifreskoni faqen.</div>';
    $toastNow = ['type'=>'error','msg'=>'Seanca e pavlefshme. Ju lutem rifreskoni faqen.'];
  }
  // Rate limit
  elseif ($locked_until && $now_ts < (int)$locked_until) {
    $sec_left = (int)$locked_until - $now_ts;
    $response = '<div class="alert alert-danger">Shumë tentativa të dështuara. Provo përsëri pas '.$sec_left.'s.</div>';
    $toastNow = ['type'=>'error','msg'=>'Shumë tentativa të dështuara. Provo përsëri pas '.$sec_left.'s.'];
  } else {
    $email    = trim((string)($_POST['email'] ?? ''));
    $password = (string)($_POST['password'] ?? '');
    $remember = isset($_POST['remember']);

    $oldEmail = $email;

    if ($email === '' || $password === '') {
      $response = '<div class="alert alert-danger">Ju lutem plotësoni email-in dhe fjalëkalimin.</div>';
      $toastNow = ['type'=>'error','msg'=>'Ju lutem plotësoni email-in dhe fjalëkalimin.'];
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
      $response = '<div class="alert alert-danger">Email i pavlefshëm.</div>';
      $toastNow = ['type'=>'error','msg'=>'Email i pavlefshëm.'];
    } else {
      try {
        /** @var PDO $pdo */
        $stmt = $pdo->prepare("SELECT * FROM users WHERE email = :email LIMIT 1");
        $stmt->bindValue(':email', $email);
        $stmt->execute();
        $user = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($user && password_verify($password, $user['password'])) {
          // Status kontroll
          if ($user['status'] === 'REFUZUAR') {
            $response = '<div class="alert alert-danger">Llogaria juaj është refuzuar.</div>';
            $toastNow = ['type'=>'error','msg'=>'Llogaria juaj është refuzuar.'];
          } elseif ($user['status'] === 'NE SHQYRTIM') {
            $response = '<div class="alert alert-warning">Llogaria juaj është në shqyrtim. Do të njoftoheni sapo të aprovohet.</div>';
            $toastNow = ['type'=>'warning','msg'=>'Llogaria juaj është në shqyrtim. Do të njoftoheni sapo të aprovohet.'];
          } else {
            // OK: login
            session_regenerate_id(true);
            $_SESSION['user'] = [
              'id'        => (int)$user['id'],
              'email'     => $user['email'],
              'role'      => $user['role'],
              'full_name' => $user['full_name'],
              'status'    => $user['status'],
            ];

            // Remember me → gjenero token raw dhe ruaj hash në DB
            if ($remember) {
              $raw  = bin2hex(random_bytes(32)); // 64 hex
              $hash = hash('sha256', $raw);
              $upd = $pdo->prepare("UPDATE users SET remember_token = :t WHERE id = :id");
              $upd->execute([':t' => $hash, ':id' => (int)$user['id']]);

              setcookie('ru', (string)$user['id'], [
                'expires'  => time() + 60*60*24*$REMEMBER_COOKIE_DAYS,
                'path'     => '/',
                'secure'   => $IS_HTTPS,
                'httponly' => true,
                'samesite' => 'Lax',
              ]);
              setcookie('rt', $raw, [
                'expires'  => time() + 60*60*24*$REMEMBER_COOKIE_DAYS,
                'path'     => '/',
                'secure'   => $IS_HTTPS,
                'httponly' => true,
                'samesite' => 'Lax',
              ]);
            } else {
              // pastro token-at ekzistues
              try {
                $pdo->prepare("UPDATE users SET remember_token = NULL WHERE id = ?")
                    ->execute([(int)$user['id']]);
              } catch (Throwable $e) {}
              setcookie('ru', '', time() - 3600, '/');
              setcookie('rt', '', time() - 3600, '/');
            }

            // reset rate limit
            $_SESSION['login_failures']     = 0;
            $_SESSION['login_locked_until'] = 0;

            set_flash('Mirë se erdhe, '.($user['full_name'] ?: 'përdorues').'!', 'success');
            redirect_by_role($user['role']);
          }
        } else {
          $_SESSION['login_failures'] = (int)$_SESSION['login_failures'] + 1;
          if ($_SESSION['login_failures'] > $MAX_FAILS) {
            $_SESSION['login_locked_until'] = $now_ts + $LOCK_WINDOW_SECS;
          }
          $response = '<div class="alert alert-danger">Email ose fjalëkalim i pavlefshëm.</div>';
          $toastNow = ['type'=>'error','msg'=>'Email ose fjalëkalim i pavlefshëm.'];
        }
      } catch (Throwable $e) {
        $response = '<div class="alert alert-danger">Gabim i brendshëm. Provo përsëri.</div>';
        $toastNow = ['type'=>'error','msg'=>'Gabim i brendshëm. Provo përsëri.'];
      }
    }
  }
}

// Toast për dalje (nëse vjen me query nga logout.php)
if (isset($_GET['logout']) && $_GET['logout'] === '1') {
  $toastNow = ['type'=>'info','msg'=>'Dole me sukses nga llogaria.'];
}
?>
<!doctype html>
<html lang="sq">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <title>Hyrje — kurseinformatike.com</title>
  <meta name="description" content="Hyrje në kurseinformatike.com — platformë për kurse IT, Programim dhe gjuhë të huaja.">
  <link rel="icon" href="image/favicon.ico" type="image/x-icon">

  <!-- Bootstrap & Icons -->
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
  <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css" rel="stylesheet">

  <!-- Fonts (KI v2) -->
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@600;700;800;900&family=Roboto:wght@400;500;700&display=swap" rel="stylesheet">

  <!-- AppToast (nëse e përdor) -->
  <link href="css/toast.css" rel="stylesheet">

  <style>
    /* ==========================================================
       KI v2 — AUTH / LOGIN (match promotions & landing)
    ========================================================== */
    body.ki-auth{
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
    body.ki-auth{
      margin:0;
      font-family: Roboto, system-ui, -apple-system, Segoe UI, Arial, sans-serif;
      color: var(--ki-text);
      background:
        radial-gradient(900px 600px at 10% 0%, rgba(42,75,124,.12), transparent 55%),
        radial-gradient(900px 600px at 90% 10%, rgba(240,179,35,.14), transparent 55%),
        linear-gradient(180deg, var(--ki-ice), var(--ki-sand));
      min-height: 100vh;
    }
    a{ color: inherit; text-decoration: none; }
    a:hover{ text-decoration: none; }
    .ki-wrap{ width: min(var(--ki-wrap), calc(100% - 32px)); margin-inline: auto; }

    /* (Opsionale) Slim navbar, nëse navbar.php është Bootstrap navbar */
    body.ki-auth .navbar{
      padding-top: .45rem;
      padding-bottom: .45rem;
      background: rgba(255,255,255,.55) !important;
      border-bottom: 1px solid rgba(15,23,42,.10);
      backdrop-filter: blur(10px);
    }
    body.ki-auth .navbar .nav-link{ padding-top:.35rem; padding-bottom:.35rem; }
    body.ki-auth .navbar .navbar-brand{ font-weight: 900; letter-spacing:.2px; }

    /* Type */
    .ki-h1{ font-family:Poppins,system-ui,sans-serif; font-weight:900; letter-spacing:.1px; line-height:1.05; margin:0; color: var(--ki-ink); }
    .ki-h2{ font-family:Poppins,system-ui,sans-serif; font-weight:900; letter-spacing:.1px; line-height:1.1; margin:0; color: var(--ki-ink); }
    .ki-lead{ color: rgba(11,18,32,.72); line-height:1.6; margin:0; font-size: 1.04rem; }
    .ki-mini{ font-weight: 900; letter-spacing: .2px; color: rgba(11,18,32,.68); text-transform: uppercase; font-size: .78rem; }

    /* Glass card */
    .ki-glass{
      border-radius: var(--ki-r2);
      border: 1px solid rgba(15,23,42,.10);
      box-shadow: var(--ki-shadow-soft);
      background: linear-gradient(180deg, rgba(255,255,255,.38), rgba(255,255,255,.14));
      backdrop-filter: blur(14px);
      -webkit-backdrop-filter: blur(14px);
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
    }
    .ki-btn:hover{ transform: translateY(-1px); }
    .ki-btn.primary{ background: linear-gradient(135deg, var(--ki-secondary), #ffd36a); border-color: rgba(240,179,35,.55); color:#111827; }
    .ki-btn.dark{ background: rgba(11,18,32,.92); border-color: rgba(11,18,32,.92); color:#fff; }
    .ki-btn.ghost{ background: rgba(255,255,255,.20); border-color: rgba(15,23,42,.10); color: rgba(11,18,32,.86); }

    /* Hero header */
    .ki-hero{ padding: 34px 0 16px; }
    .ki-kicker{
      display:inline-flex; align-items:center; gap:10px;
      padding: 8px 12px;
      border-radius: 999px;
      border: 1px solid rgba(15,23,42,.10);
      background: rgba(255,255,255,.40);
      backdrop-filter: blur(10px);
      font-weight: 900;
      color: rgba(11,18,32,.84);
    }
    .ki-kicker i{ color: var(--ki-secondary); }

    /* Layout */
    .ki-section{ padding: 18px 0 48px; }
    .ki-grid{
      display:grid;
      grid-template-columns: 1fr 420px;
      gap: 16px;
      align-items: stretch;
      margin-top: 14px;
    }
    @media (max-width: 992px){
      .ki-grid{ grid-template-columns: 1fr; }
    }

    .ki-panel{ padding: 18px; }
    .ki-card{ padding: 18px; }

    /* Feature list */
    .ki-list{ margin: 12px 0 0; padding: 0; list-style: none; display:grid; gap: 10px; }
    .ki-li{
      display:flex; gap:12px; align-items:flex-start;
      padding: 12px 12px;
      border-radius: 18px;
      background: rgba(255,255,255,.18);
      border: 1px solid rgba(15,23,42,.10);
    }
    .ki-ic{
      width: 40px; height: 40px; border-radius: 14px;
      display:flex; align-items:center; justify-content:center;
      background: rgba(42,75,124,.12);
      color: var(--ki-primary-2);
      flex: 0 0 auto;
    }
    .ki-li b{ color: var(--ki-ink); font-weight: 900; }
    .ki-li .muted{ color: rgba(11,18,32,.70); font-weight: 700; line-height:1.35; }

    /* Form */
    .ki-form label{ font-weight: 900; color: rgba(11,18,32,.82); }
    .ki-input{
      border-radius: 16px !important;
      border: 1px solid rgba(15,23,42,.14) !important;
      background: rgba(255,255,255,.42) !important;
      font-weight: 800;
      box-shadow: none !important;
      color: rgba(11,18,32,.90);
    }
    .ki-input:focus{
      box-shadow: 0 0 0 6px rgba(240,179,35,.18) !important;
      border-color: rgba(240,179,35,.45) !important;
      background: rgba(255,255,255,.58) !important;
    }
    .ki-addon{
      border-radius: 16px !important;
      border: 1px solid rgba(15,23,42,.14) !important;
      background: rgba(255,255,255,.42) !important;
      color: rgba(11,18,32,.70);
      font-weight: 900;
    }
    .ki-link{ color: #1d4ed8; font-weight: 900; text-decoration: none; }
    .ki-link:hover{ text-decoration: underline; }

    .ki-sep{
      display:flex; align-items:center; gap:12px;
      margin: 14px 0;
      color: rgba(11,18,32,.60);
      font-weight: 900;
      font-size: .9rem;
    }
    .ki-sep:before, .ki-sep:after{
      content:"";
      height:1px;
      flex:1;
      background: rgba(15,23,42,.12);
    }

    .ki-note{ color: rgba(11,18,32,.62); font-weight: 800; font-size: .95rem; }

    /* Small hover */
    .ki-soft{
      border-radius: 18px;
      border: 1px dashed rgba(15,23,42,.16);
      background: rgba(255,255,255,.16);
      padding: 12px;
    }
  </style>
</head>

<body class="ki-auth">

<?php
require __DIR__ . '/navbar.php';
?>

<?php
$uriPath = (string)(parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH) ?: '');
$posVirt = stripos($uriPath, '/virtuale/');
$URL_ROOT = ($posVirt !== false) ? substr($uriPath, 0, $posVirt) : rtrim((string)dirname($uriPath), '/');
if ($URL_ROOT === '/' || $URL_ROOT === '.') $URL_ROOT = '';
$URL_ROOT = rtrim($URL_ROOT, '/');
?>


<main>

  <!-- HERO -->
  <section class="ki-hero">
    <div class="ki-wrap">
      <div class="d-flex align-items-center justify-content-between flex-wrap gap-2">
        <div>
          <div class="ki-kicker">
            <i class="fa-solid fa-shield-halved"></i>
            <span>Hyrje e sigurt • Akses në platformë</span>
          </div>
          <h1 class="ki-h1 mt-3">Hyr në llogarinë tënde</h1>
          <p class="ki-lead mt-2">Menaxho kurset, materialet, regjistrimet dhe progresin — në një vend.</p>
        </div>

        <div class="d-none d-lg-flex gap-2">
          <a class="ki-btn ghost" href="/"><i class="fa-solid fa-house"></i> Kryefaqja</a>
          <a class="ki-btn dark" href="signup.php"><i class="fa-solid fa-user-plus"></i> Krijo llogari</a>
        </div>
      </div>
    </div>
  </section>

  <!-- CONTENT -->
  <section class="ki-section">
    <div class="ki-wrap">
      <div class="ki-grid">

        <!-- LEFT: Value prop -->
        <div class="ki-glass ki-panel">
          <div class="ki-mini">Pse kurseinformatike</div>
          <h2 class="ki-h2 mt-2">Praktikë, udhëzim dhe rezultate</h2>
          <p class="ki-lead mt-2">
            Platformë e ndërtuar për mësim të thjeshtë, me programe të qarta dhe mbështetje reale.
          </p>

          <ul class="ki-list">
            <li class="ki-li">
              <div class="ki-ic"><i class="fa-solid fa-bullseye"></i></div>
              <div>
                <b>Rrugë e qartë mësimi</b>
                <div class="muted">Hap pas hapi, pa konfuzion dhe me detyra praktike.</div>
              </div>
            </li>
            <li class="ki-li">
              <div class="ki-ic"><i class="fa-solid fa-laptop-code"></i></div>
              <div>
                <b>Projekte reale</b>
                <div class="muted">Ushtrime dhe shembuj që të shërbejnë edhe për punë.</div>
              </div>
            </li>
            <li class="ki-li">
              <div class="ki-ic"><i class="fa-solid fa-comments"></i></div>
              <div>
                <b>Mbështetje</b>
                <div class="muted">Komunikim i shpejtë kur has vështirësi gjatë kursit.</div>
              </div>
            </li>
          </ul>

          <div class="ki-soft mt-3">
            <div class="d-flex align-items-start gap-2">
              <div style="width:36px;height:36px;border-radius:14px;background:rgba(240,179,35,.20);display:flex;align-items:center;justify-content:center;color:rgba(11,18,32,.85);">
                <i class="fa-solid fa-lock"></i>
              </div>
              <div class="ki-note">
                Të dhënat ruhen me kujdes. Përdorim CSRF, sesiune të sigurta dhe “Remember me” me token të hash-uar.
              </div>
            </div>
          </div>

          <div class="d-flex gap-2 flex-wrap mt-3">
            <a class="ki-btn primary" href="promotions_public.php"><i class="fa-solid fa-tag"></i> Shiko promocionet</a>
            <a class="ki-btn ghost" href="contact.php"><i class="fa-solid fa-envelope"></i> Kontakto</a>
          </div>
        </div>

        <!-- RIGHT: Login -->
        <div class="ki-glass ki-card">
          <div class="ki-mini">Hyrje</div>
          <h2 class="ki-h2 mt-2">Mirë se u ktheve</h2>
          <div class="ki-note mt-2">Hyr me email-in dhe fjalëkalimin. Nëse s’ke llogari, krijoje brenda 1 minute.</div>

          <?php if (!empty($response)) : ?>
            <div class="mt-3"><?= $response ?></div>
          <?php endif; ?>

          <form method="post" class="mt-3" novalidate>
            <input type="hidden" name="csrf_token" value="<?= h($csrf) ?>">

            <div class="mb-3">
              <label class="form-label" for="email">Email</label>
              <div class="input-group">
                <span class="input-group-text ki-addon"><i class="fa-solid fa-envelope"></i></span>
                <input class="form-control ki-input" id="email" type="email" name="email"
                       value="<?= h($oldEmail) ?>"
                       placeholder="p.sh. emri@email.com" autocomplete="email" required>
              </div>
            </div>

            <div class="mb-2">
              <label class="form-label" for="password">Fjalëkalimi</label>
              <div class="input-group">
                <span class="input-group-text ki-addon"><i class="fa-solid fa-lock"></i></span>
                <input class="form-control ki-input" type="password" name="password" id="password"
                       placeholder="••••••••" autocomplete="current-password" required>
                <button class="input-group-text ki-addon" type="button" id="togglePassword" title="Shfaq/Fshih" aria-label="Shfaq/Fshih fjalëkalimin">
                  <i class="fa-regular fa-eye"></i>
                </button>
              </div>
            </div>

            <div class="d-flex align-items-center justify-content-between mt-2 mb-3">
              <div class="form-check">
                <input class="form-check-input" type="checkbox" id="remember" name="remember">
                <label class="form-check-label ki-note" for="remember">Më mbaj mend</label>
              </div>
              <a class="ki-link" href="forgot_password.php">Ke harruar fjalëkalimin?</a>
            </div>

            <button class="ki-btn dark w-100" type="submit">
              <i class="fa-solid fa-right-to-bracket"></i> Hyr
            </button>

            <div class="ki-sep">ose</div>

            <div class="d-flex gap-2">
              <button type="button" class="ki-btn ghost w-50" disabled aria-disabled="true">
                <i class="fa-brands fa-google"></i> Google
              </button>
              <button type="button" class="ki-btn ghost w-50" disabled aria-disabled="true">
                <i class="fa-brands fa-facebook-f"></i> Facebook
              </button>
            </div>

            <div class="text-center mt-3 ki-note">
              S’ke llogari? <a class="ki-link" href="signup.php" style="color:#b7791f;">Krijo llogari</a>
            </div>
          </form>
        </div>

      </div>
    </div>
  </section>

</main>

<?php
if (file_exists(__DIR__ . '/../footer.php')) {
  include __DIR__ . '/../footer.php';
} elseif (file_exists(__DIR__ . '/footer.php')) {
  include __DIR__ . '/footer.php';
}
?>

<!-- Toast root (AppToast) -->
<div id="toast-root"></div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script src="js/toast.js"></script>
<script>
  // Toggle password
  document.getElementById('togglePassword')?.addEventListener('click', function(){
    const inp = document.getElementById('password');
    const isPwd = inp.type === 'password';
    inp.type = isPwd ? 'text' : 'password';
    this.innerHTML = isPwd ? '<i class="fa-regular fa-eye-slash"></i>' : '<i class="fa-regular fa-eye"></i>';
  });

  <?php if ($toastNow): ?>
  document.addEventListener('DOMContentLoaded', function () {
    if (window.AppToast && typeof AppToast.show === 'function') {
      AppToast.show(<?= json_encode($toastNow['type']) ?>, <?= json_encode($toastNow['msg']) ?>);
    }
  });
  <?php endif; ?>

  <?php if ($fl = get_flash()): ?>
  document.addEventListener('DOMContentLoaded', function () {
    if (window.AppToast && typeof AppToast.show === 'function') {
      AppToast.show(<?= json_encode($fl['type']) ?>, <?= json_encode($fl['msg']) ?>);
    }
  });
  <?php endif; ?>
</script>
</body>
</html>
