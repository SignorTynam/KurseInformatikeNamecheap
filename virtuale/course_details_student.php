<?php
// course_details_student.php — Berthama e kursit (STUDENT) me renditje sipas section_items (area='MATERIALS')
declare(strict_types=1);
session_start();
require_once __DIR__ . '/lib/database.php';
require_once __DIR__ . '/lib/Parsedown.php';

/* ------------------------------- Helpers ------------------------------- */
function h(?string $s): string { return htmlspecialchars($s ?? '', ENT_QUOTES, 'UTF-8'); }
function is_url(?string $u): bool { return (bool)$u && filter_var((string)$u, FILTER_VALIDATE_URL); }
function table_has_column(PDO $pdo, string $table, string $column): bool {
  try {
    $stmt = $pdo->query("SHOW COLUMNS FROM `{$table}` LIKE " . $pdo->quote($column));
    return (bool)$stmt->fetch(PDO::FETCH_ASSOC);
  } catch (Throwable $e) {
    return false;
  }
}
function normalize_category(string $c): string {
  $u = mb_strtoupper(trim($c), 'UTF-8');
  if ($u === 'LEKSION I PLOTE') return 'LEKSION';
  if ($u === 'SKEDARE')         return 'FILE';
  if ($u === 'NJOFTIM')         return 'TJETER';
  return $u ?: 'TJETER';
}
/** Renderon butonin "Mark as read" (përdoret në materials tab) */
function render_mark_btn(string $type, int $id, bool $isRead, int $course_id, string $csrf): string {
  $cls   = $isRead ? 'btn-success' : 'btn-outline-secondary';
  $label = $isRead ? 'Lexuar' : 'Shëno si lexuar';
  $icon  = $isRead ? 'bi-check2-circle' : 'bi-bookmark-check';
  $aria  = $isRead ? 'true' : 'false';
  return '<button class="btn btn-sm '.$cls.' mark-read-btn"
                  data-type="'.h($type).'"
                  data-id="'.(int)$id.'"
                  data-course="'.(int)$course_id.'"
                  data-csrf="'.h($csrf).'"
                  aria-pressed="'.$aria.'">
            <i class="bi '.$icon.' me-1"></i>'.$label.'
          </button>';
}

/* ------------------------------- RBAC ---------------------------------- */
if (!isset($_SESSION['user']) || (($_SESSION['user']['role'] ?? '') !== 'Student')) {
  header('Location: login.php'); exit;
}
$ME    = $_SESSION['user'];
$ME_ID = (int)($ME['id'] ?? 0);

/* ----------------------------- CSRF ------------------------------------ */
if (empty($_SESSION['csrf_token'])) { $_SESSION['csrf_token'] = bin2hex(random_bytes(32)); }
$CSRF = $_SESSION['csrf_token'];

/* ----------------------------- Inputs ---------------------------------- */
if (!isset($_GET['course_id']) || !is_numeric($_GET['course_id'])) { die('Kursi nuk është specifikuar.'); }
$course_id = (int)$_GET['course_id'];
$activeTab = isset($_GET['tab']) ? (string)$_GET['tab'] : '';
$AREA_MAT  = 'MATERIALS';

/* mapim i mundshëm i tab-eve të vjetër */
if ($activeTab === 'labs') {
  $activeTab = 'materials';
}
$validTabs = ['', 'overview', 'materials', 'forum', 'people', 'payments'];
if (!in_array($activeTab, $validTabs, true)) {
  $activeTab = '';
}

