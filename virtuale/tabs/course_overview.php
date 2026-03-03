<?php
/* =========================
   KPI & të dhëna bazë për kursin
   ========================= */
require_once __DIR__ . '/../lib/lib_access_code.php';
try { $stmtSec=$pdo->prepare("SELECT COUNT(*) FROM sections WHERE course_id=?"); $stmtSec->execute([$course_id]); $cntSections=(int)$stmtSec->fetchColumn(); } catch (PDOException $e){ $cntSections=0; }
try { $stmtLes=$pdo->prepare("SELECT COUNT(*) FROM lessons WHERE course_id=?"); $stmtLes->execute([$course_id]); $cntLessons=(int)$stmtLes->fetchColumn(); } catch (PDOException $e){ $cntLessons=0; }
try { $stmtAsg=$pdo->prepare("SELECT COUNT(*) FROM assignments WHERE course_id=?"); $stmtAsg->execute([$course_id]); $cntAssign=(int)$stmtAsg->fetchColumn(); } catch (PDOException $e){ $cntAssign=0; }
try { $stmtQz =$pdo->prepare("SELECT COUNT(*) FROM quizzes WHERE course_id=?"); $stmtQz->execute([$course_id]); $cntQuizzes=(int)$stmtQz->fetchColumn(); } catch (PDOException $e){ $cntQuizzes=0; }
try { $stmtPeo=$pdo->prepare("SELECT COUNT(*) FROM enroll WHERE course_id=?"); $stmtPeo->execute([$course_id]); $cntPeople=(int)$stmtPeo->fetchColumn(); } catch (PDOException $e){ $cntPeople=0; }

/* Klasë virtuale (pa kalendar brenda kursit) */
$classLink = trim((string)($course['AulaVirtuale'] ?? ''));
$hasClassLink = $classLink !== '' && filter_var($classLink, FILTER_VALIDATE_URL);

