<?php
/**
 * materials.php — Pamja për studentin e materialeve të kursit (pa mjete editimi)
 * - Përdor skemën sections/section_items kur ekziston.
 * - Ka fallback të sigurt kur mungojnë rreshta në section_items.
 * - “Shëno si lexuar” i skopuar me area='MATERIALS' (vetëm për AJAX, jo në DB).
 *
 * Kërkon:   $pdo, $course_id
 * Opsionale: $CSRF, $Parsedown
 */
declare(strict_types=1);

if (!isset($pdo, $course_id)) {
  die('materials.php: scope missing');
}
if (session_status() !== PHP_SESSION_ACTIVE) {
  @session_start();
}

$ME_ID = (int)($_SESSION['user']['id'] ?? 0);
$CSRF  = (string)($CSRF ?? ($_SESSION['csrf'] ?? ''));

/* ====================== Helpers ====================== */
if (!function_exists('h')) {
  function h(?string $s): string {
    return htmlspecialchars((string)$s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
  }
}

if (!function_exists('normalize_category')) {
  function normalize_category(?string $cat): string {
    $key = mb_strtoupper(trim((string)$cat), 'UTF-8');
    $map = ['LEKSION','VIDEO','LINK','FILE','REFERENCA','LAB','TJETER','QUIZ','USHTRIME','PROJEKTE'];
    return in_array($key, $map, true)
      ? $key
      : ($key ? ucfirst(mb_strtolower($key,'UTF-8')) : 'TJETER');
  }
}

if (!function_exists('materials_cat_meta')) {
  function materials_cat_meta(string $cat): array {
    static $map = [
      'LEKSION'   => ['bi-journal-text',   '#0f6cbf'],
      'VIDEO'     => ['bi-camera-video',   '#dc3545'],
      'LINK'      => ['bi-link-45deg',     '#6f42c1'],
      'FILE'      => ['bi-file-earmark',   '#28a745'],
      'REFERENCA' => ['bi-bookmark',       '#198754'],
      'QUIZ'      => ['bi-patch-question', '#20c997'],
      'LAB'       => ['bi-flask',          '#8b5cf6'],
      'USHTRIME'  => ['bi-pencil-square',  '#ffc107'],
      'PROJEKTE'  => ['bi-kanban',         '#0d6efd'],
      'TJETER'    => ['bi-collection',     '#6c757d'],
    ];
    $u = normalize_category($cat);
    return $map[$u] ?? $map['TJETER'];
  }
}

/**
 * Butoni “Shëno si lexuar” (data-area = 'MATERIALS' vetëm për AJAX)
 */
if (!function_exists('render_mark_btn_scoped')) {
  function render_mark_btn_scoped(
    string $type,
    int $id,
    bool $isRead,
    int $course_id,
    string $csrf,
    string $area = 'MATERIALS'
  ): string {
    // përdorim klasa tona, jo Bootstrap state
    $cls   = $isRead ? 'km-mat-btn-read' : 'km-mat-btn-unread';
    $label = $isRead ? 'Lexuar' : 'Shëno si lexuar';
    $icon  = $isRead ? 'bi-check2-circle' : 'bi-bookmark-check';
    $aria  = $isRead ? 'true' : 'false';

    return '<button type="button" class="mark-read-btn '.$cls.'"
                    data-area="'.h($area).'"
                    data-type="'.h($type).'"
                    data-id="'.(int)$id.'"
                    data-course="'.(int)$course_id.'"
                    data-csrf="'.h($csrf).'"
                    aria-pressed="'.$aria.'">
              <i class="bi '.$icon.' me-1"></i>'.$label.'
            </button>';
  }
}

if (!function_exists('table_exists')) {
  function table_exists(PDO $pdo, string $t): bool {
    try {
      $pdo->query("SELECT 1 FROM `{$t}` LIMIT 1");
      return true;
    } catch (Throwable $e) {
      return false;
    }
  }
}

if (!function_exists('table_has_column')) {
  function table_has_column(PDO $pdo, string $table, string $column): bool {
    try {
      $st = $pdo->query("SHOW COLUMNS FROM `{$table}` LIKE " . $pdo->quote($column));
      return (bool)$st->fetch(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
      return false;
    }
  }
}

/* ====================== Përgatitja e skemës ====================== */
$AREA = 'MATERIALS'; // vetëm për AJAX/user_reads, jo kolonë në DB

$SECTIONS_EXISTS   = table_exists($pdo, 'sections');
$SI_EXISTS         = table_exists($pdo, 'section_items');
$SECTIONS_HAS_AREA = $SECTIONS_EXISTS && table_has_column($pdo, 'sections', 'area');       // për kompatibilitet me DB e vjetër
$SI_HAS_AREA       = $SI_EXISTS       && table_has_column($pdo, 'section_items', 'area');   // për kompatibilitet

// Në skemën e re NUK kemi area, por përdorim section_items nëse ekziston tabela
$USE_SI = $SI_EXISTS;

/* File i parë (lesson_files) për leksionet me kategori FILE */
$fileMap = [];
if (table_exists($pdo, 'lesson_files')) {
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
  } catch (Throwable $e) {
    // ignore
  }
}

/* ====================== user_reads (LESSON / ASSIGNMENT / QUIZ) ====================== */
$readLessons     = [];
$readAssignments = [];
$readQuizzes     = [];

if ($ME_ID) {
  try {
    $q = $pdo->prepare("SELECT item_type, item_id FROM user_reads WHERE user_id = ?");
    $q->execute([$ME_ID]);
    foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $r) {
      $t  = strtoupper((string)$r['item_type']);
      $id = (int)$r['item_id'];
      if ($t === 'LESSON')        $readLessons[$id]     = true;
      elseif ($t === 'ASSIGNMENT') $readAssignments[$id] = true;
      elseif ($t === 'QUIZ')      $readQuizzes[$id]     = true;
    }
  } catch (Throwable $e) {
    // ignore
  }
}

