<?php
// ============================================================
// Course Overview (STUDENT) — brand new
// Fokus: Leksioni i ardhshëm, detyra/quiz në pritje, ecuria ime,
// aktiviteti i fundit dhe info praktike.
// CSS: ../css/course_overview_student.css
// ============================================================

if (!defined('COURSE_OVERVIEW_STUDENT_CSS')) {
  define('COURSE_OVERVIEW_STUDENT_CSS', true);
  // Kujdes: rruga është relative nga folder-i tabs_student/
  echo '<link rel="stylesheet" href="../css/course_overview_student.css">';
}

/* Helper HTML escape */
if (!function_exists('h')) {
  function h(?string $s): string { return htmlspecialchars($s ?? '', ENT_QUOTES, 'UTF-8'); }
}

/* Helper: kontrollon nëse tabela ka kolonë */
if (!function_exists('table_has_column')) {
  function table_has_column(PDO $pdo, string $table, string $column): bool {
    try {
      $stmt = $pdo->query("SHOW COLUMNS FROM `{$table}` LIKE " . $pdo->quote($column));
      return (bool)$stmt->fetch(PDO::FETCH_ASSOC);
    } catch (Throwable $e) { return false; }
  }
}

/* Fallback-e të buta për variablat bazë */
$course    = is_array($course ?? null) ? $course : [];
$course_id = isset($course_id) ? (int)$course_id : (int)($course['id'] ?? 0);
$ME_ID     = isset($ME_ID) ? (int)$ME_ID : (int)($_SESSION['user']['id'] ?? 0);

/* $now -> timestamp i sigurt */
$nowTs = isset($now)
  ? (is_int($now) ? $now : (is_string($now) ? strtotime($now) : time()))
  : time();

/* Përshkrimi i kursit (fallback) */
if (empty($courseDescriptionHtml)) {
  $rawDesc = (string)($course['description'] ?? '');
  if (!empty($rawDesc) && class_exists('Parsedown')) {
    $Parsedown = new Parsedown();
    if (method_exists($Parsedown, 'setSafeMode')) $Parsedown->setSafeMode(true);
    $courseDescriptionHtml = $Parsedown->text($rawDesc);
  } else {
    $courseDescriptionHtml = $rawDesc !== ''
      ? nl2br(h($rawDesc))
      : '<span class="text-muted-soft">Pa përshkrim.</span>';
  }
}

/* =========================================================
   KPI të shpejta (strukturë kursi)
   ========================================================= */
try { $st = $pdo->prepare("SELECT COUNT(*) FROM sections WHERE course_id=?"); $st->execute([$course_id]); $cntSections=(int)$st->fetchColumn(); } catch (Throwable $e){ $cntSections=0; }
try { $st = $pdo->prepare("SELECT COUNT(*) FROM lessons WHERE course_id=?");  $st->execute([$course_id]); $cntLessons =(int)$st->fetchColumn(); } catch (Throwable $e){ $cntLessons =0; }
try { $st = $pdo->prepare("SELECT COUNT(*) FROM assignments WHERE course_id=?"); $st->execute([$course_id]); $cntAssign  =(int)$st->fetchColumn(); } catch (Throwable $e){ $cntAssign  =0; }
try { $st = $pdo->prepare("SELECT COUNT(*) FROM quizzes WHERE course_id=?"); $st->execute([$course_id]); $cntQuizzes =(int)$st->fetchColumn(); } catch (Throwable $e){ $cntQuizzes =0; }
try { $st = $pdo->prepare("SELECT COUNT(*) FROM enroll WHERE course_id=?");  $st->execute([$course_id]); $cntPeople  =(int)$st->fetchColumn(); } catch (Throwable $e){ $cntPeople  =0; }

/* =========================================================
   Klasë virtuale (pa kalendar brenda kursit)
   ========================================================= */
$classLink = (string)($course['AulaVirtuale'] ?? '');
$hasClassLink = $classLink !== '' && filter_var($classLink, FILTER_VALIDATE_URL);

/* =========================================================
   Detyra + kuize në pritje për studentin
   ========================================================= */
$ASSIGN_HAS_HIDDEN = table_has_column($pdo,'assignments','hidden');
$QUIZ_HAS_HIDDEN   = table_has_column($pdo,'quizzes','hidden');

