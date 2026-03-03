<?php
declare(strict_types=1);

if (session_status() === PHP_SESSION_NONE) { session_start(); }
require_once __DIR__ . '/lib/database.php';

/* ------------------------- Auth / RBAC ------------------------- */
if (!isset($_SESSION['user'])) { header('Location: login.php'); exit; }
$ROLE    = (string)($_SESSION['user']['role'] ?? '');
$USER_ID = (int)($_SESSION['user']['id'] ?? 0);
if ($USER_ID <= 0) { header('Location: login.php'); exit; }

/* ------------------------- CSRF ------------------------- */
if (empty($_SESSION['csrf_token'])) { $_SESSION['csrf_token'] = bin2hex(random_bytes(32)); }
$CSRF = (string)$_SESSION['csrf_token'];

/* ------------------------- Helpers ------------------------- */
function h(?string $s): string { return htmlspecialchars($s ?? '', ENT_QUOTES, 'UTF-8'); }

function set_flash(string $msg, string $type='success'): void {
  $_SESSION['flash'] = ['msg'=>$msg, 'type'=>$type];
}
function get_flash(): ?array {
  if (!empty($_SESSION['flash'])) {
    $f = $_SESSION['flash']; unset($_SESSION['flash']);
    return $f;
  }
  return null;
}
function pick_nav_for_role(string $role): string {
  $r = strtolower(trim($role));
  if ($r === 'administrator') return __DIR__ . '/navbar_logged_administrator.php';
  if ($r === 'instruktor' || $r === 'instructor') return __DIR__ . '/navbar_logged_instructor.php';
  return __DIR__ . '/navbar_logged_student.php';
}

$message = ''; $error = '';

/* ------------------------- Load user ------------------------- */
try {
  $stmt = $pdo->prepare("SELECT id, role, full_name, birth_date, phone_number, email, password, created_at FROM users WHERE id=? LIMIT 1");
  $stmt->execute([$USER_ID]);
  $user = $stmt->fetch(PDO::FETCH_ASSOC);
  if (!$user) { die('Përdoruesi nuk u gjet.'); }
} catch (PDOException $e) {
  die("Gabim: " . h($e->getMessage()));
}

