<?php
// promotion_details.php — Publike (Guest/Student/Admin)
// - Guest/Student: formë regjistrimi (nëse student i regjistruar -> mesazh "tashmë i regjistruar")
// - Admin: listë regjistrimesh
declare(strict_types=1);
session_start();
require_once __DIR__ . '/lib/database.php';

/* ------------------------------- Helpers ------------------------------- */
function h(?string $s): string { return htmlspecialchars($s ?? '', ENT_QUOTES, 'UTF-8'); }
function set_flash(string $msg, string $type='success'): void { $_SESSION['flash'] = ['msg'=>$msg, 'type'=>$type]; }
function get_flash(): ?array { if (!empty($_SESSION['flash'])) { $f=$_SESSION['flash']; unset($_SESSION['flash']); return $f; } return null; }

/* ------------------------------- CSRF ---------------------------------- */
if (empty($_SESSION['csrf_token'])) { $_SESSION['csrf_token'] = bin2hex(random_bytes(32)); }
$CSRF = $_SESSION['csrf_token'];

/* ------------------------------ Role/Me -------------------------------- */
$USER = $_SESSION['user'] ?? null;
$ROLE = $USER['role'] ?? null;
$ME_ID = isset($USER['id']) ? (int)$USER['id'] : null;

/* ------------------------------ Load Promo ----------------------------- */
if (!isset($_GET['id']) || !ctype_digit((string)$_GET['id'])) { http_response_code(404); die('Promocioni nuk u gjet.'); }
$promoId = (int)$_GET['id'];

try {
  $st = $pdo->prepare("SELECT * FROM promoted_courses WHERE id=:id");
  $st->execute([':id'=>$promoId]);
  $promo = $st->fetch(PDO::FETCH_ASSOC);
  if (!$promo) { http_response_code(404); die('Promocioni nuk u gjet.'); }
} catch (Throwable $e) {
  http_response_code(500); die('Gabim gjatë leximit: ' . h($e->getMessage()));
}

/* -------------------------- Student already? --------------------------- */
$already = false;
if ($ROLE === 'Student' && $ME_ID) {
  try {
    $chk = $pdo->prepare("SELECT 1 FROM promoted_course_enrollments WHERE promotion_id=:p AND user_id=:u LIMIT 1");
    $chk->execute([':p'=>$promoId, ':u'=>$ME_ID]);
    $already = (bool)$chk->fetchColumn();
  } catch (Throwable $e) { /* ignore */ }
}

