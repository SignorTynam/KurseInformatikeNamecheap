<?php
// signup.php — KI v2 Auth (glass) + CSRF + reCAPTCHA v2 + AppToast (match new login.php)
declare(strict_types=1);
session_start();
require_once __DIR__ . '/lib/database.php';

function h(?string $s): string { return htmlspecialchars($s ?? '', ENT_QUOTES, 'UTF-8'); }

/* ---------------- Flash helpers (toasts) ---------------- */
function set_flash(string $msg, string $type='info'): void { $_SESSION['flash'] = ['msg'=>$msg,'type'=>$type]; }
function get_flash(): ?array { if (!empty($_SESSION['flash'])){ $f=$_SESSION['flash']; unset($_SESSION['flash']); return $f; } return null; }

/* ---------------- reCAPTCHA v2 (checkbox) ---------------- */
// Replace with your keys (ENV supported)
$RECAPTCHA_SITE_KEY = getenv('RECAPTCHA_SITE_KEY') ?: '6LcT_OErAAAAAG4HfoB8xebJ4adrFRhED_sRGtc8';
$RECAPTCHA_SECRET   = getenv('RECAPTCHA_SECRET')   ?: '6LcT_OErAAAAAOc5yjvFTDmcOtk6scOD7wigzUek';

function verify_recaptcha_v2(string $secret, string $response): array {
  if ($response === '' || $secret === '') return [false, 'Captcha nuk u verifikua (sekreti mungon).'];
  $post = http_build_query(['secret'=>$secret,'response'=>$response,'remoteip'=>$_SERVER['REMOTE_ADDR'] ?? null]);

  if (function_exists('curl_init')) {
    $ch = curl_init('https://www.google.com/recaptcha/api/siteverify');
    curl_setopt_array($ch,[
      CURLOPT_POST=>true,
      CURLOPT_POSTFIELDS=>$post,
      CURLOPT_RETURNTRANSFER=>true,
      CURLOPT_TIMEOUT=>8
    ]);
    $out = curl_exec($ch); $err = curl_error($ch); curl_close($ch);
    if ($out === false) { error_log("[signup] reCAPTCHA curl error: $err"); return [false,'Captcha nuk u verifikua.']; }
  } else {
    $ctx = stream_context_create(['http'=>[
      'method'=>'POST',
      'header'=>"Content-type: application/x-www-form-urlencoded\r\n",
      'content'=>$post,
      'timeout'=>8
    ]]);
    $out = @file_get_contents('https://www.google.com/recaptcha/api/siteverify', false, $ctx);
    if ($out === false) { error_log("[signup] reCAPTCHA fopen error"); return [false,'Captcha nuk u verifikua.']; }
  }

  $j = json_decode($out, true);
  return (!is_array($j) || empty($j['success'])) ? [false,'Captcha dështoi, provo sërish.'] : [true,null];
}

/* ---------------- CSRF ---------------- */
if (empty($_SESSION['csrf_token'])) { $_SESSION['csrf_token'] = bin2hex(random_bytes(32)); }
$csrf = $_SESSION['csrf_token'];

