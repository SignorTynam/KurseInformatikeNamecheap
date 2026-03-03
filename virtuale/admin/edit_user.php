<?php
// edit_user.php — Revamp (Administrator)
declare(strict_types=1);
session_start();
require_once __DIR__ . '/../lib/database.php';

/* ------------------------------- Helpers ------------------------------- */
function h(?string $s): string { return htmlspecialchars($s ?? '', ENT_QUOTES, 'UTF-8'); }
function flash(string $msg, string $type='danger'): void { $_SESSION['flash']=['msg'=>$msg,'type'=>$type]; }
function get_flash(): ?array { $f=$_SESSION['flash']??null; unset($_SESSION['flash']); return $f; }
function valid_date(string $d): bool { $t = strtotime($d); return $t !== false && date('Y-m-d',$t) === $d; }

/* -------------------------------- Auth --------------------------------- */
if (!isset($_SESSION['user']) || ($_SESSION['user']['role'] ?? '') !== 'Administrator') {
  header('Location: ../login.php'); exit;
}

/* ------------------------------ CSRF token ----------------------------- */
if (empty($_SESSION['csrf'])) { $_SESSION['csrf'] = bin2hex(random_bytes(16)); }
$csrf = $_SESSION['csrf'];

/* ---------------------------- Param: user id --------------------------- */
if (!isset($_GET['id']) || !ctype_digit((string)$_GET['id'])) {
  flash('ID i pavlefshëm.', 'danger');
  header('Location: ../users.php'); exit;
}
$id = (int)$_GET['id'];

/* -------------------------- Lexo përdoruesin --------------------------- */
try {
  $stmt = $pdo->prepare("SELECT * FROM users WHERE id = ?");
  $stmt->execute([$id]);
  $user = $stmt->fetch(PDO::FETCH_ASSOC);
  if (!$user) {
    flash('Përdoruesi nuk u gjet.', 'danger');
    header('Location: ../users.php'); exit;
  }
} catch (PDOException $e) {
  flash('Gabim: '.h($e->getMessage()), 'danger');
  header('Location: ../users.php'); exit;
}

/* ------------------------- Defaults & allowed -------------------------- */
$allowedRoles    = ['Administrator','Instruktor','Student'];
$allowedStatuses = ['NE SHQYRTIM','APROVUAR','REFUZUAR'];

$errors = [];
$flash  = get_flash();

$full_name    = (string)($user['full_name']   ?? '');
$birth_date   = (string)($user['birth_date']  ?? '');
$role         = (string)($user['role']        ?? 'Student');
$phone_number = (string)($user['phone_number']?? '');
$email        = (string)($user['email']       ?? '');
$status       = (string)($user['status']      ?? 'NE SHQYRTIM');