/* ------------------------------ POST: Register ------------------------- */
$errors = [];
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action']==='register') {
  // CSRF
  if (!hash_equals($CSRF, (string)($_POST['csrf'] ?? ''))) {
    $errors[] = 'Seancë e pasigurt (CSRF). Rifresko faqen dhe provo sërish.';
  }

  // Lexo input (për studentë, i mbushim nga profili)
  $first = trim((string)($_POST['first_name'] ?? ''));
  $last  = trim((string)($_POST['last_name'] ?? ''));
  $email = trim((string)($_POST['email'] ?? ''));
  $phone = trim((string)($_POST['phone'] ?? ''));
  $note  = trim((string)($_POST['note'] ?? ''));
  $consent = isset($_POST['consent']) && $_POST['consent'] === '1';

  if ($ROLE === 'Student' && $ME_ID) {
    // Merr nga profili, mos lejo spoof
    $first = $first ?: $USER['full_name'];
    // ndajmë emrin në first/last nëse s’ka hyrje të veçantë
    if (!$last) {
      $parts = preg_split('/\s+/', (string)$USER['full_name']);
      $first = $parts ? implode(' ', array_slice($parts, 0, max(1,count($parts)-1))) : $USER['full_name'];
      $last  = $parts && count($parts)>1 ? end($parts) : '';
    }
    $email = $USER['email'] ?? $email;
    $phone = $USER['phone_number'] ?? $phone;
  }

  // Validime
  if ($first === '') { $errors[] = 'Emri është i detyrueshëm.'; }
  if ($last === '')  { $errors[] = 'Mbiemri është i detyrueshëm.'; }
  if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) { $errors[] = 'Email i pavlefshëm.'; }
  if (!$consent) { $errors[] = 'Duhet të pranosh kushtet/privatësinë.'; }

  // Insert
  if (!$errors) {
    try {
      $stmt = $pdo->prepare("
        INSERT INTO promoted_course_enrollments
          (promotion_id, user_id, first_name, last_name, email, phone, note, consent)
        VALUES
          (:p, :u, :f, :l, :e, :ph, :n, :c)
      ");
      $stmt->execute([
        ':p'  => $promoId,
        ':u'  => $ROLE==='Student' && $ME_ID ? $ME_ID : null,
        ':f'  => $first,
        ':l'  => $last,
        ':e'  => $email,
        ':ph' => $phone !== '' ? $phone : null,
        ':n'  => $note  !== '' ? $note  : null,
        ':c'  => $consent ? 1 : 0,
      ]);
      set_flash('U regjistrove me sukses! Do të të kontaktojmë së shpejti.', 'success');
      header('Location: promotion_details.php?id='.(int)$promoId); exit;
    } catch (Throwable $e) {
      // Duplicate? (unique promotion_id+email ose +user)
      if (strpos($e->getMessage(), 'uq_promo_email') !== false || strpos($e->getMessage(), 'uq_promo_user') !== false) {
        set_flash('Tashmë je i regjistruar për këtë kurs.', 'warning');
        header('Location: promotion_details.php?id='.(int)$promoId); exit;
      }
      $errors[] = 'Gabim gjatë regjistrimit: ' . $e->getMessage();
    }
  }
}

/* -------------------------- Admin: data për listë --------------------- */
$adminList = [];
$searchQ = trim((string)($_GET['q'] ?? ''));
if ($ROLE === 'Administrator') {
  try {
    $where = "WHERE promotion_id = :p";
    $params = [':p'=>$promoId];
    if ($searchQ !== '') {
      $where .= " AND (first_name LIKE :q OR last_name LIKE :q OR email LIKE :q OR phone LIKE :q)";
      $params[':q'] = "%{$searchQ}%";
    }
    $sql = "SELECT * FROM promoted_course_enrollments {$where} ORDER BY created_at DESC";
    $stx = $pdo->prepare($sql);
    $stx->execute($params);
    $adminList = $stx->fetchAll(PDO::FETCH_ASSOC) ?: [];
  } catch (Throwable $e) { $adminList = []; }
}

/* -------------------------- UI helpers (price) ------------------------ */
$price     = $promo['price']     !== null ? (float)$promo['price']     : null;
$old_price = $promo['old_price'] !== null ? (float)$promo['old_price'] : null;
$discount  = ($price !== null && $old_price !== null && $old_price>0 && $price<$old_price)
           ? round((($old_price - $price)/$old_price)*100) : null;

$photo = $promo['photo'] ?: 'image/course_placeholder.jpg';
$badge = $promo['label'] ?: '';
$badgeColor = $promo['badge_color'] ?: '#F0B323';
$levelMap = ['BEGINNER'=>'Fillestar','INTERMEDIATE'=>'Mesatar','ADVANCED'=>'I avancuar','ALL'=>'Për të gjithë'];
$levelLabel = $levelMap[$promo['level'] ?? 'ALL'] ?? 'Për të gjithë';