/* ---------------------- Course + enrollment check ---------------------- */
try {
  $stmt = $pdo->prepare("
    SELECT c.*, u.full_name AS creator_name, u.id AS creator_id
    FROM courses c
    LEFT JOIN users u ON c.id_creator = u.id
    WHERE c.id = ?
  ");
  $stmt->execute([$course_id]);
  $course = $stmt->fetch(PDO::FETCH_ASSOC);
  if (!$course) die('Kursi nuk u gjet.');

  $chk = $pdo->prepare("SELECT 1 FROM enroll WHERE course_id = ? AND user_id = ? LIMIT 1");
  $chk->execute([$course_id, $ME_ID]);
  if (!$chk->fetchColumn()) die('Nuk jeni i regjistruar në këtë kurs.');
} catch (PDOException $e) { die('Gabim: ' . h($e->getMessage())); }

/* ------------------------------ Parsedown ------------------------------ */
$Parsedown = new Parsedown();
if (method_exists($Parsedown, 'setSafeMode')) { $Parsedown->setSafeMode(true); }
$courseDescriptionHtml = $Parsedown->text((string)($course['description'] ?? ''));

/* ----------------------------- Feature flags --------------------------- */
$SECTIONS_HAS_HIDDEN = table_has_column($pdo,'sections','hidden');
$SECTIONS_HAS_AREA   = table_has_column($pdo,'sections','area');
$ASSIGN_HAS_HIDDEN   = table_has_column($pdo,'assignments','hidden');
$QUIZ_HAS_HIDDEN     = table_has_column($pdo,'quizzes','hidden');

/* ------------------------------- Sections (area=MATERIALS) ------------- */
try {
  $sqlSec = "SELECT id, course_id, title, description, position, hidden, highlighted
             FROM sections
             WHERE course_id=? ";
  if ($SECTIONS_HAS_AREA)   $sqlSec .= " AND area='MATERIALS' ";
  if ($SECTIONS_HAS_HIDDEN) $sqlSec .= " AND (hidden=0 OR hidden IS NULL) ";
  $sqlSec .= " ORDER BY position ASC, id ASC";
  $stmtSec = $pdo->prepare($sqlSec);
  $stmtSec->execute([$course_id]);
  $sections = $stmtSec->fetchAll(PDO::FETCH_ASSOC) ?: [];
} catch (PDOException $e) { $sections = []; }

/* ------------------------------- FILE MAP (lesson -> first file) ------- */
$fileMap = [];
try {
  $stmtLF = $pdo->prepare("
    SELECT lf.lesson_id, lf.file_path
    FROM lesson_files lf
    JOIN (
      SELECT MIN(id) AS id
      FROM lesson_files
      WHERE lesson_id IN (SELECT id FROM lessons WHERE course_id=?)
      GROUP BY lesson_id
    ) t ON t.id = lf.id
  ");
  $stmtLF->execute([$course_id]);
  foreach ($stmtLF->fetchAll(PDO::FETCH_ASSOC) as $r) {
    $fileMap[(int)$r['lesson_id']] = $r['file_path'];
  }
} catch (Throwable $e) { /* ignore */ }

/* =========================================================================
   ITEMS NGA section_items (area='MATERIALS') – renditja & seksioni korrekt
   ========================================================================= */
$bySection   = [];                 // [section_id] => ['lessons'=>[], 'assignments'=>[], 'quizzes'=>[]]
$unsectioned = ['lessons'=>[], 'assignments'=>[], 'quizzes'=>[]];
$flatLessons = []; $flatAssignments = []; $flatQuizzes = [];

$lessonIds=[]; $assignIds=[]; $quizIds=[];

/* — Lessons */
try {
  $stmt = $pdo->prepare("
    SELECT si.id AS si_id, si.section_id, si.position,
           l.id, l.section_id AS legacy_section_id,
           l.title, l.description, l.URL, l.category, l.uploaded_at
    FROM section_items si
    JOIN lessons l
      ON si.item_type='LESSON' AND si.item_ref_id=l.id AND l.course_id=si.course_id
    WHERE si.course_id=? AND si.area='MATERIALS' AND si.hidden=0
    ORDER BY si.section_id ASC, si.position ASC, si.id ASC
  ");
  $stmt->execute([$course_id]);
  while ($r = $stmt->fetch(PDO::FETCH_ASSOC)) {
    $sid = (int)$r['section_id'];
    $row = [
      'id'         => (int)$r['id'],
      'section_id' => $sid,
      'title'      => (string)$r['title'],
      'description'=> (string)($r['description'] ?? ''),
      'URL'        => (string)($r['URL'] ?? ''),
      'category'   => normalize_category((string)($r['category'] ?? '')),
      'uploaded_at'=> (string)$r['uploaded_at'],
      'si_id'      => (int)$r['si_id'],
      'position'   => (int)$r['position'],
    ];
    if (!isset($bySection[$sid])) $bySection[$sid] = ['lessons'=>[], 'assignments'=>[], 'quizzes'=>[]];
    $bySection[$sid]['lessons'][] = $row;
    if ($sid===0) $unsectioned['lessons'][] = $row;
    $flatLessons[] = $row;
    $lessonIds[] = (int)$r['id'];
  }
} catch (Throwable $e) {}

/* — Assignments (filtron a.hidden) */
try {
  $sql = "
    SELECT si.id AS si_id, si.section_id, si.position,
           a.id, a.title, a.description, a.due_date, a.status, a.hidden, a.uploaded_at
    FROM section_items si
    JOIN assignments a
      ON si.item_type='ASSIGNMENT' AND si.item_ref_id=a.id AND a.course_id=si.course_id
    WHERE si.course_id=? AND si.area='MATERIALS' AND si.hidden=0
  ";
  if ($ASSIGN_HAS_HIDDEN) $sql .= " AND a.hidden=0 ";
  $sql .= " ORDER BY si.section_id ASC, si.position ASC, si.id ASC";
  $stmt = $pdo->prepare($sql);
  $stmt->execute([$course_id]);
  while ($r = $stmt->fetch(PDO::FETCH_ASSOC)) {
    $sid = (int)$r['section_id'];
    $row = [
      'id'          => (int)$r['id'],
      'section_id'  => $sid,
      'title'       => (string)$r['title'],
      'description' => (string)($r['description'] ?? ''),
      'due_date'    => $r['due_date'] ? (string)$r['due_date'] : null,
      'status'      => (string)($r['status'] ?? 'PENDING'),
      'uploaded_at' => (string)($r['uploaded_at'] ?? ''),
      'si_id'       => (int)$r['si_id'],
      'position'    => (int)$r['position'],
      // 'submission_id' do të vendoset më poshtë
    ];
    if (!isset($bySection[$sid])) $bySection[$sid] = ['lessons'=>[], 'assignments'=>[], 'quizzes'=>[]];
    $bySection[$sid]['assignments'][] = $row;
    if ($sid===0) $unsectioned['assignments'][] = $row;
    $flatAssignments[] = $row;
    $assignIds[] = (int)$r['id'];
  }
} catch (Throwable $e) {}

/* — Quizzes (vetëm të PUBLISHED dhe jo hidden) */
try {
  $sql = "
    SELECT si.id AS si_id, si.section_id, si.position,
           q.id, q.title, q.description, q.open_at, q.close_at, q.time_limit_sec,
           q.attempts_allowed, q.shuffle_questions, q.shuffle_answers,
           q.status, q.hidden, q.created_at, q.updated_at
    FROM section_items si
    JOIN quizzes q
      ON si.item_type='QUIZ' AND si.item_ref_id=q.id AND q.course_id=si.course_id
    WHERE si.course_id=? AND si.area='MATERIALS' AND si.hidden=0
      AND q.status='PUBLISHED' ";
  if ($QUIZ_HAS_HIDDEN) $sql .= " AND q.hidden=0 ";
  $sql .= " ORDER BY si.section_id ASC, si.position ASC, si.id ASC";
  $stmt = $pdo->prepare($sql);
  $stmt->execute([$course_id]);
  while ($r = $stmt->fetch(PDO::FETCH_ASSOC)) {
    $sid = (int)$r['section_id'];
    $row = [
      'id'               => (int)$r['id'],
      'section_id'       => $sid,
      'title'            => (string)$r['title'],
      'description'      => (string)($r['description'] ?? ''),
      'open_at'          => $r['open_at'] ? (string)$r['open_at'] : null,
      'close_at'         => $r['close_at'] ? (string)$r['close_at'] : null,
      'time_limit_sec'   => $r['time_limit_sec'] !== null ? (int)$r['time_limit_sec'] : null,
      'attempts_allowed' => (int)($r['attempts_allowed'] ?? 1),
      'shuffle_questions'=> (int)($r['shuffle_questions'] ?? 0),
      'shuffle_answers'  => (int)($r['shuffle_answers'] ?? 0),
      'status'           => (string)$r['status'],
      'created_at'       => (string)($r['created_at'] ?? ''),
      'updated_at'       => (string)($r['updated_at'] ?? ''),
      'si_id'            => (int)$r['si_id'],
      'position'         => (int)$r['position'],
    ];
    if (!isset($bySection[$sid])) $bySection[$sid] = ['lessons'=>[], 'assignments'=>[], 'quizzes'=>[]];
    $bySection[$sid]['quizzes'][] = $row;
    if ($sid===0) $unsectioned['quizzes'][] = $row;
    $flatQuizzes[] = $row;
    $quizIds[] = (int)$r['id'];
  }
} catch (Throwable $e) {}

/* Siguro buckets për çdo seksion (edhe nëse bosh) */
foreach ($sections as $sec) {
  $sid = (int)$sec['id'];
  if (!isset($bySection[$sid])) $bySection[$sid] = ['lessons'=>[], 'assignments'=>[], 'quizzes'=>[]];
}

/* --------------------- READ STATUS (user_reads) --------------------- */
$readLessons = $readAssignments = $readQuizzes = [];
try {
  if ($lessonIds) {
    $ph = implode(',', array_fill(0,count($lessonIds),'?'));
    $st = $pdo->prepare("SELECT item_id FROM user_reads WHERE user_id=? AND item_type='LESSON' AND item_id IN ($ph)");
    $st->execute(array_merge([$ME_ID], $lessonIds));
    foreach ($st->fetchAll(PDO::FETCH_COLUMN) as $rid) $readLessons[(int)$rid]=true;
  }
  if ($assignIds) {
    $ph = implode(',', array_fill(0,count($assignIds),'?'));
    $st = $pdo->prepare("SELECT item_id FROM user_reads WHERE user_id=? AND item_type='ASSIGNMENT' AND item_id IN ($ph)");
    $st->execute(array_merge([$ME_ID], $assignIds));
    foreach ($st->fetchAll(PDO::FETCH_COLUMN) as $rid) $readAssignments[(int)$rid]=true;
  }
  if ($quizIds) {
    $ph = implode(',', array_fill(0,count($quizIds),'?'));
    $st = $pdo->prepare("SELECT item_id FROM user_reads WHERE user_id=? AND item_type='QUIZ' AND item_id IN ($ph)");
    $st->execute(array_merge([$ME_ID], $quizIds));
    foreach ($st->fetchAll(PDO::FETCH_COLUMN) as $rid) $readQuizzes[(int)$rid]=true;
  }
} catch (Throwable $e) {}

/* --------- Dorëzimet e mia për assignments (submission_id) ----------- */
try {
  if ($assignIds) {
    $ph = implode(',', array_fill(0,count($assignIds),'?'));
    $st = $pdo->prepare("
      SELECT assignment_id, MIN(id) AS sub_id
      FROM assignments_submitted
      WHERE user_id=? AND assignment_id IN ($ph)
      GROUP BY assignment_id
    ");
    $st->execute(array_merge([$ME_ID], $assignIds));
    $subByAssign = [];
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) $subByAssign[(int)$r['assignment_id']] = (int)$r['sub_id'];
    foreach ($bySection as $sid=>&$packs){
      foreach ($packs['assignments'] as &$a){ $aid=(int)$a['id']; $a['submission_id'] = $subByAssign[$aid] ?? null; }
      unset($a);
    } unset($packs);
    foreach ($unsectioned['assignments'] as &$ua){ $aid=(int)$ua['id']; $ua['submission_id'] = $subByAssign[$aid] ?? null; }
    unset($ua);
    foreach ($flatAssignments as &$fa){ $aid=(int)$fa['id']; $fa['submission_id'] = $subByAssign[$aid] ?? null; }
    unset($fa);
  }
} catch (Throwable $e) {}

/* --------- Quiz attempts & last result -------------------------------- */
$attemptsByQuiz = [];   // [quiz_id] => ['in_progress'=>..,'submitted'=>..,'total'=>..]
$lastResultByQuiz = []; // [quiz_id] => ['id','score','total_points','submitted_at']
try {
  if ($quizIds) {
    $ph = implode(',', array_fill(0,count($quizIds),'?'));
    // counters
    $st = $pdo->prepare("
      SELECT quiz_id,
             SUM(submitted_at IS NULL) AS in_progress,
             SUM(submitted_at IS NOT NULL) AS submitted,
             COUNT(*) AS total
      FROM quiz_attempts
      WHERE user_id=? AND quiz_id IN ($ph)
      GROUP BY quiz_id
    ");
    $st->execute(array_merge([$ME_ID], $quizIds));
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
      $attemptsByQuiz[(int)$r['quiz_id']] = [
        'in_progress'=>(int)$r['in_progress'],
        'submitted'  =>(int)$r['submitted'],
        'total'      =>(int)$r['total'],
      ];
    }
    // last submitted
    $st = $pdo->prepare("
      SELECT qa.*
      FROM quiz_attempts qa
      JOIN (
        SELECT quiz_id, MAX(submitted_at) AS ms
        FROM quiz_attempts
        WHERE user_id=? AND submitted_at IS NOT NULL AND quiz_id IN ($ph)
        GROUP BY quiz_id
      ) t ON t.quiz_id=qa.quiz_id AND t.ms=qa.submitted_at
    ");
    $st->execute(array_merge([$ME_ID], $quizIds));
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
      $qid=(int)$r['quiz_id'];
      $lastResultByQuiz[$qid]=[
        'id'           => (int)$r['id'],
        'score'        => (int)($r['score'] ?? 0),
        'total_points' => (int)($r['total_points'] ?? 0),
        'submitted_at' => (string)$r['submitted_at'],
      ];
    }
  }
} catch (Throwable $e) {}

/* ------------------------------ KPIs & Progress ------------------------ */
$totalLessons   = count($flatLessons);
$readLessonsCnt = count($readLessons);
$unreadLessons  = max(0, $totalLessons - $readLessonsCnt);

$cntAssign = count($flatAssignments);
$myAssignDone = 0; $pendingAssignActive = 0;
$nowTs = time();
foreach ($flatAssignments as $a){
  $sub = !empty($a['submission_id']);
  if ($sub) $myAssignDone++;
  $dueTs = !empty($a['due_date']) ? strtotime((string)$a['due_date'].' 23:59:59') : null;
  $active = (!$sub) && (is_null($dueTs) || $dueTs >= $nowTs);
  if ($active) $pendingAssignActive++;
}

$cntQuizzes = count($flatQuizzes);
$myQuizDone = 0; $pendingQuizzesActive = 0;
foreach ($flatQuizzes as $q){
  $qid = (int)$q['id'];
  $meta = $attemptsByQuiz[$qid] ?? ['in_progress'=>0,'submitted'=>0,'total'=>0];
  $openAt  = !empty($q['open_at'])  ? strtotime((string)$q['open_at'])  : null;
  $closeAt = !empty($q['close_at']) ? strtotime((string)$q['close_at']) : null;
  $isOpen = (!$openAt || $nowTs >= $openAt) && (!$closeAt || $nowTs <= $closeAt);
  $attempted = (int)($meta['submitted'] ?? 0) > 0;
  if ($attempted) $myQuizDone++;
  if ($isOpen && !$attempted && (int)($meta['in_progress'] ?? 0) === 0) $pendingQuizzesActive++;
}
$allOk = ($unreadLessons === 0 && $pendingAssignActive === 0 && $pendingQuizzesActive === 0);

$myAssignPct = ($cntAssign>0)  ? (int)round(($myAssignDone/$cntAssign)*100) : 0;
$myQuizPct   = ($cntQuizzes>0) ? (int)round(($myQuizDone/$cntQuizzes)*100) : 0;
$lessonsPct  = ($totalLessons>0)? (int)round(($readLessonsCnt/$totalLessons)*100) : 0;

/* ----------------------------- Ikona/Ngjyra kategori ------------------- */
$iconMap = [
  'LEKSION'   => ['bi-journal-text',  '#0f6cbf'],
  'VIDEO'     => ['bi-camera-video',  '#dc3545'],
  'LINK'      => ['bi-link-45deg',    '#6f42c1'],
  'FILE'      => ['bi-file-earmark',  '#28a745'],
  'USHTRIME'  => ['bi-pencil-square', '#ffc107'],
  'PROJEKTE'  => ['bi-kanban',        '#0d6efd'],
  'QUIZ'      => ['bi-patch-question','#20c997'],
  'LAB'       => ['bi-cpu',           '#fd7e14'],
  'REFERENCA' => ['bi-bookmark',      '#198754'],
  'TJETER'    => ['bi-collection',    '#6c757d'],
];
$catMeta = function(string $c) use ($iconMap){
  $u = normalize_category($c);
  return $iconMap[$u] ?? ['bi-collection','#6c757d'];
};

/* ---------------------------- Participants ----------------------------- */
try {
  $stmt = $pdo->prepare("
    SELECT e.*, u.full_name
    FROM enroll e
    JOIN users u ON u.id = e.user_id
    WHERE e.course_id = ?
    ORDER BY u.full_name
  ");
  $stmt->execute([$course_id]);
  $participants = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
} catch (PDOException $e) { $participants = []; }

/* -------------------------------- Forum -------------------------------- */
$THREADS_HAS_COURSE_ID = table_has_column($pdo,'threads','course_id');
try {
  if ($THREADS_HAS_COURSE_ID) {
    $stmtThreads = $pdo->prepare("
      SELECT t.id, t.title, t.content, t.created_at,
             u.full_name,
             (SELECT COUNT(*) FROM thread_replies r WHERE r.thread_id = t.id) AS replies_count
      FROM threads t
      JOIN users u ON u.id = t.user_id
      WHERE t.course_id = ?
      ORDER BY t.created_at DESC
      LIMIT 20
    ");
    $stmtThreads->execute([$course_id]);
  } else {
    $stmtThreads = $pdo->prepare("
      SELECT t.id, t.title, t.content, t.created_at,
             u.full_name,
             (SELECT COUNT(*) FROM thread_replies r WHERE r.thread_id = t.id) AS replies_count
      FROM threads t
      JOIN lessons l ON l.id = t.lesson_id
      JOIN users u   ON u.id = t.user_id
      WHERE l.course_id = ?
      ORDER BY t.created_at DESC
      LIMIT 20
    ");
    $stmtThreads->execute([$course_id]);
  }
  $threads = $stmtThreads->fetchAll(PDO::FETCH_ASSOC) ?: [];
} catch (PDOException $e) { $threads = []; }

/* ------------------------------- Payments ------------------------------ */
try {
  $stmt = $pdo->prepare("
    SELECT p.*, l.title AS lesson_title
    FROM payments p
    LEFT JOIN lessons l ON l.id = p.lesson_id
    WHERE p.course_id = ? AND p.user_id = ?
    ORDER BY p.payment_date DESC, p.id DESC
  ");
  $stmt->execute([$course_id, $ME_ID]);
  $payments = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
} catch (PDOException $e) { $payments = []; }
$paidSum = 0.0; $paidCount = 0; $failedCount = 0;
foreach ($payments as $p) {
  $st = (string)($p['payment_status'] ?? '');
  if ($st === 'COMPLETED') { $paidSum += (float)($p['amount'] ?? 0); $paidCount++; }
  elseif ($st === 'FAILED') { $failedCount++; }
}

/* ----------------------------- Activity (7 ditë) ----------------------- */
$since = (new DateTime('-7 days'))->format('Y-m-d H:i:s');
$activity = [];
function pushActivity(&$arr,$ts,$type,$title,$url='',$meta='') {
  $arr[] = ['ts'=>strtotime($ts),'type'=>$type,'title'=>$title,'url'=>$url,'meta'=>$meta];
}
foreach ($flatLessons as $l) {
  if (!empty($l['uploaded_at']) && $l['uploaded_at'] >= $since) {
    pushActivity($activity,$l['uploaded_at'],'LEKSION',$l['title'],"lesson_details.php?lesson_id=".(int)$l['id']);
  }
}
foreach ($flatAssignments as $a) {
  if (!empty($a['uploaded_at']) && $a['uploaded_at'] >= $since) {
    pushActivity($activity,$a['uploaded_at'],'DETYRË',$a['title'],"assignment_details.php?assignment_id=".(int)$a['id'],
      !empty($a['due_date']) ? ('Afat: '.date('d M',strtotime((string)$a['due_date']))) : ''
    );
  }
}
foreach ($flatQuizzes as $qz) {
  if (!empty($qz['created_at']) && $qz['created_at'] >= $since) {
    pushActivity($activity,$qz['created_at'],'QUIZ: Publikuar',$qz['title'],"quizzes/quiz_details.php?quiz_id=".(int)$qz['id']);
  } elseif (!empty($qz['updated_at']) && $qz['updated_at'] >= $since) {
    pushActivity($activity,$qz['updated_at'],'QUIZ: Përditësuar',$qz['title'],"quizzes/quiz_details.php?quiz_id=".(int)$qz['id']);
  }
}
foreach ($threads as $t) {
  if (!empty($t['created_at']) && $t['created_at'] >= $since) {
    pushActivity($activity,$t['created_at'],'FORUM: Temë',$t['title'],"threads/thread_view.php?thread_id=".(int)$t['id'], $t['full_name'] ?? '');
  }
}
usort($activity, fn($x,$y)=> $y['ts']<=>$x['ts']);
$activity = array_slice($activity,0,10);

/* ------------------------------ Counters -------------------------------- */
$cntSections = count($sections);
$cntLessons  = count($flatLessons);
$cntAssign   = count($flatAssignments);
$cntQuizzes  = count($flatQuizzes);
$cntPeople   = count($participants);

/* --------------------------------- View --------------------------------- */
?>
<!DOCTYPE html>
<html lang="sq" data-bs-theme="light">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title><?= h($course['title']) ?> — Paneli i Kursit (Student) | kurseinformatike.com</title>

  <link rel="icon" href="image/favicon.ico" type="image/x-icon" />
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css">
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css">
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css">
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/highlight.js/11.7.0/styles/github.min.css">

  <!-- CSS paneli i kursit (brand) -->
  <link rel="stylesheet" href="css/course_panel.css?v=1">
  <link rel="stylesheet" href="css/course_overview_student.css?v=1">
  <!-- Layout i materialeve, njësoj si te course_details.php -->
  <link rel="stylesheet" href="css/km-materials.css?v=1">
  <link rel="stylesheet" href="css/km-course-tabs.css?v=2">
</head>
<body class="course-body">
<?php include __DIR__ . '/navbar_logged_student.php'; ?>

<header class="course-hero">
  <div class="container-fluid px-3 px-lg-4">
    <div class="row g-3 align-items-start">
      <!-- Majtas: breadcrumb + titulli -->
      <div class="col-lg-7">
        <div class="course-breadcrumb">
          <a href="courses_student.php">
            <i class="bi bi-arrow-left-short me-1"></i> Kurset e mia
          </a>
          <span class="sep">/</span>
          <span class="current"><?= h($course['title']) ?></span>
        </div>
        <h1><?= h($course['title']) ?></h1>
        <p>
          Instruktor: <strong><?= h($course['creator_name']) ?></strong>
          • <?= date('d.m.Y H:i', strtotime((string)$course['created_at'])) ?>
          • Kategoria:
          <span class="course-tag"><?= h($course['category'] ?? 'TJETRA') ?></span>
        </p>
      </div>

      <!-- Djathtas: actions + statistika të shpejta -->
      <div class="col-lg-5">
        <div class="course-hero-actions d-flex flex-wrap justify-content-lg-end gap-2 mb-2">
          <?php if (is_url((string)($course['AulaVirtuale'] ?? ''))): ?>
            <a class="btn btn-sm course-action-primary" target="_blank" href="<?= h((string)$course['AulaVirtuale']) ?>">
              <i class="bi bi-camera-video me-1"></i> Hyr në klasë virtuale
            </a>
          <?php endif; ?>
          <a href="course_details_student.php?course_id=<?= (int)$course_id ?>&tab=materials" class="btn btn-sm course-action-outline">
            <i class="bi bi-layers me-1"></i> Shko te materialet
          </a>

          <form method="post" action="course_unenroll.php" class="d-inline"
                onsubmit="return confirm('Je i sigurt që do të çregjistrohesh nga ky kurs?');">
            <input type="hidden" name="course_id" value="<?= (int)$course_id ?>">
            <input type="hidden" name="csrf" value="<?= h((string)$CSRF) ?>">
            <button class="btn btn-sm btn-outline-danger" type="submit" title="Çregjistrohu nga kursi">
              <i class="bi bi-box-arrow-right me-1"></i> Çregjistrohu
            </button>
          </form>
        </div>

        <div class="course-hero-stats">
          <div class="course-stat">
            <div class="icon">
              <i class="bi bi-clipboard-check"></i>
            </div>
            <div>
              <div class="label">Detyra të dorëzuara</div>
              <div class="value">
                <?= $cntAssign > 0 ? ($myAssignDone . '/' . $cntAssign) : '—' ?>
              </div>
            </div>
          </div>

          <div class="course-stat d-none d-md-flex">
            <div class="icon">
              <i class="bi bi-patch-question"></i>
            </div>
            <div>
              <div class="label">Quiz-e të përfunduara</div>
              <div class="value">
                <?= $cntQuizzes > 0 ? ($myQuizDone . '/' . $cntQuizzes) : '—' ?>
              </div>
            </div>
          </div>
        </div>
      </div><!-- col-lg-5 -->
    </div>
  </div>
</header>

<main class="course-main">
  <div class="container-fluid px-3 px-lg-4">
    <!-- Tabs + info -->
    <div class="course-nav-wrapper">
      <ul class="nav course-tabs" role="tablist">
        <li class="nav-item">
          <a class="nav-link <?= ($activeTab==='' || $activeTab==='overview') ? 'active' : '' ?>" data-bs-toggle="tab" data-bs-target="#overview" href="course_details_student.php?course_id=<?= (int)$course_id ?>" role="tab">
            <i class="bi bi-layout-text-sidebar"></i>
            <span>Përmbledhje</span>
          </a>
        </li>
        <li class="nav-item">
          <a class="nav-link <?= $activeTab==='materials'?'active':'' ?>" data-bs-toggle="tab" data-bs-target="#materials" href="course_details_student.php?course_id=<?= (int)$course_id ?>&tab=materials" role="tab">
            <i class="bi bi-layers"></i>
            <span>Materialet</span>
          </a>
        </li>
        <li class="nav-item">
          <a class="nav-link <?= $activeTab==='forum'?'active':'' ?>" data-bs-toggle="tab" data-bs-target="#forum" href="course_details_student.php?course_id=<?= (int)$course_id ?>&tab=forum" role="tab">
            <i class="bi bi-chat-dots"></i>
            <span>Forumi</span>
          </a>
        </li>
        <li class="nav-item">
          <a class="nav-link <?= $activeTab==='people'?'active':'' ?>" data-bs-toggle="tab" data-bs-target="#people" href="course_details_student.php?course_id=<?= (int)$course_id ?>&tab=people" role="tab">
            <i class="bi bi-people"></i>
            <span>Pjesëmarrës</span>
          </a>
        </li>
        <li class="nav-item">
          <a class="nav-link <?= $activeTab==='payments'?'active':'' ?>" data-bs-toggle="tab" data-bs-target="#payments" href="course_details_student.php?course_id=<?= (int)$course_id ?>&tab=payments" role="tab">
            <i class="bi bi-currency-dollar"></i>
            <span>Pagesat</span>
          </a>
        </li>
      </ul>

      <div class="course-nav-extra d-none d-md-block">
        Leksione: <strong><?= (int)$cntLessons ?></strong> •
        Detyra: <strong><?= (int)$cntAssign ?></strong> •
        Quiz: <strong><?= (int)$cntQuizzes ?></strong> •
        Pjesëmarrës: <strong><?= (int)$cntPeople ?></strong>
      </div>
    </div>

    <!-- Shell i bardhë me tab-content -->
    <div class="course-shell">
      <div class="tab-content">
        <div class="tab-pane fade <?= ($activeTab==='' || $activeTab==='overview') ? 'show active' : '' ?>" id="overview" role="tabpanel">
          <?php include __DIR__ . '/tabs_student/overview.php'; ?>
        </div>

        <div class="tab-pane fade <?= $activeTab==='materials'?'show active':'' ?>" id="materials" role="tabpanel">
          <?php
          // Variablat që i duhen materialeve:
          // $sections, $unsectioned, $bySection, $Parsedown, $fileMap,
          // $readLessons, $readAssignments, $readQuizzes, $CSRF, $course_id,
          // $allOk, $unreadLessons, $pendingAssignActive, $pendingQuizzesActive,
          // $myAssignPct, $myAssignDone, $cntAssign, $myQuizPct, $myQuizDone, $cntQuizzes,
          // $lessonsPct, $totalLessons, $readLessonsCnt, $attemptsByQuiz, $lastResultByQuiz,
          // $catMeta
          include __DIR__ . '/tabs_student/materials.php';
          ?>
        </div>

        <div class="tab-pane fade <?= $activeTab==='forum'?'show active':'' ?>" id="forum" role="tabpanel">
          <?php include __DIR__ . '/tabs_student/forum.php'; ?>
        </div>

        <div class="tab-pane fade <?= $activeTab==='people'?'show active':'' ?>" id="people" role="tabpanel">
          <?php include __DIR__ . '/tabs_student/people.php'; ?>
        </div>

        <div class="tab-pane fade <?= $activeTab==='payments'?'show active':'' ?>" id="payments" role="tabpanel">
          <?php include __DIR__ . '/tabs_student/payments.php'; ?>
        </div>
      </div>
    </div>
  </div>
</main>

<?php include __DIR__ . '/footer2.php'; ?>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script>
(function(){
  // Fallback për tabs nëse CDN/Bootstrap JS s’ngarkohet.
  function showTab(link){
    const targetSel = link.getAttribute('data-bs-target') || link.getAttribute('href');
    if (!targetSel || !targetSel.startsWith('#')) return;

    const tabRoot = link.closest('.course-nav-wrapper') || document;
    tabRoot.querySelectorAll('.course-tabs .nav-link').forEach(a => a.classList.remove('active'));
    link.classList.add('active');

    const shell = document.querySelector('.course-shell');
    if (!shell) return;
    shell.querySelectorAll('.tab-pane').forEach(p => p.classList.remove('show','active'));
    const pane = document.querySelector(targetSel);
    if (pane) pane.classList.add('show','active');
  }

  document.addEventListener('click', function(ev){
    const link = ev.target && ev.target.closest ? ev.target.closest('.course-tabs [data-bs-toggle="tab"]') : null;
    if (!link) return;
    ev.preventDefault();

    // Update URL (pa reload) që refresh/share të ruajë tab-in aktual.
    try {
      const href = link.getAttribute('href');
      if (href && !href.startsWith('#')) {
        const nextUrl = new URL(href, window.location.href);
        window.history.pushState({ tab: link.getAttribute('data-bs-target') || '' }, '', nextUrl.toString());
      }
    } catch (e) {}
    try {
      if (window.bootstrap && bootstrap.Tab) {
        bootstrap.Tab.getOrCreateInstance(link).show();
        return;
      }
    } catch (e) {}
    showTab(link);
  }, true);
})();
</script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/highlight.js/11.7.0/highlight.min.js"></script>
<script>
  // Syntax highlight global
  document.querySelectorAll('pre code').forEach(el => { try{ hljs.highlightElement(el); }catch(e){} });
</script>
</body>
</html>
