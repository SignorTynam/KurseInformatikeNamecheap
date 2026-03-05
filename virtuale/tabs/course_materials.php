<?php
/**
 * tabs/course_materials.php
 * — “Materialet” e kursit, tani duke shfaqur TË GJITHA seksionet (MATERIALS + LABS)
 *
 * Kërkon nga prindi: $pdo, $CSRF, $course_id, $Parsedown
 */
declare(strict_types=1);
if (!isset($pdo, $CSRF, $course_id, $Parsedown)) { die('Materials: missing scope'); }

/** helpers të përbashkët */
require_once __DIR__ . '/../lib/sections_utils.php';

$AREA = 'MATERIALS'; // Ky skedar ende etiketohet si MATERIALS për veprimet (add/copy), por shfaq të gjitha seksionet

// Rol dhe user nga session
$ROLE  = $_SESSION['user']['role'] ?? '';
$ME_ID = (int)($_SESSION['user']['id'] ?? 0);

/* ========= AUTO-SYNC (schema e re: pa area) ========= */
try {
  // LESSONS (nëse do t'i fusësh edhe LAB-et, hiqe filtrin category<>'LAB')
  $q = $pdo->prepare("
    SELECT id, COALESCE(section_id,0) sid
    FROM lessons
    WHERE course_id=?
      AND NOT EXISTS (
        SELECT 1 FROM section_items si
        WHERE si.course_id=lessons.course_id
          AND si.item_type='LESSON' AND si.item_ref_id=lessons.id
      )
    ORDER BY id
  ");
  $q->execute([$course_id]);
  while ($r = $q->fetch(PDO::FETCH_ASSOC)) {
    $sid = (int)$r['sid'];
    $pos = si_next_pos($pdo, $course_id, $sid);
    $pdo->prepare("
      INSERT INTO section_items (course_id, section_id, item_type, item_ref_id, position)
      VALUES (?,?,?,?,?)
    ")->execute([$course_id, $sid, 'LESSON', (int)$r['id'], $pos]);
  }

  // ASSIGNMENTS
  $q = $pdo->prepare("
    SELECT a.id, COALESCE(a.section_id,0) sid
    FROM assignments a
    WHERE a.course_id=?
      AND NOT EXISTS (
        SELECT 1 FROM section_items si
        WHERE si.course_id=a.course_id
          AND si.item_type='ASSIGNMENT' AND si.item_ref_id=a.id
      )
    ORDER BY a.id
  ");
  $q->execute([$course_id]);
  while ($r = $q->fetch(PDO::FETCH_ASSOC)) {
    $sid = (int)$r['sid'];
    $pos = si_next_pos($pdo, $course_id, $sid);
    $pdo->prepare("
      INSERT INTO section_items (course_id, section_id, item_type, item_ref_id, position)
      VALUES (?,?,?,?,?)
    ")->execute([$course_id, $sid, 'ASSIGNMENT', (int)$r['id'], $pos]);
  }

  // QUIZZES
  $q = $pdo->prepare("
    SELECT q.id, COALESCE(q.section_id,0) sid
    FROM quizzes q
    WHERE q.course_id=?
      AND NOT EXISTS (
        SELECT 1 FROM section_items si
        WHERE si.course_id=q.course_id
          AND si.item_type='QUIZ' AND si.item_ref_id=q.id
      )
    ORDER BY q.id
  ");
  $q->execute([$course_id]);
  while ($r = $q->fetch(PDO::FETCH_ASSOC)) {
    $sid = (int)$r['sid'];
    $pos = si_next_pos($pdo, $course_id, $sid);
    $pdo->prepare("
      INSERT INTO section_items (course_id, section_id, item_type, item_ref_id, position)
      VALUES (?,?,?,?,?)
    ")->execute([$course_id, $sid, 'QUIZ', (int)$r['id'], $pos]);
  }
} catch (PDOException $e) {
  /* ignore auto-sync errors */
}


/* ===== Seksionet (MATERIALS + LABS, pa filtrim area) ===== */
$hasUn = false;
try {
  $stmtSec = $pdo->prepare("
    SELECT *
    FROM sections
    WHERE course_id=?
    ORDER BY position ASC, id ASC
  ");
  $stmtSec->execute([$course_id]);
  $sections = $stmtSec->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
  $sections = [];
}

/* File i parë i Leksionit */
$fileMap = [];
try {
  $stmtLF = $pdo->prepare("
    SELECT lf.lesson_id, lf.file_path
    FROM lesson_files lf
    JOIN (
      SELECT MIN(id) AS id
      FROM lesson_files
      WHERE lesson_id IN (SELECT id FROM lessons WHERE course_id = ?)
      GROUP BY lesson_id
    ) t ON t.id = lf.id
  ");
  $stmtLF->execute([$course_id]);
  foreach ($stmtLF->fetchAll(PDO::FETCH_ASSOC) as $r) {
    $fileMap[(int)$r['lesson_id']] = $r['file_path'];
  }
} catch (PDOException $e) {}

/* Lista e seksioneve (+ “jashtë seksioneve” pa filtrim area) */
$allSec = $sections;
try {
  $q = $pdo->prepare("
    SELECT COUNT(*)
    FROM section_items
    WHERE course_id=? AND section_id=0
  ");
  $q->execute([$course_id]);
  $hasUn = ((int)$q->fetchColumn() > 0);
} catch (PDOException $e) {}

if ($hasUn) {
  $allSec[] = [
    'id'          => 0,
    'course_id'   => $course_id,
    'area'        => 'MATERIALS',
    'title'       => 'Jashtë seksioneve',
    'description' => 'Elemente pa seksion.',
    'position'    => 0,
    'hidden'      => 0,
    'highlighted' => 0,
  ];
}

usort($allSec, function ($a, $b) {
  $ida = (int)($a['id'] ?? 0);
  $idb = (int)($b['id'] ?? 0);
  // “Jashtë seksioneve” (id=0) gjithmonë sipër
  if ($ida === 0 && $idb !== 0) return -1;
  if ($idb === 0 && $ida !== 0) return 1;

  $pa = (int)($a['position'] ?? 0);
  $pb = (int)($b['position'] ?? 0);
  return $pa <=> $pb;
});

/* Lexo section_items për TË GJITHË seksionet (pa filtrim area) */
$secIds = array_map(fn($s) => (int)$s['id'], $allSec);
if (!$secIds) {
  $secIds = [0];
}
$ph  = implode(',', array_fill(0, count($secIds), '?'));
$sql = "
  SELECT si.*,
         l.id   AS l_id, l.title AS l_title, l.category AS l_cat, l.uploaded_at AS l_up, l.URL AS l_url,
         a.id   AS a_id, a.title AS a_title, a.due_date AS a_due,
         q.id   AS q_id, q.title AS q_title, q.open_at AS q_open, q.close_at AS q_close
  FROM section_items si
  LEFT JOIN lessons l     ON si.item_type='LESSON'     AND si.item_ref_id=l.id
  LEFT JOIN assignments a ON si.item_type='ASSIGNMENT' AND si.item_ref_id=a.id
  LEFT JOIN quizzes q     ON si.item_type='QUIZ'       AND si.item_ref_id=q.id
  WHERE si.course_id=? AND si.section_id IN ($ph)
  ORDER BY si.section_id ASC, si.position ASC, si.id ASC
";
$stmtSI = $pdo->prepare($sql);
$stmtSI->execute(array_merge([$course_id], $secIds));
$itemsBySection = [];
while ($r = $stmtSI->fetch(PDO::FETCH_ASSOC)) {
  $itemsBySection[(int)$r['section_id']][] = $r;
}

/* Ikona */
$iconMap = [
  'LEKSION'   => ['bi-journal-text',  '#0f6cbf'],
  'VIDEO'     => ['bi-camera-video',  '#dc3545'],
  'LINK'      => ['bi-link-45deg',    '#6f42c1'],
  'FILE'      => ['bi-file-earmark',  '#28a745'],
  'REFERENCA' => ['bi-bookmark',      '#198754'],
  'LAB'       => ['bi-flask',         '#8b5cf6'],
  'TJETER'    => ['bi-collection',    '#6c757d'],
  'ASSIGN'    => ['bi-clipboard-check','#0d6efd'],
  'QUIZ'      => ['bi-patch-question','#20c997'],
];

if (!function_exists('catMeta')) {
  function catMeta($cat, $iconMap) {
    $key = strtoupper((string)$cat ?: 'TJETER');
    return $iconMap[$key] ?? ($iconMap['TJETER'] ?? ['bi-collection', '#6c757d']);
  }
}

/* pjesëmarrës (për pace box) */
try {
  $stmtEnroll = $pdo->prepare("
    SELECT e.*, u.full_name, u.email, u.id AS uid, e.user_id
    FROM enroll e
    LEFT JOIN users u ON e.user_id=u.id
    WHERE e.course_id=?
    ORDER BY u.full_name
  ");
  $stmtEnroll->execute([$course_id]);
  $participants = $stmtEnroll->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
  $participants = [];
}

/* ====== Të dhëna për modale (Copy Sections / Copy Item) ====== */
$otherCourses = [];
try {
  if ($ROLE === 'Administrator') {
    $stmt = $pdo->prepare("
      SELECT id, title
      FROM courses
      WHERE id<>?
      ORDER BY created_at DESC
    ");
    $stmt->execute([$course_id]);
  } else {
    $stmt = $pdo->prepare("
      SELECT id, title
      FROM courses
      WHERE id<>? AND id_creator=?
      ORDER BY created_at DESC
    ");
    $stmt->execute([$course_id, $ME_ID]);
  }
  $otherCourses = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
} catch (PDOException $e) {
  $otherCourses = [];
}

$sectionsByCourse = [];  // [courseId] => [ [id,title], ... ]
$lessonsByCourse  = [];  // [courseId] => [ [id,title], ... ]
$assignsByCourse  = [];
$quizzesByCourse  = [];
$textsByCourse    = [];  // nga section_items TEXT

foreach ($otherCourses as $oc) {
  $cid = (int)$oc['id'];

  // Sections (të gjitha area për atë kurs)
  try {
    $s = $pdo->prepare("
      SELECT id, title
      FROM sections
      WHERE course_id=?
      ORDER BY position, id
    ");
    $s->execute([$cid]);
    $sectionsByCourse[$cid] = $s->fetchAll(PDO::FETCH_ASSOC) ?: [];
  } catch (PDOException $e) {
    $sectionsByCourse[$cid] = [];
  }

  // Lessons (të gjitha kategoritë, përfshi LAB)
  try {
    $s = $pdo->prepare("
      SELECT id, title
      FROM lessons
      WHERE course_id=?
      ORDER BY uploaded_at DESC, id DESC
    ");
    $s->execute([$cid]);
    $lessonsByCourse[$cid] = $s->fetchAll(PDO::FETCH_ASSOC) ?: [];
  } catch (PDOException $e) {
    $lessonsByCourse[$cid] = [];
  }

  // Assignments (të gjitha, pa filtrim area)
  try {
    $s = $pdo->prepare("
      SELECT a.id, a.title
      FROM assignments a
      WHERE a.course_id=?
      ORDER BY a.id DESC
    ");
    $s->execute([$cid]);
    $assignsByCourse[$cid] = $s->fetchAll(PDO::FETCH_ASSOC) ?: [];
  } catch (PDOException $e) {
    $assignsByCourse[$cid] = [];
  }

  // Quizzes (të gjitha, pa filtrim area)
  try {
    $s = $pdo->prepare("
      SELECT q.id, q.title
      FROM quizzes q
      WHERE q.course_id=?
      ORDER BY q.id DESC
    ");
    $s->execute([$cid]);
    $quizzesByCourse[$cid] = $s->fetchAll(PDO::FETCH_ASSOC) ?: [];
  } catch (PDOException $e) {
    $quizzesByCourse[$cid] = [];
  }

  // TEXT blocks nga section_items (të gjitha area)
  try {
    $s = $pdo->prepare("
      SELECT id, LEFT(COALESCE(content_md,''), 100) AS title
      FROM section_items
      WHERE course_id=? AND item_type='TEXT'
      ORDER BY id DESC
    ");
    $s->execute([$cid]);
    $textsByCourse[$cid] = $s->fetchAll(PDO::FETCH_ASSOC) ?: [];
  } catch (PDOException $e) {
    $textsByCourse[$cid] = [];
  }
}

// Helper i vogël për HTML-escape
if (!function_exists('h')) {
  function h(?string $s): string {
    return htmlspecialchars($s ?? '', ENT_QUOTES, 'UTF-8');
  }
}

/* --------- Përmbledhje për panelin në të djathtë --------- */
$totalLessons      = 0;
$totalAssignments  = 0;
$totalQuizzes      = 0;

foreach ($itemsBySection as $sid => $items) {
  foreach ($items as $it) {
    switch ($it['item_type'] ?? '') {
      case 'LESSON':
        $totalLessons++;
        break;
      case 'ASSIGNMENT':
        $totalAssignments++;
        break;
      case 'QUIZ':
        $totalQuizzes++;
        break;
    }
  }
}

$totalItems    = $totalLessons + $totalAssignments + $totalQuizzes;
$totalSections = 0;
foreach ($allSec as $sec) {
  if ((int)$sec['id'] !== 0) {
    $totalSections++;
  }
}
$totalStudents = count($participants);

/* --------- Data për JS (copy sections / copy item) --------- */
$jsSectionsByCourse = [];
foreach ($sectionsByCourse as $cid => $list) {
  $cid = (int)$cid;
  $jsSectionsByCourse[$cid] = array_map(
    static fn($s) => [
      'id'    => (int)($s['id'] ?? 0),
      'title' => (string)($s['title'] ?? '')
    ],
    $list
  );
}

$jsListsByCourse = [
  'LESSON'     => [],
  'ASSIGNMENT' => [],
  'QUIZ'       => [],
  'TEXT'       => [],
];

foreach ($lessonsByCourse as $cid => $list) {
  $cid = (int)$cid;
  $jsListsByCourse['LESSON'][$cid] = array_map(
    static fn($x) => [
      'id'    => (int)($x['id'] ?? 0),
      'title' => (string)($x['title'] ?? '')
    ],
    $list
  );
}
foreach ($assignsByCourse as $cid => $list) {
  $cid = (int)$cid;
  $jsListsByCourse['ASSIGNMENT'][$cid] = array_map(
    static fn($x) => [
      'id'    => (int)($x['id'] ?? 0),
      'title' => (string)($x['title'] ?? '')
    ],
    $list
  );
}
foreach ($quizzesByCourse as $cid => $list) {
  $cid = (int)$cid;
  $jsListsByCourse['QUIZ'][$cid] = array_map(
    static fn($x) => [
      'id'    => (int)($x['id'] ?? 0),
      'title' => (string)($x['title'] ?? '')
    ],
    $list
  );
}
foreach ($textsByCourse as $cid => $list) {
  $cid = (int)$cid;
  $jsListsByCourse['TEXT'][$cid] = array_map(
    static fn($x) => [
      'id'    => (int)($x['id'] ?? 0),
      'title' => (string)($x['title'] ?? '')
    ],
    $list
  );
}

$jsonOpts = JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE;
?>

<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css">
<link rel="stylesheet" href="css/km-materials.css">

<script src="https://cdn.jsdelivr.net/npm/sortablejs@1.15.2/Sortable.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/marked/marked.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/dompurify@3.1.6/dist/purify.min.js"></script>

<script>
window.KM_MAT_CONFIG = <?= json_encode([
    'csrf'     => $CSRF,
    'courseId' => (int)$course_id,
    'area'     => $AREA,
], $jsonOpts) ?>;

window.KM_SECTIONS_BY_COURSE = <?= json_encode($jsSectionsByCourse, $jsonOpts) ?>;
window.KM_LISTS_BY_COURSE    = <?= json_encode($jsListsByCourse, $jsonOpts) ?>;
</script>

<div class="km-mat-root">
  <div class="row g-3" id="materialsRow">

    <!-- KOLONA MAJTAS: NAVIGIMI (desktop) -->
    <div class="col-12 col-lg-3 d-none d-lg-block" id="leftCol">
      <div class="km-mat-left-col-sticky d-flex flex-column gap-3">
        <aside class="km-mat-nav" id="navBox">
          <div class="d-flex justify-content-between align-items-center mb-2">
            <h6 class="mb-0">
              <i class="bi bi-list-ul me-1"></i>Navigimi
            </h6>
            <?php if ($totalSections > 0): ?>
              <span class="badge text-bg-light"><?= $totalSections ?></span>
            <?php endif; ?>
          </div>

          <?php if ($allSec): ?>
            <?php foreach ($allSec as $sec): ?>
              <?php
              $sid       = (int)$sec['id'];
              $secTitle  = $sid === 0 ? 'Jashtë seksioneve' : ($sec['title'] ?? '');
              $secItems  = $itemsBySection[$sid] ?? [];
              $itemsCnt  = count($secItems);
              $isHidden  = !empty($sec['hidden']);
              ?>
              <div class="km-mat-nav-section open"
                   data-sec="<?= $sid ?>">
                <button type="button"
                        class="km-mat-nav-toggle">
                  <span>
                    <i class="bi bi-folder<?= $sid === 0 ? '-symlink' : '' ?> me-1"></i>
                    <?= h($secTitle) ?>
                  </span>
                  <?php if ($itemsCnt): ?>
                    <span class="badge text-bg-light"><?= $itemsCnt ?></span>
                  <?php endif; ?>
                </button>
                <ul>
                  <li>
                    <a class="km-mat-nav-item"
                       href="#sec-<?= $sid ?>">
                      <i class="bi bi-arrow-bar-right me-1"></i> Shko te seksioni
                    </a>
                  </li>
                  <?php foreach ($secItems as $it): ?>
                    <?php
                    $type = $it['item_type'] ?? '';
                    $hrefId = '';
                    $label  = '';
                    $icon   = 'bi-collection';

                    if ($type === 'LESSON' && !empty($it['l_id'])) {
                        $hrefId = 'lesson-' . (int)$it['l_id'];
                        $label  = $it['l_title'] ?: ('Leksion #' . $it['l_id']);
                        [$icon, ] = catMeta($it['l_cat'] ?? '', $iconMap);
                    } elseif ($type === 'ASSIGNMENT' && !empty($it['a_id'])) {
                        $hrefId = 'assign-' . (int)$it['a_id'];
                        $label  = $it['a_title'] ?: ('Detyrë #' . $it['a_id']);
                        $icon   = $iconMap['ASSIGN'][0];
                    } elseif ($type === 'QUIZ' && !empty($it['q_id'])) {
                        $hrefId = 'quiz-' . (int)$it['q_id'];
                        $label  = $it['q_title'] ?: ('Quiz #' . $it['q_id']);
                        $icon   = $iconMap['QUIZ'][0];
                    } else {
                        continue; // TEXT ose element pa ID -> s’e futim në nav
                    }
                    ?>
                    <li>
                      <a class="km-mat-nav-item"
                         href="#<?= h($hrefId) ?>">
                        <i class="bi <?= h($icon) ?> me-1"></i><?= h($label) ?>
                      </a>
                    </li>
                  <?php endforeach; ?>
                  <?php if ($isHidden): ?>
                    <li>
                      <span class="km-mat-nav-item text-muted small">
                        <i class="bi bi-eye-slash me-1"></i> Seksion i fshehur
                      </span>
                    </li>
                  <?php endif; ?>
                </ul>
              </div>
            <?php endforeach; ?>
          <?php else: ?>
            <p class="text-muted small mb-0">
              Nuk ka ende seksione për materialet.
            </p>
          <?php endif; ?>
        </aside>
      </div>
    </div>

    <!-- KOLONA QENDRORE: MATERIALS -->
    <div class="col-12 col-lg-6" id="rightCol">

      <!-- BULK: MATERIALS -->
      <div class="km-mat-bulkbar d-none"
           id="bulkMaterialsBar"
           aria-hidden="true">
        <span class="count" id="bulkMatCount">0</span> materiale të përzgjedhura
        <select class="form-select form-select-sm"
                id="bulkMoveTarget"
                style="width:220px">
          <option value="">— Zhvendos te seksioni —</option>
          <option value="0">Jashtë seksioneve</option>
          <?php foreach ($sections as $sec): ?>
            <option value="<?= (int)$sec['id'] ?>">
              <?= h($sec['title'] ?? '') ?>
            </option>
          <?php endforeach; ?>
        </select>
        <button class="btn btn-sm btn-outline-primary only-edit"
                id="bulkMoveBtn">
          <i class="bi bi-arrow-left-right me-1"></i> Zhvendos
        </button>
        <button class="btn btn-sm btn-outline-danger only-edit"
                id="bulkDelMatBtn">
          <i class="bi bi-trash me-1"></i> Fshi
        </button>
      </div>

      <!-- BULK: SECTIONS -->
      <div class="km-mat-bulkbar d-none"
           id="bulkSectionsBar"
           aria-hidden="true">
        <span class="count" id="bulkSecCount">0</span> seksione të përzgjedhura
        <div class="btn-group">
          <button class="btn btn-sm btn-outline-secondary only-edit"
                  data-sec-action="hide">
            <i class="bi bi-eye-slash me-1"></i> Fshihe
          </button>
          <button class="btn btn-sm btn-outline-secondary only-edit"
                  data-sec-action="unhide">
            <i class="bi bi-eye me-1"></i> Bëj publik
          </button>
          <button class="btn btn-sm btn-outline-secondary only-edit"
                  data-sec-action="highlight">
            <i class="bi bi-highlighter me-1"></i> Highlight
          </button>
          <button class="btn btn-sm btn-outline-secondary only-edit"
                  data-sec-action="unhighlight">
            <i class="bi bi-highlighter me-1"></i> Heq Highlight
          </button>
          <button class="btn btn-sm btn-outline-danger only-edit"
                  data-sec-action="delete">
            <i class="bi bi-trash me-1"></i> Fshi
          </button>
        </div>
      </div>

      <!-- SEKSIONET E MATERIALIT -->
      <section>
        <div class="km-mat-section-accordion vstack gap-3" id="sectionsList">

          <?php if ($allSec): ?>
            <?php foreach ($allSec as $sec): ?>
              <?php
              $sid          = (int)$sec['id'];
              $secTitle     = $sid === 0 ? 'Jashtë seksioneve' : ($sec['title'] ?? '');
              $secDescMd    = (string)($sec['description'] ?? '');
              $secDescHtml  = $secDescMd !== '' ? $Parsedown->text($secDescMd) : '';
              $secItems     = $itemsBySection[$sid] ?? [];
              $isHiddenSec  = !empty($sec['hidden']);
              $isHighSec    = !empty($sec['highlighted']);
              $open         = !$isHiddenSec;
              $secCardClass = 'km-mat-sec-card';
              if ($sid === 0)      $secCardClass .= ' km-mat-sec-static';
              if ($isHiddenSec)    $secCardClass .= ' km-mat-sec-hidden';
              if ($isHighSec)      $secCardClass .= ' km-mat-sec-highlighted';
              ?>
              <div class="<?= $secCardClass ?>"
                   data-sec="<?= $sid ?>">
                <div class="km-mat-sec-head anchor-target"
                     id="sec-<?= $sid ?>">
                  <div class="d-flex align-items-center gap-2">
                    <span class="km-mat-sec-drag"
                          title="Zhvendos seksionin">
                      <i class="bi bi-grip-vertical"></i>
                    </span>

                    <label class="mb-0 d-flex align-items-center gap-2">
                      <input type="checkbox"
                             class="form-check-input km-mat-selbox km-mat-sel-section"
                             data-sec-id="<?= $sid ?>">
                      <span class="h5 mb-0 km-mat-sec-title-line">
                        <i class="bi bi-folder<?= $sid === 0 ? '-symlink' : '' ?> me-1"></i>
                        <?= h($secTitle) ?>
                      </span>
                    </label>

                    <?php if ($isHiddenSec): ?>
                      <span class="badge km-mat-badge-soft">
                        <i class="bi bi-eye-slash me-1"></i> Fshehur
                      </span>
                    <?php endif; ?>
                    <?php if ($isHighSec): ?>
                      <span class="badge text-bg-info">
                        <i class="bi bi-stars me-1"></i> Theksuar
                      </span>
                    <?php endif; ?>
                  </div>

                  <div class="d-flex align-items-center gap-2">
                    <?php if ($sid > 0): ?>
                      <!-- Toggle i shpejtë i dukshmërisë -->
                    <button class="btn btn-outline-secondary km-mat-sec-quick-toggle"
                            data-sec-id="<?= $sid ?>"
                            data-hidden="<?= $isHiddenSec ? 1 : 0 ?>"
                            type="button"
                            title="<?= $isHiddenSec ? 'Bëje publik seksionin' : 'Fshihe seksionin' ?>">
                      <i class="bi <?= $isHiddenSec ? 'bi-eye' : 'bi-eye-slash' ?>"></i>
                    </button>

                    <!-- Gear (vetëm në Edit Mode) -->
                    <div class="d-flex align-items-center gap-2 km-mat-sec-gear">
                      <div class="dropdown">
                        <button class="btn btn-sm btn-outline-secondary dropdown-toggle only-edit"
                                data-bs-toggle="dropdown"
                                type="button">
                          <i class="bi bi-gear"></i>
                        </button>
                        <ul class="dropdown-menu">
                          <li>
                            <button class="dropdown-item only-edit"
                                    data-bs-toggle="modal"
                                    data-bs-target="#editSectionModal-<?= $sid ?>"
                                    type="button">
                              <i class="bi bi-pencil me-2"></i>Modifiko
                            </button>
                          </li>
                          <li><hr class="dropdown-divider"></li>
                          <li>
                                <button class="dropdown-item text-danger only-edit"
                                  type="button"
                                  data-action="delete-section"
                                  data-sec-id="<?= (int)$sid ?>"
                                  data-confirm="Fshi seksionin bashkë me materialet e tij? Ky veprim është i pakthyeshëm.">
                              <i class="bi bi-trash me-2"></i>Fshi seksionin
                            </button>
                          </li>
                        </ul>
                      </div>
                    </div>
                    <?php endif; ?>

                    <!-- Toggle collapse -->
                    <button class="km-mat-sec-toggle"
                            data-bs-toggle="collapse"
                            data-bs-target="#sec-body-<?= $sid ?>"
                            aria-expanded="<?= $open ? 'true' : 'false' ?>"
                            type="button">
                      <i class="bi bi-arrows-expand"></i>
                    </button>
                  </div>
                </div>

                <?php if ($secDescHtml): ?>
                  <div class="px-3 pt-2 text-muted small km-mat-sec-desc">
                    <?= $secDescHtml ?>
                  </div>
                <?php endif; ?>

                <div id="sec-body-<?= $sid ?>"
                     class="km-mat-sec-body collapse<?= $open ? ' show' : '' ?>">
                  <div class="km-mat-items d-grid"
                       data-section-id="<?= $sid ?>">

                    <?php if ($secItems): ?>
                      <?php foreach ($secItems as $it): ?>
                        <?php
                        $siId    = (int)$it['id'];
                        $type    = $it['item_type'] ?? '';
                        $isSub   = !empty($it['is_subsection']);
                        // Për materialet, përdor hidden nga section_items (kompatibil me DB të vjetër)
                        $hiddenI = !empty($it['hidden']);
                        $elemCls = 'km-mat-elem anchor-target km-mat-draggable';
                        if ($isSub)   $elemCls .= ' km-mat-subsection-start';
                        if ($hiddenI) $elemCls .= ' km-mat-elem-hidden';

                        $dataSub = $isSub ? '1' : '0';

                        $anchorId = 'si-' . $siId;
                        $refId = 0;
                        if ($type === 'LESSON' && !empty($it['l_id'])) {
                            $anchorId = 'lesson-' . (int)$it['l_id'];
                          $refId = (int)$it['l_id'];
                        } elseif ($type === 'ASSIGNMENT' && !empty($it['a_id'])) {
                            $anchorId = 'assign-' . (int)$it['a_id'];
                          $refId = (int)$it['a_id'];
                        } elseif ($type === 'QUIZ' && !empty($it['q_id'])) {
                            $anchorId = 'quiz-' . (int)$it['q_id'];
                          $refId = (int)$it['q_id'];
                        } elseif ($type === 'TEXT') {
                            $anchorId = 'text-' . $siId;
                          $refId = $siId;
                        }

                        $toggleHref = '';
                        if ($siId > 0 && in_array($type, ['LESSON','ASSIGNMENT','QUIZ','TEXT'], true)) {
                          $toggleHref = 'actions/toggle_section_item_visibility.php?si_id=' . $siId
                            . '&action=' . ($hiddenI ? 'unhide' : 'hide')
                            . '&return=' . rawurlencode('course_details.php?course_id=' . (int)$course_id . '&tab=materials#' . $anchorId);
                        }

                        // Për leksionet
                        if ($type === 'LESSON') {
                            $lessonId    = (int)($it['l_id'] ?? 0);
                            $lessonTitle = $it['l_title'] ?? ('Leksion #' . $lessonId);
                            $uploaded    = $it['l_up'] ?? null;
                          $cat         = strtoupper(trim((string)($it['l_cat'] ?? '')));
                            [$ic, $col]  = catMeta($cat, $iconMap);
                            $itemIcon    = $ic;
                            $iconColor   = $col;

                          // Default: hap detajet; për FILE hap skedarin direkt.
                            $href = 'lesson_details.php?lesson_id=' . $lessonId;
                          if ($cat === 'FILE') {
                            $filePath = (string)($fileMap[$lessonId] ?? '');
                            if ($filePath !== '') {
                              $href = $filePath;
                            } elseif (!empty($it['l_url'])) {
                              $href = (string)$it['l_url'];
                            }
                          }

                            $metaParts = [];
                            if ($cat !== '') $metaParts[] = strtoupper($cat);
                            if (!empty($uploaded)) {
                                try {
                                    $ts = strtotime($uploaded);
                                    if ($ts) {
                                        $metaParts[] = date('d M Y, H:i', $ts);
                                    }
                                } catch (Throwable $e) {}
                            }
                            $metaStr = implode(' • ', $metaParts);
                        } elseif ($type === 'ASSIGNMENT') {
                            $assignId    = (int)($it['a_id'] ?? 0);
                            $assignTitle = $it['a_title'] ?? ('Detyrë #' . $assignId);
                            $due         = $it['a_due'] ?? null;
                            $itemIcon    = $iconMap['ASSIGN'][0];
                            $iconColor   = $iconMap['ASSIGN'][1];
                            $metaStr     = $due ? ('Skadon: ' . date('d M Y, H:i', strtotime($due))) : '';

                        } elseif ($type === 'QUIZ') {
                            $quizId    = (int)($it['q_id'] ?? 0);
                            $quizTitle = $it['q_title'] ?? ('Quiz #' . $quizId);
                            $openAt    = $it['q_open'] ?? null;
                            $closeAt   = $it['q_close'] ?? null;
                            $itemIcon  = $iconMap['QUIZ'][0];
                            $iconColor = $iconMap['QUIZ'][1];
                            $metaParts = [];
                            if ($openAt)  $metaParts[] = 'Hape: '  . date('d M Y, H:i', strtotime($openAt));
                            if ($closeAt) $metaParts[] = 'Mbyll: ' . date('d M Y, H:i', strtotime($closeAt));
                            $metaStr = implode(' • ', $metaParts);
                        }
                        ?>
                        <div class="<?= $elemCls ?>"
                             data-si-id="<?= $siId ?>"
                             data-type="<?= h($type) ?>"
                              data-subsection="<?= $dataSub ?>"
                              data-ref-id="<?= (int)$refId ?>"
                             id="<?= h($anchorId) ?>">
                          <div class="km-mat-elem-left d-flex align-items-start gap-3">
                            <span class="km-mat-drag-handle"
                                  title="Zhvendos">
                              <i class="bi bi-grip-vertical"></i>
                            </span>
                            <input type="checkbox"
                                   class="form-check-input km-mat-selbox km-mat-sel-item"
                                   data-si-id="<?= $siId ?>">

                            <?php if ($type === 'TEXT'): ?>
                              <?php
                              $contentMd   = (string)($it['content_md'] ?? '');
                              $contentHtml = $contentMd !== '' ? $Parsedown->text($contentMd) : '';
                              ?>
                              <div class="flex-grow-1 km-mat-text-block">
                                <?php if ($hiddenI): ?>
                                  <div class="mb-2">
                                    <span class="badge km-mat-badge-soft">
                                      <i class="bi bi-eye-slash me-1"></i> Fshehur
                                    </span>
                                  </div>
                                <?php endif; ?>
                                <div class="km-mat-md-body">
                                  <?= $contentHtml ?>
                                </div>
                              </div>

                            <?php elseif ($type === 'LESSON'): ?>
                              <div class="km-mat-elem-icon km-mat-elem-icon-rounded"
                                   style="--km-mat-icon-bg: <?= h($iconColor) ?>;">
                                <i class="bi <?= h($itemIcon) ?>"></i>
                              </div>
                              <div>
                                <?php if (!empty($href)): ?>
                                  <a class="text-decoration-none text-dark"
                                     href="<?= h($href) ?>"
                                     target="_blank">
                                    <strong><?= h($lessonTitle) ?></strong>
                                  </a>
                                <?php else: ?>
                                  <strong><?= h($lessonTitle) ?></strong>
                                <?php endif; ?>
                                <?php if ($hiddenI): ?>
                                  <span class="badge km-mat-badge-soft ms-2">
                                    <i class="bi bi-eye-slash me-1"></i> Fshehur
                                  </span>
                                <?php endif; ?>
                                <?php if (!empty($metaStr)): ?>
                                  <div class="small text-muted">
                                    <?= h($metaStr) ?>
                                  </div>
                                <?php endif; ?>
                              </div>

                            <?php elseif ($type === 'ASSIGNMENT'): ?>
                              <div class="km-mat-elem-icon km-mat-elem-icon-rounded"
                                   style="--km-mat-icon-bg: <?= h($iconColor) ?>;">
                                <i class="bi <?= h($itemIcon) ?>"></i>
                              </div>
                              <div>
                                <a class="text-decoration-none text-dark"
                                  href="assignment_details.php?assignment_id=<?= $assignId ?>"
                                  target="_blank">
                                  <strong><?= h($assignTitle) ?></strong>
                                </a>
                                <?php if ($hiddenI): ?>
                                  <span class="badge km-mat-badge-soft ms-2">
                                    <i class="bi bi-eye-slash me-1"></i> Fshehur
                                  </span>
                                <?php endif; ?>
                                <?php if (!empty($metaStr)): ?>
                                  <div class="small text-muted">
                                    <?= h($metaStr) ?>
                                  </div>
                                <?php endif; ?>
                              </div>

                            <?php elseif ($type === 'QUIZ'): ?>
                              <div class="km-mat-elem-icon km-mat-elem-icon-rounded"
                                   style="--km-mat-icon-bg: <?= h($iconColor) ?>;">
                                <i class="bi <?= h($itemIcon) ?>"></i>
                              </div>
                              <div>
                                <a class="text-decoration-none text-dark"
                                  href="quizzes/quiz_details.php?quiz_id=<?= $quizId ?>"
                                  target="_blank">
                                  <strong><?= h($quizTitle) ?></strong>
                                </a>
                                <?php if ($hiddenI): ?>
                                  <span class="badge km-mat-badge-soft ms-2">
                                    <i class="bi bi-eye-slash me-1"></i> Fshehur
                                  </span>
                                <?php endif; ?>
                                <?php if (!empty($metaStr)): ?>
                                  <div class="small text-muted">
                                    <?= h($metaStr) ?>
                                  </div>
                                <?php endif; ?>
                              </div>

                            <?php else: ?>
                              <!-- Lloj tjetër i papërcaktuar -->
                              <div class="flex-grow-1">
                                <span class="badge text-bg-secondary me-2">
                                  <?= h($type) ?>
                                </span>
                                <span class="text-muted">Element i panjohur (ID: <?= $siId ?>)</span>
                              </div>
                            <?php endif; ?>
                          </div>

                          <div class="d-flex align-items-center gap-2 km-mat-item-actions">
                            <?php if (!empty($toggleHref)): ?>
                              <a class="btn btn-sm btn-outline-secondary only-edit"
                                 href="<?= h($toggleHref) ?>"
                                 title="<?= $hiddenI ? 'Bëje publik elementin' : 'Fshihe elementin' ?>">
                                <i class="bi <?= $hiddenI ? 'bi-eye' : 'bi-eye-slash' ?>"></i>
                              </a>
                            <?php endif; ?>
                            <?php if ($type === 'TEXT'): ?>
                                    <button class="btn btn-sm btn-outline-secondary only-edit"
                                      data-bs-toggle="modal"
                                      data-bs-target="#editTextModal-<?= $siId ?>"
                                      data-action="edit-item"
                                      type="button">
                                <i class="bi bi-pencil"></i>
                              </button>
                            <?php else: ?>
                                    <button class="btn btn-sm btn-outline-secondary only-edit"
                                      data-action="edit-item"
                                      type="button">
                                <i class="bi bi-pencil"></i>
                              </button>
                            <?php endif; ?>
                                  <button class="btn btn-sm btn-outline-danger only-edit"
                                    data-action="delete-item"
                                    type="button"
                                    data-confirm="Fshi elementin?">
                              <i class="bi bi-trash"></i>
                            </button>
                          </div>
                        </div>

                        <?php if ($type === 'TEXT'): ?>
                          <!-- MODAL: Edit Text -->
                          <div class="modal fade"
                               id="editTextModal-<?= $siId ?>"
                               tabindex="-1"
                               aria-hidden="true">
                            <div class="modal-dialog modal-lg">
                              <form class="modal-content"
                                    method="post"
                                    action="block_text_actions.php">
                                <input type="hidden" name="csrf" value="<?= h($CSRF) ?>">
                                <input type="hidden" name="action" value="update">
                                <input type="hidden" name="course_id" value="<?= (int)$course_id ?>">
                                <input type="hidden" name="area" value="<?= h($AREA) ?>">
                                <input type="hidden" name="section_item_id" value="<?= $siId ?>">
                                <input type="hidden" name="si_id" value="<?= $siId ?>">
                                <div class="modal-header">
                                  <h5 class="modal-title">Ndrysho bllokun e tekstit</h5>
                                  <button class="btn-close"
                                          data-bs-dismiss="modal"
                                          type="button"></button>
                                </div>
                                <div class="modal-body">
                                  <div class="row g-3">
                                    <div class="col-lg-6">
                                      <label class="form-label">Markdown</label>
                                      <textarea class="form-control"
                                                rows="12"
                                                name="content_md"
                                                id="md-text-<?= $siId ?>"
                                                data-preview="#md-prev-<?= $siId ?>"><?= h($it['content_md'] ?? '') ?></textarea>
                                    </div>
                                    <div class="col-lg-6">
                                      <label class="form-label">Parapamje</label>
                                      <div id="md-prev-<?= $siId ?>"
                                           class="border rounded p-2 bg-body-tertiary small"
                                           style="min-height: 300px; overflow:auto;"></div>
                                    </div>
                                  </div>
                                </div>
                                <div class="modal-footer">
                                  <button class="btn btn-secondary"
                                          type="button"
                                          data-bs-dismiss="modal">
                                    Mbyll
                                  </button>
                                  <button class="btn btn-primary only-edit"
                                          type="submit">
                                    <i class="bi bi-save me-1"></i>Ruaj
                                  </button>
                                </div>
                              </form>
                            </div>
                          </div>
                        <?php endif; ?>

                      <?php endforeach; ?>
                    <?php else: ?>
                      <div class="text-muted small px-3 py-2">
                        Nuk ka ende materiale në këtë seksion.
                      </div>
                    <?php endif; ?>

                    <!-- Add-blocks në FUND të seksionit -->
                    <div class="km-mat-add-blocks">
                      <div class="km-mat-add-block-card only-edit"
                          data-action="lesson"
                          role="button">
                        <i class="bi bi-journal-plus"></i> Leksion
                      </div>
                      <div class="km-mat-add-block-card only-edit"
                          data-action="assignment"
                          role="button">
                        <i class="bi bi-clipboard-plus"></i> Detyrë
                      </div>
                      <div class="km-mat-add-block-card only-edit"
                          data-action="quiz"
                          role="button">
                        <i class="bi bi-patch-plus"></i> Quiz
                      </div>
                      <div class="km-mat-add-block-card only-edit"
                          data-bs-toggle="modal"
                          data-bs-target="#newTextModal-<?= $sid ?>"
                          role="button">
                        <i class="bi bi-text-paragraph"></i> Tekst
                      </div>
                      <div class="km-mat-add-block-card only-edit"
                          data-bs-toggle="modal"
                          data-bs-target="#copyItemModal-<?= $sid ?>"
                          role="button">
                        <i class="bi bi-files"></i> Kopjo element
                      </div>
                    </div>
                  </div>

                  <!-- MODAL: New Text -->
                  <div class="modal fade"
                       id="newTextModal-<?= $sid ?>"
                       tabindex="-1"
                       aria-hidden="true">
                    <div class="modal-dialog modal-lg">
                      <form class="modal-content"
                            method="post"
                            action="block_text_actions.php">
                        <input type="hidden" name="csrf" value="<?= h($CSRF) ?>">
                        <input type="hidden" name="action" value="create">
                        <input type="hidden" name="course_id" value="<?= (int)$course_id ?>">
                        <input type="hidden" name="area" value="<?= h($AREA) ?>">
                        <input type="hidden" name="section_id" value="<?= $sid ?>">
                        <div class="modal-header">
                          <h5 class="modal-title">Bllok i ri teksti</h5>
                          <button class="btn-close"
                                  data-bs-dismiss="modal"
                                  type="button"></button>
                        </div>
                        <div class="modal-body">
                          <div class="row g-3">
                            <div class="col-lg-6">
                              <label class="form-label">Markdown</label>
                              <textarea class="form-control"
                                        rows="12"
                                        name="content_md"
                                        id="md-text-new-<?= $sid ?>"
                                        data-preview="#md-prev-new-<?= $sid ?>"></textarea>
                            </div>
                            <div class="col-lg-6">
                              <label class="form-label">Parapamje</label>
                              <div id="md-prev-new-<?= $sid ?>"
                                   class="border rounded p-2 bg-body-tertiary small"
                                   style="min-height: 300px; overflow:auto;"></div>
                            </div>
                          </div>
                        </div>
                        <div class="modal-footer">
                          <button class="btn btn-secondary"
                                  type="button"
                                  data-bs-dismiss="modal">
                            Anulo
                          </button>
                          <button class="btn btn-primary only-edit"
                                  type="submit">
                            <i class="bi bi-save me-1"></i>Ruaj
                          </button>
                        </div>
                      </form>
                    </div>
                  </div>

                  <!-- MODAL: Copy Item -->
                  <div class="modal fade"
                       id="copyItemModal-<?= $sid ?>"
                       tabindex="-1"
                       aria-hidden="true">
                    <div class="modal-dialog">
                      <form class="modal-content"
                            method="post"
                            action="copy_item.php">
                        <input type="hidden" name="csrf" value="<?= h($CSRF) ?>">
                        <input type="hidden" name="course_id" value="<?= (int)$course_id ?>">
                        <input type="hidden" name="area" value="<?= h($AREA) ?>">
                        <input type="hidden" name="target_section_id" value="<?= $sid ?>">
                        <div class="modal-header">
                          <h5 class="modal-title">Kopjo element nga kurs tjetër</h5>
                          <button class="btn-close"
                                  data-bs-dismiss="modal"
                                  type="button"></button>
                        </div>
                        <div class="modal-body">
                          <div class="mb-3">
                            <label class="form-label">Zgjidh kursin burim</label>
                            <select class="form-select"
                                    name="source_course_id"
                                    required
                                    data-ci-course>
                              <option value="">— Zgjidh kursin —</option>
                              <?php foreach ($otherCourses as $oc): ?>
                                <option value="<?= (int)$oc['id'] ?>">
                                  <?= h($oc['title'] ?? '') ?>
                                </option>
                              <?php endforeach; ?>
                            </select>
                          </div>

                          <div class="mb-3">
                            <label class="form-label">Lloji i elementit</label>
                            <select class="form-select"
                                    name="item_type"
                                    required
                                    data-ci-kind>
                              <option value="LESSON">Leksion</option>
                              <option value="ASSIGNMENT">Detyrë</option>
                              <option value="QUIZ">Quiz</option>
                              <option value="TEXT">Tekst</option>
                            </select>
                          </div>

                          <div class="mb-3">
                            <label class="form-label">Elementi</label>
                            <select class="form-select"
                                    name="source_item_id"
                                    required
                                    data-ci-item>
                              <option value="">— Së pari zgjidh kursin dhe llojin —</option>
                            </select>
                            <div class="form-text">
                              Për “Tekst”, lista paraqet një fragment të përmbajtjes.
                            </div>
                          </div>
                        </div>
                        <div class="modal-footer">
                          <button class="btn btn-secondary"
                                  type="button"
                                  data-bs-dismiss="modal">
                            Anulo
                          </button>
                          <button class="btn btn-primary"
                                  type="submit">
                            <i class="bi bi-files me-1"></i>Kopjo
                          </button>
                        </div>
                      </form>
                    </div>
                  </div>

                </div>

                <?php if ($sid > 0): ?>
                <!-- MODAL: Edit Section -->
                <div class="modal fade"
                     id="editSectionModal-<?= $sid ?>"
                     tabindex="-1"
                     aria-hidden="true">
                  <div class="modal-dialog">
                    <form class="modal-content"
                          method="post"
                        action="sections/section_actions.php">
                      <input type="hidden" name="csrf" value="<?= h($CSRF) ?>">
                      <input type="hidden" name="action" value="update">
                      <input type="hidden" name="course_id" value="<?= (int)$course_id ?>">
                      <input type="hidden" name="area" value="<?= h($AREA) ?>">
                      <input type="hidden" name="section_id" value="<?= $sid ?>">
                      <div class="modal-header">
                        <h5 class="modal-title">Modifiko seksionin</h5>
                        <button class="btn-close"
                                data-bs-dismiss="modal"
                                type="button"></button>
                      </div>
                      <div class="modal-body">
                        <div class="mb-3">
                          <label class="form-label">Titulli</label>
                          <input class="form-control"
                                 name="title"
                                 required
                                 maxlength="255"
                                 value="<?= h($secTitle) ?>">
                        </div>
                        <div class="mb-3">
                          <label class="form-label">Përshkrimi (Markdown, opsional)</label>
                          <textarea class="form-control"
                                    rows="3"
                                    name="description_md"
                                    id="md-text-sec-<?= $sid ?>"
                                    data-preview="#md-prev-sec-<?= $sid ?>"><?= h($secDescMd) ?></textarea>
                          <div id="md-prev-sec-<?= $sid ?>"
                               class="border rounded p-2 bg-body-tertiary small mt-2"></div>
                        </div>
                      </div>
                      <div class="modal-footer">
                        <button class="btn btn-secondary"
                                type="button"
                                data-bs-dismiss="modal">
                          Mbyll
                        </button>
                        <button class="btn btn-primary only-edit"
                                type="submit">
                          <i class="bi bi-save me-1"></i>Ruaj
                        </button>
                      </div>
                    </form>
                  </div>
                </div>
                <?php endif; ?>
              </div>
            <?php endforeach; ?>
          <?php else: ?>
            <div class="km-mat-empty text-center text-muted py-4">
              Nuk ka ende materiale për këtë kurs.
            </div>
          <?php endif; ?>

          <!-- “Shto seksion” global në fund -->
          <div class="km-mat-add-section-global mt-2 only-edit"
               data-bs-toggle="modal"
               data-bs-target="#addSectionModal">
            <i class="bi bi-folder-plus me-1"></i>
            <strong>Shto seksion të ri</strong>
          </div>

          <!-- “Kopjo seksion(e)” global -->
          <?php if ($otherCourses): ?>
            <div class="km-mat-add-section-global mt-2 only-edit"
                 data-bs-toggle="modal"
                 data-bs-target="#copySectionsModal">
              <i class="bi bi-folder-symlink me-1"></i>
              <strong>Kopjo seksion(e) nga kurs tjetër</strong>
            </div>
          <?php endif; ?>
        </div>
      </section>
    </div>

    <!-- KOLONA DJATHTAS: PËRMBLEDHJE + STUDENTËT -->
    <div class="col-12 col-lg-3 d-none d-lg-block" id="rightSidebar">
      <div class="km-mat-right-col-sticky d-flex flex-column gap-3">

        <!-- PANELI PËRMBLEDHJE MATERIALI -->
        <aside class="km-mat-summary" id="summaryBox">
          <div class="km-mat-summary-header">
            <h6 class="mb-0">
              <i class="bi bi-layer-forward me-1"></i>
              Përmbledhje materiale
            </h6>
            <span class="badge text-bg-light">
              <?= $totalSections ?> seksione
            </span>
          </div>

          <div class="km-mat-summary-grid">
            <div class="km-mat-summary-item">
              <div class="label">Leksione</div>
              <div class="value"><?= $totalLessons ?></div>
            </div>
            <div class="km-mat-summary-item">
              <div class="label">Detyra</div>
              <div class="value"><?= $totalAssignments ?></div>
            </div>
            <div class="km-mat-summary-item">
              <div class="label">Quiz-e</div>
              <div class="value"><?= $totalQuizzes ?></div>
            </div>
            <div class="km-mat-summary-item">
              <div class="label">Total</div>
              <div class="value"><?= $totalItems ?></div>
            </div>
          </div>

          <div class="km-mat-summary-meta mt-2">
            <div>
              Studentë të regjistruar:
              <strong><?= $totalStudents ?></strong>
            </div>
          </div>
        </aside>

        <!-- PANELI STUDENTËT -->
        <aside class="km-mat-pace" id="paceBox">
          <div class="d-flex justify-content-between align-items-center mb-2">
            <h6 class="mb-0">
              <i class="bi bi-people me-1"></i>Studentët
            </h6>
            <span class="badge text-bg-light"><?= $totalStudents ?></span>
          </div>

          <?php if (!$participants): ?>
            <p class="text-muted small mb-0">
              Ende nuk ka studentë të regjistruar në këtë kurs.
            </p>
          <?php else: ?>
            <?php foreach ($participants as $p): ?>
              <?php
              $name  = $p['full_name'] ?? '';
              $email = $p['email'] ?? '';
              if ($name === '' && $email !== '') {
                  $name = $email;
              }
              ?>
              <div class="km-mat-student mb-3">
                <div class="d-flex justify-content-between">
                  <strong><?= h($name) ?></strong>
                  <span class="small text-muted">?/<?= $totalItems ?: '?' ?></span>
                </div>
                <div class="progress mt-1">
                  <div class="progress-bar" role="progressbar" style="width: 0%;"></div>
                </div>
                <div class="small text-muted mt-1">
                  Përfunduar: ? / <?= $totalItems ?: '?' ?>
                </div>
                <?php if ($email): ?>
                  <div class="small text-muted">
                    <?= h($email) ?>
                  </div>
                <?php endif; ?>
              </div>
            <?php endforeach; ?>
          <?php endif; ?>
        </aside>
      </div>
    </div>

  </div>
</div>

<!-- MODAL: SHTO SEKION (GLOBAL) -->
<div class="modal fade"
     id="addSectionModal"
     tabindex="-1"
     aria-hidden="true">
  <div class="modal-dialog">
    <form class="modal-content"
          method="post"
          action="sections/section_actions.php">
      <input type="hidden" name="csrf" value="<?= h($CSRF) ?>">
      <input type="hidden" name="action" value="create">
      <input type="hidden" name="course_id" value="<?= (int)$course_id ?>">
      <input type="hidden" name="area" value="<?= h($AREA) ?>">
      <div class="modal-header">
        <h5 class="modal-title">Shto seksion</h5>
        <button class="btn-close"
                data-bs-dismiss="modal"
                type="button"></button>
      </div>
      <div class="modal-body">
        <div class="mb-3">
          <label class="form-label">Titulli</label>
          <input class="form-control"
                 name="title"
                 required
                 maxlength="255">
        </div>
        <div class="mb-3">
          <label class="form-label">Përshkrimi (Markdown, opsional)</label>
          <textarea class="form-control"
                    rows="3"
                    name="description_md"
                    id="md-text-sec-new"
                    data-preview="#md-prev-sec-new"></textarea>
          <div id="md-prev-sec-new"
               class="border rounded p-2 bg-body-tertiary small mt-2"></div>
        </div>
      </div>
      <div class="modal-footer">
        <button class="btn btn-secondary"
                type="button"
                data-bs-dismiss="modal">Anulo</button>
        <button class="btn btn-primary only-edit"
                type="submit">
          <i class="bi bi-save me-1"></i>Ruaj
        </button>
      </div>
    </form>
  </div>
</div>

<!-- MODAL: COPY SECTIONS (GLOBAL) -->
<div class="modal fade"
     id="copySectionsModal"
     tabindex="-1"
     aria-hidden="true">
  <div class="modal-dialog modal-lg">
    <form class="modal-content"
          method="post"
          action="copy_sections.php">
      <input type="hidden" name="csrf" value="<?= h($CSRF) ?>">
      <input type="hidden" name="course_id" value="<?= (int)$course_id ?>">
      <input type="hidden" name="area" value="<?= h($AREA) ?>">
      <div class="modal-header">
        <h5 class="modal-title">Kopjo seksion(e) nga kurs tjetër</h5>
        <button class="btn-close"
                data-bs-dismiss="modal"
                type="button"></button>
      </div>
      <div class="modal-body">
        <div class="mb-3">
          <label class="form-label">Kursi burim</label>
          <select class="form-select"
                  name="source_course_id"
                  required
                  id="cs-course">
            <option value="">— Zgjidh kursin —</option>
            <?php foreach ($otherCourses as $oc): ?>
              <option value="<?= (int)$oc['id'] ?>">
                <?= h($oc['title'] ?? '') ?>
              </option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="mb-3">
          <label class="form-label">Seksionet për kopjim</label>
          <select class="form-select"
                  name="source_section_ids[]"
                  id="cs-sections"
                  multiple
                  size="8"
                  required>
            <option value="">— Së pari zgjidh kursin —</option>
          </select>
          <div class="form-text">
            Seksionet e kopjuara do krijohen në fund. (Mund t’i fshehësh më pas nëse dëshiron.)
          </div>
        </div>
      </div>
      <div class="modal-footer">
        <button class="btn btn-secondary"
                type="button"
                data-bs-dismiss="modal">Anulo</button>
        <button class="btn btn-primary"
                type="submit">
          <i class="bi bi-folder-symlink me-1"></i>Kopjo seksionet
        </button>
      </div>
    </form>
  </div>
</div>

<!-- FAB mini -->
<div class="km-mat-fab-col" id="fabCol">
  <button class="km-mat-fab"
          data-action="toggleEdit"
          title="Edit Mode"
          type="button">
    <i class="bi bi-sliders"></i>
  </button>
  <button class="km-mat-fab"
          data-action="section"
          title="Shto seksion"
          type="button">
    <i class="bi bi-folder-plus"></i>
  </button>
  <button class="km-mat-fab"
          data-action="lesson"
          title="Shto leksion"
          type="button">
    <i class="bi bi-journal-plus"></i>
  </button>
  <button class="km-mat-fab"
          data-action="assignment"
          title="Shto detyrë"
          type="button">
    <i class="bi bi-clipboard-plus"></i>
  </button>
  <button class="km-mat-fab"
          data-action="quiz"
          title="Shto quiz"
          type="button">
    <i class="bi bi-patch-plus"></i>
  </button>
  <?php if ($otherCourses): ?>
    <button class="km-mat-fab"
            data-action="copysections"
            title="Kopjo seksion(e)"
            type="button">
      <i class="bi bi-folder-symlink"></i>
    </button>
    <button class="km-mat-fab"
            data-action="copyitem"
            title="Kopjo element"
            type="button">
      <i class="bi bi-files"></i>
    </button>
  <?php endif; ?>
</div>

<script>
(function(){
  const root = document.body;

  const cfg     = window.KM_MAT_CONFIG || { csrf: '', courseId: 0, area: 'MATERIALS' };
  const csrf    = cfg.csrf || '';
  const courseId= cfg.courseId || 0;
  const AREA    = cfg.area || 'MATERIALS';

  const sectionsByCourse = window.KM_SECTIONS_BY_COURSE || {};
  const listsByCourse    = window.KM_LISTS_BY_COURSE || {
    LESSON: {}, ASSIGNMENT: {}, QUIZ: {}, TEXT: {}
  };

  let editOn = localStorage.getItem('materials.edit') === '1';

  const secList = document.getElementById('sectionsList');
  const lists   = Array.from(document.querySelectorAll('#sectionsList .km-mat-items'));
  const listSortables = [];
  let secSortable = null;

  /* ========================
     Sortable (drag & drop)
     ======================== */
  function collectOrders(from = null, to = null){
    const payload = { csrf, course_id: courseId, area: AREA, moves: [] };
    const consider = lists.filter(el => !from && !to ? true : (el === from || el === to));
    consider.forEach(list => {
      const sid   = parseInt(list.getAttribute('data-section-id') || '0', 10) || 0;
      const order = Array.from(list.querySelectorAll('.km-mat-draggable'))
                         .map(d => parseInt(d.getAttribute('data-si-id') || '0', 10));
      payload.moves.push({ section_id: sid, order });
    });
    return payload;
  }

  function initSortables(){
    // Items
    lists.forEach(list => {
      const s = Sortable.create(list, {
        handle: '.km-mat-drag-handle',
        animation: 150,
        group: 'materials',
        disabled: !editOn,
        onEnd: (evt) => {
          const payload = collectOrders(evt.from, evt.to);
          fetch('materials_reorder.php', {
            method: 'POST',
            headers: {'Content-Type':'application/json'},
            body: JSON.stringify(payload)
          })
          .then(r => r.json())
          .then(j => {
            if (j.ok) {
              showToast('success','Renditja u ruajt.');
              recomputeSubsections();
            } else {
              showToast('danger', j.error || 'S’u ruajt renditja.');
            }
          })
          .catch(() => showToast('danger','Gabim rrjeti.'));
        }
      });
      listSortables.push(s);
    });

    // Sections
    if (secList){
      secSortable = Sortable.create(secList, {
        handle: '.km-mat-sec-drag',
        animation: 150,
        draggable: '.km-mat-sec-card:not(.km-mat-sec-static)',
        disabled: !editOn,
        onEnd: () => {
          const order = Array.from(secList.querySelectorAll('.km-mat-sec-card'))
            .map(el => parseInt(el.getAttribute('data-sec') || '0', 10))
            .filter(id => id > 0);
          fetch('sections/sections_reorder.php', {
            method: 'POST',
            headers: {'Content-Type':'application/json'},
            body: JSON.stringify({ csrf, course_id: courseId, area: AREA, order })
          })
          .then(r => r.json())
          .then(j => {
            if (j.ok) {
              showToast('success','Renditja e seksioneve u ruajt.');
            } else {
              showToast('danger', j.error || 'S’u ruajt renditja.');
            }
          })
          .catch(() => showToast('danger','Gabim rrjeti.'));
        }
      });
    }
  }

  function setEdit(on){
    editOn = !!on;
    root.classList.toggle('km-mat-edit', editOn);
    localStorage.setItem('materials.edit', editOn ? '1' : '0');
    listSortables.forEach(s => s.option('disabled', !editOn));
    if (secSortable) secSortable.option('disabled', !editOn);
  }

  initSortables();
  setEdit(editOn);

  /* ========================
     Markdown preview
     ======================== */
  function bindMarkedPreview(){
    document.querySelectorAll('textarea[data-preview]').forEach(ta => {
      const sel = ta.getAttribute('data-preview');
      const out = document.querySelector(sel);
      if (!out) return;
      const update = () => {
        const html = (window.marked ? marked.parse(ta.value || '') : (ta.value || ''));
        out.innerHTML = (window.DOMPurify ? DOMPurify.sanitize(html) : html);
      };
      ta.addEventListener('input', update);
      update();
    });
  }
  bindMarkedPreview();

  /* ========================
     Navigimi anësor
     ======================== */
  document.querySelectorAll('.km-mat-nav .km-mat-nav-section > .km-mat-nav-toggle')
    .forEach(btn => {
      btn.addEventListener('click', () => {
        btn.parentElement.classList.toggle('open');
      });
    });

  document.querySelectorAll('.km-mat-nav a.km-mat-nav-item').forEach(a => {
    a.addEventListener('click', (e) => {
      const hash = a.getAttribute('href');
      if (!hash || !hash.startsWith('#')) return;
      const target = document.querySelector(hash);
      if (!target) return;
      const card = target.closest('.km-mat-sec-card');
      const secBody = card ? card.querySelector('.km-mat-sec-body') : null;
      if (secBody && secBody.classList.contains('collapse')) {
        const c = bootstrap.Collapse.getOrCreateInstance(secBody, { toggle:false });
        c.show();
      }
      document.querySelectorAll('.km-mat-nav a.km-mat-nav-item.active')
              .forEach(x => x.classList.remove('active'));
      a.classList.add('active');
    });
  });

  // ScrollSpy / highlight nav gjatë scroll
  (function(){
    const anchors = Array
      .from(document.querySelectorAll('#rightCol .anchor-target'))
      .filter(el => el.id);

    const navLinks = new Map(
      anchors.map(a => {
        const link = document.querySelector(`.km-mat-nav a.km-mat-nav-item[href="#${CSS.escape(a.id)}"]`);
        return [a.id, link];
      }).filter(([,el]) => !!el)
    );

    function ensureInView(el){
      if (!el) return;
      const parent = document.getElementById('navBox');
      if (!parent) return;
      const pr = parent.getBoundingClientRect();
      const er = el.getBoundingClientRect();
      if (er.top < pr.top + 60 || er.bottom > pr.bottom - 60) {
        el.scrollIntoView({ block: 'nearest', inline: 'nearest' });
      }
    }

    function openSecForAnchor(anchorId){
      const card = document.getElementById(anchorId)?.closest('.km-mat-sec-card');
      const sid = card ? card.getAttribute('data-sec') : null;
      if (!sid) return;
      const navSec = document.querySelector(`.km-mat-nav .km-mat-nav-section[data-sec="${CSS.escape(sid)}"]`);
      if (navSec && !navSec.classList.contains('open')) {
        navSec.classList.add('open');
      }
    }

    const io = new IntersectionObserver((entries) => {
      let best = null;
      let bestTop = Infinity;
      entries.forEach(e => {
        if (e.isIntersecting) {
          const top = e.target.getBoundingClientRect().top;
          if (top >= 0 && top < bestTop) {
            bestTop = top;
            best = e.target.id;
          }
        }
      });
      if (!best) return;
      document.querySelectorAll('.km-mat-nav a.km-mat-nav-item.active')
              .forEach(el => el.classList.remove('active'));
      const link = navLinks.get(best);
      if (link){
        link.classList.add('active');
        openSecForAnchor(best);
        ensureInView(link);
      }
    }, { root:null, rootMargin:'0px 0px -60% 0px', threshold:[0,0.1] });

    anchors.forEach(a => io.observe(a));
  })();

  /* ========================
     Nënseksionet (TEXT divider)
     ======================== */
  function recomputeSubsections(){
    document.querySelectorAll('#sectionsList .km-mat-items').forEach(list => {
      let inSub = false;
      list.querySelectorAll('.km-mat-elem').forEach(el => {
        const type = el.getAttribute('data-type');
        if (type === 'TEXT') {
          const isDivider = el.getAttribute('data-subsection') === '1';
          if (isDivider) {
            inSub = true;
            el.classList.add('km-mat-subsection-start');
            el.classList.remove('km-mat-subchild');
          } else {
            el.classList.toggle('km-mat-subchild', inSub);
            el.classList.remove('km-mat-subsection-start');
          }
          return;
        }
        el.classList.toggle('km-mat-subchild', inSub);
      });
    });
  }
  recomputeSubsections();

  /* ========================
     Guard për Edit Mode OFF
     ======================== */
  document.addEventListener('click', (ev) => {
    if (!editOn) {
      const el = ev.target && ev.target.closest('.only-edit, .km-mat-item-actions button, .km-mat-sec-gear button, .km-mat-fab:not([data-action="toggleEdit"])');
      if (el) {
        ev.preventDefault();
        ev.stopPropagation();
        showToast('warning','Aktivo “Edit Mode” për të kryer ndryshime.');
      }
    }
  }, true);

  /* ========================
     Edit / Delete një element
     ======================== */
  document.addEventListener('click', (ev) => {
    const btn = ev.target && ev.target.closest('[data-action="edit-item"], [data-action="delete-item"]');
    if (!btn) return;

    const item = btn.closest('.km-mat-elem');
    if (!item) return;
    if (!editOn) return;

    const action = btn.getAttribute('data-action') || '';
    const type = (item.getAttribute('data-type') || '').toUpperCase();
    const siId = parseInt(item.getAttribute('data-si-id') || '0', 10) || 0;
    const refId = parseInt(item.getAttribute('data-ref-id') || '0', 10) || 0;

    if (action === 'edit-item') {
      if (type === 'LESSON' && refId > 0) {
        location.href = `admin/edit_lesson.php?lesson_id=${encodeURIComponent(refId)}`;
      } else if (type === 'ASSIGNMENT' && refId > 0) {
        location.href = `admin/edit_assignment.php?assignment_id=${encodeURIComponent(refId)}`;
      } else if (type === 'QUIZ' && refId > 0) {
        location.href = `admin/quiz_builder.php?quiz_id=${encodeURIComponent(refId)}`;
      } else if (type === 'TEXT') {
        const modal = document.getElementById(`editTextModal-${siId}`);
        if (modal) bootstrap.Modal.getOrCreateInstance(modal).show();
      } else {
        showToast('warning','S’u gjet destinacioni i editimit.');
      }
      return;
    }

    if (action === 'delete-item') {
      if (siId <= 0) {
        showToast('warning','Element i pavlefshëm.');
        return;
      }
      const msg = btn.getAttribute('data-confirm') || 'Fshi elementin?';
      if (!confirm(msg)) return;

      fetch('materials_bulk.php', {
        method:'POST',
        headers:{'Content-Type':'application/json'},
        body: JSON.stringify({
          csrf,
          course_id: courseId,
          area: AREA,
          action: 'delete',
          si_ids: [siId]
        })
      })
      .then(r => r.json())
      .then(j => {
        if (j.ok) location.reload();
        else showToast('danger', j.error || 'S’u krye fshirja.');
      })
      .catch(() => showToast('danger','Gabim rrjeti.'));
    }
  });

  /* ========================
     Delete section
     ======================== */
  document.addEventListener('click', (ev) => {
    const btn = ev.target && ev.target.closest('[data-action="delete-section"]');
    if (!btn) return;
    if (!editOn) return;

    const sid = parseInt(btn.getAttribute('data-sec-id') || '0', 10) || 0;
    if (sid <= 0) {
      showToast('warning','Seksioni i pavlefshëm.');
      return;
    }

    const msg = btn.getAttribute('data-confirm') || 'Fshi seksionin?';
    if (!confirm(msg)) return;

    const fd = new FormData();
    fd.append('csrf', csrf);
    fd.append('action', 'delete');
    fd.append('course_id', String(courseId));
    fd.append('section_id', String(sid));

    fetch('sections/section_actions.php', { method:'POST', body: fd })
      .then(r => r.ok ? location.reload() : Promise.reject())
      .catch(() => showToast('danger','S’u fshi seksioni.'));
  });

  /* ========================
     Toggle i shpejtë i dukshmërisë
     ======================== */
  function toggleSectionVisibility(sectionId, currentlyHidden){
    if (!csrf || !courseId) {
      showToast('danger','Konfigurimi i kursit mungon.');
      return;
    }
    const fd = new FormData();
    fd.append('csrf', csrf);
    fd.append('action', currentlyHidden ? 'unhide' : 'hide');
    fd.append('area', AREA);
    fd.append('section_id', String(sectionId));
    fd.append('course_id', String(courseId));
    fetch('sections/section_actions.php', { method:'POST', body: fd })
      .then(r => r.ok ? location.reload() : Promise.reject())
      .catch(() => showToast('danger','S’u përditësua dukshmëria.'));
  }

  document.querySelectorAll('.km-mat-sec-quick-toggle').forEach(btn => {
    btn.addEventListener('click', () => {
      const sid = parseInt(btn.getAttribute('data-sec-id') || '0', 10);
      const hid = (btn.getAttribute('data-hidden') || '0') === '1';
      if (!sid) return;
      toggleSectionVisibility(sid, hid);
    });
  });

  /* ========================
     FAB actions
     ======================== */
  let lastSectionId = null;
  document.querySelectorAll('.km-mat-sec-card').forEach(card => {
    card.addEventListener('click', () => {
      const sid = parseInt(card.getAttribute('data-sec') || '0', 10);
      if (sid > 0) lastSectionId = sid;
    }, true);
  });

  function chooseSectionId(){
    if (lastSectionId) return lastSectionId;
    const first = document.querySelector('.km-mat-sec-card[data-sec]:not(.km-mat-sec-static)');
    return first ? parseInt(first.getAttribute('data-sec') || '0', 10) : 0;
  }

  function goAdd(kind){
    if (kind === 'toggleEdit') {
      setEdit(!editOn);
      return;
    }
    if (!editOn) return;

    if (kind === 'section') {
      const m = document.getElementById('addSectionModal');
      if (m) bootstrap.Modal.getOrCreateInstance(m).show();
      return;
    }
    if (kind === 'copysections') {
      const m = document.getElementById('copySectionsModal');
      if (m) bootstrap.Modal.getOrCreateInstance(m).show();
      return;
    }
    if (kind === 'copyitem') {
      const sid = chooseSectionId();
      if (sid > 0) {
        const m = document.getElementById(`copyItemModal-${sid}`);
        if (m) {
          bootstrap.Modal.getOrCreateInstance(m).show();
          return;
        }
      }
      showToast('warning','Zgjidh ose kliko një seksion përpara.');
      return;
    }

    const sid = chooseSectionId();
    let base;
    if (kind === 'lesson') {
      base = 'admin/add_lesson.php';
    } else if (kind === 'assignment') {
      base = 'admin/add_assignment.php';
    } else if (kind === 'quiz') {
      base = 'admin/add_quiz.php';
    } else {
      return;
    }

    const qs = `course_id=${encodeURIComponent(courseId)}&section_id=${encodeURIComponent(sid)}`;
    location.href = `${base}?${qs}`;
  }

  document.querySelectorAll('.km-mat-fab').forEach(btn => {
    btn.addEventListener('click', () => {
      const action = btn.getAttribute('data-action');
      goAdd(action);
    });
  });

    // Klikimet në kartat "Shto Leksion/Detyrë/Quiz" brenda çdo seksioni
  document.querySelectorAll('.km-mat-add-block-card[data-action]').forEach(card => {
    card.addEventListener('click', () => {
      const kind = card.getAttribute('data-action'); // lesson / assignment / quiz
      goAdd(kind);
    });
  });

  /* ========================
     Bulk selections
     ======================== */
  const selItemBoxes = () =>
    Array.from(document.querySelectorAll('.km-mat-sel-item'));
  const selSecBoxes = () =>
    Array.from(document.querySelectorAll('.km-mat-sel-section'));

  const bulkMatBar   = document.getElementById('bulkMaterialsBar');
  const bulkSecBar   = document.getElementById('bulkSectionsBar');
  const bulkMatCount = document.getElementById('bulkMatCount');
  const bulkSecCount = document.getElementById('bulkSecCount');

  function updateBulkBars(){
    const itemsSel = selItemBoxes()
      .filter(b => b.checked)
      .map(b => parseInt(b.getAttribute('data-si-id') || '0', 10));
    const secsSel = selSecBoxes()
      .filter(b => b.checked)
      .map(b => parseInt(b.getAttribute('data-sec-id') || '0', 10));

    bulkMatCount.textContent = itemsSel.length;
    bulkSecCount.textContent = secsSel.length;

    const matOn = itemsSel.length > 0;
    const secOn = secsSel.length > 0;

    if (bulkMatBar) {
      bulkMatBar.setAttribute('aria-hidden', matOn ? 'false' : 'true');
      bulkMatBar.classList.toggle('d-none', !matOn);
    }
    if (bulkSecBar) {
      bulkSecBar.setAttribute('aria-hidden', secOn ? 'false' : 'true');
      bulkSecBar.classList.toggle('d-none', !secOn);
    }
  }
  document.addEventListener('change', (e) => {
    const t = e.target;
    if (!t) return;
    if (t.classList && (t.classList.contains('km-mat-sel-item') || t.classList.contains('km-mat-sel-section'))) {
      updateBulkBars();
    }
  });
  updateBulkBars();

  // Bulk move/delete materials
  const bulkMoveBtn   = document.getElementById('bulkMoveBtn');
  const bulkDelMatBtn = document.getElementById('bulkDelMatBtn');

  if (bulkMoveBtn) {
    bulkMoveBtn.addEventListener('click', () => {
      if (!editOn) return;
      const ids = selItemBoxes()
        .filter(b => b.checked)
        .map(b => parseInt(b.getAttribute('data-si-id') || '0', 10))
        .filter(x => x > 0);
      const targetSel = document.getElementById('bulkMoveTarget');
      const target = targetSel
        ? parseInt(targetSel.value || 'NaN', 10)
        : NaN;
      if (!ids.length || Number.isNaN(target)) {
        showToast('warning','Zgjidh materialet dhe seksionin target.');
        return;
      }
      fetch('materials_bulk.php', {
        method:'POST',
        headers:{'Content-Type':'application/json'},
        body: JSON.stringify({
          csrf,
          course_id: courseId,
          area: AREA,
          action: 'move',
          si_ids: ids,
          target_section_id: target
        })
      })
      .then(r => r.json())
      .then(j => {
        if (j.ok) location.reload();
        else showToast('danger', j.error || 'S’u krye zhvendosja.');
      })
      .catch(() => showToast('danger','Gabim rrjeti.'));
    });
  }

  if (bulkDelMatBtn) {
    bulkDelMatBtn.addEventListener('click', () => {
      if (!editOn) return;
      if (!confirm('Fshi materialet e përzgjedhura?')) return;
      const ids = selItemBoxes()
        .filter(b => b.checked)
        .map(b => parseInt(b.getAttribute('data-si-id') || '0', 10))
        .filter(x => x > 0);
      if (!ids.length) {
        showToast('warning','Asnjë material i përzgjedhur.');
        return;
      }
      fetch('materials_bulk.php', {
        method:'POST',
        headers:{'Content-Type':'application/json'},
        body: JSON.stringify({
          csrf,
          course_id: courseId,
          area: AREA,
          action: 'delete',
          si_ids: ids
        })
      })
      .then(r => r.json())
      .then(j => {
        if (j.ok) location.reload();
        else showToast('danger', j.error || 'S’u krye fshirja.');
      })
      .catch(() => showToast('danger','Gabim rrjeti.'));
    });
  }

  // Bulk actions for sections
  document.querySelectorAll('#bulkSectionsBar [data-sec-action]').forEach(btn => {
    btn.addEventListener('click', () => {
      if (!editOn) return;
      const act = btn.getAttribute('data-sec-action');
      const ids = selSecBoxes()
        .filter(b => b.checked)
        .map(b => parseInt(b.getAttribute('data-sec-id') || '0', 10))
        .filter(x => x > 0);

      if (!ids.length) {
        showToast('warning','Asnjë seksion i përzgjedhur.');
        return;
      }
      if (act === 'delete' &&
          !confirm('Fshi seksionet e përzgjedhura bashkë me materialet e tyre?')) {
        return;
      }
      fetch('sections/sections_bulk.php', {
        method:'POST',
        headers:{'Content-Type':'application/json'},
        body: JSON.stringify({
          csrf,
          course_id: courseId,
          area: AREA,
          action: act,
          section_ids: ids
        })
      })
      .then(r => r.json())
      .then(j => {
        if (j.ok) location.reload();
        else showToast('danger', j.error || 'S’u krye veprimi.');
      })
      .catch(() => showToast('danger','Gabim rrjeti.'));
    });
  });

  /* ========================
     COPY SECTIONS modal
     ======================== */
  (function(){
    const selCourse = document.getElementById('cs-course');
    const selSecs   = document.getElementById('cs-sections');
    if (!selCourse || !selSecs) return;

    function refill(){
      const cid = parseInt(selCourse.value || '0', 10);
      selSecs.innerHTML = '';
      const list = sectionsByCourse[cid] || [];
      if (!cid || !list.length) {
        const opt = document.createElement('option');
        opt.value = '';
        opt.textContent = cid ? '— Nuk ka seksione në këtë kurs për këtë zonë —'
                              : '— Së pari zgjidh kursin —';
        selSecs.appendChild(opt);
        return;
      }
      list.forEach(s => {
        const opt = document.createElement('option');
        opt.value = s.id;
        opt.textContent = s.title;
        selSecs.appendChild(opt);
      });
    }

    selCourse.addEventListener('change', refill);
  })();

  /* ========================
     COPY ITEM modalët
     ======================== */
  document.querySelectorAll('[id^="copyItemModal-"]').forEach(modal => {
    modal.addEventListener('show.bs.modal', () => {
      const selCourse = modal.querySelector('[data-ci-course]');
      const selKind   = modal.querySelector('[data-ci-kind]');
      const selItem   = modal.querySelector('[data-ci-item]');
      if (!selCourse || !selKind || !selItem) return;

      function refill(){
        const cid  = parseInt(selCourse.value || '0', 10);
        const kind = selKind.value || 'LESSON';
        selItem.innerHTML = '';

        if (!cid) {
          const opt = document.createElement('option');
          opt.value = '';
          opt.textContent = '— Së pari zgjidh kursin —';
          selItem.appendChild(opt);
          return;
        }

        const bucket = (listsByCourse[kind] || {});
        const list = bucket[cid] || [];
        if (!list.length) {
          const opt = document.createElement('option');
          opt.value = '';
          opt.textContent = '— S’ka elemente për këtë lloj —';
          selItem.appendChild(opt);
          return;
        }

        list.forEach(x => {
          const opt = document.createElement('option');
          opt.value = x.id;
          opt.textContent = x.title || (`#${x.id}`);
          selItem.appendChild(opt);
        });
      }

      selCourse.addEventListener('change', refill);
      selKind.addEventListener('change', refill);
      refill();
    });
  });

  /* ========================
     Toast helper
     ======================== */
  window.showToast = function(kind, msg){
    try {
      let container = document.getElementById('kmMatToastContainer');
      if (!container){
        container = document.createElement('div');
        container.id = 'kmMatToastContainer';
        container.style.position = 'fixed';
        container.style.right = '16px';
        container.style.bottom = '16px';
        container.style.zIndex = '1080';
        document.body.appendChild(container);
      }
      const el = document.createElement('div');
      el.className = `toast align-items-center text-bg-${kind} border-0`;
      el.role = 'alert';
      el.ariaLive = 'assertive';
      el.ariaAtomic = 'true';
      el.innerHTML = `
        <div class="d-flex">
          <div class="toast-body">${msg}</div>
          <button type="button" class="btn-close btn-close-white me-2 m-auto"
                  data-bs-dismiss="toast" aria-label="Close"></button>
        </div>`;
      container.appendChild(el);
      const t = new bootstrap.Toast(el, { delay: 3000 });
      t.show();
      el.addEventListener('hidden.bs.toast', () => el.remove());
    } catch(e){
      alert(msg);
    }
  };

})();
</script>