/* ------------------------------- Layout -------------------------------- */
?>
<!DOCTYPE html>
<html lang="sq">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <title><?= h($promo['name']) ?> — kurseinformatike.com</title>

  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
  <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css" rel="stylesheet">
  <link rel="icon" href="/image/favicon.ico" type="image/x-icon" />

  <style>
    :root{
      --primary:#2A4B7C; --primary-dark:#1d3a63; --secondary:#F0B323;
      --card-r:18px; --shadow:0 10px 28px rgba(0,0,0,.08);
      --brand-font: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, "Helvetica Neue", Arial, "Noto Sans", "Liberation Sans", sans-serif, "Apple Color Emoji", "Segoe UI Emoji", "Segoe UI Symbol", "Noto Color Emoji";
    }
    body{ background:#f6f8fb; }
    h1,h2,h3,h4,h5,h6{ font-family: var(--brand-font); letter-spacing:.2px; }
    .hero{
      background: linear-gradient(135deg,var(--primary),var(--primary-dark));
      color:#fff; padding:32px 0; margin-bottom:16px;
    }
    .card-ui{ border:0; border-radius:var(--card-r); box-shadow:var(--shadow); background:#fff; }
    .thumb{ width:100%; aspect-ratio:16/9; object-fit:cover; border-radius:14px; background:#f1f5f9; }
    .badge-pill{ border-radius:999px; padding:.35rem .6rem; font-weight:600; }
    .price-now{ font-size:1.6rem; font-weight:800; color: white; }
    .price-old{ text-decoration:line-through; color:#6b7280; }
    .discount{ font-weight:700; }

    /* Markdown */
    .markdown-body{ font-size: .98rem; line-height:1.6; }
    .markdown-body h1,.markdown-body h2{ border-bottom:1px solid #e5e7eb; padding-bottom:.3rem; margin-top:1rem; }
    .markdown-body pre{ background:#0f172a; color:#e2e8f0; padding:.75rem; border-radius:10px; overflow:auto; }
    .markdown-body code{ background:#f1f5f9; padding:.1rem .3rem; border-radius:6px; }
    .markdown-body a{ color:#1d4ed8; text-decoration: underline; }
    .markdown-body blockquote{ border-left:4px solid #cbd5e1; padding-left:.75rem; color:#475569; }

    /* Toast bottom-right */
    #toastZone{ position: fixed; right: 16px; bottom: 16px; z-index: 1100; }
    .toast.kurse{ background:#fff; border:1px solid #e8ecf4; box-shadow:var(--shadow); border-radius:12px; overflow:hidden; }
    .toast.kurse .toast-header{ background: linear-gradient(135deg,var(--primary),var(--primary-dark)); color:#fff; }
    .toast.kurse .btn-close{ filter: invert(1); }
  </style>
</head>
<body>

<?php
// Navbar: përdor atë që ke, ose mos shfaq nëse s’ke publik
if (!empty($USER)) {
  if ($ROLE === 'Administrator')      include __DIR__ . '/navbar_logged_administrator.php';
  elseif ($ROLE === 'Instruktor')     include __DIR__ . '/navbar_logged_instructor.php';
  else                                include __DIR__ . '/navbar_logged_student.php';
} else {
  if (file_exists(__DIR__ . '/navbar.php')) include __DIR__ . '/navbar.php';
}
?>

<section class="hero">
  <div class="container">
    <div class="row g-3 align-items-center">
      <div class="col-lg-8">
        <div class="d-flex align-items-center gap-2 flex-wrap mb-2">
          <?php if ($badge): ?>
            <span class="badge-pill text-white" style="background: <?= h($badgeColor) ?>"><?= h($badge) ?></span>
          <?php endif; ?>
          <span class="badge text-bg-light"><i class="fa-regular fa-clock me-1"></i><?= (int)$promo['hours_total'] ?> orë</span>
          <span class="badge text-bg-light"><i class="fa-solid fa-signal me-1"></i><?= h($levelLabel) ?></span>
        </div>
        <h1 class="mb-2"><?= h($promo['name']) ?></h1>
        <?php if (!empty($promo['short_desc'])): ?>
          <p class="mb-0 text-white-75"><?= h($promo['short_desc']) ?></p>
        <?php endif; ?>
      </div>
      <div class="col-lg-4 text-lg-end">
        <?php if ($price !== null): ?>
          <div class="price-now mb-1">
            <i class="fa-solid fa-euro-sign me-1"></i><?= number_format($price, 2) ?>
            <?php if ($discount !== null): ?>
              <span class="discount text-success ms-2">-<?= (int)$discount ?>%</span>
            <?php endif; ?>
          </div>
          <?php if ($old_price !== null): ?>
            <div class="price-old"><i class="fa-solid fa-euro-sign me-1"></i><?= number_format($old_price, 2) ?></div>
          <?php endif; ?>
        <?php endif; ?>
      </div>
    </div>
  </div>
</section>

<div class="container mb-4">
  <div class="row g-3">
    <div class="col-lg-8">
      <div class="card card-ui">
        <div class="card-body">
          <img src="<?= h($photo) ?>" class="thumb mb-3" alt="Promo image">

          <!-- Video (opsionale) -->
          <?php if (!empty($promo['video_url']) && filter_var($promo['video_url'], FILTER_VALIDATE_URL)): ?>
            <div class="mb-3">
              <a class="btn btn-outline-dark" href="<?= h($promo['video_url']) ?>" target="_blank" rel="noopener">
                <i class="fa-solid fa-circle-play me-1"></i> Shiko videon
              </a>
            </div>
          <?php endif; ?>

          <h5 class="mb-2">Përshkrimi</h5>
          <div id="mdRendered" class="markdown-body"></div>
          <textarea id="mdSource" class="d-none"><?= h((string)$promo['description']) ?></textarea>
        </div>
      </div>
    </div>

    <div class="col-lg-4">
      <?php if ($ROLE === 'Administrator'): ?>
        <!-- ADMIN: lista e të regjistruarve -->
        <div class="card card-ui">
          <div class="card-body">
            <div class="d-flex align-items-center justify-content-between mb-2">
              <h5 class="mb-0"><i class="fa-solid fa-users me-2"></i> Regjistrimet</h5>
              <a class="btn btn-sm btn-outline-secondary" href="promotion_details_export.php?id=<?= (int)$promoId ?>">
                <i class="fa-solid fa-file-export me-1"></i> Export CSV
              </a>
            </div>

            <form class="mb-2" method="get">
              <input type="hidden" name="id" value="<?= (int)$promoId ?>">
              <div class="input-group">
                <span class="input-group-text"><i class="fa-solid fa-search"></i></span>
                <input type="text" class="form-control" name="q" value="<?= h($searchQ) ?>" placeholder="Kërko emër/email/telefon…">
              </div>
            </form>

            <?php if (!$adminList): ?>
              <div class="text-secondary">S’ka regjistrime ende.</div>
            <?php else: ?>
              <div class="vstack gap-2">
                <?php foreach ($adminList as $r): ?>
                  <div class="border rounded p-2">
                    <div class="fw-semibold"><?= h($r['first_name'] . ' ' . $r['last_name']) ?></div>
                    <div class="small"><i class="fa-regular fa-envelope me-1"></i><?= h($r['email']) ?><?php if(!empty($r['phone'])): ?> · <i class="fa-solid fa-phone me-1"></i><?= h($r['phone']) ?><?php endif; ?></div>
                    <div class="text-secondary small"><i class="fa-regular fa-clock me-1"></i><?= date('d.m.Y H:i', strtotime($r['created_at'])) ?></div>
                    <?php if (!empty($r['note'])): ?>
                      <div class="small mt-1 border-top pt-1"><?= nl2br(h($r['note'])) ?></div>
                    <?php endif; ?>
                  </div>
                <?php endforeach; ?>
              </div>
            <?php endif; ?>
          </div>
        </div>
      <?php else: ?>
        <!-- GUEST / STUDENT: formë regjistrimi -->
        <div class="card card-ui">
          <div class="card-body">
            <h5 class="mb-2"><i class="fa-solid fa-clipboard-check me-2"></i> Regjistrohu</h5>

            <?php if ($errors): ?>
              <div class="alert alert-danger">
                <div class="fw-semibold mb-1">Disa fusha kërkojnë vëmendje:</div>
                <ul class="mb-0"><?php foreach ($errors as $e): ?><li><?= h($e) ?></li><?php endforeach; ?></ul>
              </div>
            <?php endif; ?>

            <?php if ($ROLE==='Student' && $already): ?>
              <div class="alert alert-info"><i class="fa-solid fa-circle-info me-1"></i>
                Tashmë je i regjistruar për këtë kurs.
              </div>
            <?php else: ?>
              <?php
                // Prefill nëse është student i loguar
                $pf_first = ''; $pf_last = ''; $pf_email = ''; $pf_phone = '';
                if ($ROLE==='Student' && $ME_ID) {
                  $pf_email = (string)($USER['email'] ?? '');
                  $pf_phone = (string)($USER['phone_number'] ?? '');
                  $full = (string)($USER['full_name'] ?? '');
                  $parts = preg_split('/\s+/', $full);
                  $pf_first = $parts ? implode(' ', array_slice($parts, 0, max(1,count($parts)-1))) : $full;
                  $pf_last  = $parts && count($parts)>1 ? end($parts) : '';
                }
              ?>
              <form method="post">
                <input type="hidden" name="csrf" value="<?= h($CSRF) ?>">
                <input type="hidden" name="action" value="register">

                <div class="row g-2">
                  <div class="col-6">
                    <label class="form-label">Emri</label>
                    <input type="text" name="first_name" class="form-control" required value="<?= h($pf_first) ?>">
                  </div>
                  <div class="col-6">
                    <label class="form-label">Mbiemri</label>
                    <input type="text" name="last_name" class="form-control" required value="<?= h($pf_last) ?>">
                  </div>
                </div>

                <div class="row g-2 mt-1">
                  <div class="col-6">
                    <label class="form-label">Email</label>
                    <input type="email" name="email" class="form-control" required value="<?= h($pf_email) ?>" <?= $ROLE==='Student'?'readonly':'' ?>>
                  </div>
                  <div class="col-6">
                    <label class="form-label">Telefon</label>
                    <input type="text" name="phone" class="form-control" value="<?= h($pf_phone) ?>">
                  </div>
                </div>

                <div class="mt-2">
                  <label class="form-label">Shënim (opsional)</label>
                  <textarea name="note" rows="3" class="form-control" placeholder="Pyetje/sqarime shtesë…"></textarea>
                </div>

                <div class="form-check mt-2">
                  <input class="form-check-input" type="checkbox" name="consent" id="consent" value="1" checked>
                  <label class="form-check-label" for="consent">
                    Pajtohem me përpunimin e të dhënave sipas politikës së privatësisë.
                  </label>
                </div>

                <div class="d-grid mt-3">
                  <button class="btn btn-primary"><i class="fa-solid fa-paper-plane me-1"></i> Dergo regjistrimin</button>
                </div>
              </form>
            <?php endif; ?>
          </div>
        </div>
      <?php endif; ?>
    </div>
  </div>
</div>

<!-- Toast container -->
<div id="toastZone" aria-live="polite" aria-atomic="true"></div>

<?php include __DIR__ . '/footer.php'; ?>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<!-- Markdown + sanitizer -->
<script src="https://cdn.jsdelivr.net/npm/marked/marked.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/dompurify/dist/purify.min.js"></script>
<script>
/* Render Markdown */
if (window.marked) { marked.setOptions({ breaks: true }); }
const src = document.getElementById('mdSource')?.value || '';
const html = window.marked ? marked.parse(src) : src;
document.getElementById('mdRendered').innerHTML = window.DOMPurify ? DOMPurify.sanitize(html) : html;

/* Toasts bottom-right */
function toastIcon(type){
  if (type==='success') return '<i class="fa-solid fa-circle-check me-2"></i>';
  if (type==='danger' || type==='error') return '<i class="fa-solid fa-triangle-exclamation me-2"></i>';
  if (type==='warning') return '<i class="fa-solid fa-circle-exclamation me-2"></i>';
  return '<i class="fa-solid fa-circle-info me-2"></i>';
}
function showToast(type, msg){
  const zone = document.getElementById('toastZone');
  const id = 't' + Math.random().toString(16).slice(2);
  const el = document.createElement('div');
  el.className = 'toast kurse align-items-center';
  el.id = id;
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
<?php if ($fl = get_flash()): ?>
  (function(){ showToast(<?= json_encode($fl['type']) ?>, <?= json_encode($fl['msg']) ?>); })();
<?php endif; ?>
</script>
</body>
</html>