/* Threads pa përgjigje (skema e re threads pa lesson_id) */
try {
  $stmtUnanswered = $pdo->prepare("
    SELECT COUNT(*) 
    FROM threads t 
    WHERE t.course_id = ? 
      AND NOT EXISTS (SELECT 1 FROM thread_replies r WHERE r.thread_id = t.id)
  ");
  $stmtUnanswered->execute([$course_id]);
  $cntUnanswered = (int)$stmtUnanswered->fetchColumn();
} catch (PDOException $e) { $cntUnanswered=0; }

/* Pagesat (për kursin) */
try {
  $stmtPayMetrics = $pdo->prepare("
    SELECT 
      COUNT(*) AS cnt_all,
      COALESCE(SUM(p.amount),0) AS sum_all,
      SUM(CASE WHEN p.payment_status='COMPLETED' THEN 1 ELSE 0 END) AS cnt_completed,
      COALESCE(SUM(CASE WHEN p.payment_status='COMPLETED' THEN p.amount ELSE 0 END),0) AS sum_completed,
      SUM(CASE WHEN p.payment_status='FAILED' THEN 1 ELSE 0 END) AS cnt_failed,
      COALESCE(SUM(CASE WHEN p.payment_status='FAILED' THEN p.amount ELSE 0 END),0) AS sum_failed
    FROM payments p 
    WHERE p.course_id = ?
  ");
  $stmtPayMetrics->execute([$course_id]); 
  $payMetrics=$stmtPayMetrics->fetch(PDO::FETCH_ASSOC) ?: [
    'cnt_all'=>0,'sum_all'=>0,
    'cnt_completed'=>0,'sum_completed'=>0,
    'cnt_failed'=>0,'sum_failed'=>0
  ];
} catch (PDOException $e){
  $payMetrics=['cnt_all'=>0,'sum_all'=>0,'cnt_completed'=>0,'sum_completed'=>0,'cnt_failed'=>0,'sum_failed'=>0];
}

/* Përshkrimi (Parsedown safe) – supozojmë që $Parsedown & $course ekzistojnë nga file-i prind */
$courseDescriptionHtml = $Parsedown->text((string)($course['description'] ?? ''));

/* Access code (opsional) */
$HAS_ACCESS_CODE = ki_table_has_column($pdo, 'courses', 'access_code');
$courseAccessCode = '';
if ($HAS_ACCESS_CODE) {
  $courseAccessCode = trim((string)($course['access_code'] ?? ''));
}

/* Aktiviteti (7 ditët e fundit) */
$since = (new DateTime('-7 days'))->format('Y-m-d H:i:s');
$activity = [];
function _push_overview(&$arr,$ts,$type,$title,$url='',$meta=''){
  $arr[]=['ts'=>strtotime($ts),'type'=>$type,'title'=>$title,'url'=>$url,'meta'=>$meta];
}

try {
  $stmtL=$pdo->prepare("SELECT * FROM lessons WHERE course_id=? AND uploaded_at>=? ORDER BY uploaded_at DESC");
  $stmtL->execute([$course_id,$since]);
  foreach($stmtL as $l){
    _push_overview($activity,$l['uploaded_at'],'Leksion i ri',$l['title'],"lesson_details.php?lesson_id=".$l['id']);
  }
} catch (PDOException $e){}

try {
  $stmtA=$pdo->prepare("SELECT * FROM assignments WHERE course_id=? AND uploaded_at>=? ORDER BY uploaded_at DESC");
  $stmtA->execute([$course_id,$since]);
  foreach($stmtA as $a){
    _push_overview(
      $activity,
      $a['uploaded_at'],
      'Detyrë',
      $a['title'],
      "assignment_details.php?assignment_id=".$a['id'],
      !empty($a['due_date']) ? ('Afat: '.date('d M',strtotime((string)$a['due_date']))) : ''
    );
  }
} catch (PDOException $e){}

try {
  $stmtQ=$pdo->prepare("SELECT * FROM quizzes WHERE course_id=? ORDER BY id DESC");
  $stmtQ->execute([$course_id]);
  foreach($stmtQ as $qz){
    if(($qz['created_at']??'') >= $since){
      _push_overview($activity,$qz['created_at'],'Quiz i ri',$qz['title'],"quizzes/quiz_details.php?quiz_id=".$qz['id']);
    } elseif(($qz['updated_at']??'') >= $since){
      _push_overview($activity,$qz['updated_at'],'Quiz përditësuar',$qz['title'],"quizzes/quiz_details.php?quiz_id=".$qz['id']);
    }
  }
} catch (PDOException $e){}

try {
  $stmtT=$pdo->prepare("
    SELECT t.id,t.title,t.created_at,u.full_name 
    FROM threads t 
    JOIN users u ON u.id=t.user_id 
    WHERE t.course_id=? AND t.created_at>=?
    ORDER BY t.created_at DESC
  ");
  $stmtT->execute([$course_id,$since]);
  foreach($stmtT as $t){
    _push_overview($activity,$t['created_at'],'Temë në forum',$t['title'],"threads/thread_view.php?thread_id=".$t['id'],$t['full_name'] ?? '');
  }
} catch (PDOException $e){}

try {
  $stmtE=$pdo->prepare("SELECT * FROM enroll WHERE course_id=? AND enrolled_at>=?");
  $stmtE->execute([$course_id,$since]);
  foreach($stmtE as $e){
    _push_overview($activity,$e['enrolled_at'],'Regjistrim i ri','Student i ri');
  }
} catch (PDOException $e){}

try {
  $stmtP=$pdo->prepare("SELECT * FROM payments WHERE course_id=? AND payment_date>=?");
  $stmtP->execute([$course_id,$since]);
  foreach($stmtP as $p){
    $lab = $p['payment_status']==='COMPLETED' ? 'Pagesë (OK)' : 'Pagesë (FAIL)';
    _push_overview($activity,$p['payment_date'],$lab,number_format((float)$p['amount'],2).'€');
  }
} catch (PDOException $e){}

usort($activity, fn($x,$y)=> $y['ts']<=>$x['ts']);
$activity = array_slice($activity,0,10);

/* =========================
   Ecuria e studentëve (mesatare kursi)
   ========================= */
function safe_div_overview($num,$den){ return $den>0 ? round(($num/$den)*100) : 0; }

try { $stmt=$pdo->prepare("SELECT COUNT(*) FROM enroll WHERE course_id=?"); $stmt->execute([$course_id]); $numStudents=(int)$stmt->fetchColumn(); } catch (PDOException $e){ $numStudents=0; }
try { $stmt=$pdo->prepare("SELECT COUNT(*) FROM lessons WHERE course_id=?"); $stmt->execute([$course_id]); $totLessons=(int)$stmt->fetchColumn(); } catch (PDOException $e){ $totLessons=0; }
try { $stmt=$pdo->prepare("SELECT COUNT(*) FROM assignments WHERE course_id=?"); $stmt->execute([$course_id]); $totAssign=(int)$stmt->fetchColumn(); } catch (PDOException $e){ $totAssign=0; }
try { $stmt=$pdo->prepare("SELECT COUNT(*) FROM quizzes WHERE course_id=?"); $stmt->execute([$course_id]); $totQuizzes=(int)$stmt->fetchColumn(); } catch (PDOException $e){ $totQuizzes=0; }

/* Lexime materialesh */
try {
  $stmt = $pdo->prepare("
    SELECT COUNT(*) 
    FROM user_reads ur
    JOIN lessons l  ON l.id = ur.item_id AND ur.item_type='LESSON' AND l.course_id=?
    JOIN enroll  e  ON e.user_id = ur.user_id AND e.course_id = l.course_id
  ");
  $stmt->execute([$course_id]); 
  $readsTotal = (int)$stmt->fetchColumn();
} catch (PDOException $e){ $readsTotal=0; }

/* Dorëzime detyrash (unik per user/assignment) */
try {
  $stmt = $pdo->prepare("
    SELECT COUNT(DISTINCT s.user_id, s.assignment_id)
    FROM assignments_submitted s
    JOIN assignments a ON a.id=s.assignment_id AND a.course_id=?
    JOIN enroll     e ON e.user_id=s.user_id AND e.course_id=a.course_id
  ");
  $stmt->execute([$course_id]); 
  $submittedAssign = (int)$stmt->fetchColumn();
} catch (PDOException $e){ $submittedAssign=0; }

/* Kuize të përfunduara (unik per user/quiz) */
try {
  $stmt = $pdo->prepare("
    SELECT COUNT(DISTINCT qa.user_id, qa.quiz_id)
    FROM quiz_attempts qa
    JOIN quizzes q ON q.id=qa.quiz_id AND q.course_id=?
    JOIN enroll  e ON e.user_id=qa.user_id AND e.course_id=q.course_id
    WHERE qa.submitted_at IS NOT NULL
  ");
  $stmt->execute([$course_id]); 
  $completedQuizzes=(int)$stmt->fetchColumn();
} catch (PDOException $e){ $completedQuizzes=0; }

$avgLessonsPct = safe_div_overview($readsTotal,        max(1, $numStudents*$totLessons));
$avgAssignPct  = safe_div_overview($submittedAssign,   max(1, $numStudents*$totAssign));
$avgQuizPct    = safe_div_overview($completedQuizzes,  max(1, $numStudents*$totQuizzes));
?>


<div class="course-overview">
  <div class="row g-3">

    <!-- Kolona kryesore: Leksioni i ardhshëm + KPI + Aktivitet -->
    <div class="col-lg-8">

      <!-- Klasë virtuale (pa kalendar brenda kursit) -->
      <section class="co-card co-next">
        <div class="co-card-header">
          <div>
            <h5 class="mb-1"><i class="bi bi-camera-video me-2"></i>Klasë virtuale</h5>
            <p class="text-muted small mb-0">
              Lidhja direkte për në klasën virtuale të kursit.
            </p>
          </div>
        </div>

        <?php if ($hasClassLink): ?>
          <div class="co-next-main">
            <div class="text-muted small">
              Hap linkun e klasës virtuale në një dritare të re.
            </div>
            <div class="co-next-actions">
              <a href="<?= htmlspecialchars($classLink, ENT_QUOTES) ?>" target="_blank" rel="noopener" class="btn btn-sm btn-primary">
                <i class="bi bi-camera-video me-1"></i>Hap klasën
              </a>
            </div>
          </div>
        <?php else: ?>
          <div class="co-next-empty text-center py-4">
            <i class="bi bi-camera-video-off fs-1 text-muted"></i>
            <p class="mt-2 mb-0 text-muted">Ky kurs nuk ka vendosur ende një link të klasës virtuale.</p>
          </div>
        <?php endif; ?>
      </section>

      <!-- Snapshot i kursit -->
      <section class="co-card co-kpi mt-3">
        <div class="co-card-header mb-2">
          <div>
            <h6 class="mb-1"><i class="bi bi-speedometer2 me-2"></i>Snapshot i kursit</h6>
            <p class="small mb-0 text-muted">Pamje e shpejtë e strukturës dhe aktivitetit.</p>
          </div>
        </div>

        <div class="row g-2 co-kpi-grid">
          <div class="col-6 col-md-4">
            <div class="co-kpi-pill">
              <div class="co-kpi-icon"><i class="bi bi-folder"></i></div>
              <div class="co-kpi-body">
                <div class="co-kpi-value"><?= (int)$cntSections ?></div>
                <div class="co-kpi-label">Seksione</div>
              </div>
            </div>
          </div>
          <div class="col-6 col-md-4">
            <div class="co-kpi-pill">
              <div class="co-kpi-icon"><i class="bi bi-layers"></i></div>
              <div class="co-kpi-body">
                <div class="co-kpi-value"><?= (int)($cntLessons + $cntAssign + $cntQuizzes) ?></div>
                <div class="co-kpi-label">Elemente mësimore</div>
              </div>
            </div>
          </div>
          <div class="col-6 col-md-4">
            <div class="co-kpi-pill">
              <div class="co-kpi-icon"><i class="bi bi-people"></i></div>
              <div class="co-kpi-body">
                <div class="co-kpi-value"><?= (int)$cntPeople ?></div>
                <div class="co-kpi-label">Pjesëmarrës</div>
              </div>
            </div>
          </div>

          <div class="col-6 col-md-4">
            <div class="co-kpi-pill">
              <div class="co-kpi-icon"><i class="bi bi-chat-left-dots"></i></div>
              <div class="co-kpi-body">
                <div class="co-kpi-value"><?= (int)$cntUnanswered ?></div>
                <div class="co-kpi-label">Tema pa përgjigje</div>
              </div>
            </div>
          </div>

          <div class="col-6 col-md-4">
            <div class="co-kpi-pill">
              <div class="co-kpi-icon"><i class="bi bi-currency-euro"></i></div>
              <div class="co-kpi-body">
                <div class="co-kpi-value"><?= number_format((float)($payMetrics['sum_completed'] ?? 0),2) ?>€</div>
                <div class="co-kpi-label">Pagesa (OK)</div>
              </div>
            </div>
          </div>
        </div>
      </section>

      <!-- Aktiviteti i fundit -->
      <section class="co-card co-activity mt-3">
        <div class="co-card-header mb-2">
          <div>
            <h6 class="mb-1"><i class="bi bi-activity me-2"></i>Aktiviteti i fundit (7 ditë)</h6>
            <p class="small mb-0 text-muted">Lëvizjet e fundit në kurs: materiale, detyra, kuize, forum.</p>
          </div>
        </div>

        <?php if (!$activity): ?>
          <p class="text-muted small mb-0">S’ka aktivitet të regjistruar gjatë 7 ditëve të fundit.</p>
        <?php else: ?>
          <div class="co-timeline">
            <?php foreach ($activity as $ev): ?>
              <div class="co-timeline-item">
                <span class="co-dot"></span>
                <div class="co-timeline-content">
                  <div class="co-timeline-title">
                    <strong><?= htmlspecialchars($ev['type'],ENT_QUOTES) ?></strong>
                    <?php if (!empty($ev['url'])): ?>
                      — <a href="<?= htmlspecialchars($ev['url'],ENT_QUOTES) ?>" class="text-decoration-none">
                        <?= htmlspecialchars($ev['title'],ENT_QUOTES) ?>
                      </a>
                    <?php else: ?>
                      — <?= htmlspecialchars($ev['title'],ENT_QUOTES) ?>
                    <?php endif; ?>
                  </div>
                  <div class="co-timeline-meta">
                    <?= date('d M Y, H:i', $ev['ts']) ?>
                    <?= !empty($ev['meta']) ? ' • '.htmlspecialchars($ev['meta'],ENT_QUOTES) : '' ?>
                  </div>
                </div>
              </div>
            <?php endforeach; ?>
          </div>
        <?php endif; ?>
      </section>

    </div>

    <!-- Kolona djathtas: Përshkrimi, ecuria, financat, veprime të shpejta -->
    <div class="col-lg-4">

      <!-- Access code (për vetë-regjistrim) -->
      <section class="co-card co-access-code">
        <div class="co-card-header mb-2">
          <div>
            <h6 class="mb-1"><i class="bi bi-shield-lock me-2"></i>Access code</h6>
            <p class="small mb-0 text-muted">Studentët regjistrohen me këtë kod 5-shifror.</p>
          </div>
        </div>

        <?php if (!$HAS_ACCESS_CODE): ?>
          <div class="alert alert-warning py-2 mb-2">
            Skema e databazës nuk është përditësuar (mungon <code>courses.access_code</code>).
          </div>
          <button class="btn btn-sm btn-primary" type="button" disabled title="Përditëso DB dhe provo sërish">
            <i class="bi bi-magic me-1"></i>Gjenero kodin
          </button>
        <?php elseif ($courseAccessCode !== ''): ?>
          <div class="d-flex align-items-center justify-content-between gap-2">
            <div class="fw-bold" style="font-size:1.35rem; letter-spacing:.12em;">
              <span id="courseAccessCodeText"><?= htmlspecialchars($courseAccessCode, ENT_QUOTES) ?></span>
            </div>
            <button type="button" class="btn btn-sm btn-outline-secondary" id="copyAccessCodeBtn">
              <i class="bi bi-clipboard me-1"></i>Kopjo
            </button>
          </div>
          <div class="small text-muted mt-2">Sugjerim: mos e publiko në postime publike.</div>
        <?php else: ?>
          <div class="small text-muted mb-2">Ky kurs nuk ka ende një access code.</div>
          <form method="post" action="course_access_code_generate.php" class="d-flex gap-2">
            <input type="hidden" name="course_id" value="<?= (int)$course_id ?>">
            <input type="hidden" name="csrf" value="<?= htmlspecialchars((string)$CSRF, ENT_QUOTES) ?>">
            <button class="btn btn-sm btn-primary" type="submit">
              <i class="bi bi-magic me-1"></i>Gjenero kodin
            </button>
          </form>
        <?php endif; ?>
      </section>

      <!-- Përshkrimi i kursit -->
      <section class="co-card co-description">
        <div class="co-card-header mb-2">
          <div>
            <h6 class="mb-1"><i class="bi bi-info-circle me-2"></i>Përshkrimi i kursit</h6>
            <p class="small mb-0 text-muted">Çfarë mbulon ky kurs dhe si sugjerohet të ndiqet.</p>
          </div>
        </div>

        <div id="coDesc" class="co-desc">
          <div class="prose mb-1">
            <?= $courseDescriptionHtml ?: '<span class="text-muted small">Pa përshkrim.</span>' ?>
          </div>
          <?php if (!empty($course['description'])): ?>
            <span class="co-desc-fade"></span>
          <?php endif; ?>
        </div>

        <?php if (!empty($course['description'])): ?>
          <div class="text-center mt-2">
            <button id="coDescToggle" class="btn btn-outline-secondary btn-sm" type="button">
              <i class="bi bi-arrows-expand me-1"></i>Shfaq më shumë
            </button>
          </div>
        <?php endif; ?>
      </section>

      <!-- Ecuria mesatare e studentëve -->
      <section class="co-card co-progress mt-3">
        <div class="co-card-header mb-2">
          <div>
            <h6 class="mb-1"><i class="bi bi-graph-up-arrow me-2"></i>Ecuria e studentëve</h6>
            <p class="small mb-0 text-muted">Përqindje mesatare bazuar në aktivitetet kryesore.</p>
          </div>
        </div>

        <div class="mb-2">
          <div class="d-flex justify-content-between">
            <span class="small">Materiale të lexuara</span>
            <span class="small text-muted"><?= $avgLessonsPct ?>%</span>
          </div>
          <div class="progress co-progress-bar">
            <div class="progress-bar" style="width:<?= $avgLessonsPct ?>%"></div>
          </div>
        </div>

        <div class="mb-2">
          <div class="d-flex justify-content-between">
            <span class="small">Detyra të dorëzuara</span>
            <span class="small text-muted"><?= $avgAssignPct ?>%</span>
          </div>
          <div class="progress co-progress-bar">
            <div class="progress-bar bg-success" style="width:<?= $avgAssignPct ?>%"></div>
          </div>
        </div>

        <div>
          <div class="d-flex justify-content-between">
            <span class="small">Kuize të përfunduara</span>
            <span class="small text-muted"><?= $avgQuizPct ?>%</span>
          </div>
          <div class="progress co-progress-bar">
            <div class="progress-bar bg-info" style="width:<?= $avgQuizPct ?>%"></div>
          </div>
        </div>

        <p class="small text-muted mt-2 mb-0">
          Bazuar në <?= (int)$numStudents ?> studentë, <?= (int)$totLessons ?> materiale,
          <?= (int)$totAssign ?> detyra dhe <?= (int)$totQuizzes ?> kuize.
        </p>
      </section>

      <!-- Financa -->
      <section class="co-card co-payments mt-3">
        <div class="co-card-header mb-2">
          <div>
            <h6 class="mb-1"><i class="bi bi-cash-coin me-2"></i>Financa e kursit</h6>
            <p class="small mb-0 text-muted">Pasqyra bazë e pagesave për këtë kurs.</p>
          </div>
        </div>

        <div class="co-pay-grid">
          <div>
            <div class="small text-muted">Totali (të gjitha)</div>
            <div class="fw-semibold">
              <?= number_format((float)($payMetrics['sum_all'] ?? 0), 2) ?>€
            </div>
            <div class="tiny text-muted">
              <?= (int)($payMetrics['cnt_all'] ?? 0) ?> transaksione
            </div>
          </div>

          <div>
            <div class="small text-muted text-success">Të kryera</div>
            <div class="fw-semibold text-success">
              <?= number_format((float)($payMetrics['sum_completed'] ?? 0), 2) ?>€
            </div>
            <div class="tiny text-muted">
              <?= (int)($payMetrics['cnt_completed'] ?? 0) ?> OK
            </div>
          </div>

          <div>
            <div class="small text-muted text-danger">Dështuar</div>
            <div class="fw-semibold text-danger">
              <?= number_format((float)($payMetrics['sum_failed'] ?? 0), 2) ?>€
            </div>
            <div class="tiny text-muted">
              <?= (int)($payMetrics['cnt_failed'] ?? 0) ?> FAIL
            </div>
          </div>
        </div>

        <a class="btn btn-sm btn-outline-dark w-100 mt-2"
           href="#payments" data-bs-toggle="tab">
          <i class="bi bi-arrow-right-short me-1"></i>Hap tab-in "Pagesat"
        </a>
      </section>
    </div>
  </div>
</div>

<script>
(function(){
  const box = document.getElementById('coDesc');
  const btn = document.getElementById('coDescToggle');
  if (box && btn) {
    btn.addEventListener('click', () => {
      box.classList.toggle('is-open');
      btn.innerHTML = box.classList.contains('is-open')
        ? '<i class="bi bi-arrows-collapse me-1"></i>Shfaq më pak'
        : '<i class="bi bi-arrows-expand me-1"></i>Shfaq më shumë';
    });
  }

  const copyBtn = document.getElementById('copyAccessCodeBtn');
  if (copyBtn) {
    copyBtn.addEventListener('click', async () => {
      const txtEl = document.getElementById('courseAccessCodeText');
      const code = txtEl ? (txtEl.textContent || '').trim() : '';
      if (!code) return;
      try {
        await navigator.clipboard.writeText(code);
        copyBtn.innerHTML = '<i class="bi bi-check2 me-1"></i>U kopjua';
        setTimeout(() => { copyBtn.innerHTML = '<i class="bi bi-clipboard me-1"></i>Kopjo'; }, 1500);
      } catch (e) {
        // fallback
        const ta = document.createElement('textarea');
        ta.value = code;
        document.body.appendChild(ta);
        ta.select();
        document.execCommand('copy');
        document.body.removeChild(ta);
      }
    });
  }
})();
</script>
