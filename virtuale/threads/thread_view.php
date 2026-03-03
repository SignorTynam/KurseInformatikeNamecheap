<?php
declare(strict_types=1);
session_start();

$ROOT = dirname(__DIR__);
$scriptDir = str_replace('\\', '/', dirname((string)($_SERVER['SCRIPT_NAME'] ?? '')));
$BASE_URL = $scriptDir;
foreach (['/threads', '/quizzes', '/sections'] as $suffix) {
  if ($suffix !== '/' && str_ends_with($BASE_URL, $suffix)) {
    $BASE_URL = substr($BASE_URL, 0, -strlen($suffix));
  }
}
if ($BASE_URL === '') $BASE_URL = '/';

require_once $ROOT . '/lib/database.php';
require_once $ROOT . '/lib/Parsedown.php';

/* ------------------------------- Helpers ------------------------------- */
function h(?string $s): string { return htmlspecialchars($s ?? '', ENT_QUOTES, 'UTF-8'); }

function csrf_token(): string {
  if (empty($_SESSION['csrf'])) { $_SESSION['csrf'] = bin2hex(random_bytes(32)); }
  if (empty($_SESSION['csrf_token'])) { $_SESSION['csrf_token'] = $_SESSION['csrf']; }
  return $_SESSION['csrf'];
}
function ensureCsrf(): void {
  $posted = (string)($_POST['csrf'] ?? '');
  $ok = false;
  foreach (['csrf','csrf_token'] as $k) {
    if (!empty($_SESSION[$k]) && hash_equals((string)$_SESSION[$k], $posted)) { $ok = true; break; }
  }
  if (!$ok) { http_response_code(403); exit('CSRF verifikimi dështoi.'); }
}
function flash_set(string $type, string $msg): void { $_SESSION["flash_$type"] = $msg; }
function flash_get(string $type): ?string { $k = "flash_$type"; if (!empty($_SESSION[$k])) { $m = $_SESSION[$k]; unset($_SESSION[$k]); return $m; } return null; }
function initial_letter(string $name): string { $name = trim($name); return $name === '' ? '?' : mb_strtoupper(mb_substr($name, 0, 1, 'UTF-8'), 'UTF-8'); }

/* -------------------------------- Auth --------------------------------- */
if (!isset($_SESSION['user'])) { header('Location: ' . $BASE_URL . '/login.php'); exit; }
$user     = $_SESSION['user'];
$user_id  = (int)($user['id'] ?? 0);
$userRole = (string)($user['role'] ?? '');

$thread_id = (int)($_GET['thread_id'] ?? 0);
if ($thread_id <= 0) { exit('Thread-i nuk është specifikuar.'); }

