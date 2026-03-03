<?php
// course_enroll.php — Vetë-regjistrim i studentit me access code 5-shifror
declare(strict_types=1);
session_start();

require_once __DIR__ . '/lib/database.php';
require_once __DIR__ . '/lib/lib_access_code.php';

if (empty($_SESSION['user']) || (string)($_SESSION['user']['role'] ?? '') !== 'Student') {
    header('Location: login.php');
    exit;
}
$ME_ID = (int)($_SESSION['user']['id'] ?? 0);

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$CSRF = (string)$_SESSION['csrf_token'];

function h(?string $s): string { return htmlspecialchars($s ?? '', ENT_QUOTES, 'UTF-8'); }

$errors = [];
$info = '';
$course = null;

$HAS_ACCESS_CODE = ki_table_has_column($pdo, 'courses', 'access_code');

$course_id = 0;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $course_id = (int)($_POST['course_id'] ?? 0);
} else {
    $course_id = (int)($_GET['course_id'] ?? 0);
}

if ($course_id <= 0) {
    header('Location: courses_student.php?tab=available');
    exit;
}

// Already enrolled? Go directly in.
try {
    $chk = $pdo->prepare('SELECT 1 FROM enroll WHERE course_id=? AND user_id=? LIMIT 1');
    $chk->execute([$course_id, $ME_ID]);
    if ($chk->fetchColumn()) {
        header('Location: course_details_student.php?course_id=' . $course_id);
        exit;
    }
} catch (Throwable $e) {
    // ignore
}

// Fetch course
try {
    $sql = '
      SELECT c.id, c.title, c.status, c.category, c.photo, ';
    $sql .= $HAS_ACCESS_CODE ? 'c.access_code' : 'NULL AS access_code';
    $sql .= ', u.full_name AS creator_name
      FROM courses c
      LEFT JOIN users u ON u.id = c.id_creator
      WHERE c.id = ?
      LIMIT 1
    ';
    $st = $pdo->prepare($sql);
    $st->execute([$course_id]);
    $course = $st->fetch(PDO::FETCH_ASSOC);
    if (!$course) {
        header('Location: courses_student.php?tab=available');
        exit;
    }
} catch (Throwable $e) {
    $errors[] = 'Gabim gjatë leximit të kursit.';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $csrf = (string)($_POST['csrf'] ?? '');
    if (!hash_equals($CSRF, $csrf)) {
        $errors[] = 'Seancë e pavlefshme (CSRF). Ringarko faqen.';
    }

    if (!$HAS_ACCESS_CODE) {
        $errors[] = 'Skema e DB nuk është përditësuar (mungon access_code).';
    }

    $code = ki_normalize_access_code((string)($_POST['access_code'] ?? ''));
    if ($code === null) {
        $errors[] = 'Shkruaj një access code të vlefshëm (5 shifra).';
    }

    $status = strtoupper((string)($course['status'] ?? ''));
    if ($status !== 'ACTIVE') {
        $errors[] = 'Ky kurs nuk është aktiv për regjistrim.';
    }

    $stored = trim((string)($course['access_code'] ?? ''));
    if ($stored === '') {
        $errors[] = 'Ky kurs nuk ka access code. Kontakto instruktorin.';
    } elseif ($code !== null && $stored !== '' && $code !== $stored) {
        $errors[] = 'Access code është i pasaktë.';
    }

    if (!$errors) {
        try {
            $ins = $pdo->prepare('INSERT INTO enroll (course_id, user_id, enrolled_at) VALUES (?, ?, NOW())');
            $ins->execute([$course_id, $ME_ID]);
            $_SESSION['flash'] = ['msg' => 'U regjistruat me sukses në kurs!', 'type' => 'success'];
            header('Location: course_details_student.php?course_id=' . $course_id);
            exit;
        } catch (PDOException $e) {
            if ($e->getCode() === '23000') {
                $_SESSION['flash'] = ['msg' => 'Jeni tashmë i regjistruar në këtë kurs.', 'type' => 'info'];
                header('Location: course_details_student.php?course_id=' . $course_id);
                exit;
            }
            $errors[] = 'Gabim gjatë regjistrimit.';
        }
    }
}

