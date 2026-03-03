<?php
declare(strict_types=1);
if (session_status() === PHP_SESSION_NONE) { session_start(); }
require_once __DIR__ . '/../lib/database.php';

/* -------------------- RBAC -------------------- */
if (!isset($_SESSION['user']) || ($_SESSION['user']['role'] ?? '') !== 'Administrator') {
    header('Location: ../login.php'); exit;
}

/* -------------------- CSRF -------------------- */
if (empty($_SESSION['csrf_token'])) { $_SESSION['csrf_token'] = bin2hex(random_bytes(32)); }
$CSRF = $_SESSION['csrf_token'];

/* -------------------- Helpers ----------------- */
function h(?string $s): string { return htmlspecialchars($s ?? '', ENT_QUOTES, 'UTF-8'); }

/* -------------------- Defaults ---------------- */
$errors = [];
$full_name    = '';
$birth_date   = '';
$role         = '';
$phone_number = '';
$email        = '';
$password     = '';
$confirm_pass = '';
$status       = 'NE SHQYRTIM';

$allowed_roles   = ['Administrator','Instruktor','Student'];
$allowed_status  = ['NE SHQYRTIM','APROVUAR','REFUZUAR'];

/* -------------------- POST -------------------- */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // CSRF
    if (!isset($_POST['csrf']) || !hash_equals($CSRF, (string)$_POST['csrf'])) {
        $errors[] = 'Seancë e pavlefshme (CSRF). Ringarko faqen dhe provo përsëri.';
    }

    // Lexo inputet
    $full_name    = trim((string)($_POST['full_name'] ?? ''));
    $birth_date   = trim((string)($_POST['birth_date'] ?? ''));
    $role         = (string)($_POST['role'] ?? '');
    $phone_number = trim((string)($_POST['phone_number'] ?? ''));
    $email        = trim((string)($_POST['email'] ?? ''));
    $password     = (string)($_POST['password'] ?? '');
    $confirm_pass = (string)($_POST['confirm_password'] ?? '');
    $status_in    = (string)($_POST['status'] ?? 'NE SHQYRTIM');
    $status       = in_array($status_in, $allowed_status, true) ? $status_in : 'NE SHQYRTIM';

    // Validime bazë
    if ($full_name === '' || mb_strlen($full_name) < 3) {
        $errors[] = 'Emri i plotë është i detyrueshëm (min 3 karaktere).';
    }

    if ($birth_date === '' || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $birth_date)) {
        $errors[] = 'Data e lindjes është e pavlefshme.';
    } else {
        $dob = DateTime::createFromFormat('Y-m-d', $birth_date);
        $dob_valid = $dob && $dob->format('Y-m-d') === $birth_date;
        if (!$dob_valid) {
            $errors[] = 'Data e lindjes është e pavlefshme.';
        } else {
            // kontrollo që personi të jetë të paktën 10 vjeç dhe jo në të ardhmen
            $now = new DateTime('today');
            if ($dob > $now) {
                $errors[] = 'Data e lindjes nuk mund të jetë në të ardhmen.';
            } else {
                $age = (int)$dob->diff($now)->y;
                if ($age < 10) {
                    $errors[] = 'Përdoruesi duhet të jetë të paktën 10 vjeç.';
                }
            }
        }
    }

    if (!in_array($role, $allowed_roles, true)) {
        $errors[] = 'Zgjidh një rol të vlefshëm.';
    }

    if ($phone_number === '' || !preg_match('/^[0-9+\s().-]{7,20}$/', $phone_number)) {
        $errors[] = 'Numri i telefonit është i pavlefshëm.';
    }

    if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $errors[] = 'Email i pavlefshëm.';
    }

    if ($password === '') {
        $errors[] = 'Fjalëkalimi është i detyrueshëm.';
    } else {
        if (strlen($password) < 8) { $errors[] = 'Fjalëkalimi duhet të ketë të paktën 8 karaktere.'; }
        if (!preg_match('/[A-Z]/', $password)) { $errors[] = 'Fjalëkalimi duhet të ketë të paktën një shkronjë të madhe.'; }
        if (!preg_match('/[a-z]/', $password)) { $errors[] = 'Fjalëkalimi duhet të ketë të paktën një shkronjë të vogël.'; }
        if (!preg_match('/\d/', $password))   { $errors[] = 'Fjalëkalimi duhet të ketë të paktën një numër.'; }
    }

    if ($confirm_pass === '' || $confirm_pass !== $password) {
        $errors[] = 'Konfirmimi i fjalëkalimit nuk përputhet.';
    }

    // Ruaj në DB nëse çdo gjë është OK
    if (!$errors) {
        $hashed_password = password_hash($password, PASSWORD_DEFAULT);

        $sql = "INSERT INTO users (full_name, birth_date, role, phone_number, email, password, status) 
                VALUES (:full_name, :birth_date, :role, :phone_number, :email, :password, :status)";

        $stmt = $pdo->prepare($sql);
        try {
            $stmt->execute([
                ':full_name'    => $full_name,
                ':birth_date'   => $birth_date,
                ':role'         => $role,
                ':phone_number' => $phone_number,
                ':email'        => $email,
                ':password'     => $hashed_password,
                ':status'       => $status
            ]);
            $_SESSION['flash'] = ['msg'=>'Përdoruesi u shtua me sukses.', 'type'=>'success'];
            header('Location: ../users.php'); exit;
        } catch (PDOException $e) {
            // MySQL 1062 -> duplicate key (email unik)
            if (isset($e->errorInfo[1]) && (int)$e->errorInfo[1] === 1062) {
                $errors[] = 'Ky email është tashmë i regjistruar.';
            } else {
                $errors[] = 'Gabim: ' . h($e->getMessage());
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="sq" data-bs-theme="light">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <title>Shto Përdorues — Paneli i Administratorit</title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
  <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css" rel="stylesheet">
  <link rel="icon" href="image/favicon.ico" type="image/x-icon" />
  <style>
    :root{
      --primary:#1F4B99;      /* blu e thellë */
      --primary-dark:#163a78;
      --accent:#E879F9;       /* violet pastel */
      --muted:#6b7280;
      --bg:#F6F8FB;
      --border:#E5E7EB;
      --radius:18px;
      --shadow:0 14px 38px rgba(0,0,0,.08);
    }
    body{ background:var(--bg); }

    /* Hero */
    .hero{
      background:
        radial-gradient(700px 280px at 85% -40%, rgba(232,121,249,.18), transparent 60%),
        linear-gradient(135deg, var(--primary), var(--primary-dark));
      color:#fff; padding:64px 0 32px; margin-bottom:22px;
    }
    .chip{
      background:#ffffff22; border:1px solid #ffffff40; color:#fff; padding:.25rem .6rem; border-radius:999px; font-weight:600;
    }

    /* Card */
    .cardx{
      background:#fff; border:0; border-radius:var(--radius); box-shadow:var(--shadow);
    }
    .section-title{ color:var(--primary); font-weight:700; }

    /* Inputs */
    .form-control, .form-select{
      border:2px solid var(--border); border-radius:12px; padding:.8rem .95rem;
    }
    .form-control:focus, .form-select:focus{
      border-color:var(--primary); box-shadow:0 0 0 .2rem rgba(31,75,153,.12);
    }

    .input-group-text{
      background:#f8fafc; border:2px solid var(--border); border-right:none;
    }

    .password-meter{
      height:8px; border-radius:999px; background:#e5e7eb; overflow:hidden;
    }
    .password-meter > div{
      height:100%; width:0%; transition:width .25s ease;
      background: linear-gradient(90deg, #ef4444, #f59e0b, #10b981);
    }

    .btn-primary{ background:var(--primary); border:none; }
    .btn-primary:hover{ background:var(--primary-dark); }
  </style>
</head>
<body>

<?php include __DIR__ . '/../navbar_logged_administrator.php'; ?>

<section class="hero">
  <div class="container">
    <div class="d-flex flex-column flex-lg-row align-items-start align-items-lg-center justify-content-between gap-3">
      <div>
        <h1 class="mb-1"><i class="fa-solid fa-user-plus me-2"></i>Shto përdorues</h1>
        <p class="mb-0">Krijo një llogari të re për administrim, instruktor ose student.</p>
      </div>
      <span class="chip"><i class="fa-regular fa-shield me-1"></i> Administrator</span>
    </div>
  </div>
</section>

<div class="container">
  <!-- Alerts -->
  <?php if ($errors): ?>
    <div class="alert alert-danger cardx p-3 mb-3">
      <div class="d-flex align-items-start gap-2">
        <i class="fa-solid fa-triangle-exclamation mt-1"></i>
        <div>
          <strong>Gabim në formular:</strong>
          <ul class="mb-0 mt-1">
            <?php foreach ($errors as $e): ?><li><?= h($e) ?></li><?php endforeach; ?>
          </ul>
        </div>
      </div>
    </div>
  <?php endif; ?>

  <!-- Form -->
  <form method="POST" class="row g-3">
    <input type="hidden" name="csrf" value="<?= h($CSRF) ?>">

    <div class="col-12 col-lg-8">
      <div class="cardx p-3">
        <h5 class="section-title mb-3"><i class="fa-regular fa-rectangle-list me-2"></i>Detajet e përdoruesit</h5>

        <div class="row g-3">
          <div class="col-12">
            <label class="form-label">Emri i plotë</label>
            <div class="input-group">
              <span class="input-group-text"><i class="fa-regular fa-user"></i></span>
              <input type="text" class="form-control" name="full_name" required placeholder="p.sh. Arber Hoxha" value="<?= h($full_name) ?>">
            </div>
          </div>

          <div class="col-md-6">
            <label class="form-label">Data e lindjes</label>
            <div class="input-group">
              <span class="input-group-text"><i class="fa-regular fa-calendar"></i></span>
              <input type="date" class="form-control" name="birth_date" required value="<?= h($birth_date) ?>">
            </div>
          </div>

          <div class="col-md-6">
            <label class="form-label">Numri i telefonit</label>
            <div class="input-group">
              <span class="input-group-text"><i class="fa-solid fa-phone"></i></span>
              <input type="text" class="form-control" name="phone_number" required placeholder="+355 6X XXX XXXX" value="<?= h($phone_number) ?>">
            </div>
          </div>

          <div class="col-md-6">
            <label class="form-label">Email</label>
            <div class="input-group">
              <span class="input-group-text"><i class="fa-regular fa-envelope"></i></span>
              <input type="email" class="form-control" name="email" required placeholder="emri@example.com" value="<?= h($email) ?>">
            </div>
          </div>

          <div class="col-md-6">
            <label class="form-label">Roli</label>
            <select class="form-select" name="role" required>
              <option value="">Zgjidh rolin…</option>
              <?php foreach ($allowed_roles as $r): ?>
                <option value="<?= h($r) ?>" <?= $role===$r?'selected':'' ?>><?= h($r) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
        </div>

        <hr class="my-3">

        <h6 class="text-secondary fw-semibold mb-2"><i class="fa-solid fa-lock me-2"></i>Siguria</h6>
        <div class="row g-3">
          <div class="col-md-6">
            <label class="form-label">Fjalëkalimi</label>
            <div class="input-group">
              <span class="input-group-text"><i class="fa-solid fa-key"></i></span>
              <input type="password" class="form-control" id="password" name="password" required placeholder="Të paktën 8 karaktere">
              <button class="btn btn-outline-secondary" type="button" id="togglePass"><i class="fa-regular fa-eye"></i></button>
            </div>
            <div class="password-meter mt-2"><div id="pwBar"></div></div>
            <div class="form-text">Përdorni shkronja të mëdha/vogla dhe numra.</div>
          </div>

          <div class="col-md-6">
            <label class="form-label">Konfirmo fjalëkalimin</label>
            <div class="input-group">
              <span class="input-group-text"><i class="fa-solid fa-shield-halved"></i></span>
              <input type="password" class="form-control" id="confirm_password" name="confirm_password" required placeholder="Rishkruani fjalëkalimin">
              <button class="btn btn-outline-secondary" type="button" id="togglePass2"><i class="fa-regular fa-eye"></i></button>
            </div>
          </div>
        </div>
      </div>
    </div>

    <div class="col-12 col-lg-4">
      <div class="cardx p-3">
        <h5 class="section-title mb-3"><i class="fa-regular fa-circle-check me-2"></i>Statusi</h5>
        <label class="form-label">Gjendja e llogarisë</label>
        <select class="form-select" name="status">
          <?php foreach ($allowed_status as $st): ?>
            <option value="<?= h($st) ?>" <?= $status===$st?'selected':'' ?>><?= h($st) ?></option>
          <?php endforeach; ?>
        </select>

        <div class="d-grid mt-4">
          <button type="submit" class="btn btn-primary btn-lg"><i class="fa-regular fa-floppy-disk me-1"></i>Shto përdoruesin</button>
          <a href="../users.php" class="btn btn-outline-secondary mt-2"><i class="fa-solid fa-arrow-left-long me-1"></i>Kthehu te lista</a>
        </div>
      </div>
    </div>
  </form>
</div>

<br>

<?php include __DIR__ . '/footer.php'; ?>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script>
  // Toggle password visibility
  const pw = document.getElementById('password');
  const cpw = document.getElementById('confirm_password');
  document.getElementById('togglePass').addEventListener('click', ()=> {
    pw.type = pw.type === 'password' ? 'text' : 'password';
  });
  document.getElementById('togglePass2').addEventListener('click', ()=> {
    cpw.type = cpw.type === 'password' ? 'text' : 'password';
  });

  // Simple password strength meter
  const bar = document.getElementById('pwBar');
  function strength(s){
    let score = 0;
    if (!s) return 0;
    if (s.length >= 8) score++;
    if (/[A-Z]/.test(s)) score++;
    if (/[a-z]/.test(s)) score++;
    if (/\d/.test(s)) score++;
    if (/[^A-Za-z0-9]/.test(s)) score++; // karakter special (opsionale)
    return Math.min(score, 5);
  }
  pw.addEventListener('input', ()=>{
    const sc = strength(pw.value);
    const pct = (sc/5)*100;
    bar.style.width = pct + '%';
  });
</script>
</body>
</html>