/* Detyrat në pritje (jo të dorëzuara, pa afat të skaduar) */
$pendingAssignments = [];
try {
  $sql = "
    SELECT a.id, a.title, a.due_date
    FROM assignments a
    WHERE a.course_id = ?
  ";
  if ($ASSIGN_HAS_HIDDEN) $sql .= " AND (a.hidden=0 OR a.hidden IS NULL) ";
  $sql .= "
    AND NOT EXISTS (
      SELECT 1 FROM assignments_submitted s
      WHERE s.assignment_id = a.id AND s.user_id = ?
    )
    AND (a.due_date IS NULL OR a.due_date >= CURDATE())
    ORDER BY a.due_date IS NULL, a.due_date ASC
    LIMIT 3
  ";
  $st = $pdo->prepare($sql);
  $st->execute([$course_id, $ME_ID]);
  $pendingAssignments = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
} catch (Throwable $e) { $pendingAssignments = []; }

/* Kuizet në pritje (PUBLISHED, të hapur dhe pa attempt të dorëzuar) */
$pendingQuizzes = [];
try {
  $sql = "
    SELECT q.id, q.title, q.open_at, q.close_at, q.time_limit_sec
    FROM quizzes q
    WHERE q.course_id = ?
      AND q.status = 'PUBLISHED'
  ";
  if ($QUIZ_HAS_HIDDEN) $sql .= " AND (q.hidden=0 OR q.hidden IS NULL) ";
  $sql .= "
    AND (q.open_at IS NULL OR q.open_at <= NOW())
    AND (q.close_at IS NULL OR q.close_at >= NOW())
    AND NOT EXISTS (
      SELECT 1 FROM quiz_attempts qa
      WHERE qa.quiz_id = q.id
        AND qa.user_id = ?
        AND qa.submitted_at IS NOT NULL
    )
    ORDER BY q.close_at IS NULL, q.close_at ASC
    LIMIT 3
  ";
  $st = $pdo->prepare($sql);
  $st->execute([$course_id, $ME_ID]);
  $pendingQuizzes = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
} catch (Throwable $e) { $pendingQuizzes = []; }

/* =========================================================
   Ecuria ime (studenti aktual)
   ========================================================= */