/* ---------------- Init ---------------- */
$response = '';
$errors = [];
$toastNow = null; // ['type'=>'success|error|warning|info','msg'=>'...']
$data = ['first_name'=>'','last_name'=>'','birth_date'=>'','phone_number'=>'','email'=>''];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  // Honeypot
  if (!empty($_POST['hp_email'] ?? '')) { http_response_code(400); die('Kërkesë e pavlefshme.'); }

  // CSRF
  if (!hash_equals($_SESSION['csrf_token'] ?? '', $_POST['csrf_token'] ?? '')) {
    $errors[] = 'Seanca e pavlefshme. Rifreskoni faqen.';
  }

  // Inputs
  $data = [
    'first_name'   => trim((string)($_POST['first_name'] ?? '')),
    'last_name'    => trim((string)($_POST['last_name'] ?? '')),
    'birth_date'   => trim((string)($_POST['birth_date'] ?? '')),
    'phone_number' => trim((string)($_POST['phone_number'] ?? '')),
    'email'        => trim((string)($_POST['email'] ?? '')),
  ];
  $password = (string)($_POST['password'] ?? '');
  $confirm  = (string)($_POST['confirm_password'] ?? '');

  // Validime bazë
  if ($data['first_name'] === '') $errors[] = 'Shkruani emrin.';
  if ($data['last_name']  === '') $errors[] = 'Shkruani mbiemrin.';
  if ($data['email'] === '' || !filter_var($data['email'], FILTER_VALIDATE_EMAIL)) $errors[] = 'Email i pavlefshëm.';

  if ($data['birth_date'] === '') {
    $errors[] = 'Zgjidhni datëlindjen.';
  } else {
    $d = DateTime::createFromFormat('Y-m-d', $data['birth_date']);
    $d_valid = $d && $d->format('Y-m-d') === $data['birth_date'];
    if (!$d_valid) $errors[] = 'Data e lindjes nuk është e vlefshme.';
    else {
      $min = (new DateTime('now'))->modify('-13 years');
      if ($d > $min) $errors[] = 'Duhet të jeni të paktën 13 vjeç.';
    }
  }

  if ($data['phone_number'] === '') $errors[] = 'Shkruani numrin e telefonit.';
  if ($data['phone_number'] !== '' && !preg_match('~^[+\d][\d\s\-]{5,14}$~', $data['phone_number'])) $errors[] = 'Numër telefoni i pavlefshëm.';

  // Fjalëkalimi
  $pwErrors = [];
  if (strlen($password) < 8)                  $pwErrors[] = 'Të paktën 8 karaktere';
  if (!preg_match('/[A-Z]/', $password))      $pwErrors[] = 'Një shkronjë të madhe';
  if (!preg_match('/\d/', $password))         $pwErrors[] = 'Një numër';
  if (!preg_match('/[!@#$%^&*]/', $password)) $pwErrors[] = 'Një karakter special';
  if ($password !== $confirm)                 $pwErrors[] = 'Fjalëkalimet nuk përputhen';
  if ($pwErrors) $errors[] = 'Fjalëkalimi: ' . implode(', ', $pwErrors) . '.';

  // reCAPTCHA
  $gresp = (string)($_POST['g-recaptcha-response'] ?? '');
  if ($gresp === '') $errors[] = 'Konfirmoni që nuk jeni robot.';
  else {
    [$ok, $msg] = verify_recaptcha_v2($RECAPTCHA_SECRET, $gresp);
    if (!$ok) $errors[] = (string)$msg;
  }

  // INSERT
  if (!$errors) {
    $full_name = trim($data['first_name'].' '.$data['last_name']);
    $hash = password_hash($password, PASSWORD_BCRYPT);

    try {
      /** @var PDO $pdo */
      $stmt = $pdo->prepare("
        INSERT INTO users (full_name, birth_date, role, phone_number, email, password, status)
        VALUES (:full_name, :birth_date, 'Student', :phone, :email, :password, 'APROVUAR')
      ");
      $stmt->execute([
        ':full_name' => $full_name,
        ':birth_date'=> $data['birth_date'],
        ':phone'     => $data['phone_number'],
        ':email'     => $data['email'],
        ':password'  => $hash,
      ]);

      set_flash('Regjistrimi u krye me sukses. Tani mund të hyni.', 'success');
      $toastNow = ['type'=>'success','msg'=>'Regjistrimi u krye me sukses. Tani mund të hyni.'];
      $response = '<div class="alert alert-success">Regjistrimi u krye me sukses. Tani mund të hyni.</div>';

      $data = ['first_name'=>'','last_name'=>'','birth_date'=>'','phone_number'=>'','email'=>''];
      header('Refresh: 2; url=login.php');
    } catch (Throwable $e) {
      if ($e instanceof PDOException && $e->getCode() === '23000') {
        $response = '<div class="alert alert-danger">Ky email është i regjistruar tashmë.</div>';
        $toastNow = ['type'=>'error','msg'=>'Ky email është i regjistruar tashmë.'];
      } else {
        error_log('[signup] '.$e->getMessage());
        $response = '<div class="alert alert-danger">Ndodhi një gabim. Provo përsëri.</div>';
        $toastNow = ['type'=>'error','msg'=>'Ndodhi një gabim. Provo përsëri.'];
      }
    }
  } else {
    $response = '<div class="alert alert-danger">'.h($errors[0]).'</div>';
    $toastNow = ['type'=>'error','msg'=>$errors[0]];
  }
}
?>
<!doctype html>
<html lang="sq">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <title>Regjistrohu — kurseinformatike.com</title>
  <meta name="description" content="Krijo llogari në kurseinformatike.com dhe nis udhëtimin tënd të mësimit.">
  <link rel="icon" href="image/favicon.ico" type="image/x-icon">

  <!-- Bootstrap & Icons -->
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
  <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css" rel="stylesheet">

  <!-- Fonts (KI v2) -->
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@600;700;800;900&family=Roboto:wght@400;500;700&display=swap" rel="stylesheet">

  <!-- reCAPTCHA -->
  <script src="https://www.google.com/recaptcha/api.js" async defer></script>

  <!-- AppToast (same as login.php) -->
  <link href="css/toast.css" rel="stylesheet">

  <style>
    /* ==========================================================
       KI v2 — AUTH / SIGNUP (match new login.php)
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
      --ki-ice:#f7fbff;

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
    .ki-wrap{ width: min(var(--ki-wrap), calc(100% - 32px)); margin-inline: auto; }

    /* (Opsionale) Slim navbar vetëm në këtë faqe */
    body.ki-auth .navbar{
      padding-top: .45rem;
      padding-bottom: .45rem;
      background: rgba(255,255,255,.55) !important;
      border-bottom: 1px solid rgba(15,23,42,.10);
      backdrop-filter: blur(10px);
    }

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

    /* Hero */
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
      grid-template-columns: 1fr 460px;
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
    .ki-note{ color: rgba(11,18,32,.62); font-weight: 800; font-size: .95rem; }

    .ki-soft{
      border-radius: 18px;
      border: 1px dashed rgba(15,23,42,.16);
      background: rgba(255,255,255,.16);
      padding: 12px;
    }

    /* Password meter */
    .ki-meter{
      height: 8px;
      border-radius: 999px;
      border: 1px solid rgba(15,23,42,.12);
      background: rgba(255,255,255,.22);
      overflow: hidden;
    }
    .ki-meter > div{
      height: 100%;
      width: 0%;
      transition: width .2s ease;
      background: #ef4444;
    }

    /* reCAPTCHA - make it fit */
    .ki-captcha{
      padding: 12px;
      border-radius: 18px;
      border: 1px solid rgba(15,23,42,.10);
      background: rgba(255,255,255,.18);
    }
    .g-recaptcha{ transform-origin: left top; }
    @media (max-width: 420px){
      .g-recaptcha{ transform: scale(.92); }
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
            <i class="fa-solid fa-user-plus"></i>
            <span>Krijo llogari • Nis mësimin sot</span>
          </div>
          <h1 class="ki-h1 mt-3">Regjistrohu në platformë</h1>
          <p class="ki-lead mt-2">Plotëso të dhënat dhe akseso menjëherë kurset, materialet dhe njoftimet.</p>
        </div>

        <div class="d-none d-lg-flex gap-2">
          <a class="ki-btn ghost" href="/"><i class="fa-solid fa-house"></i> Kryefaqja</a>
          <a class="ki-btn dark" href="login.php"><i class="fa-solid fa-right-to-bracket"></i> Hyr</a>
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
          <div class="ki-mini">Përfitimet</div>
          <h2 class="ki-h2 mt-2">Një llogari, gjithë platforma</h2>
          <p class="ki-lead mt-2">
            Regjistrohu që të kesh akses në kurse, programe, komunikim dhe progresin tënd.
          </p>

          <ul class="ki-list">
            <li class="ki-li">
              <div class="ki-ic"><i class="fa-solid fa-layer-group"></i></div>
              <div>
                <b>Kurse dhe materiale</b>
                <div class="muted">Akses i centralizuar në module, detyra dhe udhëzime.</div>
              </div>
            </li>
            <li class="ki-li">
              <div class="ki-ic"><i class="fa-solid fa-chart-line"></i></div>
              <div>
                <b>Progres i qartë</b>
                <div class="muted">Ndjek përparimin dhe objektivat pa humbur kohë.</div>
              </div>
            </li>
            <li class="ki-li">
              <div class="ki-ic"><i class="fa-solid fa-shield-halved"></i></div>
              <div>
                <b>Siguri</b>
                <div class="muted">CSRF, validime, dhe verifikim anti-bot (reCAPTCHA).</div>
              </div>
            </li>
          </ul>

          <div class="ki-soft mt-3">
            <div class="d-flex align-items-start gap-2">
              <div style="width:36px;height:36px;border-radius:14px;background:rgba(240,179,35,.20);display:flex;align-items:center;justify-content:center;color:rgba(11,18,32,.85);">
                <i class="fa-solid fa-circle-info"></i>
              </div>
              <div class="ki-note">
                Përdor email-in real: do të duhet për rikuperim fjalëkalimi dhe njoftime.
              </div>
            </div>
          </div>

          <div class="d-flex gap-2 flex-wrap mt-3">
            <a class="ki-btn primary" href="promotions_public.php"><i class="fa-solid fa-tag"></i> Shiko promocionet</a>
            <a class="ki-btn ghost" href="contact.php"><i class="fa-solid fa-envelope"></i> Kontakto</a>
          </div>
        </div>

        <!-- RIGHT: Signup form -->
        <div class="ki-glass ki-card">
          <div class="ki-mini">Regjistrim</div>
          <h2 class="ki-h2 mt-2">Krijo llogarinë</h2>
          <div class="ki-note mt-2">Mjaftojnë pak të dhëna. Pastaj hyn direkt në platformë.</div>

          <?php if (!empty($response)) : ?>
            <div class="mt-3"><?= $response ?></div>
          <?php endif; ?>

          <?php if (!empty($errors)) : ?>
            <div class="alert alert-warning mt-3">
              <ul class="mb-0">
                <?php foreach ($errors as $e): ?><li><?= h($e) ?></li><?php endforeach; ?>
              </ul>
            </div>
          <?php endif; ?>

          <form method="post" class="mt-3 ki-form" novalidate>
            <input type="hidden" name="csrf_token" value="<?= h($csrf) ?>">
            <!-- Honeypot -->
            <input type="text" name="hp_email" autocomplete="off" style="display:none!important">

            <div class="row g-2">
              <div class="col-md-6">
                <label class="form-label" for="first_name">Emri</label>
                <div class="input-group">
                  <span class="input-group-text ki-addon"><i class="fa-regular fa-user"></i></span>
                  <input class="form-control ki-input" id="first_name" name="first_name" value="<?= h($data['first_name']) ?>" placeholder="Emri" required>
                </div>
              </div>
              <div class="col-md-6">
                <label class="form-label" for="last_name">Mbiemri</label>
                <div class="input-group">
                  <span class="input-group-text ki-addon"><i class="fa-regular fa-user"></i></span>
                  <input class="form-control ki-input" id="last_name" name="last_name" value="<?= h($data['last_name']) ?>" placeholder="Mbiemri" required>
                </div>
              </div>
            </div>

            <div class="row g-2 mt-1">
              <div class="col-md-6">
                <label class="form-label" for="birth_date">Datëlindja</label>
                <div class="input-group">
                  <span class="input-group-text ki-addon"><i class="fa-regular fa-calendar"></i></span>
                  <input type="date" class="form-control ki-input" id="birth_date" name="birth_date" value="<?= h($data['birth_date']) ?>" required>
                </div>
              </div>
              <div class="col-md-6">
                <label class="form-label" for="phone_number">Telefoni</label>
                <div class="input-group">
                  <span class="input-group-text ki-addon"><i class="fa-solid fa-phone"></i></span>
                  <input class="form-control ki-input" id="phone_number" name="phone_number" value="<?= h($data['phone_number']) ?>" placeholder="+39 ... / 06..." required>
                </div>
              </div>
            </div>

            <div class="mt-1">
              <label class="form-label" for="email">Email</label>
              <div class="input-group">
                <span class="input-group-text ki-addon"><i class="fa-regular fa-envelope"></i></span>
                <input type="email" class="form-control ki-input" id="email" name="email" value="<?= h($data['email']) ?>" placeholder="email@domain.com" required>
              </div>
            </div>

            <div class="row g-2 mt-1">
              <div class="col-md-6">
                <label class="form-label" for="password">Fjalëkalimi</label>
                <div class="input-group">
                  <span class="input-group-text ki-addon"><i class="fa-solid fa-lock"></i></span>
                  <input type="password" class="form-control ki-input" id="password" name="password" placeholder="••••••••" required>
                </div>
                <div class="ki-meter mt-2" aria-hidden="true"><div id="pw-meter"></div></div>
                <div class="ki-note mt-1" id="pw-text"></div>
              </div>
              <div class="col-md-6">
                <label class="form-label" for="confirm_password">Konfirmo</label>
                <div class="input-group">
                  <span class="input-group-text ki-addon"><i class="fa-solid fa-lock"></i></span>
                  <input type="password" class="form-control ki-input" id="confirm_password" name="confirm_password" placeholder="••••••••" required>
                </div>
              </div>
            </div>

            <div class="ki-note mt-2">
              Kërkesa: ≥8, një shkronjë e madhe, një numër, një simbol (!@#$%^&*).
            </div>

            <div class="ki-captcha mt-3">
              <div class="g-recaptcha" data-sitekey="<?= h($RECAPTCHA_SITE_KEY) ?>"></div>
              <div class="ki-note mt-2">Plotëso captchën për të vazhduar.</div>
            </div>

            <button class="ki-btn dark w-100 mt-3" type="submit">
              <i class="fa-solid fa-user-plus"></i> Regjistrohu
            </button>

            <div class="text-center mt-3 ki-note">
              Ke llogari? <a class="ki-link" href="login.php">Hyr</a>
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
  // Password meter (light)
  const pw = document.getElementById('password');
  const meter = document.getElementById('pw-meter');
  const txt = document.getElementById('pw-text');

  function pwScore(v){
    let s=0;
    if (v.length>=8) s+=25;
    if (/[A-Z]/.test(v)) s+=25;
    if (/\d/.test(v)) s+=25;
    if (/[!@#$%^&*]/.test(v)) s+=25;
    return s;
  }

  pw?.addEventListener('input', ()=>{
    const v = pw.value || '';
    const s = pwScore(v);
    meter.style.width = s + '%';

    if (!v) { txt.textContent=''; meter.style.backgroundColor='#ef4444'; return; }
    if (s < 50) { txt.textContent='Dobët'; meter.style.backgroundColor='#ef4444'; }
    else if (s < 75) { txt.textContent='Mesatar'; meter.style.backgroundColor='#f59e0b'; }
    else { txt.textContent='I fortë'; meter.style.backgroundColor='#10b981'; }
  });

  // AppToast (same as login.php)
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