/* ====================== Seksionet ====================== */
$sections = [];
try {
  if ($SECTIONS_EXISTS) {
    if ($SECTIONS_HAS_AREA) {
      // DB e vjetër me kolonën area => filtro vetëm area=MATERIALS
      $stmtSec = $pdo->prepare("
        SELECT id, course_id, title, description, position, hidden, highlighted
        FROM sections
        WHERE course_id = ?
          AND UPPER(area) = 'MATERIALS'
          AND COALESCE(hidden,0) = 0
        ORDER BY position ASC, id ASC
      ");
      $stmtSec->execute([$course_id]);
    } else {
      // Skema e re pa area => merr të gjitha seksionet jo të fshehura të kursit
      $stmtSec = $pdo->prepare("
        SELECT id, course_id, title, description, position, hidden, highlighted
        FROM sections
        WHERE course_id = ?
          AND COALESCE(hidden,0) = 0
        ORDER BY position ASC, id ASC
      ");
      $stmtSec->execute([$course_id]);
    }
    $sections = $stmtSec->fetchAll(PDO::FETCH_ASSOC) ?: [];
  }
} catch (Throwable $e) {
  $sections = [];
}

/* A ka elemente jashtë seksioneve në section_items? (section_id=0) */
$hasUnsectioned = false;
if ($USE_SI) {
  try {
    $sql = "
      SELECT COUNT(*)
      FROM section_items
      WHERE course_id = ?
        AND section_id = 0
    ";
    // Në DB e vjetër, filtro edhe area=MATERIALS
    if ($SI_HAS_AREA) {
      $sql = "
        SELECT COUNT(*)
        FROM section_items
        WHERE course_id = ?
          AND UPPER(area) = 'MATERIALS'
          AND section_id = 0
      ";
    }

    $stmt = $pdo->prepare($sql);
    $stmt->execute([$course_id]);
    $hasUnsectioned = ((int)$stmt->fetchColumn() > 0);
  } catch (Throwable $e) {
    $hasUnsectioned = false;
  }
}

/* allSections = seksionet ekzistuese (+ “Jashtë seksioneve” nëse duhen) */
$allSections = $sections;
if ($hasUnsectioned) {
  array_unshift($allSections, [
    'id'          => 0,
    'title'       => 'Jashtë seksioneve',
    'description' => 'Elemente pa seksion.',
    'position'    => 0,
    'hidden'      => 0,
    'highlighted' => 0,
  ]);
}

/* ====================== ITEMS nga section_items ====================== */
$itemsBySection = [];  // [section_id] => array of rows from query

if ($USE_SI && $allSections) {
  $secIds = array_map(fn($s) => (int)$s['id'], $allSections);
  $ph     = implode(',', array_fill(0, count($secIds), '?'));

  $sql = "
    SELECT
      si.*,
      l.id              AS l_id,
      l.title           AS l_title,
      l.category        AS l_cat,
      l.uploaded_at     AS l_up,
      COALESCE(l.URL, l.url) AS l_url,
      a.id              AS a_id,
      a.title           AS a_title,
      a.due_date        AS a_due,
      a.hidden          AS a_hidden,
      q.id              AS q_id,
      q.title           AS q_title,
      q.open_at         AS q_open,
      q.close_at        AS q_close,
      q.time_limit_sec  AS q_tls,
      q.attempts_allowed AS q_attempts,
      q.hidden          AS q_hidden,
      q.status          AS q_status
    FROM section_items si
    LEFT JOIN lessons     l ON si.item_type = 'LESSON'     AND si.item_ref_id = l.id
    LEFT JOIN assignments a ON si.item_type = 'ASSIGNMENT' AND si.item_ref_id = a.id
    LEFT JOIN quizzes     q ON si.item_type = 'QUIZ'       AND si.item_ref_id = q.id
    WHERE si.course_id = ?
      ".($SI_HAS_AREA ? "AND UPPER(si.area) = 'MATERIALS'" : "")."
      AND si.section_id IN ($ph)
      AND COALESCE(si.hidden,0) = 0
    ORDER BY si.section_id ASC, si.position ASC, si.id ASC
  ";

  try {
    $stmtSI = $pdo->prepare($sql);
    $stmtSI->execute(array_merge([$course_id], $secIds));
    while ($r = $stmtSI->fetch(PDO::FETCH_ASSOC)) {
      $type = (string)($r['item_type'] ?? '');

      // Filtrim për studentin
      if ($type === 'ASSIGNMENT' && !empty($r['a_id']) && (int)($r['a_hidden'] ?? 0) === 1) {
        continue;
      }
      if ($type === 'QUIZ' && !empty($r['q_id'])) {
        if ((int)($r['q_hidden'] ?? 0) === 1) continue;
        if (!empty($r['q_status']) && $r['q_status'] !== 'PUBLISHED') continue;
      }

      $sid = (int)($r['section_id'] ?? 0);
      $itemsBySection[$sid][] = $r;
    }
  } catch (Throwable $e) {
    // nëse ka gabim (p.sh. join i vjetër me text_blocks), mos blloko faqen
  }
}

/* ====================== Fallback: kujt i kemi tashmë nga SI? ====================== */
$haveL = [];
$haveA = [];
$haveQ = [];

foreach ($itemsBySection as $secItems) {
  foreach ($secItems as $it) {
    $t = (string)($it['item_type'] ?? '');
    if ($t === 'LESSON'     && !empty($it['l_id'])) $haveL[(int)$it['l_id']] = true;
    if ($t === 'ASSIGNMENT' && !empty($it['a_id'])) $haveA[(int)$it['a_id']] = true;
    if ($t === 'QUIZ'       && !empty($it['q_id'])) $haveQ[(int)$it['q_id']] = true;
  }
}

/* ====================== Fallback LESSONS ====================== */
try {
  $whereNotExists = '';
  if ($USE_SI) {
    $whereNotExists = "
      AND NOT EXISTS (
        SELECT 1 FROM section_items si
        WHERE si.course_id = l.course_id
          ".($SI_HAS_AREA ? "AND UPPER(si.area) = 'MATERIALS'" : "")."
          AND si.item_type = 'LESSON'
          AND si.item_ref_id = l.id
      )";
  }

  $q = $pdo->prepare("
    SELECT
      l.id,
      l.title,
      l.category,
      l.uploaded_at,
      COALESCE(l.URL, l.url) AS URL,
      COALESCE(l.section_id, 0) AS sid
    FROM lessons l
    LEFT JOIN sections s ON s.id = l.section_id
    WHERE l.course_id = ?
      AND UPPER(COALESCE(l.category,'')) <> 'LAB'
      AND COALESCE(s.hidden,0) = 0
      $whereNotExists
    ORDER BY l.id
  ");
  $q->execute([$course_id]);

  while ($r = $q->fetch(PDO::FETCH_ASSOC)) {
    $lid = (int)$r['id'];
    if (!empty($haveL[$lid])) continue; // është tashmë në section_items

    $sid = (int)$r['sid'];
    $itemsBySection[$sid][] = [
      'id'         => 0,
      'section_id' => $sid,
      'item_type'  => 'LESSON',
      'l_id'       => $lid,
      'l_title'    => $r['title'],
      'l_cat'      => $r['category'],
      'l_up'       => $r['uploaded_at'],
      'l_url'      => $r['URL'],
    ];
  }
} catch (Throwable $e) {
  // ignore
}

/* ====================== Fallback ASSIGNMENTS ====================== */
try {
  $whereNotExists = '';
  if ($USE_SI) {
    $whereNotExists = "
      AND NOT EXISTS (
        SELECT 1 FROM section_items si
        WHERE si.course_id = a.course_id
          ".($SI_HAS_AREA ? "AND UPPER(si.area) = 'MATERIALS'" : "")."
          AND si.item_type = 'ASSIGNMENT'
          AND si.item_ref_id = a.id
      )";
  }

  $q = $pdo->prepare("
    SELECT
      a.id,
      a.title,
      a.due_date,
      COALESCE(a.section_id, 0) AS sid,
      COALESCE(a.hidden,0)      AS hidden
    FROM assignments a
    LEFT JOIN sections s ON s.id = a.section_id
    WHERE a.course_id = ?
      AND COALESCE(a.hidden,0) = 0
      AND COALESCE(s.hidden,0) = 0
      $whereNotExists
    ORDER BY a.id
  ");
  $q->execute([$course_id]);

  while ($r = $q->fetch(PDO::FETCH_ASSOC)) {
    $aid = (int)$r['id'];
    if (!empty($haveA[$aid])) continue;

    $sid = (int)$r['sid'];
    $itemsBySection[$sid][] = [
      'id'         => 0,
      'section_id' => $sid,
      'item_type'  => 'ASSIGNMENT',
      'a_id'       => $aid,
      'a_title'    => $r['title'],
      'a_due'      => $r['due_date'],
      'a_hidden'   => $r['hidden'],
    ];
  }
} catch (Throwable $e) {
  // ignore
}

/* ====================== Fallback QUIZZES ====================== */
try {
  $whereNotExists = '';
  if ($USE_SI) {
    $whereNotExists = "
      AND NOT EXISTS (
        SELECT 1 FROM section_items si
        WHERE si.course_id = q.course_id
          ".($SI_HAS_AREA ? "AND UPPER(si.area) = 'MATERIALS'" : "")."
          AND si.item_type = 'QUIZ'
          AND si.item_ref_id = q.id
      )";
  }

  $q = $pdo->prepare("
    SELECT
      q.id,
      q.title,
      q.open_at,
      q.close_at,
      q.status,
      COALESCE(q.section_id, 0) AS sid,
      COALESCE(q.hidden,0)      AS hidden,
      q.time_limit_sec          AS q_tls,
      q.attempts_allowed        AS q_attempts
    FROM quizzes q
    LEFT JOIN sections s ON s.id = q.section_id
    WHERE q.course_id = ?
      AND COALESCE(q.hidden,0) = 0
      AND COALESCE(s.hidden,0) = 0
      $whereNotExists
    ORDER BY q.id
  ");
  $q->execute([$course_id]);

  while ($r = $q->fetch(PDO::FETCH_ASSOC)) {
    $qid = (int)$r['id'];
    if (!empty($haveQ[$qid])) continue;
    if (!empty($r['status']) && $r['status'] !== 'PUBLISHED') continue;

    $sid = (int)$r['sid'];
    $itemsBySection[$sid][] = [
      'id'          => 0,
      'section_id'  => $sid,
      'item_type'   => 'QUIZ',
      'q_id'        => $qid,
      'q_title'     => $r['title'],
      'q_open'      => $r['open_at'],
      'q_close'     => $r['close_at'],
      'q_hidden'    => 0,
      'q_status'    => $r['status'] ?? null,
      'q_tls'       => $r['q_tls'] ?? null,
      'q_attempts'  => $r['q_attempts'] ?? 1,
    ];
  }
} catch (Throwable $e) {
  // ignore
}

/* Nëse nga section_items + fallback kemi gjëra në sid=0 dhe nuk e kemi seksionin “Jashtë seksioneve”, shtoje */
if (!empty($itemsBySection[0])) {
  $hasZero = false;
  foreach ($allSections as $s) {
    if ((int)$s['id'] === 0) {
      $hasZero = true;
      break;
    }
  }
  if (!$hasZero) {
    array_unshift($allSections, [
      'id'          => 0,
      'title'       => 'Jashtë seksioneve',
      'description' => 'Elemente pa seksion.',
      'position'    => 0,
      'hidden'      => 0,
      'highlighted' => 0,
    ]);
  }
}

/* ====================== Submissions për assignments ====================== */
$assignIds = [];
foreach ($itemsBySection as $secItems) {
  foreach ($secItems as $it) {
    if (($it['item_type'] ?? '') === 'ASSIGNMENT' && !empty($it['a_id'])) {
      $assignIds[] = (int)$it['a_id'];
    }
  }
}
$assignIds = array_values(array_unique($assignIds));

$submittedByAssign = [];
if ($ME_ID && $assignIds) {
  try {
    $ph = implode(',', array_fill(0, count($assignIds), '?'));
    $q  = $pdo->prepare("
      SELECT assignment_id, MAX(id) AS submission_id
      FROM assignments_submitted
      WHERE user_id = ? AND assignment_id IN ($ph)
      GROUP BY assignment_id
    ");
    $q->execute(array_merge([$ME_ID], $assignIds));
    foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $r) {
      $submittedByAssign[(int)$r['assignment_id']] = (int)$r['submission_id'];
    }
  } catch (Throwable $e) {
    // ignore
  }
}

/* ====================== Attempts & last result për quize ====================== */
$quizIds = [];
foreach ($itemsBySection as $secItems) {
  foreach ($secItems as $it) {
    if (($it['item_type'] ?? '') === 'QUIZ' && !empty($it['q_id'])) {
      $quizIds[] = (int)$it['q_id'];
    }
  }
}
$quizIds = array_values(array_unique($quizIds));

$attemptsByQuiz   = [];
$lastResultByQuiz = [];

if ($ME_ID && $quizIds) {
  try {
    $ph = implode(',', array_fill(0, count($quizIds), '?'));
    $q  = $pdo->prepare("
      SELECT quiz_id,
             SUM(submitted_at IS NULL)     AS in_progress,
             SUM(submitted_at IS NOT NULL) AS submitted,
             COUNT(*) AS total
      FROM quiz_attempts
      WHERE user_id = ? AND quiz_id IN ($ph)
      GROUP BY quiz_id
    ");
    $q->execute(array_merge([$ME_ID], $quizIds));
    foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $r) {
      $attemptsByQuiz[(int)$r['quiz_id']] = [
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
    $q  = $pdo->prepare("
      SELECT qa.*
      FROM quiz_attempts qa
      JOIN (
        SELECT quiz_id, MAX(submitted_at) AS last_sub
        FROM quiz_attempts
        WHERE user_id = ? AND submitted_at IS NOT NULL AND quiz_id IN ($ph)
        GROUP BY quiz_id
      ) t ON t.quiz_id = qa.quiz_id AND t.last_sub = qa.submitted_at
    ");
    $q->execute(array_merge([$ME_ID], $quizIds));
    foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $r) {
      $lastResultByQuiz[(int)$r['quiz_id']] = $r;
    }
  } catch (Throwable $e) {
    // ignore
  }
}

/* ====================== Statistikat / përmbledhja ====================== */

/* Leksionet */
$lessonIds = [];
foreach ($itemsBySection as $secItems) {
  foreach ($secItems as $it) {
    if (($it['item_type'] ?? '') === 'LESSON' && !empty($it['l_id'])) {
      $lessonIds[] = (int)$it['l_id'];
    }
  }
}
$lessonIds = array_values(array_unique($lessonIds));

$totalLessons   = count($lessonIds);
$readLessonsCnt = $totalLessons ? count(array_intersect($lessonIds, array_keys($readLessons))) : 0;
$lessonsPct     = $totalLessons ? (int)round(($readLessonsCnt / $totalLessons) * 100) : 0;
$unreadLessons  = $totalLessons - $readLessonsCnt;

/* Detyrat në pritje */
$nowTs               = time();
$pendingAssignActive = 0;
foreach ($assignIds as $aid) {
  if (isset($submittedByAssign[$aid])) continue; // tashmë dërguar

  $due = null;
  foreach ($itemsBySection as $secItems) {
    foreach ($secItems as $it) {
      if (($it['item_type'] ?? '') === 'ASSIGNMENT' && (int)$it['a_id'] === $aid) {
        if (!empty($it['a_due'])) {
          $due = strtotime((string)$it['a_due'] . ' 23:59:59');
        }
        break 2;
      }
    }
  }
  if (!$due || $nowTs <= $due) {
    $pendingAssignActive++;
  }
}

$cntAssign    = count($assignIds);
$myAssignDone = $cntAssign ? count(array_intersect($assignIds, array_keys($submittedByAssign))) : 0;
$myAssignPct  = $cntAssign ? (int)round(($myAssignDone / $cntAssign) * 100) : 0;

/* Kuizet në pritje */
$pendingQuizzesActive = 0;
foreach ($quizIds as $qid) {
  $openAt  = null;
  $closeAt = null;
  foreach ($itemsBySection as $secItems) {
    foreach ($secItems as $it) {
      if (($it['item_type'] ?? '') === 'QUIZ' && (int)$it['q_id'] === $qid) {
        $openAt  = !empty($it['q_open'])  ? strtotime((string)$it['q_open'])  : null;
        $closeAt = !empty($it['q_close']) ? strtotime((string)$it['q_close']) : null;
        break 2;
      }
    }
  }
  $isOpen = (!$openAt || $nowTs >= $openAt) && (!$closeAt || $nowTs <= $closeAt);
  if (!$isOpen) continue;

  $meta = $attemptsByQuiz[$qid] ?? ['in_progress' => 0, 'submitted' => 0];
  if ((int)($meta['in_progress'] ?? 0) === 0 && (int)($meta['submitted'] ?? 0) === 0) {
    $pendingQuizzesActive++;
  }
}

$cntQuizzes = count($quizIds);
$myQuizDone = 0;
foreach ($quizIds as $qid) {
  $m = $attemptsByQuiz[$qid] ?? ['submitted' => 0];
  if ((int)($m['submitted'] ?? 0) > 0) {
    $myQuizDone++;
  }
}
$myQuizPct = $cntQuizzes ? (int)round(($myQuizDone / $cntQuizzes) * 100) : 0;

$allOk = ($unreadLessons === 0 && $pendingAssignActive === 0 && $pendingQuizzesActive === 0);

/* ====================== Parsedown ====================== */
if (!isset($Parsedown) || !is_object($Parsedown)) {
  $pdFile = __DIR__ . '/../lib/Parsedown.php';
  if (!is_readable($pdFile)) {
    $pdFile = __DIR__ . '/../lib/Parsedown.php';
  }
  if (is_readable($pdFile)) {
    require_once $pdFile;
    $Parsedown = new Parsedown();
    if (method_exists($Parsedown, 'setSafeMode')) {
      $Parsedown->setSafeMode(true);
    }
  } else {
    // fallback minimal
    $Parsedown = new class {
      public function text($s) { return nl2br(h((string)$s)); }
    };
  }
}
?>

<style>
/* ------------------------------
   Butonat "Shëno si lexuar" / "Lexuar"
   ------------------------------ */
.km-mat-root .km-mat-item-actions {
  display: flex !important;
  align-items: center;
  gap: 0.35rem;
  opacity: 1 !important;
  visibility: visible !important;
  transform: none !important;
}

/* bazë */
.km-mat-root .mark-read-btn {
  font-size: 0.8rem;
  border-radius: 999px;      /* pill */
  padding: 0.2rem 0.75rem;
  display: inline-flex;
  align-items: center;
  gap: 0.25rem;
  line-height: 1.2;
  cursor: pointer;
  border: 1px solid transparent;
  background-color: #e8f1ff;
  color: #1d3a63;
  box-shadow: none;
}

/* gjendja "jo e lexuar" */
.km-mat-root .mark-read-btn.km-mat-btn-unread {
  background-color: #e8f1ff;
  border-color: var(--km-mat-primary, #2A4B7C);
  color: #1d3a63;
}

/* gjendja "Lexuar" */
.km-mat-root .mark-read-btn.km-mat-btn-read {
  background-color: #16a34a;
  border-color: #15803d;
  color: #ffffff;
}

/* hover */
.km-mat-root .mark-read-btn:hover {
  box-shadow: var(--km-mat-shadow, 0 8px 20px rgba(0,0,0,.06));
  transform: translateY(-1px);
}

/* ikona brenda */
.km-mat-root .mark-read-btn i {
  font-size: 0.9em;
  margin-right: 0.2rem;
}
</style>

<div class="km-mat-root">
  <div class="row g-3" id="materialsRow">
    <!-- NAV majtas -->
    <div class="col-12 col-lg-3 d-none d-lg-block" id="leftCol">
      <div class="km-mat-left-col-sticky">
        <aside class="km-mat-nav" id="navBox">
          <div class="d-flex justify-content-between align-items-center mb-2">
            <h6 class="mb-0">
              <i class="bi bi-list-ul me-1"></i>
              Navigimi
            </h6>
          </div>

          <?php if ($allSections): ?>
            <?php foreach ($allSections as $sec):
              $sid   = (int)$sec['id'];
              $items = $itemsBySection[$sid] ?? [];

              $navLessons = [];
              $navAssigns = [];
              $navQuizzes = [];

              foreach ($items as $it) {
                $t = (string)($it['item_type'] ?? '');
                if ($t === 'LESSON'     && !empty($it['l_id'])) $navLessons[] = ['id' => (int)$it['l_id'], 'title' => $it['l_title'] ?? '—'];
                if ($t === 'ASSIGNMENT' && !empty($it['a_id'])) $navAssigns[] = ['id' => (int)$it['a_id'], 'title' => $it['a_title'] ?? '—'];
                if ($t === 'QUIZ'       && !empty($it['q_id'])) $navQuizzes[] = ['id' => (int)$it['q_id'], 'title' => $it['q_title'] ?? '—'];
              }
              $total = count($navLessons) + count($navAssigns) + count($navQuizzes);
            ?>
              <div class="km-mat-nav-section" data-sec="<?= $sid ?>">
                <button type="button" class="km-mat-nav-toggle">
                  <span>
                    <i class="bi bi-folder<?= (int)($sec['hidden'] ?? 0) ? '-x' : '' ?> me-1"></i>
                    <?= h($sec['title']) ?>
                  </span>
                  <span class="badge text-bg-light"><?= $total ?></span>
                </button>
                <ul>
                  <?php if ($sid !== 0): ?>
                    <li>
                      <a class="km-mat-nav-item" href="#sec-<?= $sid ?>">Shko te seksioni</a>
                    </li>
                  <?php endif; ?>

                  <?php foreach ($navLessons as $l): ?>
                    <li>
                      <a class="km-mat-nav-item" href="#lesson-<?= (int)$l['id'] ?>">
                        <i class="bi bi-journal-text me-1"></i><?= h($l['title']) ?>
                      </a>
                    </li>
                  <?php endforeach; ?>

                  <?php foreach ($navAssigns as $a): ?>
                    <li>
                      <a class="km-mat-nav-item" href="#assign-<?= (int)$a['id'] ?>">
                        <i class="bi bi-clipboard-check me-1"></i><?= h($a['title']) ?>
                      </a>
                    </li>
                  <?php endforeach; ?>

                  <?php foreach ($navQuizzes as $qz): ?>
                    <li>
                      <a class="km-mat-nav-item" href="#quiz-<?= (int)$qz['id'] ?>">
                        <i class="bi bi-patch-question me-1"></i><?= h($qz['title']) ?>
                      </a>
                    </li>
                  <?php endforeach; ?>
                </ul>
              </div>
            <?php endforeach; ?>
          <?php else: ?>
            <div class="text-muted small">S’ka ende seksione.</div>
          <?php endif; ?>
        </aside>
      </div>
    </div>

    <!-- Qendra: seksionet & materialet -->
    <div class="col-12 col-lg-6">
      <section class="km-mat-section-accordion vstack gap-3" id="sectionsList">
        <?php if (!$allSections): ?>
          <div class="km-mat-empty text-center py-5 text-muted">
            <i class="bi bi-folder2-open display-6"></i>
            <p class="mt-2 mb-0">Ende nuk ka seksione.</p>
          </div>
        <?php else: ?>
          <?php foreach ($allSections as $sec):
            $sid       = (int)$sec['id'];
            $isVirtual = ($sid === 0);
            $secHidden = (int)($sec['hidden'] ?? 0) === 1;
            $isHi      = (int)($sec['highlighted'] ?? 0) === 1;
            $items     = $itemsBySection[$sid] ?? [];
            $total     = count($items);
          ?>
            <div class="km-mat-sec-card <?= $isHi ? 'km-mat-sec-highlighted' : '' ?> <?= $secHidden ? 'km-mat-sec-hidden' : '' ?>" data-sec="<?= $sid ?>">
              <div class="km-mat-sec-head anchor-target" id="sec-<?= $sid ?>">
                <div class="km-mat-sec-title-line">
                  <i class="bi bi-folder<?= $secHidden ? '-x' : '' ?>"></i>
                  <span class="fw-semibold"><?= h($sec['title']) ?></span>
                  <span class="badge text-bg-light"><?= $total ?> elemente</span>
                  <?php if ($secHidden): ?>
                    <span class="badge km-mat-badge-soft">Fshehur</span>
                  <?php endif; ?>
                  <?php if ($isHi): ?>
                    <span class="badge text-bg-info">I THEKSUAR</span>
                  <?php endif; ?>
                </div>
                <button
                  class="km-mat-sec-toggle"
                  type="button"
                  data-bs-toggle="collapse"
                  data-bs-target="#km-mat-sec-body-<?= $sid ?>"
                  aria-expanded="true"
                  aria-controls="km-mat-sec-body-<?= $sid ?>"
                >
                  <i class="bi bi-chevron-expand"></i>
                </button>
              </div>

              <?php if (!$isVirtual && !empty($sec['description'])): ?>
                <div class="km-mat-sec-desc px-3 pt-2 pb-2 small text-muted">
                  <div class="km-mat-md-body">
                    <?= $Parsedown->text((string)$sec['description']) ?>
                  </div>
                </div>
              <?php endif; ?>

              <div id="km-mat-sec-body-<?= $sid ?>" class="km-mat-sec-body collapse show">
                <?php if ($total === 0): ?>
                  <div class="p-3 text-muted small">S'ka elemente në këtë seksion.</div>
                <?php else: ?>
                  <div class="km-mat-items">
                    <?php foreach ($items as $it):
                      $type   = (string)($it['item_type'] ?? '');
                      $elemId = 'si-' . (int)($it['id'] ?? 0);
                      if     ($type === 'TEXT')       $elemId = 'text-' . (int)($it['id'] ?? 0);
                      elseif ($type === 'LESSON')     $elemId = 'lesson-' . (int)$it['l_id'];
                      elseif ($type === 'ASSIGNMENT') $elemId = 'assign-' . (int)$it['a_id'];
                      elseif ($type === 'QUIZ')       $elemId = 'quiz-' . (int)$it['q_id'];
                    ?>

                      <?php if ($type === 'TEXT'): ?>
                        <?php
                          $contentMd = (string)($it['content_md'] ?? '');
                          $lines     = preg_split('/\R/u', $contentMd);
                          $firstLine = trim($lines[0] ?? '');

                          $subTitle = preg_replace('/^\s{0,3}#{1,6}\s*/u', '', $firstLine);
                          $subTitle = preg_replace('/\[(.*?)\]\([^\)]*\)/u', '$1', $subTitle);
                          $subTitle = trim($subTitle);

                          if ($subTitle === '' && $contentMd !== '') {
                            if (preg_match_all('/[\p{L}\p{N}][\p{L}\p{N}\p{Mn}\p{Pd}\']*/u', $contentMd, $m2)) {
                              $subTitle = implode(' ', array_slice($m2[0], 0, 5));
                            }
                          }

                          $titleWordCount = 0;
                          if ($subTitle !== '') {
                            if (preg_match_all('/[\p{L}\p{N}][\p{L}\p{N}\p{Mn}\p{Pd}\']*/u', $subTitle, $mw)) {
                              $titleWordCount = count($mw[0]);
                            }
                          }
                          $isSubsection = ($titleWordCount >= 1 && $titleWordCount <= 5);
                        ?>
                        <div
                          class="km-mat-elem km-mat-text-block anchor-target"
                          id="<?= h($elemId) ?>"
                          data-subsection="<?= $isSubsection ? '1' : '0' ?>"
                        >
                          <div class="km-mat-elem-left w-100">
                            <div class="km-mat-md-body">
                              <?= $Parsedown->text($contentMd) ?>
                            </div>
                          </div>
                          <div class="km-mat-item-actions"></div>
                        </div>

                      <?php elseif ($type === 'LESSON' && !empty($it['l_id'])): ?>
                        <?php
                          $cat        = normalize_category((string)($it['l_cat'] ?? ''));
                          [$ico, $col] = materials_cat_meta($cat);

                          $href  = "lesson_details.php?lesson_id=" . (int)$it['l_id'];
                          $attrs = '';

                          if ($cat === 'FILE') {
                            if (!empty($fileMap[(int)$it['l_id']])) {
                              $href  = $fileMap[(int)$it['l_id']];
                              $attrs = ' download';
                            }
                          } elseif (in_array($cat, ['VIDEO', 'LINK', 'REFERENCA'], true)) {
                            if (!empty($it['l_url']) && filter_var((string)$it['l_url'], FILTER_VALIDATE_URL)) {
                              $href  = $it['l_url'];
                              $attrs = ' target="_blank" rel="noopener"';
                            }
                          }

                          $lid    = (int)$it['l_id'];
                          $isRead = !empty($readLessons[$lid]);
                        ?>
                        <div class="km-mat-elem anchor-target<?= $isRead ? ' is-read' : '' ?>" id="<?= h($elemId) ?>">
                          <div class="km-mat-elem-left">
                            <div class="km-mat-elem-icon km-mat-elem-icon-rounded" style="--km-mat-icon-bg: <?= h($col) ?>;">
                              <i class="bi <?= h($ico) ?>"></i>
                            </div>
                            <div>
                              <a class="text-decoration-none text-dark" href="<?= h($href) ?>"<?= $attrs ?>>
                                <strong><?= h($it['l_title'] ?? '—') ?></strong>
                              </a>
                              <div class="small text-muted">
                                <?= $cat ?>
                                <?= !empty($it['l_up']) ? ' • ' . date('d M Y, H:i', strtotime((string)$it['l_up'])) : '' ?>
                              </div>
                            </div>
                          </div>
                          <div class="km-mat-item-actions">
                            <?= render_mark_btn_scoped('LESSON', $lid, $isRead, (int)$course_id, $CSRF, $AREA) ?>
                          </div>
                        </div>

                      <?php elseif ($type === 'ASSIGNMENT' && !empty($it['a_id'])): ?>
                        <?php
                          $aid         = (int)$it['a_id'];
                          $isReadA     = !empty($readAssignments[$aid]);
                          $submittedId = $submittedByAssign[$aid] ?? null;
                          $dueTs       = !empty($it['a_due']) ? strtotime((string)$it['a_due'] . ' 23:59:59') : null;
                          $late        = ($dueTs && $nowTs > $dueTs && !$submittedId);
                        ?>
                        <div class="km-mat-elem anchor-target<?= $isReadA ? ' is-read' : '' ?>" id="<?= h($elemId) ?>">
                          <div class="km-mat-elem-left">
                            <div class="km-mat-elem-icon km-mat-elem-icon-rounded" style="--km-mat-icon-bg:#0d6efd;">
                              <i class="bi bi-clipboard-check"></i>
                            </div>
                            <div>
                              <a class="text-decoration-none text-dark" href="assignment_details.php?assignment_id=<?= $aid ?>">
                                <strong><?= h($it['a_title'] ?? '—') ?></strong>
                              </a>
                              <div class="small text-muted">
                                Detyrë •
                                <?= !empty($it['a_due']) ? ('Skadon: ' . date('d M Y', strtotime((string)$it['a_due']))) : 'Pa afat' ?>
                                <?php if ($submittedId): ?>
                                  <span class="badge text-bg-success ms-1">Dërguar</span>
                                <?php elseif ($late): ?>
                                  <span class="badge text-bg-danger ms-1">Afati mbaroi</span>
                                <?php else: ?>
                                  <span class="badge text-bg-warning text-dark ms-1">Në pritje</span>
                                <?php endif; ?>
                              </div>
                            </div>
                          </div>
                          <div class="km-mat-item-actions">
                            <?= render_mark_btn_scoped('ASSIGNMENT', $aid, $isReadA, (int)$course_id, $CSRF, $AREA) ?>
                          </div>
                        </div>

                      <?php elseif ($type === 'QUIZ' && !empty($it['q_id'])): ?>
                        <?php
                          [$ico, $col] = materials_cat_meta('QUIZ');
                          $qid         = (int)$it['q_id'];
                          $isReadQ     = !empty($readQuizzes[$qid]);

                          $openAt  = !empty($it['q_open'])  ? strtotime((string)$it['q_open'])  : null;
                          $closeAt = !empty($it['q_close']) ? strtotime((string)$it['q_close']) : null;
                          $isOpen     = (!$openAt || $nowTs >= $openAt) && (!$closeAt || $nowTs <= $closeAt);
                          $isUpcoming = ($openAt && $nowTs < $openAt);
                          $isClosed   = ($closeAt && $nowTs > $closeAt);

                          $meta      = $attemptsByQuiz[$qid] ?? ['in_progress' => 0, 'submitted' => 0, 'total' => 0];
                          $allowed   = (int)($it['q_attempts'] ?? 1);
                          $unlimited = $allowed <= 0;
                          $canCont   = ((int)($meta['in_progress'] ?? 0) > 0);
                          $canStart  = !$canCont && $isOpen && ($unlimited || ((int)($meta['submitted'] ?? 0) < max(1, $allowed)));
                          $btnLabel  = $canCont ? 'Vazhdo' : ($canStart ? 'Nis' : 'Hyr');
                          $btnDis    = (!$canCont && !$canStart) ? 'disabled' : '';

                          $statusBadge =
                            $isUpcoming ? '<span class="badge text-bg-warning text-dark ms-1">Hapet ' . date('d M Y, H:i', $openAt) . '</span>' :
                            ($isClosed ? '<span class="badge text-bg-secondary ms-1">Mbyllur</span>' :
                                          '<span class="badge text-bg-success ms-1">Hapur</span>');

                          $last     = $lastResultByQuiz[$qid] ?? null;
                          $scoreTxt = '';
                          if ($last && isset($last['total_points'], $last['score'])) {
                            $tp   = (int)$last['total_points'];
                            $sc   = (int)$last['score'];
                            $perc = ($tp > 0) ? round(($sc / $tp) * 100) : null;
                            $scoreTxt =
                              '<span class="badge text-bg-info ms-1">' .
                              '<i class="bi bi-bar-chart-line me-1"></i>' .
                              $sc . ' / ' . $tp . ($perc !== null ? ' (' . $perc . '%)' : '') .
                              '</span>';
                          }

                          $used        = (int)($meta['submitted'] ?? 0);
                          $txtAttempts = $unlimited ? "$used / ∞" : "$used / " . max(1, $allowed);
                        ?>
                        <div class="km-mat-elem anchor-target<?= $isReadQ ? ' is-read' : '' ?>" id="<?= h($elemId) ?>">
                          <div class="km-mat-elem-left">
                            <div class="km-mat-elem-icon km-mat-elem-icon-rounded" style="--km-mat-icon-bg: <?= h($col) ?>;">
                              <i class="bi <?= h($ico) ?>"></i>
                            </div>
                            <div>
                              <strong class="text-dark"><?= h($it['q_title'] ?? '—') ?></strong>
                              <div class="small text-muted">
                                QUIZ •
                                <?= $it['q_open'] ? 'Hapet: ' . date('d M Y, H:i', strtotime((string)$it['q_open'])) : 'Pa datë hapjeje' ?> •
                                <?= $it['q_close'] ? 'Mbyllet: ' . date('d M Y, H:i', strtotime((string)$it['q_close'])) : 'Pa datë mbylljeje' ?>
                                <?= $statusBadge ?>
                                <span class="badge text-bg-light ms-1">
                                  <i class="bi bi-arrow-repeat me-1"></i><?= $txtAttempts ?> tentativa
                                </span>
                                <?php if (!empty($it['q_tls'])): ?>
                                  <span class="badge text-bg-dark ms-1">
                                    <i class="bi bi-stopwatch me-1"></i><?= (int)$it['q_tls'] ?>s
                                  </span>
                                <?php endif; ?>
                                <?= $scoreTxt ?>
                              </div>
                            </div>
                          </div>
                          <div class="km-mat-item-actions">
                            <?php if ($last && !empty($last['id'])): ?>
                              <a
                                class="btn btn-sm btn-outline-secondary"
                                title="Shiko rezultatin e fundit"
                                href="quizzes/quiz_attempt_view.php?attempt_id=<?= (int)$last['id'] ?>"
                              >
                                <i class="bi bi-graph-up"></i>
                              </a>
                            <?php endif; ?>
                            <a
                              class="btn btn-sm btn-outline-primary <?= $btnDis ?>"
                              href="quizzes/take_quiz.php?quiz_id=<?= $qid ?>"
                            >
                              <i class="bi bi-play-circle me-1"></i><?= $btnLabel ?>
                            </a>
                            <?= render_mark_btn_scoped('QUIZ', $qid, $isReadQ, (int)$course_id, $CSRF, $AREA) ?>
                          </div>
                        </div>

                      <?php endif; ?>
                    <?php endforeach; ?>
                  </div>
                <?php endif; ?>
              </div>
            </div>

          <?php endforeach; ?>
        <?php endif; ?>
      </section>
    </div>

    <!-- Djathtas: Përmbledhja + Progresi -->
    <div class="col-12 col-lg-3" id="rightCol">
      <div class="km-mat-right-col-sticky">
        <aside class="km-mat-summary">
          <div class="km-mat-summary-header">
            <h6 class="mb-0">
              <i class="bi bi-clipboard-data me-1"></i>
              Përmbledhje
            </h6>
            <?php if ($allOk): ?>
              <span class="badge text-bg-success">Të gjitha OK</span>
            <?php else: ?>
              <span class="badge text-bg-warning text-dark">Kujdes</span>
            <?php endif; ?>
          </div>

          <p class="small text-muted mb-2">
            Gjendja aktuale e materialeve, detyrave dhe kuizeve të këtij kursi.
          </p>

          <div class="km-mat-summary-grid">
            <div class="km-mat-summary-item">
              <div class="label">Materiale pa lexuar</div>
              <div class="value">
                <span id="cnt-unread-lessons"><?= (int)$unreadLessons ?></span>
              </div>
            </div>

            <div class="km-mat-summary-item">
              <div class="label">Detyra në pritje</div>
              <div class="value">
                <span id="cnt-pending-assignments"><?= (int)$pendingAssignActive ?></span>
              </div>
            </div>

            <div class="km-mat-summary-item">
              <div class="label">Kuize aktive pa tentativë</div>
              <div class="value">
                <span id="cnt-pending-quizzes"><?= (int)$pendingQuizzesActive ?></span>
              </div>
            </div>

            <div class="km-mat-summary-item">
              <div class="label">Gjithsej materiale</div>
              <div class="value">
                <?= (int)$totalLessons ?>
              </div>
            </div>
          </div>
        </aside>

        <aside class="km-mat-pace km-mat-student" id="paceBox">
          <div class="km-mat-summary-header">
            <h6 class="mb-0">
              <i class="bi bi-speedometer2 me-1"></i>
              Progresi im
            </h6>
          </div>

          <div class="mb-3">
            <div class="d-flex justify-content-between">
              <strong>Detyra</strong>
              <span class="small text-muted"><?= (int)$myAssignPct ?>%</span>
            </div>
            <div class="progress mt-1">
              <div
                class="progress-bar"
                role="progressbar"
                style="width: <?= (int)$myAssignPct ?>%;"
              ></div>
            </div>
            <div class="small text-muted mt-1">
              Dorëzime: <?= (int)$myAssignDone ?>/<?= (int)$cntAssign ?>
            </div>
          </div>

          <div class="mb-3">
            <div class="d-flex justify-content-between">
              <strong>Kuize</strong>
              <span class="small text-muted"><?= (int)$myQuizPct ?>%</span>
            </div>
            <div class="progress mt-1">
              <div
                class="progress-bar bg-success"
                role="progressbar"
                style="width: <?= (int)$myQuizPct ?>%;"
              ></div>
            </div>
            <div class="small text-muted mt-1">
              Të përfunduara: <?= (int)$myQuizDone ?>/<?= (int)$cntQuizzes ?>
            </div>
          </div>

          <div>
            <div class="d-flex justify-content-between">
              <strong>Materiale (lexuar)</strong>
              <span class="small text-muted">
                <span id="pct-lessons"><?= (int)$lessonsPct ?></span>%
              </span>
            </div>
            <div class="progress mt-1">
              <div
                class="progress-bar bg-info"
                id="bar-lessons"
                role="progressbar"
                style="width: <?= (int)$lessonsPct ?>%;"
              ></div>
            </div>
            <div class="small text-muted mt-1">
              Lexuar:
              <span id="num-read-lessons"><?= (int)$readLessonsCnt ?></span> /
              <span id="num-total-lessons"><?= (int)$totalLessons ?></span>
            </div>
          </div>
        </aside>
      </div>
    </div>
  </div>
</div>

<script>
(() => {
  /* --------------------------------------------------
     NAV: hap/mbyll seksionet majtas
     -------------------------------------------------- */
  document.querySelectorAll('#navBox .km-mat-nav-toggle').forEach(btn => {
    btn.addEventListener('click', () => {
      const sec = btn.closest('.km-mat-nav-section');
      if (sec) sec.classList.toggle('open');
    });
  });

  /* Klikimi i një item-i te nav: hap seksionin dhe shkon te anchor-i */
  document.querySelectorAll('#navBox .km-mat-nav-item').forEach(a => {
    a.addEventListener('click', () => {
      const hash = a.getAttribute('href');
      if (!hash || !hash.startsWith('#')) return;

      const target = document.querySelector(hash);
      if (!target) return;

      const secCard = target.closest('.km-mat-sec-card');
      const secBody = secCard ? secCard.querySelector('.km-mat-sec-body.collapse') : null;
      if (secBody && secBody.classList.contains('collapse')) {
        const c = window.bootstrap?.Collapse?.getOrCreateInstance?.(secBody, { toggle: false });
        c && c.show();
      }

      document.querySelectorAll('#navBox .km-mat-nav-item.active').forEach(x => x.classList.remove('active'));
      a.classList.add('active');
    });
  });

  /* --------------------------------------------------
     ScrollSpy: cilin element kemi në pamje, e thekson te nav
     -------------------------------------------------- */
  const anchors = Array.from(document.querySelectorAll('#sectionsList .anchor-target')).filter(el => el.id);
  const navLinks = new Map(
    anchors
      .map(a => [
        a.id,
        document.querySelector(`#navBox .km-mat-nav-item[href="#${CSS.escape(a.id)}"]`)
      ])
      .filter(([, el]) => !!el)
  );

  const visible = new Map();
  const io = new IntersectionObserver(entries => {
    for (const e of entries) {
      if (e.isIntersecting) visible.set(e.target.id, e);
      else visible.delete(e.target.id);
    }
    highlightCurrent();
  }, {
    root: null,
    rootMargin: '0px 0px -60% 0px',
    threshold: [0, 0.1]
  });

  anchors.forEach(a => io.observe(a));

  function highlightCurrent() {
    if (!anchors.length) return;
    let bestId = null;
    let bestTop = Infinity;

    for (const [id, entry] of visible) {
      const top = entry.boundingClientRect ? entry.boundingClientRect.top : entry.target.getBoundingClientRect().top;
      if (top >= 0 && top < bestTop) {
        bestTop = top;
        bestId  = id;
      }
    }
    if (!bestId) return;

    document.querySelectorAll('#navBox .km-mat-nav-item.active').forEach(el => el.classList.remove('active'));
    const link = navLinks.get(bestId);
    if (link) {
      link.classList.add('active');
      const sec = link.closest('.km-mat-nav-section');
      if (sec && !sec.classList.contains('open')) {
        sec.classList.add('open');
      }
    }
  }

  window.addEventListener('scroll', () => highlightCurrent(), { passive: true });
  window.addEventListener('resize', () => highlightCurrent());
  document.querySelectorAll('.km-mat-sec-body.collapse').forEach(el => {
    el.addEventListener('shown.bs.collapse',  highlightCurrent);
    el.addEventListener('hidden.bs.collapse', highlightCurrent);
  });
  highlightCurrent();

  /* --------------------------------------------------
     Nënseksionet: TEXT me data-subsection="1" → header,
     elementët pas tij → km-mat-subchild
     -------------------------------------------------- */
  function applySubsections() {
    document.querySelectorAll('#sectionsList .km-mat-sec-body').forEach(body => {
      let inSub = false;
      body.querySelectorAll(':scope .km-mat-elem').forEach(el => {
        const ds = el.getAttribute('data-subsection');
        if (ds === '1') {
          inSub = true;
          el.classList.add('km-mat-subsection-start');
          el.classList.remove('km-mat-subchild');
        } else {
          el.classList.toggle('km-mat-subchild', inSub);
        }
      });
    });
  }
  applySubsections();

  /* --------------------------------------------------
     Update progresi i leksioneve (përdoret nga AJAX)
     -------------------------------------------------- */
  function updateLessonProgress(counts) {
    if (!counts) return;
    const numRead   = document.getElementById('num-read-lessons');
    const numTotal  = document.getElementById('num-total-lessons');
    const pct       = document.getElementById('pct-lessons');
    const bar       = document.getElementById('bar-lessons');
    const cntUnread = document.getElementById('cnt-unread-lessons');

    if (numRead)   numRead.textContent   = counts.read_lessons;
    if (numTotal)  numTotal.textContent  = counts.total_lessons;
    if (pct)       pct.textContent       = counts.pct_lessons;
    if (bar)       bar.style.width       = counts.pct_lessons + '%';
    if (cntUnread) cntUnread.textContent = counts.unread_lessons;
  }

  /* --------------------------------------------------
     AJAX “Shëno si lexuar” (area = MATERIALS, vetëm në front-end)
     -------------------------------------------------- */
  const root = document.querySelector('.km-mat-root');

  root?.addEventListener('click', async (ev) => {
    const btn = ev.target?.closest?.('.mark-read-btn');
    if (!btn || !root.contains(btn)) return;

    ev.stopPropagation?.();
    ev.stopImmediatePropagation?.();

    const type   = btn.dataset.type;
    const id     = btn.dataset.id;
    const csrf   = btn.dataset.csrf;
    const course = btn.dataset.course;
    const area   = btn.dataset.area || 'MATERIALS';

    if (!type || !id || !csrf || !course) return;

    btn.disabled = true;
    try {
      const res = await fetch('ajax/ajax_mark_read_student.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' },
        body: new URLSearchParams({
          action:     'mark_read',
          item_type:  type,
          item_id:    id,
          csrf:       csrf,
          course_id:  course,
          area:       area
        })
      });

      const data = await res.json();
      if (data && data.ok) {
        const nowRead = !!data.now_read;
        const row     = btn.closest('.km-mat-elem');
        if (row) row.classList.toggle('is-read', nowRead);

        btn.classList.toggle('km-mat-btn-read', nowRead);
        btn.classList.toggle('km-mat-btn-unread', !nowRead);
        btn.setAttribute('aria-pressed', nowRead ? 'true' : 'false');
        btn.innerHTML = nowRead
          ? '<i class="bi bi-check2-circle me-1"></i>Lexuar'
          : '<i class="bi bi-bookmark-check me-1"></i>Shëno si lexuar';

        if (type === 'LESSON' && data.counts) {
          updateLessonProgress(data.counts);
        }
      } else {
        alert('Nuk u përditësua (' + (data?.error || 'gabim') + ')');
      }
    } catch (e) {
      console.error(e);
      alert('Gabim serveri.');
    } finally {
      btn.disabled = false;
    }
  }, true);
})();
</script>