$flash = $_SESSION['flash'] ?? null;
unset($_SESSION['flash']);
$flashMsg = is_array($flash) ? (string)($flash['msg'] ?? '') : '';
$flashType = is_array($flash) ? (string)($flash['type'] ?? 'info') : '';
if ($flashType === 'error') $flashType = 'danger';

?>
<!doctype html>
<html lang="sq" data-bs-theme="light">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <title>Regjistrohu në kurs — Virtuale</title>
  <link rel="icon" href="image/favicon.ico" type="image/x-icon" />
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
  <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css" rel="stylesheet">
  <link rel="stylesheet" href="css/navbar.css?v=1">
  <link rel="stylesheet" href="css/km-lms-forms.css?v=1">
</head>
<body class="km-body">

<?php include __DIR__ . '/navbar_logged_student.php'; ?>

<main class="container km-page-shell">
  <div class="mx-auto" style="max-width: 760px;">
    <div class="km-page-header d-flex flex-column gap-1 mb-3">
      <div class="km-breadcrumb small">
        <a href="courses_student.php?tab=available" class="km-breadcrumb-link">
          <i class="fa-solid fa-arrow-left-long me-1"></i>Kthehu te kurset
        </a>
        <span class="mx-1">/</span>
        <span class="km-breadcrumb-current">Regjistrohu</span>
      </div>
      <div class="fw-bold">Regjistrohu në kurs</div>
      <div class="km-help-text">Shkruaj access code 5-shifror që të aktivizohet regjistrimi.</div>
    </div>

    <div class="km-card">
      <div class="km-card-header">
        <div>
          <h1 class="km-card-title"><i class="fa-solid fa-circle-plus"></i> Regjistrim</h1>
          <div class="km-card-subtitle">Plotëso kodin dhe vazhdo.</div>
        </div>
      </div>
      <div class="km-card-body">

      <?php if ($flashMsg !== ''): ?>
        <div class="alert alert-<?= h($flashType ?: 'info') ?>"><?= h($flashMsg) ?></div>
      <?php endif; ?>

      <?php if ($errors): ?>
        <div class="alert alert-danger">
          <div class="fw-semibold mb-1">Nuk u krye regjistrimi:</div>
          <ul class="mb-0">
            <?php foreach ($errors as $e): ?>
              <li><?= h($e) ?></li>
            <?php endforeach; ?>
          </ul>
        </div>
      <?php endif; ?>

      <?php if ($course): ?>
        <div class="border rounded p-3 mb-3" style="background:#fff;">
          <div class="d-flex justify-content-between flex-wrap gap-2">
            <div>
              <div class="fw-semibold"><?= h((string)$course['title']) ?></div>
              <div class="small text-muted">
                Instruktor: <?= h((string)($course['creator_name'] ?? '—')) ?>
                • Status: <?= h((string)($course['status'] ?? '—')) ?>
              </div>
            </div>
            <div class="small text-muted">#<?= (int)$course_id ?></div>
          </div>
        </div>
      <?php endif; ?>

      <form method="post" class="vstack gap-3">
        <input type="hidden" name="course_id" value="<?= (int)$course_id ?>">
        <input type="hidden" name="csrf" value="<?= h($CSRF) ?>">

        <div>
          <label class="form-label fw-semibold">Access code</label>
          <input
            type="text"
            name="access_code"
            inputmode="numeric"
            maxlength="5"
            pattern="\d{5}"
            class="form-control form-control-lg"
            placeholder="p.sh. 12345"
            required
            autocomplete="off"
          >
          <div class="form-text">Kodi është 5 shifra (vetëm numra).</div>
        </div>

        <div class="d-flex gap-2">
          <button type="submit" class="btn btn-success btn-lg">
            <i class="fa-solid fa-circle-plus me-1"></i> Regjistrohu
          </button>
          <a href="courses_student.php?tab=available" class="btn btn-outline-secondary btn-lg">Anulo</a>
        </div>
      </form>
      </div>
    </div>
  </div>
</main>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