/* ------------------------- POST: update profile/password ------------------------- */
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
  $csrf = (string)($_POST['csrf'] ?? '');
  if ($csrf === '' || !hash_equals($CSRF, $csrf)) {
    $error = 'Seancë e pavlefshme (CSRF). Ringarko faqen dhe provo sërish.';
  } else {
    $full_name    = trim((string)($_POST['full_name'] ?? ''));
    $birth_date   = trim((string)($_POST['birth_date'] ?? ''));
    $phone_number = trim((string)($_POST['phone_number'] ?? ''));
    $email        = trim((string)($_POST['email'] ?? ''));

    $cur_pass  = (string)($_POST['current_password'] ?? '');
    $new_pass  = (string)($_POST['password'] ?? '');
    $conf_pass = (string)($_POST['confirm_password'] ?? '');

    $errs = [];

    // Basic validation
    if ($full_name === '') $errs[] = 'Emri dhe mbiemri është i detyrueshëm.';
    if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) $errs[] = 'Adresa email nuk është e vlefshme.';

    // Birth date required (si te kodi yt)
    if ($birth_date === '') {
      $errs[] = 'Data e lindjes është e detyrueshme.';
    } elseif (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $birth_date)) {
      $errs[] = 'Data e lindjes duhet të jetë në formatin YYYY-MM-DD.';
    } else {
      [$y,$m,$d] = explode('-', $birth_date);
      if (!checkdate((int)$m,(int)$d,(int)$y)) $errs[] = 'Data e lindjes nuk është e vlefshme.';
    }

    if ($phone_number === '') $errs[] = 'Numri i telefonit është i detyrueshëm.';

    // Email uniqueness
    try {
      $stmtEmail = $pdo->prepare("SELECT id FROM users WHERE email=? AND id<>? LIMIT 1");
      $stmtEmail->execute([$email, $USER_ID]);
      if ($stmtEmail->fetch()) $errs[] = 'Kjo adresë email është tashmë në përdorim.';
    } catch (PDOException $e) {
      $errs[] = 'Gabim gjatë verifikimit të email-it.';
    }

    // Password change logic
    $willChangePassword = ($cur_pass !== '' || $new_pass !== '' || $conf_pass !== '');
    if ($willChangePassword) {
      if ($cur_pass === '' || $new_pass === '' || $conf_pass === '') {
        $errs[] = 'Për të ndryshuar fjalëkalimin, plotësoni të tre fushat.';
      } else {
        if (!password_verify($cur_pass, (string)$user['password'])) $errs[] = 'Fjalëkalimi aktual është i pasaktë.';
        if ($new_pass !== $conf_pass) $errs[] = 'Fjalëkalimi i ri dhe konfirmimi nuk përputhen.';
        if (strlen($new_pass) < 8) $errs[] = 'Fjalëkalimi i ri duhet të ketë të paktën 8 karaktere.';
      }
    }

    if ($errs) {
      $error = implode(' ', $errs);
    } else {
      try {
        if ($willChangePassword) {
          $hashed = password_hash($new_pass, PASSWORD_DEFAULT);
          $st = $pdo->prepare("
            UPDATE users
            SET full_name=?, birth_date=?, phone_number=?, email=?, password=?, updated_at=CURRENT_TIMESTAMP
            WHERE id=?
          ");
          $st->execute([$full_name, $birth_date, $phone_number, $email, $hashed, $USER_ID]);
        } else {
          $st = $pdo->prepare("
            UPDATE users
            SET full_name=?, birth_date=?, phone_number=?, email=?, updated_at=CURRENT_TIMESTAMP
            WHERE id=?
          ");
          $st->execute([$full_name, $birth_date, $phone_number, $email, $USER_ID]);
        }

        // refresh session
        $_SESSION['user']['full_name']    = $full_name;
        $_SESSION['user']['birth_date']   = $birth_date;
        $_SESSION['user']['phone_number'] = $phone_number;
        $_SESSION['user']['email']        = $email;

        set_flash('Profili u përditësua me sukses.', 'success');

        // reload user for render
        $stmt = $pdo->prepare("SELECT id, role, full_name, birth_date, phone_number, email, created_at, updated_at FROM users WHERE id=? LIMIT 1");
        $stmt->execute([$USER_ID]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC) ?: $user;

        header("Location: profile.php"); exit;

      } catch (PDOException $e) {
        $error = 'Gabim gjatë përditësimit: ' . h($e->getMessage());
      }
    }
  }
}

/* ------------------------- Derived UI values ------------------------- */
$fullName = (string)($user['full_name'] ?? '');
$email    = (string)($user['email'] ?? '');
$avatar   = mb_strtoupper(mb_substr($fullName !== '' ? $fullName : ($email !== '' ? $email : 'U'), 0, 1, 'UTF-8'), 'UTF-8');
$roleLabel = $ROLE !== '' ? $ROLE : (string)($user['role'] ?? 'User');

$createdAt = !empty($user['created_at']) ? date('d.m.Y', strtotime((string)$user['created_at'])) : '—';
$updatedAt = !empty($user['updated_at']) ? date('d.m.Y H:i', strtotime((string)$user['updated_at'])) : '—';
?>
<!DOCTYPE html>
<html lang="sq" data-bs-theme="light">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width,initial-scale=1.0">
  <title>Profili Im</title>
  <link rel="icon" href="image/favicon.ico" type="image/x-icon" />

  <!-- CSS & Icons -->
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css">
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css">

  <!-- (opsionale) nëse ke courses.css dhe do unifikim me sistemin -->
  <link rel="stylesheet" href="css/courses.css?v=1">
  <link rel="stylesheet" href="css/profile.css?v=1">
</head>

<body class="course-body">

