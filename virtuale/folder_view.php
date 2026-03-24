<?php
declare(strict_types=1);

session_start();
require_once __DIR__ . '/lib/database.php';

if (!isset($_SESSION['user'])) {
  header('Location: login.php');
  exit;
}

$ROLE = (string)($_SESSION['user']['role'] ?? '');
$ME_ID = (int)($_SESSION['user']['id'] ?? 0);
$folderId = (int)($_GET['folder_id'] ?? 0);

if ($folderId <= 0) {
  http_response_code(400);
  exit('Folder i pavlefshem.');
}

if (empty($_SESSION['csrf_token'])) {
  $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$CSRF = (string)$_SESSION['csrf_token'];

function h(?string $s): string {
  return htmlspecialchars((string)$s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function pick_nav_for_role(string $role): string {
  $r = strtolower(trim($role));
  if ($r === 'administrator') return __DIR__ . '/navbar_logged_administrator.php';
  if ($r === 'instruktor' || $r === 'instructor') return __DIR__ . '/navbar_logged_instruktor.php';
  return __DIR__ . '/navbar_logged_student.php';
}

function folder_cat_meta(string $cat): array {
  $c = strtoupper(trim($cat));
  if ($c === 'VIDEO') return ['bi-camera-video', '#dc3545'];
  if ($c === 'FILE') return ['bi-file-earmark', '#2A4B7C'];
  return ['bi-collection', '#6c757d'];
}

function can_manage_folder(string $role, int $meId, int $creatorId): bool {
  $r = strtolower(trim($role));
  if ($r === 'administrator') return true;
  if (($r === 'instruktor' || $r === 'instructor') && $meId > 0 && $meId === $creatorId) return true;
  return false;
}

$folder = null;
try {
  $q = $pdo->prepare("\n    SELECT sf.id, sf.course_id, sf.title, sf.description, sf.created_at, sf.updated_at,\n           c.title AS course_title, c.id_creator\n    FROM section_folders sf\n    JOIN courses c ON c.id = sf.course_id\n    WHERE sf.id = ?\n    LIMIT 1\n  ");
  $q->execute([$folderId]);
  $folder = $q->fetch(PDO::FETCH_ASSOC) ?: null;
} catch (Throwable $e) {
  $folder = null;
}

if (!$folder) {
  http_response_code(404);
  exit('Folder-i nuk u gjet.');
}

$courseId = (int)($folder['course_id'] ?? 0);
$creatorId = (int)($folder['id_creator'] ?? 0);
$canManage = can_manage_folder($ROLE, $ME_ID, $creatorId);

$sectionId = 0;
try {
  $qSec = $pdo->prepare("\n    SELECT section_id\n    FROM section_items\n    WHERE course_id=? AND item_type='FOLDER' AND item_ref_id=?\n    ORDER BY id ASC\n    LIMIT 1\n  ");
  $qSec->execute([$courseId, $folderId]);
  $sectionId = (int)$qSec->fetchColumn();
} catch (Throwable $e) {
  $sectionId = 0;
}

$items = [];
try {
  $qi = $pdo->prepare("\n    SELECT sfi.lesson_id, sfi.position, l.title, UPPER(COALESCE(l.category,'')) AS category,\n           COALESCE(l.URL, l.url) AS lesson_url,\n           lf.file_path\n    FROM section_folder_items sfi\n    JOIN lessons l ON l.id = sfi.lesson_id AND l.course_id = ?\n    LEFT JOIN (\n      SELECT x.lesson_id, x.file_path\n      FROM lesson_files x\n      JOIN (\n        SELECT lesson_id, MIN(id) AS min_id\n        FROM lesson_files\n        GROUP BY lesson_id\n      ) z ON z.lesson_id=x.lesson_id AND z.min_id=x.id\n    ) lf ON lf.lesson_id = l.id\n    WHERE sfi.folder_id = ?\n    ORDER BY sfi.position ASC, sfi.id ASC\n  ");
  $qi->execute([$courseId, $folderId]);
  $items = $qi->fetchAll(PDO::FETCH_ASSOC) ?: [];
} catch (Throwable $e) {
  $items = [];
}

$existingLessonIds = array_map(static fn($r) => (int)($r['lesson_id'] ?? 0), $items);
$existingLessonIds = array_values(array_unique($existingLessonIds));

$eligible = [];
if ($canManage) {
  try {
    $whereNotIn = '';
    $params = [$courseId];
    if ($existingLessonIds) {
      $ph = implode(',', array_fill(0, count($existingLessonIds), '?'));
      $whereNotIn = " AND id NOT IN ($ph)";
      $params = array_merge($params, $existingLessonIds);
    }

    $qa = $pdo->prepare("\n      SELECT id, title, UPPER(COALESCE(category,'')) AS category\n      FROM lessons\n      WHERE course_id=?\n        AND UPPER(COALESCE(category,'')) IN ('FILE','VIDEO')\n        $whereNotIn\n      ORDER BY uploaded_at DESC, id DESC\n    ");
    $qa->execute($params);
    $eligible = $qa->fetchAll(PDO::FETCH_ASSOC) ?: [];
  } catch (Throwable $e) {
    $eligible = [];
  }
}

$totalFiles = 0;
$totalVideos = 0;
foreach ($items as $it) {
  $c = strtoupper((string)($it['category'] ?? ''));
  if ($c === 'FILE') $totalFiles++;
  if ($c === 'VIDEO') $totalVideos++;
}

$flash = $_SESSION['flash'] ?? null;
unset($_SESSION['flash']);
$flashMsg = '';
$flashType = 'info';
if (is_array($flash)) {
  $flashMsg = (string)($flash['msg'] ?? '');
  $flashType = (string)($flash['type'] ?? 'info');
}

if (isset($_GET['download']) && $_GET['download'] === 'zip') {
  if (!$canManage) {
    http_response_code(403);
    exit('No access.');
  }

  if (!class_exists('ZipArchive')) {
    http_response_code(500);
    exit('ZipArchive nuk eshte i aktivizuar ne server.');
  }

  $zipPath = sys_get_temp_dir() . '/folder_' . $folderId . '_' . time() . '.zip';
  $zip = new ZipArchive();
  if ($zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
    http_response_code(500);
    exit('Nuk u krijua ZIP.');
  }

  $baseDir = realpath(__DIR__) ?: __DIR__;
  $manifest = [];
  foreach ($items as $it) {
    $lessonId = (int)($it['lesson_id'] ?? 0);
    $title = (string)($it['title'] ?? ('Lesson ' . $lessonId));
    $cat = (string)($it['category'] ?? '');
    $filePath = ltrim((string)($it['file_path'] ?? ''), '/');

    if (strtoupper($cat) === 'FILE' && $filePath !== '') {
      $fullPath = realpath(__DIR__ . '/' . $filePath);
      if ($fullPath === false || strpos($fullPath, $baseDir) !== 0 || !is_file($fullPath) || !is_readable($fullPath)) {
        $manifest[] = sprintf("[MISSING FILE] #%d %s - %s", $lessonId, $title, $filePath);
        continue;
      }

      $ext = pathinfo($fullPath, PATHINFO_EXTENSION);
      $safeTitle = preg_replace('/[^A-Za-z0-9 _.-]/', '_', $title) ?: ('lesson_' . $lessonId);
      $zipName = $safeTitle . ($ext !== '' ? ('.' . $ext) : '');
      $zip->addFile($fullPath, $zipName);
      continue;
    }

    $url = trim((string)($it['lesson_url'] ?? ''));
    if ($url !== '') {
      $manifest[] = sprintf("[VIDEO] #%d %s\nURL: %s", $lessonId, $title, $url);
      continue;
    }

    $manifest[] = sprintf("[SKIPPED] #%d %s - pa file/url", $lessonId, $title);
  }

  if ($manifest) {
    $zip->addFromString('links_manifest.txt', implode("\n\n", $manifest) . "\n");
  }

  $zip->close();
  if (!is_file($zipPath)) {
    http_response_code(500);
    exit('ZIP nuk u krijua si file.');
  }

  $downloadName = preg_replace('/[^A-Za-z0-9_.-]/', '_', (string)($folder['title'] ?? 'folder'));
  if ($downloadName === '') {
    $downloadName = 'folder_' . $folderId;
  }

  header('Content-Type: application/zip');
  header('Content-Disposition: attachment; filename="' . $downloadName . '.zip"');
  header('Content-Length: ' . (string)filesize($zipPath));
  header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

  readfile($zipPath);
  @unlink($zipPath);
  exit;
}
?>

<!doctype html>
<html lang="sq" data-bs-theme="light">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title><?= h((string)$folder['title']) ?> - Folder View</title>
  <link rel="icon" href="image/favicon.ico" type="image/x-icon" />
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css">
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css">
  <link rel="stylesheet" href="css/course_panel.css?v=1">
  <link rel="stylesheet" href="css/folder_view.css?v=1">
</head>
<body class="course-body">

<?php include pick_nav_for_role($ROLE); ?>

    <header class="course-hero fv-hero">
      <div class="container-fluid px-3 px-lg-4">
        <div class="row g-3 align-items-start">
          <div class="col-lg-7">
            <div class="course-breadcrumb">
              <a href="course_details.php?course_id=<?= (int)$courseId ?>&tab=materials">
                <i class="bi bi-arrow-left-short me-1"></i> Materialet
              </a>
              <span class="sep">/</span>
              <span class="current">Folder View</span>
            </div>
            <h1><i class="bi bi-folder2-open me-2"></i><?= h((string)$folder['title']) ?></h1>
            <p>
              Kursi: <strong><?= h((string)$folder['course_title']) ?></strong>
              • Folder ID: #<?= (int)$folderId ?>
              <?php if ($sectionId > 0): ?>
                • Section ID: #<?= (int)$sectionId ?>
              <?php endif; ?>
            </p>
          </div>

          <div class="col-lg-5">
            <div class="course-hero-actions d-flex flex-wrap justify-content-lg-end gap-2 mb-2">
              <a class="btn btn-sm course-action-outline" href="course_details.php?course_id=<?= (int)$courseId ?>&tab=materials">
                <i class="bi bi-arrow-left me-1"></i>Kthehu te materiali
              </a>
              <?php if ($canManage): ?>
                <a class="btn btn-sm course-action-primary" href="folder_view.php?folder_id=<?= (int)$folderId ?>&download=zip">
                  <i class="bi bi-file-earmark-zip me-1"></i>Download ZIP
                </a>
              <?php endif; ?>
            </div>

            <div class="course-hero-stats">
              <div class="course-stat">
                <div class="icon"><i class="bi bi-grid-3x3-gap"></i></div>
                <div>
                  <div class="label">Elemente</div>
                  <div class="value"><?= count($items) ?></div>
                </div>
              </div>
              <div class="course-stat">
                <div class="icon"><i class="bi bi-file-earmark"></i></div>
                <div>
                  <div class="label">FILE</div>
                  <div class="value"><?= $totalFiles ?></div>
                </div>
              </div>
              <div class="course-stat">
                <div class="icon"><i class="bi bi-camera-video"></i></div>
                <div>
                  <div class="label">VIDEO</div>
                  <div class="value"><?= $totalVideos ?></div>
                </div>
              </div>
            </div>
          </div>
        </div>
      </div>
    </header>

    <main class="folder-main">
      <div class="container-fluid px-3 px-lg-4">
        <?php if ($flashMsg !== ''): ?>
          <div class="alert alert-<?= h($flashType) ?> alert-dismissible fade show" role="alert">
            <?= h($flashMsg) ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
          </div>
        <?php endif; ?>

        <?php if (!empty($folder['description'])): ?>
          <div class="fv-note mb-3">
            <?= nl2br(h((string)$folder['description'])) ?>
          </div>
        <?php endif; ?>

        <div class="row g-3">
          <div class="col-12 col-xl-8">
            <section class="fv-card">
              <div class="fv-card-head">
                <h5 class="mb-0">Elementet ne folder</h5>
                <span class="badge text-bg-light"><?= count($items) ?></span>
              </div>

              <div class="fv-card-body">
                <?php if (!$items): ?>
                  <div class="text-muted">Folder-i nuk ka ende elemente.</div>
                <?php else: ?>
                  <div class="fv-list">
                    <?php foreach ($items as $it): ?>
                      <?php
                      $lessonId = (int)($it['lesson_id'] ?? 0);
                      $title = (string)($it['title'] ?? ('Material #' . $lessonId));
                      $cat = (string)($it['category'] ?? '');
                      [$icon, $tone] = folder_cat_meta($cat);

                      $href = 'lesson_details.php?lesson_id=' . $lessonId;
                      if (strtoupper($cat) === 'FILE' && !empty($it['file_path'])) {
                        $href = (string)$it['file_path'];
                      } elseif (!empty($it['lesson_url'])) {
                        $href = (string)$it['lesson_url'];
                      }
                      ?>
                      <div class="fv-item">
                        <div class="fv-item-left">
                          <span class="fv-item-icon" style="--fv-tone: <?= h($tone) ?>">
                            <i class="bi <?= h($icon) ?>"></i>
                          </span>
                          <div>
                            <a href="<?= h($href) ?>" target="_blank" class="fv-item-title">
                              <?= h($title) ?>
                            </a>
                            <div class="small text-muted">Tipi: <?= h((string)$cat) ?></div>
                          </div>
                        </div>

                        <?php if ($canManage): ?>
                          <form method="post" action="admin/edit_folder.php" class="m-0">
                            <input type="hidden" name="csrf" value="<?= h($CSRF) ?>">
                            <input type="hidden" name="course_id" value="<?= (int)$courseId ?>">
                            <input type="hidden" name="folder_id" value="<?= (int)$folderId ?>">
                            <input type="hidden" name="lesson_id" value="<?= (int)$lessonId ?>">
                            <input type="hidden" name="mode" value="remove_single">
                            <input type="hidden" name="back" value="folder_view">
                            <button class="btn btn-sm btn-outline-danger" type="submit" onclick="return confirm('Hiqe kete element nga folder-i?');">
                              <i class="bi bi-x-lg"></i>
                            </button>
                          </form>
                        <?php endif; ?>
                      </div>
                    <?php endforeach; ?>
                  </div>
                <?php endif; ?>
              </div>
            </section>
          </div>

          <?php if ($canManage): ?>
            <div class="col-12 col-xl-4">
              <section class="fv-card mb-3">
                <div class="fv-card-head"><h5 class="mb-0">Modifiko folder</h5></div>
                <div class="fv-card-body">
                  <form method="post" action="admin/edit_folder.php">
                    <input type="hidden" name="csrf" value="<?= h($CSRF) ?>">
                    <input type="hidden" name="course_id" value="<?= (int)$courseId ?>">
                    <input type="hidden" name="folder_id" value="<?= (int)$folderId ?>">
                    <input type="hidden" name="mode" value="meta">
                    <input type="hidden" name="back" value="folder_view">

                    <div class="mb-2">
                      <label class="form-label">Titulli</label>
                      <input class="form-control" name="title" value="<?= h((string)$folder['title']) ?>" maxlength="255" required>
                    </div>
                    <div class="mb-3">
                      <label class="form-label">Pershkrimi</label>
                      <textarea class="form-control" name="description" rows="3"><?= h((string)($folder['description'] ?? '')) ?></textarea>
                    </div>
                    <button class="btn btn-primary w-100" type="submit">
                      <i class="bi bi-save me-1"></i>Ruaj ndryshimet
                    </button>
                  </form>
                </div>
              </section>

              <section class="fv-card mb-3">
                <div class="fv-card-head"><h5 class="mb-0">Shto elemente ne folder</h5></div>
                <div class="fv-card-body">
                  <form method="post" action="admin/edit_folder.php">
                    <input type="hidden" name="csrf" value="<?= h($CSRF) ?>">
                    <input type="hidden" name="course_id" value="<?= (int)$courseId ?>">
                    <input type="hidden" name="folder_id" value="<?= (int)$folderId ?>">
                    <input type="hidden" name="mode" value="append">
                    <input type="hidden" name="back" value="folder_view">

                    <div class="fv-select-wrap">
                      <?php if (!$eligible): ?>
                        <div class="text-muted small">Nuk ka elemente FILE/VIDEO te lira per t'u shtuar.</div>
                      <?php else: ?>
                        <?php foreach ($eligible as $el): ?>
                          <label class="fv-check-row">
                            <input type="checkbox" name="lesson_ids[]" value="<?= (int)($el['id'] ?? 0) ?>">
                            <span class="title"><?= h((string)($el['title'] ?? '')) ?></span>
                            <span class="cat"><?= h((string)($el['category'] ?? '')) ?></span>
                          </label>
                        <?php endforeach; ?>
                      <?php endif; ?>
                    </div>

                    <button class="btn btn-success w-100 mt-3" type="submit">
                      <i class="bi bi-plus-circle me-1"></i>Shto te zgjedhurat
                    </button>
                  </form>
                </div>
              </section>

              <section class="fv-card fv-danger">
                <div class="fv-card-head"><h5 class="mb-0">Delete folder</h5></div>
                <div class="fv-card-body">
                  <form method="post" action="admin/delete_folder.php" onsubmit="return confirm('Fshi folder-in bashke me te gjithe elementet e tij?');">
                    <input type="hidden" name="csrf" value="<?= h($CSRF) ?>">
                    <input type="hidden" name="course_id" value="<?= (int)$courseId ?>">
                    <input type="hidden" name="folder_id" value="<?= (int)$folderId ?>">
                    <button class="btn btn-danger w-100" type="submit">
                      <i class="bi bi-trash me-1"></i>Fshi Folder
                    </button>
                  </form>
                </div>
              </section>
            </div>
          <?php endif; ?>
        </div>
      </div>
    </main>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
    </body>
    </html>