/* --------------------------------- POST -------------------------------- */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  // CSRF
  if (!hash_equals($csrf, (string)($_POST['csrf'] ?? ''))) {
    $errors[] = 'Seancë e pasigurt. Ringarkoni faqen dhe provoni sërish.';
  }

  $full_name    = trim((string)($_POST['full_name']    ?? ''));
  $birth_date   = trim((string)($_POST['birth_date']   ?? ''));
  $role         = (string)($_POST['role']              ?? '');
  $phone_number = trim((string)($_POST['phone_number'] ?? ''));
  $email        = strtolower(trim((string)($_POST['email'] ?? '')));
  $password     = (string)($_POST['password'] ?? '');
  $status       = (string)($_POST['status']   ?? 'NE SHQYRTIM');

  // Validime bazike
  if ($full_name === '')                  $errors[] = 'Emri i plotë është i detyrueshëm.';
  if ($birth_date === '' || !valid_date($birth_date)) $errors[] = 'Data e lindjes nuk është e vlefshme.';
  if (!in_array($role, $allowedRoles, true))          $errors[] = 'Roli i zgjedhur nuk është i vlefshëm.';
  if ($phone_number === '')               $errors[] = 'Numri i telefonit është i detyrueshëm.';
  if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) $errors[] = 'Email-i nuk është i vlefshëm.';
  if (!in_array($status, $allowedStatuses, true))     $errors[] = 'Statusi i zgjedhur nuk është i vlefshëm.';

  // Data e lindjes s’mund të jetë në të ardhmen
  if ($birth_date !== '' && strtotime($birth_date) > time()) {
    $errors[] = 'Data e lindjes nuk mund të jetë në të ardhmen.';
  }

  // Password nëse vendoset: >= 8 karaktere
  $passwordSql = null; $passwordParams = [];
  if ($password !== '') {
    if (mb_strlen($password,'UTF-8') < 8) {
      $errors[] = 'Fjalëkalimi duhet të ketë të paktën 8 karaktere.';
    } else {
      $passwordSql = ", password = :password";
      $passwordParams[':password'] = password_hash($password, PASSWORD_DEFAULT);
    }
  }

  // Mos lejoni që administratori të heqë rolin e vet nëse është i vetmi admin
  $editingSelf = ((int)($_SESSION['user']['id'] ?? 0) === $id);
  if ($editingSelf && $role !== 'Administrator') {
    try {
      $stmtAdmins = $pdo->query("SELECT COUNT(*) FROM users WHERE role = 'Administrator'");
      $adminCount = (int)$stmtAdmins->fetchColumn();
      if ($adminCount <= 1) {
        $errors[] = 'Nuk mund të hiqni rolin Administrator nga vetja: do të mbetej sistemi pa administrator.';
      }
    } catch (PDOException $e) {
      $errors[] = 'Gabim gjatë verifikimit të roleve: '.h($e->getMessage());
    }
  }

  // Unikueshmëri email (përveç këtij id)
  if (!$errors) {
    try {
      $stmtChk = $pdo->prepare("SELECT COUNT(*) FROM users WHERE email = ? AND id <> ?");
      $stmtChk->execute([$email, $id]);
      if ((int)$stmtChk->fetchColumn() > 0) {
        $errors[] = 'Ky email është tashmë i regjistruar.';
      }
    } catch (PDOException $e) {
      $errors[] = 'Gabim gjatë kontrollit të email-it: '.h($e->getMessage());
    }
  }

  // Update
  if (!$errors) {
    try {
      $sql = "UPDATE users
                 SET full_name = :full_name,
                     birth_date = :birth_date,
                     role = :role,
                     phone_number = :phone_number,
                     email = :email,
                     status = :status
                     $passwordSql
               WHERE id = :id";
      $params = [
        ':full_name'    => $full_name,
        ':birth_date'   => $birth_date,
        ':role'         => $role,
        ':phone_number' => $phone_number,
        ':email'        => $email,
        ':status'       => $status,
        ':id'           => $id
      ] + $passwordParams;

      $stmtU = $pdo->prepare($sql);
      $stmtU->execute($params);

      flash('Përdoruesi u përditësua me sukses!', 'success');
      header('Location: ../users.php'); exit;
    } catch (PDOException $e) {
      if (($e->errorInfo[1] ?? null) == 1062) {
        $errors[] = 'Ky email është tashmë i regjistruar.';
      } else {
        $errors[] = 'Gabim: '.h($e->getMessage());
      }
    }
  }
}
?>
<!DOCTYPE html>
<html lang="sq" data-bs-theme="light">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Modifiko Përdoruesin — Paneli i Administratorit</title>
  <link rel="icon" href="image/favicon.ico" type="image/x-icon" />
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">
  <style>
    :root{ --primary:#2A4B7C; --secondary:#5B7BA3; --light:#F8F9FC; --shadow:0 10px 28px rgba(0,0,0,.08); --r:16px; }
    body{ background:#f6f8fb; }
    .hero{ background:linear-gradient(135deg,var(--primary),#1d3a63); color:#fff; padding:28px 0 18px; }
    .card-elev{ background:#fff; border:0; border-radius:var(--r); box-shadow:var(--shadow); }
    .input-group-text{ background-color:var(--light); border:2px solid #e9ecef; border-right:none; }
    .form-control{ border:2px solid #e9ecef; }
    .form-control:focus{ border-color: var(--primary); box-shadow: 0 0 0 3px rgba(42,75,124,.1); }
    .btn-primary{ background: var(--primary); border:none; }
  </style>
</head>
<body>

<?php include __DIR__ . '/../navbar_logged_administrator.php'; ?>

<section class="hero">
  <div class="container">
    <div class="d-flex align-items-center justify-content-between">
      <a href="../users.php" class="btn btn-sm btn-light"><i class="bi bi-arrow-left"></i> Përdoruesit</a>
      <small class="opacity-75">ID: <strong><?= (int)$id ?></strong></small>
    </div>
    <h1 class="h3 mt-2 mb-0">Modifiko përdoruesin</h1>
    <div class="opacity-75">Përditëso të dhënat e llogarisë</div>
  </div>
</section>

<main class="container py-4">
  <?php if ($errors): ?>
    <div class="alert alert-danger card-elev">
      <div class="d-flex align-items-start gap-2">
        <i class="bi bi-exclamation-circle fs-4"></i>
        <div>
          <div class="fw-bold mb-1">Gabime në formular</div>
          <ul class="mb-0"><?php foreach ($errors as $er): ?><li><?= h($er) ?></li><?php endforeach; ?></ul>
        </div>
      </div>
    </div>
  <?php endif; ?>

  <?php if ($flash): ?>
    <div class="alert <?= ($flash['type']??'')==='success'?'alert-success':'alert-danger' ?> card-elev d-flex align-items-center gap-2">
      <i class="bi <?= ($flash['type']??'')==='success'?'bi-check-circle':'bi-exclamation-triangle' ?>"></i>
      <div><?= h($flash['msg'] ?? '') ?></div>
    </div>
  <?php endif; ?>

  <div class="card-elev p-3 p-md-4">
    <form method="POST" action="">
      <input type="hidden" name="csrf" value="<?= h($csrf) ?>">

      <!-- Emri i plotë -->
      <div class="mb-3">
        <label class="form-label">Emri i plotë</label>
        <div class="input-group">
          <span class="input-group-text"><i class="bi bi-person text-primary"></i></span>
          <input type="text" class="form-control" name="full_name" required maxlength="120"
                 placeholder="p.sh. Arben Hoxha" value="<?= h($full_name) ?>">
        </div>
      </div>

      <!-- Data e lindjes -->
      <div class="mb-3">
        <label class="form-label">Data e lindjes</label>
        <div class="input-group">
          <span class="input-group-text"><i class="bi bi-calendar text-primary"></i></span>
          <input type="date" class="form-control" name="birth_date" required value="<?= h($birth_date) ?>" max="<?= date('Y-m-d') ?>">
        </div>
      </div>

      <div class="row g-3">
        <!-- Roli -->
        <div class="col-md-4">
          <label class="form-label">Roli</label>
          <select class="form-select" name="role" required>
            <?php foreach ($allowedRoles as $r): ?>
              <option value="<?= h($r) ?>" <?= $role===$r?'selected':'' ?>><?= h($r) ?></option>
            <?php endforeach; ?>
          </select>
        </div>

        <!-- Statusi -->
        <div class="col-md-4">
          <label class="form-label">Statusi</label>
          <select class="form-select" name="status">
            <?php foreach ($allowedStatuses as $st): ?>
              <option value="<?= h($st) ?>" <?= $status===$st?'selected':'' ?>><?= h($st) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
      </div>

      <!-- Telefoni -->
      <div class="mt-3">
        <label class="form-label">Numri i telefonit</label>
        <div class="input-group">
          <span class="input-group-text"><i class="bi bi-phone text-primary"></i></span>
          <input type="text" class="form-control" name="phone_number" required maxlength="30"
                 placeholder="+355 6X XXX XXXX" value="<?= h($phone_number) ?>">
        </div>
      </div>

      <!-- Email -->
      <div class="mt-3">
        <label class="form-label">Email</label>
        <div class="input-group">
          <span class="input-group-text"><i class="bi bi-envelope text-primary"></i></span>
          <input type="email" class="form-control" name="email" required maxlength="160"
                 placeholder="emri@shembull.com" value="<?= h($email) ?>">
        </div>
      </div>

      <!-- Fjalëkalimi -->
      <div class="mt-3">
        <label class="form-label">Fjalëkalimi (lëre bosh për të mos e ndryshuar)</label>
        <div class="input-group">
          <span class="input-group-text"><i class="bi bi-lock text-primary"></i></span>
          <input type="password" class="form-control" id="password" name="password" placeholder="Min. 8 karaktere">
          <button class="btn btn-outline-secondary" type="button" onclick="togglePass()"><i class="bi bi-eye"></i></button>
        </div>
        <div class="form-text">Nëse e plotësoni, fjalëkalimi do të përditësohet.</div>
      </div>

      <!-- Veprime -->
      <div class="d-flex gap-2 mt-4">
        <button type="submit" class="btn btn-primary"><i class="bi bi-save me-1"></i>Ruaj ndryshimet</button>
        <a href="../users.php" class="btn btn-outline-secondary"><i class="bi bi-arrow-left me-1"></i>Anulo</a>
      </div>
    </form>
  </div>
</main>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script>
function togglePass(){
  const inp = document.getElementById('password');
  if (!inp) return;
  inp.type = inp.type === 'password' ? 'text' : 'password';
}
</script>
</body>
</html>