try {
  $st = $pdo->prepare("
    SELECT COUNT(*) 
    FROM user_reads ur
    JOIN lessons l ON l.id = ur.item_id AND ur.item_type='LESSON'
    WHERE l.course_id=? AND ur.user_id=?
  ");
  $st->execute([$course_id, $ME_ID]);
  $readLessonsCnt = (int)$st->fetchColumn();
} catch (Throwable $e) { $readLessonsCnt = 0; }

$totalLessons = $cntLessons;

try {
  $st = $pdo->prepare("
    SELECT COUNT(DISTINCT s.assignment_id)
    FROM assignments_submitted s
    JOIN assignments a ON a.id = s.assignment_id
    WHERE a.course_id=? AND s.user_id=?
  ");
  $st->execute([$course_id, $ME_ID]);
  $myAssignDone = (int)$st->fetchColumn();
} catch (Throwable $e) { $myAssignDone = 0; }

try {
  $st = $pdo->prepare("
    SELECT COUNT(DISTINCT qa.quiz_id)
    FROM quiz_attempts qa
    JOIN quizzes q ON q.id = qa.quiz_id
    WHERE q.course_id=? AND qa.user_id=? AND qa.submitted_at IS NOT NULL
  ");
  $st->execute([$course_id, $ME_ID]);
  $myQuizDone = (int)$st->fetchColumn();
} catch (Throwable $e) { $myQuizDone = 0; }

$lessonsPct = $totalLessons > 0 ? (int)round(($readLessonsCnt / $totalLessons) * 100) : 0;
$myAssignPct = $cntAssign > 0 ? (int)round(($myAssignDone / $cntAssign) * 100) : 0;
$myQuizPct   = $cntQuizzes > 0 ? (int)round(($myQuizDone / $cntQuizzes) * 100) : 0;

/* Ndihem "all good" nëse s'kam detyra/kuize në pritje dhe s'kam materiale të palexuara */
$havePendingTasks = !empty($pendingAssignments) || !empty($pendingQuizzes) || ($lessonsPct < 100);

/* =========================================================
   Vazhdo ku mbete (leksioni i parë i palexuar)
   ========================================================= */
$nextLesson = null;
try {
  $st = $pdo->prepare("
    SELECT l.id, l.title
    FROM lessons l
    WHERE l.course_id = ?
      AND NOT EXISTS (
        SELECT 1 FROM user_reads ur
        WHERE ur.user_id=? AND ur.item_type='LESSON' AND ur.item_id = l.id
      )
    ORDER BY l.uploaded_at ASC, l.id ASC
    LIMIT 1
  ");
  $st->execute([$course_id, $ME_ID]);
  $nextLesson = $st->fetch(PDO::FETCH_ASSOC) ?: null;
} catch (Throwable $e) { $nextLesson = null; }

/* =========================================================
   Threads pa përgjigje (për studentin, vetëm si info)
   ========================================================= */
try {
  $hasCourseCol = table_has_column($pdo,'threads','course_id');
  if ($hasCourseCol) {
    $st = $pdo->prepare("
      SELECT COUNT(*) 
      FROM threads t 
      WHERE t.course_id = ? 
        AND NOT EXISTS (SELECT 1 FROM thread_replies r WHERE r.thread_id = t.id)
    ");
    $st->execute([$course_id]);
  } else {
    $st = $pdo->prepare("
      SELECT COUNT(*) 
      FROM threads t
      JOIN lessons l ON l.id = t.lesson_id
      WHERE l.course_id = ?
        AND NOT EXISTS (SELECT 1 FROM thread_replies r WHERE r.thread_id = t.id)
    ");
    $st->execute([$course_id]);
  }
  $cntUnanswered = (int)$st->fetchColumn();
} catch (Throwable $e) { $cntUnanswered = 0; }

/* =========================================================
   Pagesat e studentit për këtë kurs
   ========================================================= */
if (!isset($paidSum) || !isset($paidCount)) {
  $paidSum   = 0.0;
  $paidCount = 0;
  try {
    $st = $pdo->prepare("
      SELECT payment_status, amount
      FROM payments
      WHERE course_id=? AND user_id=?
    ");
    $st->execute([$course_id, $ME_ID]);
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $p) {
      if (($p['payment_status'] ?? '') === 'COMPLETED') {
        $paidSum   += (float)($p['amount'] ?? 0);
        $paidCount++;
      }
    }
  } catch (Throwable $e) { /* ignore */ }
}

/* =========================================================
   Aktiviteti i fundit (7 ditët e fundit)
   ========================================================= */
if (!isset($activity) || !is_array($activity)) {
  $activity = [];
  $since = (new DateTimeImmutable('-7 days'))->format('Y-m-d H:i:s');

  $push = function(array &$arr, string $ts, string $type, string $title, string $url = '', string $meta = ''): void {
    $arr[] = [
      'ts'    => strtotime($ts),
      'type'  => $type,
      'title' => $title,
      'url'   => $url,
      'meta'  => $meta,
    ];
  };

  try {
    $st = $pdo->prepare("SELECT id,title,uploaded_at FROM lessons WHERE course_id=? AND uploaded_at>=? ORDER BY uploaded_at DESC");
    $st->execute([$course_id, $since]);
    foreach ($st as $l) {
      $push($activity, (string)$l['uploaded_at'], 'Leksion i ri', (string)$l['title'], "lesson_details.php?lesson_id=".(int)$l['id']);
    }
  } catch (Throwable $e) {}

  try {
    $st = $pdo->prepare("SELECT id,title,due_date,uploaded_at FROM assignments WHERE course_id=? AND uploaded_at>=? ORDER BY uploaded_at DESC");
    $st->execute([$course_id, $since]);
    foreach ($st as $a) {
      $meta = !empty($a['due_date']) ? 'Afat: '.date('d M', strtotime((string)$a['due_date'])) : 'Pa afat';
      $push($activity, (string)$a['uploaded_at'], 'Detyrë e re', (string)$a['title'], "assignment_details.php?assignment_id=".(int)$a['id'], $meta);
    }
  } catch (Throwable $e) {}

  try {
    $st = $pdo->prepare("SELECT id,title,created_at,updated_at FROM quizzes WHERE course_id=? ORDER BY id DESC");
    $st->execute([$course_id]);
    foreach ($st as $qz) {
      $label = '';
      $ts    = '';
      if (!empty($qz['created_at']) && $qz['created_at'] >= $since) {
        $label = 'Quiz i ri';
        $ts    = (string)$qz['created_at'];
      } elseif (!empty($qz['updated_at']) && $qz['updated_at'] >= $since) {
        $label = 'Quiz përditësuar';
        $ts    = (string)$qz['updated_at'];
      }
      if ($label && $ts) {
        $push($activity, $ts, $label, (string)$qz['title'], "quizzes/quiz_details.php?quiz_id=".(int)$qz['id']);
      }
    }
  } catch (Throwable $e) {}

  try {
    $st = $pdo->prepare("
      SELECT t.id,t.title,t.created_at,u.full_name
      FROM threads t
      JOIN users u ON u.id=t.user_id
      ".( $hasCourseCol ?? table_has_column($pdo,'threads','course_id')
          ? "WHERE t.course_id=? AND t.created_at>=? "
          : "JOIN lessons l ON l.id=t.lesson_id WHERE l.course_id=? AND t.created_at>=? "
        )."
      ORDER BY t.created_at DESC
    ");
    $st->execute([$course_id, $since]);
    foreach ($st as $t) {
      $meta = (string)($t['full_name'] ?? '');
      $push($activity, (string)$t['created_at'], 'Temë në forum', (string)$t['title'], "threads/thread_view.php?thread_id=".(int)$t['id'], $meta);
    }
  } catch (Throwable $e) {}

  usort($activity, fn($a,$b) => ($b['ts'] ?? 0) <=> ($a['ts'] ?? 0));
  $activity = array_slice($activity, 0, 6);
}
?>

<div class="course-overview">

  <!-- Rreshti i sipërm: Next up + Progresi im -->
  <div class="row g-3 mb-3 ov-grid-top">

    <!-- Next up: leksioni i ardhshëm + detyra/kuize -->
    <div class="col-lg-7">
      <div class="ov-card ov-card-next">
        <div class="d-flex justify-content-between align-items-center mb-2">
          <h5 class="mb-0 d-flex align-items-center gap-2">
            <i class="bi bi-calendar-check"></i>
            <span>Çfarë të pret tani</span>
          </h5>
          <?php if ($havePendingTasks): ?>
            <span class="badge bg-warning-subtle text-dark text-uppercase" style="font-size:.7rem;">
              <i class="bi bi-exclamation-circle me-1"></i> Ke ende gjëra për të bërë
            </span>
          <?php else: ?>
            <span class="badge bg-success-subtle text-success text-uppercase" style="font-size:.7rem;">
              <i class="bi bi-check2-circle me-1"></i> Je në rregull për tani
            </span>
          <?php endif; ?>
        </div>

        <!-- Klasë virtuale (pa kalendar brenda kursit) -->
        <div class="ov-next-block">
          <div class="ov-section-title">Klasë virtuale</div>

          <?php if ($hasClassLink): ?>
            <div class="ov-next-main">
              <div class="text-muted">
                Hyr direkt në klasën virtuale të kursit.
              </div>

              <div class="ov-next-actions">
                <a href="<?= h($classLink) ?>" target="_blank" rel="noopener" class="btn btn-sm btn-moodle">
                  <i class="bi bi-camera-video me-1"></i>Hyr në klasë
                </a>
              </div>
            </div>
          <?php else: ?>
            <div class="d-flex align-items-center gap-3 py-2">
              <div><i class="bi bi-camera-video-off text-muted fs-3"></i></div>
              <div class="text-muted">
                Ky kurs nuk ka vendosur ende një link të klasës virtuale.
              </div>
            </div>
          <?php endif; ?>
        </div>

        <!-- Detyrat & kuizet në pritje -->
        <div class="ov-tasks">
          <div class="row g-3">
            <!-- Detyrat -->
            <div class="col-md-6">
              <div class="ov-section-title">Detyra në pritje</div>
              <?php if (!$pendingAssignments): ?>
                <div class="text-muted-soft small">Asnjë detyrë e hapur për momentin.</div>
              <?php else: ?>
                <ul class="ov-task-list">
                  <?php foreach ($pendingAssignments as $a): ?>
                    <li class="ov-task-item">
                      <div class="ov-task-left">
                        <div class="ov-task-icon">
                          <i class="bi bi-clipboard-check"></i>
                        </div>
                        <div>
                          <div class="ov-task-title">
                            <a href="assignment_details.php?assignment_id=<?= (int)$a['id'] ?>"
                               class="text-decoration-none"><?= h($a['title'] ?? '') ?></a>
                          </div>
                          <div class="ov-task-meta">
                            <?php if (!empty($a['due_date'])): ?>
                              Afat: <?= date('d M Y', strtotime((string)$a['due_date'])) ?>
                            <?php else: ?>
                              Pa afat
                            <?php endif; ?>
                          </div>
                        </div>
                      </div>
                    </li>
                  <?php endforeach; ?>
                </ul>
              <?php endif; ?>
            </div>

            <!-- Kuizet -->
            <div class="col-md-6">
              <div class="ov-section-title">Kuizet e hapura</div>
              <?php if (!$pendingQuizzes): ?>
                <div class="text-muted-soft small">Asnjë quiz i hapur për momentin.</div>
              <?php else: ?>
                <ul class="ov-task-list">
                  <?php foreach ($pendingQuizzes as $q): ?>
                    <li class="ov-task-item">
                      <div class="ov-task-left">
                        <div class="ov-task-icon">
                          <i class="bi bi-patch-question"></i>
                        </div>
                        <div>
                          <div class="ov-task-title">
                            <a href="quizzes/quiz_details.php?quiz_id=<?= (int)$q['id'] ?>"
                               class="text-decoration-none"><?= h($q['title'] ?? '') ?></a>
                          </div>
                          <div class="ov-task-meta">
                            <?php
                              $meta = [];
                              if (!empty($q['open_at']))  $meta[] = 'Hape: '.date('d M H:i', strtotime((string)$q['open_at']));
                              if (!empty($q['close_at'])) $meta[] = 'Mbyll: '.date('d M H:i', strtotime((string)$q['close_at']));
                              echo $meta ? implode(' • ', $meta) : 'Quiz i hapur';
                            ?>
                          </div>
                        </div>
                      </div>
                    </li>
                  <?php endforeach; ?>
                </ul>
              <?php endif; ?>
            </div>
          </div>
        </div>

      </div>
    </div>

    <!-- Progresi im + mini KPI -->
    <div class="col-lg-5">
      <div class="ov-card ov-card-progress">
        <h5 class="mb-2 d-flex align-items-center gap-2">
          <i class="bi bi-person-lines-fill"></i>
          <span>Ecuria ime</span>
        </h5>

        <div class="ov-progress-row mb-2">
          <div>
            <div class="d-flex justify-content-between mb-1">
              <span>Materiale të lexuara</span>
              <span class="small text-muted"><?= $lessonsPct ?>%</span>
            </div>
            <div class="progress">
              <div class="progress-bar" style="width:<?= $lessonsPct ?>%"></div>
            </div>
            <div class="small text-muted mt-1">
              <?= (int)$readLessonsCnt ?> / <?= (int)$totalLessons ?> materiale
            </div>
          </div>

          <div>
            <div class="d-flex justify-content-between mb-1">
              <span>Detyra të dorëzuara</span>
              <span class="small text-muted"><?= $myAssignPct ?>%</span>
            </div>
            <div class="progress">
              <div class="progress-bar bg-success" style="width:<?= $myAssignPct ?>%"></div>
            </div>
            <div class="small text-muted mt-1">
              <?= (int)$myAssignDone ?> / <?= (int)$cntAssign ?> detyra
            </div>
          </div>

          <div>
            <div class="d-flex justify-content-between mb-1">
              <span>Kuize të përfunduara</span>
              <span class="small text-muted"><?= $myQuizPct ?>%</span>
            </div>
            <div class="progress">
              <div class="progress-bar bg-info" style="width:<?= $myQuizPct ?>%"></div>
            </div>
            <div class="small text-muted mt-1">
              <?= (int)$myQuizDone ?> / <?= (int)$cntQuizzes ?> kuize
            </div>
          </div>
        </div>
      </div>
    </div>
  </div>

  <!-- Rreshti i dytë: Aktiviteti & Përshkrimi / Pagesa -->
  <div class="row g-3">
    <!-- Aktiviteti i fundit -->
    <div class="col-lg-7">
      <div class="ov-card ov-card-activity">
        <div class="d-flex justify-content-between align-items-center mb-2">
          <h5 class="mb-0 d-flex align-items-center gap-2">
            <i class="bi bi-activity"></i>
            <span>Aktiviteti i fundit (7 ditë)</span>
          </h5>
        </div>

        <?php if (!$activity): ?>
          <div class="text-muted-soft small">
            S’ka aktivitet të fundit në këtë kurs.
          </div>
        <?php else: ?>
          <div class="ov-timeline">
            <?php foreach ($activity as $ev): ?>
              <div class="ov-activity-item">
                <span class="ov-activity-dot"></span>
                <div class="ov-activity-type"><?= h($ev['type'] ?? '') ?></div>
                <div class="ov-activity-title">
                  <?php if (!empty($ev['url'])): ?>
                    <a href="<?= h($ev['url']) ?>" class="text-decoration-none"><?= h($ev['title'] ?? '') ?></a>
                  <?php else: ?>
                    <?= h($ev['title'] ?? '') ?>
                  <?php endif; ?>
                </div>
                <div class="ov-activity-meta">
                  <?php if (!empty($ev['ts'])): ?>
                    <?= date('d M Y, H:i', (int)$ev['ts']) ?>
                  <?php endif; ?>
                  <?php if (!empty($ev['meta'])): ?>
                    • <?= h($ev['meta']) ?>
                  <?php endif; ?>
                </div>
              </div>
            <?php endforeach; ?>
          </div>
        <?php endif; ?>
      </div>
    </div>

    <!-- Përshkrimi + Pagesa ime -->
    <div class="col-lg-5">
      <!-- Përshkrimi -->
      <div class="ov-card ov-card-desc">
        <h5 class="mb-2 d-flex align-items-center gap-2">
          <i class="bi bi-info-circle"></i>
          <span>Përshkrimi i kursit</span>
        </h5>
        <div id="ovDesc" class="ov-desc">
          <div class="prose"><?= $courseDescriptionHtml ?></div>
          <span class="ov-desc-fade"></span>
        </div>
        <?php if (!empty($course['description'])): ?>
          <div class="text-center mt-2">
            <button id="ovDescToggle" class="btn btn-outline-secondary btn-sm" type="button">
              <i class="bi bi-arrows-expand me-1"></i>Shfaq më shumë
            </button>
          </div>
        <?php endif; ?>
      </div>

      <!-- Pagesa ime -->
      <div class="ov-card ov-card-pay mt-3">
        <h5 class="mb-2 d-flex align-items-center gap-2">
          <i class="bi bi-cash-coin"></i>
          <span>Pagesa ime</span>
        </h5>
        <div class="ov-pay">
          <div>
            <span class="text-muted-soft small">Totali i paguar</span>
            <strong><?= number_format((float)$paidSum, 2) ?>€</strong>
          </div>
          <div class="text-end">
            <span class="text-muted-soft small">Numri i pagesave</span>
            <strong><?= (int)$paidCount ?></strong>
          </div>
        </div>
        <a href="course_details_student.php?course_id=<?= (int)$course_id ?>&tab=payments"
           class="btn btn-outline-dark btn-sm w-100 mt-2">
          <i class="bi bi-arrow-right-short me-1"></i>Shiko detajet e pagesave
        </a>
      </div>
    </div>
  </div>
</div>

<script>
  // Toggle përshkrimi
  (function(){
    const box = document.getElementById('ovDesc');
    const btn = document.getElementById('ovDescToggle');
    if (!box || !btn) return;
    btn.addEventListener('click', () => {
      box.classList.toggle('is-open');
      btn.innerHTML = box.classList.contains('is-open')
        ? '<i class="bi bi-arrows-collapse me-1"></i>Shfaq më pak'
        : '<i class="bi bi-arrows-expand me-1"></i>Shfaq më shumë';
    });
  })();
</script>