/* ----------------------- Thread + Course + Author ---------------------- */
try {
  $stmt = $pdo->prepare('
    SELECT
      t.id, t.title, t.content, t.created_at, t.user_id AS author_id, t.course_id,
      u.full_name AS author_name,
      c.title AS course_title, c.id_creator AS course_owner_id
    FROM threads t
    JOIN users   u ON u.id = t.user_id
    JOIN courses c ON c.id = t.course_id
    WHERE t.id = ?
    LIMIT 1
  ');
  $stmt->execute([$thread_id]);
  $thread = $stmt->fetch(PDO::FETCH_ASSOC);
  if (!$thread) { exit('Thread-i nuk u gjet.'); }
} catch (PDOException $e) { exit('Gabim: ' . h($e->getMessage())); }

$course_id       = (int)$thread['course_id'];
$course_title    = (string)$thread['course_title'];
$course_owner_id = (int)$thread['course_owner_id'];
$author_id       = (int)$thread['author_id'];

/* --------------------------- Permissions --------------------------------*/
// Studentët duhet të jenë të regjistruar në kurs
if ($userRole === 'Student') {
  $chk = $pdo->prepare('SELECT 1 FROM enroll WHERE course_id = ? AND user_id = ? LIMIT 1');
  $chk->execute([$course_id, $user_id]);
  if (!$chk->fetchColumn()) { http_response_code(403); exit('Nuk jeni të regjistruar në këtë kurs.'); }
}

// Kush menaxhon thread-in: Admin, Instruktori i kursit (owner), ose autori i thread-it
$canManageThread = (
  $userRole === 'Administrator' ||
  ($userRole === 'Instruktor' && $course_owner_id === $user_id) ||
  ($author_id === $user_id)
);

/* ----------------------------- Parsedown -------------------------------- */
$Parsedown = new Parsedown();
if (method_exists($Parsedown, 'setSafeMode')) { $Parsedown->setSafeMode(true); }

/* ------------------------------ Actions --------------------------------- */
/* 1) Shto përgjigje */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'add_reply') {
  ensureCsrf();
  $replyContent = trim((string)($_POST['reply_content'] ?? ''));
  if ($replyContent === '') { flash_set('error', 'Përgjigja nuk mund të jetë bosh.'); header('Location: ' . $BASE_URL . '/threads/thread_view.php?thread_id=' . $thread_id . '#reply-form'); exit; }
  if (mb_strlen($replyContent, 'UTF-8') > 8000) { flash_set('error', 'Përgjigja është shumë e gjatë (max 8000 karaktere).'); header('Location: ' . $BASE_URL . '/threads/thread_view.php?thread_id=' . $thread_id . '#reply-form'); exit; }
  $ins = $pdo->prepare('INSERT INTO thread_replies (thread_id, user_id, content) VALUES (?,?,?)');
  $ins->execute([$thread_id, $user_id, $replyContent]);
  flash_set('success', 'Përgjigja u publikua.');
  header('Location: ' . $BASE_URL . '/threads/thread_view.php?thread_id=' . $thread_id . '#replies'); exit;
}

/* 2) Shto thread të ri (në të njëjtin kurs) */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'add_thread') {
  ensureCsrf();
  if ($userRole === 'Student') {
    $chk = $pdo->prepare('SELECT 1 FROM enroll WHERE course_id = ? AND user_id = ? LIMIT 1');
    $chk->execute([$course_id, $user_id]);
    if (!$chk->fetchColumn()) { http_response_code(403); exit('Nuk jeni të regjistruar në këtë kurs.'); }
  }
  $title   = trim((string)($_POST['thread_title'] ?? ''));
  $content = trim((string)($_POST['thread_content'] ?? ''));
  if ($title === '' || $content === '') { flash_set('error', 'Titulli dhe përmbajtja janë të detyrueshme për temën e re.'); header('Location: ' . $BASE_URL . '/threads/thread_view.php?thread_id=' . $thread_id); exit; }
  if (mb_strlen($title,'UTF-8') > 255) { flash_set('error', 'Titulli është shumë i gjatë (max 255).'); header('Location: ' . $BASE_URL . '/threads/thread_view.php?thread_id=' . $thread_id); exit; }
  $ins = $pdo->prepare('INSERT INTO threads (course_id, user_id, title, content) VALUES (?,?,?,?)');
  $ins->execute([$course_id, $user_id, $title, $content]);
  $newId = (int)$pdo->lastInsertId();
  flash_set('success', 'Tema u krijua me sukses.');
  header('Location: ' . $BASE_URL . '/threads/thread_view.php?thread_id=' . $newId); exit;
}

/* 3) Ndrysho thread aktual */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'edit_thread') {
  ensureCsrf();
  if (!$canManageThread) { http_response_code(403); exit('Nuk keni të drejta për ta modifikuar këtë temë.'); }
  $title   = trim((string)($_POST['thread_title'] ?? ''));
  $content = trim((string)($_POST['thread_content'] ?? ''));
  if ($title === '' || $content === '') { flash_set('error', 'Titulli dhe përmbajtja janë të detyrueshme.'); header('Location: ' . $BASE_URL . '/threads/thread_view.php?thread_id=' . $thread_id . '#editThreadModal'); exit; }
  if (mb_strlen($title,'UTF-8') > 255) { flash_set('error', 'Titulli është shumë i gjatë (max 255).'); header('Location: ' . $BASE_URL . '/threads/thread_view.php?thread_id=' . $thread_id . '#editThreadModal'); exit; }
  $upd = $pdo->prepare('UPDATE threads SET title = ?, content = ? WHERE id = ?');
  $upd->execute([$title, $content, $thread_id]);
  flash_set('success', 'Tema u përditësua.');
  header('Location: ' . $BASE_URL . '/threads/thread_view.php?thread_id=' . $thread_id); exit;
}