<?php include pick_nav_for_role($ROLE); ?>

<header class="profile-hero">
  <div class="container">
    <div class="d-flex flex-column flex-lg-row align-items-start align-items-lg-center justify-content-between gap-3">
      <div>
        <div class="profile-breadcrumb">
          <i class="fa-solid fa-house me-1"></i> Paneli / Profili
        </div>
        <h1 class="mb-1">Profili im</h1>
        <p class="mb-0">Menaxho të dhënat personale dhe sigurinë e llogarisë.</p>
      </div>

      <div class="profile-hero-right">
        <div class="profile-stat">
          <div class="ico"><i class="fa-solid fa-user-shield"></i></div>
          <div>
            <div class="label">Roli</div>
            <div class="value"><?= h($roleLabel) ?></div>
          </div>
        </div>
        <div class="profile-stat">
          <div class="ico"><i class="fa-regular fa-calendar"></i></div>
          <div>
            <div class="label">Krijuar</div>
            <div class="value"><?= h($createdAt) ?></div>
          </div>
        </div>
      </div>

    </div>
  </div>
</header>

<main class="profile-main">
  <div class="container">

    <?php if ($message): ?>
      <div class="alert alert-success alert-dismissible fade show" role="alert">
        <i class="fa-solid fa-circle-check me-2"></i><?= h($message) ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Mbyll"></button>
      </div>
    <?php endif; ?>

    <?php if ($error): ?>
      <div class="alert alert-danger alert-dismissible fade show" role="alert">
        <i class="fa-solid fa-triangle-exclamation me-2"></i><?= h($error) ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Mbyll"></button>
      </div>
    <?php endif; ?>

    <div class="row g-3">
      <!-- LEFT: Profile card -->
      <div class="col-12 col-lg-4">
        <section class="profile-card">
          <div class="profile-card-top">
            <div class="profile-avatar"><?= h($avatar) ?></div>
            <div class="ms-2">
              <div class="profile-name"><?= h($fullName ?: '—') ?></div>
              <div class="profile-email"><i class="fa-regular fa-at me-1"></i><?= h($email ?: '—') ?></div>
              <div class="profile-badges mt-2">
                <span class="badge text-bg-light"><i class="fa-solid fa-id-badge me-1"></i>ID: <?= (int)$USER_ID ?></span>
                <span class="badge text-bg-light"><i class="fa-solid fa-shield-halved me-1"></i><?= h($roleLabel) ?></span>
              </div>
            </div>
          </div>

          <hr class="my-3">

          <div class="profile-meta">
            <div class="row g-2">
              <div class="col-6">
                <div class="k">Status</div>
                <div class="v"><span style="background-color: var(--p-primary);" class="badge text-bg-success-subtle"><i class="fa-solid fa-circle-check me-1"></i> Aktiv</span></div>
              </div>
            </div>
          </div>

          <div class="profile-tip mt-3">
            <div class="title"><i class="fa-solid fa-lock me-1"></i>Këshillë sigurie</div>
            <div class="text">Përdor fjalëkalim të gjatë dhe unik. Ndryshoje periodikisht.</div>
          </div>
        </section>
      </div>

      <!-- RIGHT: Tabs + forms -->
      <div class="col-12 col-lg-8">
        <section class="profile-panel">
          <div class="profile-tabs">
            <button type="button" class="tab-btn active" data-tab="tab-personal">
              <i class="fa-regular fa-address-card me-1"></i> Të dhënat
            </button>
            <button type="button" class="tab-btn" data-tab="tab-security">
              <i class="fa-solid fa-shield-halved me-1"></i> Siguria
            </button>
          </div>

          <form method="POST" action="profile.php" class="profile-form" autocomplete="off" novalidate>
            <input type="hidden" name="csrf" value="<?= h($CSRF) ?>">

            <!-- TAB: Personal -->
            <div class="tab-pane show" id="tab-personal">
              <div class="profile-section-title">
                <div><i class="fa-regular fa-address-card me-2"></i>Të dhënat personale</div>
                <div class="hint">Përditëso emrin, kontaktin dhe email-in e hyrjes.</div>
              </div>

              <div class="row g-3">
                <div class="col-12">
                  <label class="form-label">Emri dhe Mbiemri</label>
                  <input type="text" class="form-control" name="full_name" required value="<?= h((string)($user['full_name'] ?? '')) ?>">
                </div>

                <div class="col-md-6">
                  <label class="form-label">Data e lindjes</label>
                  <input type="date" class="form-control" name="birth_date" required value="<?= h((string)($user['birth_date'] ?? '')) ?>">
                </div>

                <div class="col-md-6">
                  <label class="form-label">Nr. i telefonit</label>
                  <input type="text" class="form-control" name="phone_number" required value="<?= h((string)($user['phone_number'] ?? '')) ?>">
                </div>

                <div class="col-12">
                  <label class="form-label">Email</label>
                  <input type="email" class="form-control" name="email" required value="<?= h((string)($user['email'] ?? '')) ?>">
                  <div class="profile-help mt-1"><i class="fa-regular fa-circle-info me-1"></i>Ky email përdoret për hyrje dhe njoftime.</div>
                </div>
              </div>

              <div class="profile-actions mt-3">
                <button type="button" class="btn btn-outline-secondary" id="btnToSecurity">
                  <i class="fa-solid fa-key me-1"></i> Ndrysho fjalëkalimin
                </button>
                <button type="submit" class="btn btn-primary profile-btn-main">
                  <i class="fa-solid fa-floppy-disk me-1"></i> Ruaj ndryshimet
                </button>
              </div>
            </div>

            <!-- TAB: Security -->
            <div class="tab-pane" id="tab-security">
              <div class="profile-section-title">
                <div><i class="fa-solid fa-shield-halved me-2"></i>Siguria e llogarisë</div>
                <div class="hint">Ndrysho fjalëkalimin (opsionale). Plotëso vetëm kur do ndryshim.</div>
              </div>

              <div class="row g-3">
                <div class="col-12">
                  <label class="form-label">Fjalëkalimi aktual</label>
                  <div class="input-group">
                    <input type="password" class="form-control" id="current_password" name="current_password" autocomplete="current-password">
                    <button class="btn btn-outline-secondary" type="button" data-toggle-pass="current_password" aria-label="Shfaq/fshih">
                      <i class="fa-regular fa-eye"></i>
                    </button>
                  </div>
                  <div class="profile-help mt-1">Kërkohet vetëm nëse do të ndryshosh fjalëkalimin.</div>
                </div>

                <div class="col-md-6">
                  <label class="form-label">Fjalëkalimi i ri</label>
                  <div class="input-group">
                    <input type="password" class="form-control" id="password" name="password" autocomplete="new-password">
                    <button class="btn btn-outline-secondary" type="button" data-toggle-pass="password" aria-label="Shfaq/fshih">
                      <i class="fa-regular fa-eye"></i>
                    </button>
                  </div>
                  <div class="profile-help mt-1">Minimumi 8 karaktere.</div>
                </div>

                <div class="col-md-6">
                  <label class="form-label">Konfirmo fjalëkalimin</label>
                  <div class="input-group">
                    <input type="password" class="form-control" id="confirm_password" name="confirm_password" autocomplete="new-password">
                    <button class="btn btn-outline-secondary" type="button" data-toggle-pass="confirm_password" aria-label="Shfaq/fshih">
                      <i class="fa-regular fa-eye"></i>
                    </button>
                  </div>
                  <div class="profile-help mt-1">Duhet të përputhet me fjalëkalimin e ri.</div>
                </div>
              </div>

              <div class="profile-actions mt-3">
                <button type="button" class="btn btn-outline-secondary" id="btnToPersonal">
                  <i class="fa-solid fa-arrow-left me-1"></i> Kthehu te të dhënat
                </button>
                <button type="submit" class="btn btn-primary profile-btn-main">
                  <i class="fa-solid fa-floppy-disk me-1"></i> Ruaj ndryshimet
                </button>
              </div>
            </div>

          </form>
        </section>
      </div>
    </div>

    <div class="mt-4"></div>
  </div>
