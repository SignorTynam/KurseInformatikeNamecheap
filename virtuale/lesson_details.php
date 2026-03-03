<?php
declare(strict_types=1);
session_start();
require_once __DIR__ . '/lib/database.php';
require_once __DIR__ . '/lib/Parsedown.php';
require_once __DIR__ . '/lib/lesson_videos.php';

/* ------------------------------- Helpers ------------------------------- */
function h(?string $s): string {
  return htmlspecialchars($s ?? '', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function ensureCsrf(): void {
  if (empty($_POST['csrf']) || empty($_SESSION['csrf']) || !hash_equals($_SESSION['csrf'], (string)$_POST['csrf'])) {
    http_response_code(403);
    exit('CSRF verifikimi dështoi.');
  }
}
function csrf_token(): string {
  if (empty($_SESSION['csrf'])) {
    $_SESSION['csrf'] = bin2hex(random_bytes(32));
  }
  return $_SESSION['csrf'];
}
function flash_set(string $type, string $msg): void {
  $_SESSION["flash_$type"] = $msg;
}
function flash_get(string $type): ?string {
  $key = "flash_$type";
  if (!empty($_SESSION[$key])) {
    $m = (string)$_SESSION[$key];
    unset($_SESSION[$key]);
    return $m;
  }
  return null;
}

function flash_get_unified(): ?array {
  if (!empty($_SESSION['flash']) && is_array($_SESSION['flash'])) {
    $f = $_SESSION['flash'];
    unset($_SESSION['flash']);
    $msg = trim((string)($f['msg'] ?? ''));
    $type = (string)($f['type'] ?? 'info');
    if ($msg !== '') {
      return ['msg' => $msg, 'type' => $type];
    }
  }
  return null;
}
function asset_url(string $path): string {
  $path = trim($path);
  if ($path === '') return '';

  // Nëse është URL absolute, mos e prek
  if (preg_match('~^https?://~i', $path)) {
    return $path;
  }

  // Path relativ pa slash në fillim
  $path = ltrim($path, '/');

  // p.sh. "" ose "/kurseinformatika/virtuale"
  $basePath = rtrim(dirname($_SERVER['SCRIPT_NAME']), '/');
  if ($basePath === '/') {
    $basePath = '';
  }

  return $basePath . '/' . $path;
}

/* ----------------------------- Auth & inputs --------------------------- */
if (empty($_SESSION['user'])) {
  header('Location: login.php');
  exit;
}
$user     = $_SESSION['user'];
$user_id  = (int)($user['id'] ?? 0);
$userRole = (string)($user['role'] ?? '');

$lesson_id = (int)($_GET['lesson_id'] ?? 0);
if ($lesson_id <= 0) {
  http_response_code(400);
  exit('Leksioni nuk është specifikuar.');
}

/* --------------------------- Fetch lesson + course --------------------- */
try {
  $stmt = $pdo->prepare("
    SELECT
      l.*,
      c.id          AS course_id,
      c.title       AS course_title,
      c.category    AS course_category,
      c.photo       AS course_photo,
      c.id_creator  AS course_creator_id,
      s.id          AS section_id,
      s.title       AS section_title,
      s.hidden      AS section_hidden
    FROM lessons l
    JOIN courses c ON l.course_id = c.id
    LEFT JOIN sections s ON s.id = l.section_id
    WHERE l.id = ?
    LIMIT 1
  ");
  $stmt->execute([$lesson_id]);
  $lesson = $stmt->fetch(PDO::FETCH_ASSOC);
  if (!$lesson) {
    http_response_code(404);
    exit('Leksioni nuk u gjet.');
  }
} catch (Throwable $e) {
  http_response_code(500);
  exit('Gabim gjatë leximit të leksionit.');
}

$course_id         = (int)$lesson['course_id'];
$lessonSectionId   = (int)($lesson['section_id'] ?? 0);
$sectionHidden     = isset($lesson['section_hidden']) && (int)$lesson['section_hidden'] === 1;
$isOwnerInstructor = ($userRole === 'Instruktor' && (int)$lesson['course_creator_id'] === $user_id);

/* ------------------------------ Permissions ---------------------------- */
/* Dukshmëria e leksionit varet nga seksioni (hidden) */
if ($sectionHidden) {
  if ($userRole !== 'Administrator' && !$isOwnerInstructor) {
    http_response_code(403);
    exit('Leksioni nuk është i disponueshëm.');
  }
}

/* Studentët duhet të jenë të regjistruar në kurs */
if ($userRole === 'Student') {
  $stmtEn = $pdo->prepare("SELECT 1 FROM enroll WHERE course_id = ? AND user_id = ? LIMIT 1");
  $stmtEn->execute([$course_id, $user_id]);
  if (!$stmtEn->fetchColumn()) {
    http_response_code(403);
    exit('Nuk jeni të regjistruar në këtë kurs.');
  }
}

/* ----------------------- Mark as read (POST + redirect) ---------------- */
if ($_SERVER['REQUEST_METHOD'] === 'POST'
    && ($userRole === 'Student')
    && (($_POST['action'] ?? '') === 'mark_read')) {

  ensureCsrf();

  try {
    $stmtR = $pdo->prepare("
      INSERT INTO user_reads (user_id, item_type, item_id, read_at)
      VALUES (?, 'LESSON', ?, NOW())
      ON DUPLICATE KEY UPDATE read_at = VALUES(read_at)
    ");
    $stmtR->execute([$user_id, $lesson_id]);
    flash_set('success', 'Leksioni u shënua si i lexuar.');
  } catch (Throwable $e) {
    flash_set('error', 'Nuk u shënua si i lexuar.');
  }

  header('Location: ' . strtok($_SERVER['REQUEST_URI'], '#'));
  exit;
}

/* ------------------------- Parsedown (safe mode) ----------------------- */
$Parsedown = new Parsedown();
if (method_exists($Parsedown, 'setSafeMode')) {
  $Parsedown->setSafeMode(true);
}
$rawDescription   = (string)($lesson['description'] ?? '');
$hasDescription   = trim($rawDescription) !== '';
$descriptionHtml  = $hasDescription ? $Parsedown->text($rawDescription) : '';

if ($hasDescription && $descriptionHtml !== '') {
    // bazë: p.sh. "" ose "/KurseInformatika/virtuale"
    $basePath = rtrim(dirname($_SERVER['SCRIPT_NAME']), '/');
    if ($basePath === '/') {
        $basePath = '';
    }

    // Rregullo atributet src="/uploads/..." dhe href="/uploads/..."
    $descriptionHtml = preg_replace_callback(
        '~\s(src|href)=("|\')(/?uploads/[^"\']*)\2~i',
        function ($m) use ($basePath) {
            $attr  = $m[1];
            $quote = $m[2];
            $path  = ltrim($m[3], '/'); // heq "/" në fillim, mbetet "uploads/..."
            $url   = $basePath . '/' . $path; // p.sh. "/KurseInformatika/virtuale/uploads/..."

            return ' ' . $attr . '=' . $quote . $url . $quote;
        },
        $descriptionHtml
    );
}

$descriptionPlain = trim(preg_replace('/[#>*_\-\[\]\(\)`]+/u', ' ', $rawDescription));
$wordCount        = $descriptionPlain !== '' ? str_word_count($descriptionPlain) : 0;
$readMinutes      = $wordCount > 0 ? max(1, (int)ceil($wordCount / 180)) : 0;

/* ------------------------------ Media helper --------------------------- */
function getEmbedUrl(string $url): array {
  $u = trim($url);
  if ($u === '') {
    return ['type' => 'none', 'src' => ''];
  }

  // YouTube klasik
  if (strpos($u, 'youtube.com/watch') !== false) {
    $parts = parse_url($u);
    $q     = [];
    if (!empty($parts['query'])) {
      parse_str($parts['query'], $q);
    }
    if (!empty($q['v'])) {
      return [
        'type' => 'iframe',
        'src'  => 'https://www.youtube.com/embed/' . rawurlencode($q['v'])
      ];
    }
  }

  // YouTube short (youtu.be)
  if (strpos($u, 'youtu.be/') !== false) {
    $parts = parse_url($u);
    $vid   = ltrim((string)($parts['path'] ?? ''), '/');
    if ($vid !== '') {
      return [
        'type' => 'iframe',
        'src'  => 'https://www.youtube.com/embed/' . rawurlencode($vid)
      ];
    }
  }

  // Vimeo
  if (preg_match('~vimeo\.com/(?:video/)?(\d+)~i', $u, $m)) {
    return [
      'type' => 'iframe',
      'src'  => 'https://player.vimeo.com/video/' . $m[1]
    ];
  }

  // MP4 direkt
  if (preg_match('~\.mp4($|\?)~i', $u)) {
    return [
      'type' => 'video',
      'src'  => $u
    ];
  }

  // Fallback: link normal
  return ['type' => 'link', 'src' => $u];
}

/* --------------------- Media & URL e leksionit ------------------------- */
$lessonCategory = strtoupper((string)($lesson['category'] ?? 'LEKSION'));
$media          = ['type' => 'none', 'src' => ''];

$lessonVideos = lv_get_lesson_videos($pdo, $lesson_id, true);
$videoCount = count($lessonVideos);
$videoIndex = (int)($_GET['v'] ?? 1);
if ($videoIndex < 1) $videoIndex = 1;
if ($videoCount > 0 && $videoIndex > $videoCount) $videoIndex = $videoCount;

$primaryVideoUrl = $lessonVideos ? (string)($lessonVideos[$videoIndex - 1]['url'] ?? '') : '';

if ($primaryVideoUrl !== '') {
  $media = getEmbedUrl($primaryVideoUrl);
} elseif (!empty($lesson['URL'])) {
  $media = getEmbedUrl((string)$lesson['URL']);
}
$hasMedia = $media['type'] !== 'none';
$hasMultipleVideoLinks = count($lessonVideos) > 1;
$hasVideoPager = $hasMultipleVideoLinks && $videoCount > 1;

$videoPrevUrl = '';
$videoNextUrl = '';
if ($hasVideoPager) {
  $qsPrev = $_GET;
  $qsPrev['v'] = max(1, $videoIndex - 1);
  $videoPrevUrl = 'lesson_details.php?' . http_build_query($qsPrev);

  $qsNext = $_GET;
  $qsNext['v'] = min($videoCount, $videoIndex + 1);
  $videoNextUrl = 'lesson_details.php?' . http_build_query($qsNext);
}

/* --------------------------- Files e leksionit ------------------------- */
$filesByType = [
  'PDF'    => [],
  'SLIDES' => [],
  'DOC'    => [],
  'IMAGE'  => [],
  'VIDEO'  => [],
  'OTHER'  => [],
];

try {
  $stmtF = $pdo->prepare("
    SELECT id, file_path, file_type, uploaded_at
    FROM lesson_files
    WHERE lesson_id = ?
    ORDER BY uploaded_at ASC, id ASC
  ");
  $stmtF->execute([$lesson_id]);
  $files = $stmtF->fetchAll(PDO::FETCH_ASSOC) ?: [];

  foreach ($files as $row) {
    $path = (string)$row['file_path'];
    $type = (string)($row['file_type'] ?? '');
    $type = strtoupper($type);

    // Normalizim: IMG -> IMAGE
    if ($type === 'IMG') {
      $type = 'IMAGE';
    }

    if ($type === '') {
      $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
      if ($ext === 'pdf') {
        $type = 'PDF';
      } elseif (in_array($ext, ['ppt', 'pptx', 'odp'], true)) {
        $type = 'SLIDES';
      } elseif (in_array($ext, ['doc', 'docx', 'odt'], true)) {
        $type = 'DOC';
      } elseif (in_array($ext, ['mp4', 'mov', 'm4v', 'webm'], true)) {
        $type = 'VIDEO';
      } elseif (in_array($ext, ['jpg', 'jpeg', 'png', 'gif', 'webp', 'svg'], true)) {
        $type = 'IMAGE';
      } else {
        $type = 'OTHER';
      }
    }

    if (!isset($filesByType[$type])) {
      $type = 'OTHER';
    }
    $filesByType[$type][] = $row;
  }
} catch (Throwable $e) {
  // nëse dështon, thjesht nuk kemi liste files
}

$hasVideoAttachments = !empty($filesByType['VIDEO']);
$hasImageAttachments = !empty($filesByType['IMAGE']);
$hasVideoLinks       = !empty($lessonVideos);
$hasDownloadableFiles  = !empty($filesByType['PDF'])
                      || !empty($filesByType['SLIDES'])
                      || !empty($filesByType['DOC'])
                      || !empty($filesByType['VIDEO'])
                      || !empty($filesByType['IMAGE'])
                      || !empty($filesByType['OTHER'])
                      || $hasVideoLinks
                      || !empty($lesson['notebook_path']);

/* --------------------------- Leksione të seksionit --------------------- */
$sectionLessons = [];
if ($lessonSectionId > 0) {
  try {
    $stmtSecLessons = $pdo->prepare("
      SELECT id, title, category, uploaded_at
      FROM lessons
      WHERE course_id = ? AND section_id = ?
      ORDER BY id ASC
    ");
    $stmtSecLessons->execute([$course_id, $lessonSectionId]);
    $sectionLessons = $stmtSecLessons->fetchAll(PDO::FETCH_ASSOC) ?: [];
  } catch (Throwable $e) {
    $sectionLessons = [];
  }
}
$hasSectionLessons = count($sectionLessons) > 0;

/* -------------------- Detyrat & kuizet e seksionit --------------------- */
$sectionAssignments = [];
$sectionQuizzes     = [];

if ($lessonSectionId > 0) {
  try {
    $stmtA = $pdo->prepare("
      SELECT id, title, due_date, status, hidden
      FROM assignments
      WHERE course_id = ? AND section_id = ? AND COALESCE(hidden,0) = 0
      ORDER BY (due_date IS NULL), due_date ASC, id ASC
    ");
    $stmtA->execute([$course_id, $lessonSectionId]);
    $sectionAssignments = $stmtA->fetchAll(PDO::FETCH_ASSOC) ?: [];
  } catch (Throwable $e) {
    $sectionAssignments = [];
  }

  try {
    $stmtQ = $pdo->prepare("
      SELECT id, title, open_at, close_at, status, hidden, time_limit_sec, attempts_allowed
      FROM quizzes
      WHERE course_id = ?
        AND section_id = ?
        AND COALESCE(hidden,0) = 0
        AND status = 'PUBLISHED'
      ORDER BY (open_at IS NULL), open_at ASC, id ASC
    ");
    $stmtQ->execute([$course_id, $lessonSectionId]);
    $sectionQuizzes = $stmtQ->fetchAll(PDO::FETCH_ASSOC) ?: [];
  } catch (Throwable $e) {
    $sectionQuizzes = [];
  }
}

/* ----------------- Gjendja e studentit për detyra/kuize ---------------- */
$submittedByAssign = [];
$quizAttemptsMeta  = [];
$quizLastResult    = [];

if ($userRole === 'Student') {
  // detyrat e këtij seksioni
  $assignIds = array_map('intval', array_column($sectionAssignments, 'id'));
  $assignIds = array_values(array_unique(array_filter($assignIds)));
  if ($assignIds) {
    try {
      $ph = implode(',', array_fill(0, count($assignIds), '?'));
      $stmt = $pdo->prepare("
        SELECT assignment_id, MAX(id) AS submission_id
        FROM assignments_submitted
        WHERE user_id = ? AND assignment_id IN ($ph)
        GROUP BY assignment_id
      ");
      $stmt->execute(array_merge([$user_id], $assignIds));
      foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $submittedByAssign[(int)$r['assignment_id']] = (int)$r['submission_id'];
      }
    } catch (Throwable $e) {
      // ignore
    }
  }

  // kuizet e këtij seksioni
  $quizIds = array_map('intval', array_column($sectionQuizzes, 'id'));
  $quizIds = array_values(array_unique(array_filter($quizIds)));
  if ($quizIds) {
    try {
      $ph = implode(',', array_fill(0, count($quizIds), '?'));
      $stmt = $pdo->prepare("
        SELECT quiz_id,
               SUM(submitted_at IS NULL)     AS in_progress,
               SUM(submitted_at IS NOT NULL) AS submitted,
               COUNT(*)                      AS total
        FROM quiz_attempts
        WHERE user_id = ? AND quiz_id IN ($ph)
        GROUP BY quiz_id
      ");
      $stmt->execute(array_merge([$user_id], $quizIds));
      foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $quizAttemptsMeta[(int)$r['quiz_id']] = [
          'in_progress' => (int)$r['in_progress'],
          'submitted'   => (int)$r['submitted'],
          'total'       => (int)$r['total'],
        ];
      }
    } catch (Throwable $e) {
      // ignore
    }

    try {
      $ph = implode(',', array_fill(0, count($quizIds), '?'));
      $stmt = $pdo->prepare("
        SELECT qa.*
        FROM quiz_attempts qa
        JOIN (
          SELECT quiz_id, MAX(submitted_at) AS last_sub
          FROM quiz_attempts
          WHERE user_id = ? AND submitted_at IS NOT NULL AND quiz_id IN ($ph)
          GROUP BY quiz_id
        ) t
          ON t.quiz_id = qa.quiz_id AND t.last_sub = qa.submitted_at
      ");
      $stmt->execute(array_merge([$user_id], $quizIds));
      foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $quizLastResult[(int)$r['quiz_id']] = $r;
      }
    } catch (Throwable $e) {
      // ignore
    }
  }
}

/* ------------------------- Prev / Next leksion ------------------------- */
$prevLesson = null;
$nextLesson = null;
try {
  $stmtPrev = $pdo->prepare("
    SELECT id, title
    FROM lessons
    WHERE course_id = ? AND id < ?
    ORDER BY id DESC
    LIMIT 1
  ");
  $stmtPrev->execute([$course_id, $lesson_id]);
  $prevLesson = $stmtPrev->fetch(PDO::FETCH_ASSOC) ?: null;

  $stmtNext = $pdo->prepare("
    SELECT id, title
    FROM lessons
    WHERE course_id = ? AND id > ?
    ORDER BY id ASC
    LIMIT 1
  ");
  $stmtNext->execute([$course_id, $lesson_id]);
  $nextLesson = $stmtNext->fetch(PDO::FETCH_ASSOC) ?: null;
} catch (Throwable $e) {
  // ignore
}

/* --------------------- A e ka lexuar leksionin studenti? -------------- */
$isReadLesson = false;
if ($userRole === 'Student') {
  try {
    $stmt = $pdo->prepare("
      SELECT 1
      FROM user_reads
      WHERE user_id = ? AND item_type = 'LESSON' AND item_id = ?
      LIMIT 1
    ");
    $stmt->execute([$user_id, $lesson_id]);
    $isReadLesson = (bool)$stmt->fetchColumn();
  } catch (Throwable $e) {
    $isReadLesson = false;
  }
}

/* ----------------------- Flamurë për layout ---------------------------- */
$courseCat   = (string)($lesson['course_category'] ?? '');
$coursePhoto = (string)($lesson['course_photo'] ?? '');
$hasAssignments = !empty($sectionAssignments);
$hasQuizzes     = !empty($sectionQuizzes);
$hasNotebook    = !empty($lesson['notebook_path']);

?>
<!DOCTYPE html>
<html lang="sq" data-bs-theme="light">
<head>
  <meta charset="UTF-8">
  <title><?= h($lesson['title']) ?> - Leksion</title>
  <meta name="viewport" content="width=device-width, initial-scale=1">

  <link rel="icon" href="image/favicon.ico" type="image/x-icon">

  <!-- Fonts -->
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&family=JetBrains+Mono:wght@400;500;600&family=Source+Serif+4:ital,wght@0,400;0,600;1,400;1,600&display=swap" rel="stylesheet">

  <!-- Bootstrap & Icons & highlight.js theme -->
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css">
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css">
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/highlight.js/11.7.0/styles/github.min.css">

  <!-- CSS i personalizuar për viewer-in e leksioneve -->
  <link rel="stylesheet" href="css/km-lesson.css?ver=3">

  <!-- MathJax -->
  <script>
    window.MathJax = {
      tex: {
        inlineMath: [['$', '$'], ['\\(', '\\)']],
        displayMath: [['$$','$$'], ['\\[','\\]']],
        processEscapes: true
      },
      options: {
        skipHtmlTags: ['script','noscript','style','textarea','pre','code']
      }
    };
  </script>
  <script defer src="https://cdn.jsdelivr.net/npm/mathjax@3/es5/tex-chtml.js"></script>
</head>
<body>
<div id="readProgress" class="km-lesson-read-progress"></div>

<?php
  if ($userRole === 'Administrator') {
    include __DIR__ . '/navbar_logged_administrator.php';
  } elseif ($userRole === 'Instruktor') {
    include __DIR__ . '/navbar_logged_instructor.php';
  } else {
    include __DIR__ . '/navbar_logged_student.php';
  }
?>

<div class="km-lesson-root">
  <!-- TOP APPBAR: breadcrumb + prev/next (stil i thjeshtë, pa hero të madh) -->
  <header class="km-lesson-appbar">
    <div class="container-fluid py-2 py-md-3">
      <div class="d-flex flex-wrap justify-content-between align-items-center gap-2">
        <nav aria-label="breadcrumb" class="km-lesson-breadcrumbs flex-grow-1">
          <ol class="breadcrumb mb-0">
            <li class="breadcrumb-item">
              <a class="text-decoration-none"
                 href="<?=
                   $userRole === 'Administrator' ? 'dashboard_admin.php'
                   : ($userRole === 'Instruktor' ? 'dashboard_instruktor.php' : 'dashboard_student.php')
                 ?>">
                <i class="fa fa-home me-1"></i> Kryefaqja
              </a>
            </li>
            <li class="breadcrumb-item">
              <a class="text-decoration-none"
                 href="<?= ($userRole === 'Administrator' || $userRole === 'Instruktor') ? 'course.php' : 'courses_student.php' ?>">
                Kurset
              </a>
            </li>
            <li class="breadcrumb-item">
              <a class="text-decoration-none"
                 href="<?=
                   ($userRole === 'Administrator' || $userRole === 'Instruktor')
                     ? 'course_details.php?course_id=' . urlencode((string)$course_id)
                     : 'course_details_student.php?course_id=' . urlencode((string)$course_id)
                 ?>">
                <?= h($lesson['course_title']) ?>
              </a>
            </li>
            <?php if (!empty($lesson['section_title'])): ?>
              <li class="breadcrumb-item">
                <span class="text-muted"><?= h($lesson['section_title']) ?></span>
              </li>
            <?php endif; ?>
            <li class="breadcrumb-item active" aria-current="page">
              <?= h($lesson['title']) ?>
            </li>
          </ol>
        </nav>

        <div class="d-flex gap-2 flex-wrap">
          <?php if ($prevLesson): ?>
            <a href="lesson_details.php?lesson_id=<?= (int)$prevLesson['id'] ?>"
               class="btn btn-outline-secondary btn-sm rounded-pill">
              <i class="fa fa-arrow-left me-1"></i>
              <span class="d-none d-sm-inline"><?= h($prevLesson['title']) ?></span>
              <span class="d-inline d-sm-none">Mëparshmi</span>
            </a>
          <?php endif; ?>

          <?php if ($nextLesson): ?>
            <a href="lesson_details.php?lesson_id=<?= (int)$nextLesson['id'] ?>"
               class="btn btn-outline-secondary btn-sm rounded-pill">
              <span class="d-none d-sm-inline"><?= h($nextLesson['title']) ?></span>
              <span class="d-inline d-sm-none">Tjetri</span>
              <i class="fa fa-arrow-right ms-1"></i>
            </a>
          <?php endif; ?>
        </div>
      </div>
    </div>
  </header>

  <!-- LAYOUT KRYESOR: si Udemy/Moodle -->
  <main class="km-lesson-main">
    <div class="container-fluid py-3 py-md-4">
      <div class="row g-4 km-lesson-layout">
        <!-- Sidebar majtas: Përmbajtja e kursit (seksioni aktual + aktivitete) -->
        <aside class="col-12 col-lg-3 order-2 order-lg-1">
          <div class="km-lesson-sidebar-sticky">
            <!-- Karta: Përmbajtja e kursit -->
            <div class="km-lesson-sidebar-outline card shadow-sm">
              <div class="card-header d-flex align-items-center justify-content-between">
                <div class="d-flex align-items-center gap-2">
                  <span class="badge rounded-pill bg-primary-subtle text-primary-emphasis">
                    <i class="fa fa-list-ul me-1"></i> Përmbajtja e kursit
                  </span>
                </div>
              </div>
              <div class="card-body p-0">
                <div class="km-lesson-outline-section-title px-3 pt-3 pb-2">
                  <div class="small text-uppercase text-muted mb-1">Seksioni</div>
                  <div class="fw-semibold">
                    <?= !empty($lesson['section_title']) ? h($lesson['section_title']) : 'Pa seksion' ?>
                  </div>
                </div>

                <div class="km-lesson-outline-list">
                  <?php if ($hasSectionLessons): ?>
                    <?php foreach ($sectionLessons as $ls): ?>
                      <?php
                        $isCurrent = ((int)$ls['id'] === $lesson_id);
                        $icon = 'fa-file-lines';
                        $cat  = strtoupper((string)$ls['category']);
                        if ($cat === 'VIDEO') $icon = 'fa-circle-play';
                      ?>
                      <a href="lesson_details.php?lesson_id=<?= (int)$ls['id'] ?>"
                        class="km-lesson-outline-item <?= $isCurrent ? 'is-current' : '' ?>">
                        <div class="km-lesson-outline-icon">
                          <i class="fa <?= $icon ?>"></i>
                        </div>
                        <div class="km-lesson-outline-text">
                          <div class="km-lesson-outline-title text-truncate">
                            <?= h($ls['title']) ?>
                          </div>
                          <div class="km-lesson-outline-meta small text-muted">
                            <?= h($cat) ?> • <?= date('d M Y', strtotime((string)($ls['uploaded_at'] ?? date('Y-m-d')))) ?>
                          </div>
                        </div>
                        <?php if ($isCurrent): ?>
                          <span class="badge bg-primary-subtle text-primary-emphasis ms-2">Leksioni aktual</span>
                        <?php endif; ?>
                      </a>
                    <?php endforeach; ?>
                  <?php else: ?>
                    <div class="px-3 pb-3 small text-muted">
                      Nuk ka leksione të tjera në këtë seksion.
                    </div>
                  <?php endif; ?>
                </div>

                <?php if ($hasAssignments || $hasQuizzes): ?>
                  <div class="km-lesson-outline-section-title px-3 pt-3 pb-1 border-top small text-uppercase text-muted">
                    Detyra & kuize të seksionit
                  </div>
                  <div class="km-lesson-outline-list mb-2">
                    <?php foreach ($sectionAssignments as $a): ?>
                      <?php
                        $aid       = (int)$a['id'];
                        $hasSubmit = isset($submittedByAssign[$aid]);
                      ?>
                      <a href="assignment_details.php?assignment_id=<?= $aid ?>"
                        class="km-lesson-outline-item km-lesson-outline-activity">
                        <div class="km-lesson-outline-icon">
                          <i class="fa fa-clipboard-list"></i>
                        </div>
                        <div class="km-lesson-outline-text">
                          <div class="km-lesson-outline-title text-truncate">
                            <?= h($a['title']) ?>
                          </div>
                          <div class="km-lesson-outline-meta small text-muted">
                            Detyrë •
                            <?= !empty($a['due_date'])
                                ? 'Afati: ' . date('d M', strtotime((string)$a['due_date']))
                                : 'Pa afat' ?>
                          </div>
                        </div>
                        <?php if ($userRole === 'Student'): ?>
                          <?php if ($hasSubmit): ?>
                            <span class="badge bg-success-subtle text-success-emphasis ms-2">Dërguar</span>
                          <?php else: ?>
                            <span class="badge bg-warning-subtle text-warning-emphasis ms-2">Në pritje</span>
                          <?php endif; ?>
                        <?php endif; ?>
                      </a>
                    <?php endforeach; ?>

                    <?php foreach ($sectionQuizzes as $q): ?>
                      <?php
                        $qid   = (int)$q['id'];
                        $open  = !empty($q['open_at'])  ? strtotime((string)$q['open_at'])  : null;
                        $close = !empty($q['close_at']) ? strtotime((string)$q['close_at']) : null;
                        $nowTs = time();
                        $isOpen     = (!$open || $nowTs >= $open) && (!$close || $nowTs <= $close);
                        $isUpcoming = ($open && $nowTs < $open);
                        $isClosed   = ($close && $nowTs > $close);
                        $meta       = $quizAttemptsMeta[$qid] ?? ['in_progress' => 0, 'submitted' => 0, 'total' => 0];
                        $used       = (int)($meta['submitted'] ?? 0);

                        $statusText =
                          $isUpcoming ? 'Hapet: ' . date('d M', $open) :
                          ($isClosed ? 'Mbyllur' : 'Hapur');
                      ?>
                      <a href="quizzes/take_quiz.php?quiz_id=<?= $qid ?>"
                        class="km-lesson-outline-item km-lesson-outline-activity">
                        <div class="km-lesson-outline-icon">
                          <i class="fa fa-question-circle"></i>
                        </div>
                        <div class="km-lesson-outline-text">
                          <div class="km-lesson-outline-title text-truncate">
                            <?= h($q['title']) ?>
                          </div>
                          <div class="km-lesson-outline-meta small text-muted">
                            Kuiz • <?= h($statusText) ?>
                            <?php if ($userRole === 'Student'): ?>
                              • Tentativa: <?= $used ?>
                            <?php endif; ?>
                          </div>
                        </div>
                      </a>
                    <?php endforeach; ?>
                  </div>
                <?php endif; ?>
              </div>
            </div>

            <!-- Karta e re: Përmbajtja e leksionit (outline nga heading-et) -->
            <div class="km-lesson-sidebar-outline card shadow-sm mt-3">
              <div class="card-header d-flex align-items-center">
                <i class="fa fa-diagram-project me-2"></i>
                <span>Përmbajtja e leksionit</span>
              </div>
              <div class="card-body p-0">
                <nav id="lesson-outline" class="km-lesson-lesson-outline" aria-label="Përmbajtja e leksionit">
                  <div class="px-3 py-2 small text-muted">
                    Ky leksion nuk ka ende tituj kryesorë.
                  </div>
                </nav>
              </div>
            </div>
          </div>
        </aside>

        <!-- Kolona qendrore: Video + tabs (përmbajtja / materiale / aktivitete) -->
        <section class="col-12 col-lg-9 order-1 order-lg-2">
          <!-- HERO: video + titull + meta + actions -->
          <div class="card km-lesson-hero shadow-sm mb-3">
            <?php if ($hasMedia): ?>
              <div class="km-lesson-hero-media">
                <?php if ($media['type'] === 'iframe'): ?>
                  <div class="ratio ratio-16x9">
                    <iframe
                      src="<?= h($media['src']) ?>"
                      title="Video e leksionit"
                      allow="accelerometer; autoplay; encrypted-media; gyroscope; picture-in-picture; web-share"
                      allowfullscreen
                    ></iframe>
                  </div>
                <?php elseif ($media['type'] === 'video'): ?>
                  <div class="ratio ratio-16x9">
                    <video controls class="w-100 h-100">
                      <source src="<?= h($media['src']) ?>" type="video/mp4">
                    </video>
                  </div>
                <?php elseif ($media['type'] === 'link'): ?>
                  <div class="km-lesson-hero-link p-3">
                    <div class="d-flex align-items-center gap-2">
                      <div class="km-lesson-hero-link-icon">
                        <i class="fa fa-link"></i>
                      </div>
                      <div>
                        <div class="fw-semibold" style="color: white;">Burim i jashtëm i leksionit</div>
                        <a href="<?= h($media['src']) ?>" target="_blank" rel="noopener">
                          <?= h($media['src']) ?>
                        </a>
                      </div>
                    </div>
                  </div>
                <?php endif; ?>

                <?php if ($hasVideoPager): ?>
                  <div class="km-lesson-video-nav">
                    <a class="btn btn-sm btn-outline-light rounded-pill <?= $videoIndex <= 1 ? 'disabled' : '' ?>"
                       href="<?= h($videoPrevUrl) ?>"
                       aria-label="Video e mëparshme"
                       <?= $videoIndex <= 1 ? 'tabindex="-1" aria-disabled="true"' : '' ?>>
                      <i class="fa fa-chevron-left me-1"></i> Mbrapa
                    </a>
                    <span class="km-lesson-video-nav-meta">Video <?= (int)$videoIndex ?> / <?= (int)$videoCount ?></span>
                    <a class="btn btn-sm btn-outline-light rounded-pill <?= $videoIndex >= $videoCount ? 'disabled' : '' ?>"
                       href="<?= h($videoNextUrl) ?>"
                       aria-label="Videoja e rradhës"
                       <?= $videoIndex >= $videoCount ? 'tabindex="-1" aria-disabled="true"' : '' ?>>
                      Rradhës <i class="fa fa-chevron-right ms-1"></i>
                    </a>
                  </div>
                <?php endif; ?>
              </div>
            <?php else: ?>
              <div class="km-lesson-hero-media km-lesson-hero-media--placeholder">
                <div class="km-lesson-hero-placeholder-inner">
                  <?php if ($coursePhoto): ?>
                    <div class="km-lesson-course-thumb"
                         style="background-image:url('<?= h($coursePhoto) ?>');"></div>
                  <?php else: ?>
                    <div class="km-lesson-course-thumb km-lesson-course-thumb--placeholder">
                      <i class="fa fa-graduation-cap"></i>
                    </div>
                  <?php endif; ?>
                  <div>
                    <div class="small text-uppercase text-muted mb-1">Leksion pa video</div>
                    <div class="fw-semibold">Ky leksion nuk ka video të lidhur.</div>
                    <div class="small text-muted">Fokusoju te materialet e leximit dhe ushtrimet.</div>
                  </div>
                </div>
              </div>
            <?php endif; ?>

            <div class="card-body">
              <div class="d-flex justify-content-between align-items-start flex-wrap gap-2 mb-2">
                <div class="flex-grow-1">
                  <div class="d-flex flex-wrap gap-2 align-items-center mb-2">
                    <span class="km-lesson-badge-cat">
                      <i class="fa fa-tag me-1"></i><?= h($lessonCategory) ?>
                    </span>
                    <?php if ($courseCat): ?>
                      <span class="badge rounded-pill bg-light-subtle text-body-secondary border">
                        <i class="fa fa-layer-group me-1"></i><?= h(ucwords(strtolower($courseCat))) ?>
                      </span>
                    <?php endif; ?>
                    <?php if (!empty($lesson['section_title'])): ?>
                      <span class="badge rounded-pill bg-light-subtle text-body-secondary border">
                        <i class="fa fa-folder-tree me-1"></i><?= h($lesson['section_title']) ?>
                      </span>
                    <?php endif; ?>
                  </div>
                  <h1 class="km-lesson-hero-title h3 mb-1"><?= h($lesson['title']) ?></h1>
                  <div class="km-lesson-hero-meta small text-muted">
                    <span>
                      <i class="fa-regular fa-clock me-1"></i>
                      Ngarkuar: <?= date('d M Y, H:i', strtotime((string)($lesson['uploaded_at'] ?? date('Y-m-d H:i:s')))) ?>
                    </span>
                    <?php if (!empty($lesson['updated_at'])): ?>
                      <span class="ms-3">
                        <i class="fa-regular fa-pen-to-square me-1"></i>
                        Përditësuar: <?= date('d M Y, H:i', strtotime((string)$lesson['updated_at'])) ?>
                      </span>
                    <?php endif; ?>
                    <?php if ($readMinutes > 0): ?>
                      <span class="ms-3">
                        <i class="fa-solid fa-book-open-reader me-1"></i>
                        ~<?= (int)$readMinutes ?> min lexim (<?= (int)$wordCount ?> fjalë)
                      </span>
                    <?php endif; ?>
                  </div>
                </div>

                <div class="km-lesson-hero-actions text-end">
                  <?php if ($userRole === 'Student'): ?>
                    <?php if ($isReadLesson): ?>
                      <div class="badge bg-success-subtle text-success-emphasis px-3 py-2 rounded-pill mb-2">
                        <i class="fa fa-check-circle me-1"></i> Lexuar
                      </div>
                    <?php else: ?>
                      <form method="POST" class="mb-2">
                        <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
                        <input type="hidden" name="action" value="mark_read">
                        <button type="submit" class="btn btn-primary btn-sm rounded-pill km-lesson-mark-btn">
                          <i class="fa fa-check me-1"></i> Shëno si lexuar
                        </button>
                      </form>
                    <?php endif; ?>
                  <?php endif; ?>

                  <?php if ($userRole === 'Administrator' || $userRole === 'Instruktor'): ?>
                    <div class="dropdown">
                      <button class="btn btn-outline-secondary btn-sm rounded-pill" type="button" data-bs-toggle="dropdown">
                        <i class="fa fa-gear me-1"></i> Menaxho
                      </button>
                      <ul class="dropdown-menu dropdown-menu-end">
                        <li>
                          <a class="dropdown-item" href="admin/edit_lesson.php?lesson_id=<?= (int)$lesson_id ?>">
                            <i class="fa fa-edit me-2"></i> Modifiko leksionin
                          </a>
                        </li>
                        <li><hr class="dropdown-divider"></li>
                        <li>
                          <form method="POST"
                                action="delete_lesson.php"
                                onsubmit="return confirm('Fshi përfundimisht këtë leksion?');"
                                class="px-3 py-1">
                            <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
                            <input type="hidden" name="course_id" value="<?= (int)$course_id ?>">
                            <input type="hidden" name="lesson_id" value="<?= (int)$lesson_id ?>">
                            <button class="btn btn-sm btn-danger w-100">
                              <i class="fa fa-trash me-1"></i> Fshi leksionin
                            </button>
                          </form>
                        </li>
                      </ul>
                    </div>
                  <?php endif; ?>
                </div>
              </div>

              <?php if ($hasVideoAttachments): ?>
                <div class="km-lesson-hero-extra small mt-2">
                  <span class="text-muted me-2">
                    <i class="fa fa-film me-1"></i> Video shtesë:
                  </span>
                  <?php
                    $count = count($filesByType['VIDEO']);
                    $shown = 0;
                    foreach ($filesByType['VIDEO'] as $f):
                      $shown++;
                      if ($shown > 3) break;
                  ?>
                  <a href="<?= h(asset_url((string)$f['file_path'])) ?>" target="_blank" class="text-decoration-none me-2">
                    <?= h(basename((string)$f['file_path'])) ?>
                  </a>

                  <?php endforeach; ?>
                  <?php if ($count > 3): ?>
                    <span class="text-muted">+<?= $count - 3 ?> të tjera</span>
                  <?php endif; ?>
                </div>
              <?php endif; ?>

              <?php if ($hasMultipleVideoLinks): ?>
                <div class="km-lesson-hero-extra small mt-2">
                  <span class="text-muted me-2">
                    <i class="fa fa-video me-1"></i> Video links:
                  </span>
                  <?php
                    $shownVid = 0;
                    $cntVid = count($lessonVideos);
                    foreach ($lessonVideos as $v):
                      $shownVid++;
                      if ($shownVid > 3) break;
                      $vUrl = (string)($v['url'] ?? '');
                      if ($vUrl === '') continue;
                  ?>
                    <a href="<?= h($vUrl) ?>" target="_blank" rel="noopener" class="text-decoration-none me-2">Video <?= $shownVid ?></a>
                  <?php endforeach; ?>
                  <?php if ($cntVid > 3): ?>
                    <span class="text-muted">+<?= $cntVid - 3 ?> të tjera</span>
                  <?php endif; ?>
                </div>
              <?php endif; ?>

              <?php if ($hasImageAttachments): ?>
                <div class="km-lesson-hero-extra small mt-2">
                  <span class="text-muted me-2">
                    <i class="fa fa-image me-1"></i> Foto:
                  </span>
                  <?php
                    $countImg = count($filesByType['IMAGE']);
                    $shownImg = 0;
                    foreach ($filesByType['IMAGE'] as $f):
                      $shownImg++;
                      if ($shownImg > 3) break;
                  ?>
<a href="<?= h(asset_url((string)$f['file_path'])) ?>" target="_blank" class="text-decoration-none me-2">
  <?= h(basename((string)$f['file_path'])) ?>
</a>

                  <?php endforeach; ?>
                  <?php if ($countImg > 3): ?>
                    <span class="text-muted">+<?= $countImg - 3 ?> të tjera</span>
                  <?php endif; ?>
                </div>
              <?php endif; ?>
            </div>
          </div>

          <!-- Tabs: Përmbajtja / Materiale -->
          <div class="card km-lesson-tabcard shadow-sm">
            <div class="card-header border-0 pb-0">
              <ul class="nav nav-underline km-lesson-tabs" role="tablist">
                <li class="nav-item" role="presentation">
                  <button class="nav-link active"
                          id="tab-overview-tab"
                          data-bs-toggle="tab"
                          data-bs-target="#tab-overview"
                          type="button"
                          role="tab"
                          aria-controls="tab-overview"
                          aria-selected="true">
                    <i class="fa fa-file-lines me-1"></i> Përmbajtja
                  </button>
                </li>
                <?php if ($hasDownloadableFiles): ?>
                  <li class="nav-item" role="presentation">
                    <button class="nav-link"
                            id="tab-resources-tab"
                            data-bs-toggle="tab"
                            data-bs-target="#tab-resources"
                            type="button"
                            role="tab"
                            aria-controls="tab-resources"
                            aria-selected="false">
                      <i class="fa fa-download me-1"></i> Materiale
                    </button>
                  </li>
                <?php endif; ?>
              </ul>
            </div>

            <div class="card-body pt-3">
              <div class="tab-content">
                <!-- TAB 1: Përmbajtja (Markdown) -->
                <div class="tab-pane fade show active" id="tab-overview" role="tabpanel" aria-labelledby="tab-overview-tab">
                  <?php if ($hasDescription): ?>
                    <article class="lesson-content prose" id="lesson-prose">
                      <?= $descriptionHtml ?>
                    </article>
                  <?php else: ?>
                    <div class="text-muted small">
                      Ky leksion nuk ka përmbajtje tekstuale të përshkruar ende.
                    </div>
                  <?php endif; ?>
                </div>

                <!-- TAB 2: Materiale (attachments, notebook, pdf, slides, doc, other) -->
                <?php if ($hasDownloadableFiles): ?>
                  <div class="tab-pane fade" id="tab-resources" role="tabpanel" aria-labelledby="tab-resources-tab">
                    <?php
                      $orderTypes = [
                        'PDF'    => 'PDF',
                        'SLIDES' => 'Slides',
                        'DOC'    => 'Dokumente',
                        'VIDEO'  => 'Video',
                        'IMAGE'  => 'Foto & imazhe',
                        'OTHER'  => 'Të tjerë',
                      ];
                      $hasAnyGroup = false;
                      foreach ($orderTypes as $tKey => $label) {
                        if (!empty($filesByType[$tKey])) { $hasAnyGroup = true; break; }
                      }
                      if ($hasVideoLinks) $hasAnyGroup = true;
                    ?>

                    <?php if ($hasAnyGroup): ?>
                      <?php foreach ($orderTypes as $tKey => $label): ?>
                        <?php $group = $filesByType[$tKey] ?? []; if (!$group) continue; ?>
                        <div class="km-lesson-resource-group mb-3">
                          <div class="km-lesson-file-group-title small text-uppercase text-muted mb-1">
                            <?= h($label) ?>
                          </div>
                          <ul class="list-group list-group-flush km-lesson-resource-list">
                            <?php foreach ($group as $f): ?>
                              <li class="list-group-item d-flex align-items-center justify-content-between px-0">
                                <div class="d-flex align-items-center">
                                  <div class="km-lesson-file-icon me-2">
                                    <?php if ($tKey === 'PDF'): ?>
                                      <i class="fa fa-file-pdf"></i>
                                    <?php elseif ($tKey === 'SLIDES'): ?>
                                      <i class="fa fa-file-powerpoint"></i>
                                    <?php elseif ($tKey === 'DOC'): ?>
                                      <i class="fa fa-file-word"></i>
                                    <?php elseif ($tKey === 'VIDEO'): ?>
                                      <i class="fa fa-film"></i>
                                    <?php elseif ($tKey === 'IMAGE'): ?>
                                      <i class="fa fa-image"></i>
                                    <?php else: ?>
                                      <i class="fa fa-file-alt"></i>
                                    <?php endif; ?>
                                  </div>
                                  <div>
                                    <div class="fw-semibold">
                                      <?= h(basename((string)$f['file_path'])) ?>
                                    </div>
                                    <div class="small text-muted">
                                      Ngarkuar: <?= date('d M Y', strtotime((string)($f['uploaded_at'] ?? date('Y-m-d')))) ?>
                                    </div>
                                  </div>
                                </div>
                                <a href="<?= h(asset_url((string)$f['file_path'])) ?>"
                                  class="btn btn-sm btn-outline-primary"
                                  target="_blank">
                                  <i class="fa fa-arrow-down me-1"></i> Shkarko
                                </a>
                              </li>
                            <?php endforeach; ?>
                          </ul>
                        </div>
                      <?php endforeach; ?>

                      <?php if ($hasVideoLinks): ?>
                        <div class="km-lesson-resource-group mb-3">
                          <div class="km-lesson-file-group-title small text-uppercase text-muted mb-1">
                            Video links
                          </div>
                          <ul class="list-group list-group-flush km-lesson-resource-list">
                            <?php foreach ($lessonVideos as $idx => $v): ?>
                              <?php $vUrl = trim((string)($v['url'] ?? '')); if ($vUrl === '') continue; ?>
                              <li class="list-group-item d-flex align-items-center justify-content-between px-0">
                                <div class="d-flex align-items-center">
                                  <div class="km-lesson-file-icon me-2"><i class="fa fa-circle-play"></i></div>
                                  <div>
                                    <div class="fw-semibold">Video <?= (int)$idx + 1 ?></div>
                                    <div class="small text-muted text-break"><?= h($vUrl) ?></div>
                                  </div>
                                </div>
                                <a href="<?= h($vUrl) ?>" class="btn btn-sm btn-outline-primary" target="_blank" rel="noopener">
                                  <i class="fa fa-arrow-up-right-from-square me-1"></i> Hap
                                </a>
                              </li>
                            <?php endforeach; ?>
                          </ul>
                        </div>
                      <?php endif; ?>
                    <?php else: ?>
                      <div class="text-muted small">
                        Ky leksion nuk ka materiale të bashkangjitura.
                      </div>
                    <?php endif; ?>
                  </div>
                <?php endif; ?>
              </div>
            </div>
          </div>
        </section>
      </div>
    </div>
  </main>

  <?php include __DIR__ . '/footer2.php'; ?>
</div>

<!-- Toasts (flash mesazhet) -->
<div class="toast-container position-fixed top-0 end-0 p-3" style="z-index: 2000;">
  <?php if ($f = flash_get_unified()): ?>
    <?php
      $t = in_array($f['type'], ['success','danger','warning','info','primary','secondary','dark','light'], true)
        ? $f['type']
        : 'info';
      $icon = $t === 'success' ? 'fa-check-circle'
            : ($t === 'danger' ? 'fa-exclamation-triangle'
            : ($t === 'warning' ? 'fa-triangle-exclamation'
            : 'fa-circle-info'));
    ?>
    <div class="toast align-items-center text-bg-<?= h($t) ?> border-0 show" role="alert">
      <div class="d-flex">
        <div class="toast-body">
          <i class="fa <?= h($icon) ?> me-2"></i><?= h($f['msg']) ?>
        </div>
        <button type="button" class="btn-close btn-close-white me-2 m-auto" data-bs-dismiss="toast"></button>
      </div>
    </div>
  <?php endif; ?>
  <?php if ($m = flash_get('success')): ?>
    <div class="toast align-items-center text-bg-success border-0 show" role="alert">
      <div class="d-flex">
        <div class="toast-body">
          <i class="fa fa-check-circle me-2"></i><?= h($m) ?>
        </div>
        <button type="button" class="btn-close btn-close-white me-2 m-auto" data-bs-dismiss="toast"></button>
      </div>
    </div>
  <?php endif; ?>
  <?php if ($m = flash_get('error')): ?>
    <div class="toast align-items-center text-bg-danger border-0 show" role="alert">
      <div class="d-flex">
        <div class="toast-body">
          <i class="fa fa-exclamation-triangle me-2"></i><?= h($m) ?>
        </div>
        <button type="button" class="btn-close btn-close-white me-2 m-auto" data-bs-dismiss="toast"></button>
      </div>
    </div>
  <?php endif; ?>
</div>

<!-- JS -->
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/highlight.js/11.7.0/highlight.min.js"></script>
<script>
document.addEventListener('DOMContentLoaded', () => {
  const proseRoot = document.getElementById('lesson-prose');

  /* -------------------------- highlight.js --------------------------- */
  document.querySelectorAll('pre code').forEach(el => {
    try { hljs.highlightElement(el); } catch (e) {}
  });

  /* --------- Codeblocks modernë (me gjuhë + traffic lights + Copy) --- */
  if (proseRoot) {
    const langMeta = (raw) => {
      const norm = (raw || '').toLowerCase();
      let key = 'code';
      if (['py','python'].includes(norm)) key = 'python';
      else if (['c'].includes(norm)) key = 'c';
      else if (['cpp','c++'].includes(norm)) key = 'cpp';
      else if (['java'].includes(norm)) key = 'java';
      else if (['php'].includes(norm)) key = 'php';
      else if (['js','javascript'].includes(norm)) key = 'js';
      else if (['ts','typescript'].includes(norm)) key = 'ts';
      else if (['html','xml'].includes(norm)) key = 'html';
      else if (['css'].includes(norm)) key = 'css';
      else if (['sql'].includes(norm)) key = 'sql';
      else if (norm) key = norm;

      const map = {
        python: { label: 'Python',    icon: 'fa-brands fa-python' },
        c:      { label: 'C',         icon: 'fa-solid fa-code' },
        cpp:    { label: 'C++',       icon: 'fa-solid fa-code' },
        java:   { label: 'Java',      icon: 'fa-brands fa-java' },
        php:    { label: 'PHP',       icon: 'fa-brands fa-php' },
        js:     { label: 'JavaScript',icon: 'fa-brands fa-js' },
        ts:     { label: 'TypeScript',icon: 'fa-solid fa-code' },
        html:   { label: 'HTML',      icon: 'fa-brands fa-html5' },
        css:    { label: 'CSS',       icon: 'fa-brands fa-css3-alt' },
        sql:    { label: 'SQL',       icon: 'fa-solid fa-database' },
        code:   { label: 'Code',      icon: 'fa-solid fa-code' },
      };
      return map[key] || { label: raw || 'Code', icon: 'fa-solid fa-code' };
    };

    proseRoot.querySelectorAll('pre').forEach(pre => {
      const code = pre.querySelector('code');
      if (!code) return;

      let classLang = [...code.classList].find(c => c.startsWith('language-')) || '';
      classLang = classLang.replace('language-', '');
      let rawLang = (code.getAttribute('data-lang') || classLang || '').trim();
      const meta = langMeta(rawLang);

      const wrapper = document.createElement('div');
      wrapper.className = 'km-codeblock';

      const header = document.createElement('div');
      header.className = 'km-codeblock-header';

      const metaDiv = document.createElement('div');
      metaDiv.className = 'km-codeblock-meta';

      const dots = document.createElement('div');
      dots.className = 'km-codeblock-dots';
      ['red','amber','green'].forEach(color => {
        const dot = document.createElement('span');
        dot.className = 'km-codeblock-dot km-codeblock-dot-' + color;
        dots.appendChild(dot);
      });

      const langSpan = document.createElement('span');
      langSpan.className = 'km-codeblock-lang';
      langSpan.innerHTML = '<i class="' + meta.icon + ' me-1"></i>' + meta.label;

      metaDiv.appendChild(dots);
      metaDiv.appendChild(langSpan);

      const actions = document.createElement('div');
      actions.className = 'km-codeblock-actions';

      const copyBtn = document.createElement('button');
      copyBtn.type = 'button';
      copyBtn.className = 'btn btn-xs btn-outline-light km-codeblock-copy';
      copyBtn.innerHTML = '<i class="fa-regular fa-copy me-1"></i>Copy';

      copyBtn.addEventListener('click', async () => {
        try {
          await navigator.clipboard.writeText(code.innerText);
          copyBtn.innerHTML = '<i class="fa-solid fa-check me-1"></i>Copied';
          copyBtn.classList.add('copied');
          setTimeout(() => {
            copyBtn.innerHTML = '<i class="fa-regular fa-copy me-1"></i>Copy';
            copyBtn.classList.remove('copied');
          }, 1200);
        } catch {
          copyBtn.innerHTML = '<i class="fa-solid fa-triangle-exclamation me-1"></i>Error';
          setTimeout(() => {
            copyBtn.innerHTML = '<i class="fa-regular fa-copy me-1"></i>Copy';
          }, 1200);
        }
      });

      actions.appendChild(copyBtn);

      header.appendChild(metaDiv);
      header.appendChild(actions);

      pre.parentNode.insertBefore(wrapper, pre);
      wrapper.appendChild(header);
      wrapper.appendChild(pre);
    });

    /* Tables → table-responsive wrapper */
    proseRoot.querySelectorAll('table').forEach(tbl => {
      if (tbl.closest('.table-responsive')) return;
      const wrap = document.createElement('div');
      wrap.className = 'table-responsive';
      tbl.parentNode.insertBefore(wrap, tbl);
      wrap.appendChild(tbl);
    });

    /* External links → hapen në tab të ri */
    /* Images → klik për t'u hapur në një tab të ri (zoom i thjeshtë) */
    proseRoot.querySelectorAll('img').forEach(img => {
      img.addEventListener('click', () => {
        const src = img.getAttribute('src');
        if (src && !src.startsWith('data:')) {
          window.open(src, '_blank', 'noopener');
        }
      });
    });

      /* -------------------- Outline i leksionit (TOC) -------------------- */
    const outlineRoot = document.getElementById('lesson-outline');
    if (outlineRoot && proseRoot) {
      const allHeadings = Array.from(
        proseRoot.querySelectorAll('h1, h2, h3, h4, h5, h6')
      );

      if (!allHeadings.length) {
        outlineRoot.innerHTML = '<div class="px-3 py-2 small text-muted">Ky leksion nuk ka ende tituj kryesorë.</div>';
      } else {
        const levels = allHeadings.map(h => parseInt(h.tagName.substring(1), 10));
        const minLevel = Math.min.apply(null, levels);
        const secondLevel = minLevel + 1;

        const ul = document.createElement('ul');
        ul.className = 'km-lesson-lesson-outline-list list-unstyled mb-0';

        let lastTopLi = null;

        allHeadings.forEach((h, idx) => {
          const level = parseInt(h.tagName.substring(1), 10);
          if (level !== minLevel && level !== secondLevel) return;

          if (!h.id) {
            h.id = 'sec-' + (idx + 1);
          }

          const li = document.createElement('li');
          li.className = 'km-lesson-lesson-outline-item toc-level-' + (level === minLevel ? '1' : '2');

          const a = document.createElement('a');
          a.href = '#' + h.id;
          a.textContent = h.textContent.trim();
          a.className = 'km-lesson-lesson-outline-link';

          a.addEventListener('click', function (ev) {
            ev.preventDefault();
            const target = document.getElementById(h.id);
            if (target) {
              target.scrollIntoView({ behavior: 'smooth', block: 'start' });
            }
          });

          li.appendChild(a);

          if (level === minLevel) {
            ul.appendChild(li);
            lastTopLi = li;
          } else if (level === secondLevel && lastTopLi) {
            let sub = lastTopLi.querySelector('ul');
            if (!sub) {
              sub = document.createElement('ul');
              sub.className = 'km-lesson-lesson-outline-sublist list-unstyled';
              lastTopLi.appendChild(sub);
            }
            sub.appendChild(li);
          }
        });

        outlineRoot.innerHTML = '';
        if (ul.children.length) {
          outlineRoot.appendChild(ul);
        } else {
          outlineRoot.innerHTML = '<div class="px-3 py-2 small text-muted">Ky leksion nuk ka ende tituj kryesorë.</div>';
        }
      }
    }
  }

  /* ----------------------- Reading progress bar ----------------------- */
  const progressBar = document.getElementById('readProgress');
  if (progressBar) {
    const onScroll = () => {
      const doc = document.documentElement;
      const scrollTop = doc.scrollTop || document.body.scrollTop || 0;
      const height    = (doc.scrollHeight - doc.clientHeight) || 1;
      const pct       = Math.max(0, Math.min(100, (scrollTop / height) * 100));
      progressBar.style.width = pct + '%';
    };
    window.addEventListener('scroll', onScroll, { passive: true });
    onScroll();
  }
});
</script>
<script>
  document.addEventListener('DOMContentLoaded', () => {
    document.querySelectorAll('.toast').forEach((el) => {
      try {
        new bootstrap.Toast(el, { autohide: true, delay: 4000 }).show();
      } catch (e) {}
    });
  });
</script>
</body>
</html>