/* 4) Fshi thread aktual */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'delete_thread') {
  ensureCsrf();
  if (!$canManageThread) { http_response_code(403); exit('Nuk keni të drejta për ta fshirë këtë temë.'); }
  $redirCourse = $course_id;
  $del = $pdo->prepare('DELETE FROM threads WHERE id = ?');
  $del->execute([$thread_id]);
  flash_set('success', 'Tema u fshi.');
  header('Location: ' . $BASE_URL . '/course_details.php?course_id=' . $redirCourse . '&tab=forum'); exit;
}

/* ------------------------------ Replies --------------------------------- */
$stmt = $pdo->prepare('
  SELECT r.id, r.content, r.created_at, u.full_name
  FROM thread_replies r
  JOIN users u ON u.id = r.user_id
  WHERE r.thread_id = ?
  ORDER BY r.created_at ASC
');
$stmt->execute([$thread_id]);
$replies = $stmt->fetchAll(PDO::FETCH_ASSOC);

/* ---------------------- Similar threads (same course) ------------------- */
try {
  $stmtSim = $pdo->prepare('
    SELECT id, title, created_at
    FROM threads
    WHERE course_id = ? AND id <> ?
    ORDER BY created_at DESC
    LIMIT 3
  ');
  $stmtSim->execute([$course_id, $thread_id]);
  $similar_threads = $stmtSim->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) { $similar_threads = []; }

/* -------------------- Unique participants from replies ------------------ */
$participants = [];
foreach ($replies as $rep) { $participants[$rep['full_name']] = true; }
$participants_count = count($participants);
?>
<!DOCTYPE html>
<html lang="sq" data-bs-theme="light">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title><?= h($thread['title']) ?> — Diskutim | kurseinformatike.com</title>
  <link rel="icon" href="<?= h($BASE_URL) ?>/image/favicon.ico" type="image/x-icon" />

  <!-- Ikona + CSS -->
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css">
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css">
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css">
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/highlight.js/11.7.0/styles/github.min.css">

  <!-- UI si course_details_student.php -->
  <style>
    :root{
      --primary:#2A4B7C; --primary-dark:#1d3a63; --shadow:0 10px 28px rgba(0,0,0,.08); --r:16px;
      --soft:#f6f8fb;
    }
    body{ background:var(--soft); }
    .hero{ background:linear-gradient(135deg,var(--primary),var(--primary-dark)); color:#fff; padding:26px 0 18px; position:relative; z-index:1; }
    .header-actions .btn{ background:#ffffff1a; color:#fff; border:0; }
    .header-actions .btn:hover{ background:#fff; color:#1f2937; }
    .course-title{ font-weight:800; letter-spacing:.2px; }
    .muted{ color:#6b7280; }

    .nav-tabs-modern{ display:flex; gap:8px; border-bottom:0; margin:16px 0; flex-wrap:wrap; }
    .nav-tabs-modern .nav-link{ border:0; background:#fff; color:#1f2937; box-shadow:var(--shadow); border-radius:999px; padding:.5rem .9rem; }
    .nav-tabs-modern .nav-link.active{ background:#0d6efd; color:#fff; }

    .cardx{ background:#fff; border:0; border-radius:var(--r); box-shadow:var(--shadow); padding:16px; }
    .chip{ display:inline-flex; align-items:center; gap:6px; background:#ffffff1a; border:0; color:#fff; padding:6px 10px; border-radius:999px; }
    .chip.dark{ background:#0f172a; color:#fff; }
    .badge-soft{ background:#eef2ff; color:#334155; }

    .thread-content, .reply-content{ background:#f8fafc; border-radius:12px; padding:1rem; }
    .user-avatar{ width:42px; height:42px; border-radius:50%; background:#2A4B7C; color:#fff; display:flex; align-items:center; justify-content:center; font-weight:700; }
    .reply-card{ border-left:4px solid rgba(42,75,124,.12); }

    /* Prose (Markdown) */
    .prose h1,.prose h2,.prose h3{ margin-top:.5rem; }
    .prose p{ margin:.4rem 0; }
    pre code{ display:block; }

    .toast-container{ position:fixed; top:16px; right:16px; z-index:1080; }
  </style>
</head>
<body>
<?php
  if ($userRole === 'Administrator') {
    include $ROOT . '/navbar_logged_administrator.php';
  } elseif ($userRole === 'Instruktor') {
    $p1 = $ROOT . '/navbar_logged_instructor.php';
    $p2 = $ROOT . '/navbar_logged_instruktor.php';
    include is_file($p1) ? $p1 : $p2;
  } else {
    include $ROOT . '/navbar_logged_student.php';
  }
?>

<!-- HERO -->
<section class="hero">
  <div class="container">
    <div class="d-flex align-items-center justify-content-between">
      <div class="header-actions d-flex gap-2">
        <a href="<?= h($BASE_URL) ?>/course_details.php?course_id=<?= (int)$course_id ?>&tab=forum" class="btn btn-sm"><i class="bi bi-arrow-left me-1"></i> Forumi i kursit</a>
        <a href="<?= h($BASE_URL) ?>/courses_student.php" class="btn btn-sm d-none d-md-inline-flex"><i class="bi bi-journal-text me-1"></i> Kurset e mia</a>
      </div>
      <div class="small opacity-75">
        Autor: <strong><?= h($thread['author_name']) ?></strong> • <?= date('d.m.Y H:i', strtotime((string)$thread['created_at'])) ?>
      </div>
    </div>
    <h1 class="course-title mt-2"><?= h($thread['title']) ?></h1>
    <div class="opacity-75"><i class="bi bi-book me-1"></i>Kursi: <strong><?= h($course_title) ?></strong></div>

    <!-- Shirit veprimesh -->
    <div class="mt-3 d-flex flex-wrap gap-2">
      <button class="chip" data-bs-toggle="modal" data-bs-target="#addThreadModal"><i class="bi bi-plus-lg"></i> Temë e re</button>
      <?php if ($canManageThread): ?>
        <button class="chip" data-bs-toggle="modal" data-bs-target="#editThreadModal"><i class="bi bi-pencil-square"></i> Ndrysho</button>
        <form method="post" class="d-inline" onsubmit="return confirm('Të fshihet kjo temë? Veprim i pakthyeshëm.');">
          <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
          <input type="hidden" name="action" value="delete_thread">
          <button type="submit" class="chip dark"><i class="bi bi-trash"></i> Fshi</button>
        </form>
      <?php endif; ?>
    </div>
  </div>
</section>

<main class="py-3">
  <div class="container">
    <div class="row g-3">
      <!-- MAIN -->
      <div class="col-lg-8">
        <!-- Alerts -->
        <?php if ($m = flash_get('success')): ?>
          <div class="alert alert-success cardx py-2 mb-2"><i class="bi bi-check-circle me-2"></i><?= h($m) ?></div>
        <?php endif; ?>
        <?php if ($m = flash_get('error')): ?>
          <div class="alert alert-danger cardx py-2 mb-2"><i class="bi bi-exclamation-triangle me-2"></i><?= h($m) ?></div>
        <?php endif; ?>

        <!-- Thread body -->
        <div class="cardx">
          <div class="d-flex gap-3">
            <div class="user-avatar"><?= h(initial_letter($thread['author_name'])) ?></div>
            <div class="flex-grow-1">
              <div class="d-flex justify-content-between align-items-center mb-1">
                <h5 class="mb-0"><?= h($thread['author_name']) ?></h5>
                <span class="text-muted small"><i class="bi bi-clock me-1"></i><?= date('d M Y, H:i', strtotime((string)$thread['created_at'])) ?></span>
              </div>
              <div class="thread-content prose mt-2">
                <?= $Parsedown->text((string)$thread['content']) ?>
              </div>
            </div>
          </div>
        </div>

        <!-- Stats strip -->
        <div class="d-flex align-items-center gap-2 mt-2">
          <span class="badge badge-soft"><i class="bi bi-chat-dots me-1"></i><?= count($replies) ?> përgjigje</span>
          <span class="badge badge-soft"><i class="bi bi-people me-1"></i><?= $participants_count ?> pjesëmarrës</span>
        </div>

        <!-- Replies header -->
        <h5 id="replies" class="mt-3 mb-2"><i class="bi bi-reply me-2"></i>Përgjigjet</h5>

        <!-- Replies list -->
        <?php if (!$replies): ?>
          <div class="cardx text-center text-muted py-4">
            <i class="bi bi-chat-left-text display-6 d-block mb-2"></i>
            Nuk ka përgjigje ende. Bëhu i pari që i përgjigjet!
          </div>
        <?php else: ?>
          <?php foreach ($replies as $rep): ?>
            <div class="cardx reply-card">
              <div class="d-flex gap-3">
                <div class="user-avatar"><?= h(initial_letter($rep['full_name'])) ?></div>
                <div class="flex-grow-1">
                  <div class="d-flex justify-content-between align-items-center mb-1">
                    <h6 class="mb-0"><?= h($rep['full_name']) ?></h6>
                    <span class="text-muted small"><?= date('d M Y, H:i', strtotime((string)$rep['created_at'])) ?></span>
                  </div>
                  <div class="reply-content prose">
                    <?= $Parsedown->text((string)$rep['content']) ?>
                  </div>
                </div>
              </div>
            </div>
          <?php endforeach; ?>
        <?php endif; ?>

        <!-- Reply form -->
        <div id="reply-form" class="cardx mt-2">
          <h6 class="mb-3"><i class="bi bi-chat-right-quote me-2"></i>Shkruaj përgjigjen tënde</h6>
          <form method="POST" class="mt-2">
            <input type="hidden" name="action" value="add_reply">
            <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
            <div class="mb-3">
              <textarea name="reply_content" class="form-control" rows="4" required maxlength="8000"
                        placeholder="Përdor Markdown për kod, lista, etj."></textarea>
            </div>
            <div class="d-flex justify-content-end">
              <button type="submit" class="btn btn-primary px-4">
                <i class="bi bi-send me-1"></i> Posto
              </button>
            </div>
          </form>
        </div>
      </div>

      <!-- SIDEBAR -->
      <div class="col-lg-4">
        <div class="cardx">
          <h6 class="mb-3"><i class="bi bi-info-circle me-2"></i>Informacione</h6>
          <div class="d-flex align-items-center mb-2">
            <i class="bi bi-book me-2 text-primary"></i>
            <div>
              <div class="small text-muted">Kursi</div>
              <div class="fw-semibold"><?= h($course_title) ?></div>
            </div>
          </div>
          <div class="d-flex align-items-center mb-2">
            <i class="bi bi-calendar-event me-2 text-primary"></i>
            <div>
              <div class="small text-muted">Postuar</div>
              <div class="fw-semibold"><?= date('d M Y', strtotime((string)$thread['created_at'])) ?></div>
            </div>
          </div>
          <div class="d-flex align-items-center">
            <i class="bi bi-chat-dots me-2 text-primary"></i>
            <div>
              <div class="small text-muted">Përgjigje</div>
              <div class="fw-semibold"><?= count($replies) ?></div>
            </div>
          </div>
        </div>

        <div class="cardx mt-3">
          <h6 class="mb-3"><i class="bi bi-people me-2"></i>Pjesëmarrësit</h6>
          <div class="d-flex align-items-center mb-3">
            <div class="user-avatar me-2"><?= h(initial_letter($thread['author_name'])) ?></div>
            <div>
              <div class="fw-semibold"><?= h($thread['author_name']) ?></div>
              <div class="small text-muted">Autor</div>
            </div>
          </div>
          <?php
            $shown = 0;
            foreach (array_keys($participants) as $pname):
              if ($pname === $thread['author_name']) continue;
              if ($shown >= 4) break;
              $shown++;
          ?>
            <div class="d-flex align-items-center mb-2">
              <div class="user-avatar me-2"><?= h(initial_letter($pname)) ?></div>
              <div>
                <div class="fw-semibold"><?= h($pname) ?></div>
                <div class="small text-muted">Pjesëmarrës</div>
              </div>
            </div>
          <?php endforeach; ?>
          <?php if ($participants_count > 5): ?>
            <div class="text-center"><span class="badge text-bg-light text-muted">+<?= $participants_count - 5 ?> të tjerë</span></div>
          <?php endif; ?>
        </div>

        <div class="cardx mt-3">
          <h6 class="mb-3"><i class="bi bi-link-45deg me-2"></i>Diskutime të ngjashme</h6>
          <?php if ($similar_threads): ?>
            <div class="list-group list-group-flush">
              <?php foreach ($similar_threads as $st): ?>
                <a href="<?= h($BASE_URL) ?>/threads/thread_view.php?thread_id=<?= (int)$st['id'] ?>" class="list-group-item list-group-item-action d-flex justify-content-between align-items-center">
                  <span><?= h($st['title']) ?></span><small class="text-muted"><?= date('d M Y', strtotime((string)$st['created_at'])) ?></small>
                </a>
              <?php endforeach; ?>
            </div>
          <?php else: ?>
            <div class="text-muted">—</div>
          <?php endif; ?>
        </div>
      </div>
    </div>
  </div>
</main>

<!-- MODALS -->
<!-- Add Thread Modal -->
<div class="modal fade" id="addThreadModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog">
    <form method="post" class="modal-content">
      <input type="hidden" name="action" value="add_thread">
      <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
      <div class="modal-header">
        <h5 class="modal-title"><i class="bi bi-plus-circle me-2"></i> Krijo temë të re</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body">
        <div class="mb-3">
          <label class="form-label">Titulli</label>
          <input type="text" name="thread_title" class="form-control" maxlength="255" required>
        </div>
        <div class="mb-3">
          <label class="form-label">Përmbajtja</label>
          <textarea name="thread_content" class="form-control" rows="5" required placeholder="Përdor Markdown."></textarea>
        </div>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Anulo</button>
        <button type="submit" class="btn btn-primary"><i class="bi bi-send me-1"></i>Krijo</button>
      </div>
    </form>
  </div>
</div>

<!-- Edit Thread Modal -->
<div class="modal fade" id="editThreadModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog">
    <form method="post" class="modal-content">
      <input type="hidden" name="action" value="edit_thread">
      <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
      <div class="modal-header">
        <h5 class="modal-title"><i class="bi bi-pencil-square me-2"></i> Ndrysho temën</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body">
        <div class="mb-3">
          <label class="form-label">Titulli</label>
          <input type="text" name="thread_title" class="form-control" maxlength="255" required value="<?= h($thread['title']) ?>">
        </div>
        <div class="mb-3">
          <label class="form-label">Përmbajtja</label>
          <textarea name="thread_content" class="form-control" rows="6" required><?= h($thread['content']) ?></textarea>
        </div>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Mbyll</button>
        <button type="submit" class="btn btn-primary"><i class="bi bi-save me-1"></i>Ruaj</button>
      </div>
    </form>
  </div>
</div>

<!-- Optional toasts (nëse vijnë flashes nga rishfaqe) -->
<div class="toast-container">
  <?php if ($m = flash_get('success')): ?>
    <div class="toast align-items-center text-bg-success border-0 show" role="alert">
      <div class="d-flex">
        <div class="toast-body"><i class="bi bi-check-circle me-2"></i><?= h($m) ?></div>
        <button type="button" class="btn-close btn-close-white me-2 m-auto" data-bs-dismiss="toast"></button>
      </div>
    </div>
  <?php endif; ?>
  <?php if ($m = flash_get('error')): ?>
    <div class="toast align-items-center text-bg-danger border-0 show" role="alert">
      <div class="d-flex">
        <div class="toast-body"><i class="bi bi-exclamation-triangle me-2"></i><?= h($m) ?></div>
        <button type="button" class="btn-close btn-close-white me-2 m-auto" data-bs-dismiss="toast"></button>
      </div>
    </div>
  <?php endif; ?>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/highlight.js/11.7.0/highlight.min.js"></script>
<script>
document.addEventListener('DOMContentLoaded', () => {
  document.querySelectorAll('pre code').forEach(el => { try{ hljs.highlightElement(el); }catch(e){} });
});
</script>
<?php require_once $ROOT . '/footer.php'; ?>
</body>
</html>