</main>

<?php include __DIR__ . '/footer2.php'; ?>

<!-- Toast zone -->
<div id="toastZone" aria-live="polite" aria-atomic="true"></div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script>
// ---------------- Toast (si pagesat/users) ----------------
function toastIcon(type){
  if (type==='success') return '<i class="fa-solid fa-circle-check me-2"></i>';
  if (type==='danger' || type==='error') return '<i class="fa-solid fa-triangle-exclamation me-2"></i>';
  if (type==='warning') return '<i class="fa-solid fa-circle-exclamation me-2"></i>';
  return '<i class="fa-solid fa-circle-info me-2"></i>';
}
function showToast(type, msg){
  const zone = document.getElementById('toastZone');
  const el = document.createElement('div');
  el.className = 'toast kurse align-items-center';
  el.setAttribute('role','alert');
  el.setAttribute('aria-live','assertive');
  el.setAttribute('aria-atomic','true');
  el.innerHTML = `
    <div class="toast-header">
      <strong class="me-auto d-flex align-items-center">${toastIcon(type)} Njoftim</strong>
      <small class="text-white-50">tani</small>
      <button type="button" class="btn-close ms-2 mb-1" data-bs-dismiss="toast" aria-label="Mbyll"></button>
    </div>
    <div class="toast-body">${msg}</div>`;
  zone.appendChild(el);
  new bootstrap.Toast(el, { delay: 3500, autohide: true }).show();
}

<?php if ($f = get_flash()): ?>
showToast(<?= json_encode((string)$f['type']) ?>, <?= json_encode((string)$f['msg']) ?>);
<?php endif; ?>

// ---------------- Tabs ----------------
const tabs = document.querySelectorAll('.tab-btn');
const panes = document.querySelectorAll('.tab-pane');
function openTab(id){
  panes.forEach(p => p.classList.toggle('show', p.id === id));
  tabs.forEach(b => b.classList.toggle('active', b.getAttribute('data-tab') === id));
  localStorage.setItem('profile_tab', id);
}
tabs.forEach(b => b.addEventListener('click', ()=> openTab(b.getAttribute('data-tab'))));
openTab(localStorage.getItem('profile_tab') || 'tab-personal');

document.getElementById('btnToSecurity')?.addEventListener('click', ()=> openTab('tab-security'));
document.getElementById('btnToPersonal')?.addEventListener('click', ()=> openTab('tab-personal'));

// ---------------- Toggle password fields (FontAwesome) ----------------
document.addEventListener('click', (e)=>{
  const btn = e.target.closest('[data-toggle-pass]');
  if (!btn) return;
  const id = btn.getAttribute('data-toggle-pass');
  const inp = document.getElementById(id);
  if (!inp) return;

  const isText = (inp.type === 'text');
  inp.type = isText ? 'password' : 'text';
  const ico = btn.querySelector('i');
  if (ico) ico.className = isText ? 'fa-regular fa-eye' : 'fa-regular fa-eye-slash';
});

// ---------------- Basic client validation hint (optional) ----------------
document.querySelector('.profile-form')?.addEventListener('submit', (e)=>{
  // le të kontrollojë serveri. Këtu vetëm një hint i vogël UX për password.
  const cur = (document.getElementById('current_password')?.value || '').trim();
  const np  = (document.getElementById('password')?.value || '').trim();
  const cp  = (document.getElementById('confirm_password')?.value || '').trim();

  const will = (cur || np || cp);
  if (will && (!cur || !np || !cp)) {
    e.preventDefault();
    showToast('warning', 'Për ndryshim fjalëkalimi, plotëso të tre fushat.');
    openTab('tab-security');
  }
});
</script>
</body>
</html>
