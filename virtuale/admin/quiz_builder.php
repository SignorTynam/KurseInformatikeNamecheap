<?php
// quiz_builder_v2.php — Quiz Builder (NEW UI/UX: 2-col + tabs; light QTA-like; scoped CSS)
// Vendose brenda admin/ (si file i ri) dhe lëre versionin e vjetër si backup.
declare(strict_types=1);
session_start();
require_once __DIR__ . '/../lib/database.php';

/* -------------------- PDO safe -------------------- */
$pdo = $pdo ?? (function_exists('getPDO') ? getPDO() : null);
if (!($pdo instanceof PDO)) { http_response_code(500); exit('DB connection missing.'); }

/* -------------------- RBAC -------------------- */
if (!isset($_SESSION['user']) || !in_array($_SESSION['user']['role'] ?? '', ['Administrator','Instruktor'], true)) {
  header('Location: login.php'); exit;
}
$ROLE  = (string)($_SESSION['user']['role'] ?? '');
$ME_ID = (int)($_SESSION['user']['id'] ?? 0);

/* -------------------- CSRF -------------------- */
if (empty($_SESSION['csrf_token'])) { $_SESSION['csrf_token'] = bin2hex(random_bytes(32)); }
$CSRF = (string)$_SESSION['csrf_token'];

function h(?string $s): string { return htmlspecialchars($s ?? '', ENT_QUOTES, 'UTF-8'); }
function json_response(int $status, array $data): void {
  http_response_code($status);
  header('Content-Type: application/json; charset=utf-8');
  echo json_encode($data); exit;
}

/* -------------------- Input ------------------- */
if (!isset($_GET['quiz_id']) || !is_numeric($_GET['quiz_id'])) { http_response_code(400); exit('Quiz nuk është specifikuar.'); }
$quiz_id = (int)$_GET['quiz_id'];

/* -------------------- Load quiz + course ------- */
try {
  $stmt = $pdo->prepare("
    SELECT q.*, c.id AS course_id, c.id_creator, c.title AS course_title
    FROM quizzes q
    JOIN courses c ON c.id = q.course_id
    WHERE q.id = ?
    LIMIT 1
  ");
  $stmt->execute([$quiz_id]);
  $quiz = $stmt->fetch(PDO::FETCH_ASSOC);
  if (!$quiz) { http_response_code(404); exit('Quiz nuk u gjet.'); }

  if ($ROLE === 'Instruktor' && (int)($quiz['id_creator'] ?? 0) !== $ME_ID) {
    http_response_code(403); exit('Nuk keni akses.');
  }

  $course_id   = (int)($quiz['course_id'] ?? 0);
  $courseTitle = (string)($quiz['course_title'] ?? '');
} catch (Throwable $e) {
  http_response_code(500);
  exit('Gabim: ' . h($e->getMessage()));
}

/* -------------------- Health helper ------------------- */
function count_health(PDO $pdo, int $quiz_id): array {
  $q = $pdo->prepare("SELECT id FROM quiz_questions WHERE quiz_id=?");
  $q->execute([$quiz_id]);
  $rows = $q->fetchAll(PDO::FETCH_ASSOC) ?: [];
  $no = 0; $bad = 0;

  foreach ($rows as $r) {
    $qid = (int)$r['id'];
    $cntAll = (int)$pdo->query("SELECT COUNT(*) FROM quiz_answers WHERE question_id=$qid")->fetchColumn();
    $cntOK  = (int)$pdo->query("SELECT COUNT(*) FROM quiz_answers WHERE question_id=$qid AND is_correct=1")->fetchColumn();
    if ($cntAll < 1) $no++;
    if ($cntOK !== 1) $bad++;
  }

  return [
    'qCount' => count($rows),
    'noAnswerQs' => $no,
    'badCorrectQs' => $bad,
    'canPublish' => (count($rows) > 0 && $no === 0 && $bad === 0),
  ];
}
$health = count_health($pdo, $quiz_id);

/* -------------------- Flash ------------------- */
$flash = $_SESSION['flash'] ?? null;
unset($_SESSION['flash']);
$flashMsg = '';
$flashOk  = true;
if (is_array($flash)) {
  $flashMsg = (string)($flash['msg'] ?? '');
  $type = (string)($flash['type'] ?? 'info');
  $flashOk = !in_array($type, ['danger','error'], true);
}

/* -------------------- AJAX API (no refresh) ---- */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && (($_POST['ajax'] ?? '') === '1')) {
  if (!hash_equals($CSRF, (string)($_POST['csrf'] ?? ''))) {
    json_response(403, ['ok'=>false, 'error'=>'CSRF i pavlefshëm.']);
  }
  $action = (string)($_POST['action'] ?? '');

  try {
    if ($action === 'state') {
      $qs = $pdo->prepare("SELECT id, question, position FROM quiz_questions WHERE quiz_id=? ORDER BY position ASC, id ASC");
      $qs->execute([$quiz_id]);
      $questions = $qs->fetchAll(PDO::FETCH_ASSOC) ?: [];

      $answersByQ = [];
      if ($questions) {
        $qids = array_map(fn($r)=>(int)$r['id'], $questions);
        $in = implode(',', array_fill(0, count($qids), '?'));
        $sa = $pdo->prepare("
          SELECT id, question_id, answer_text, is_correct, position
          FROM quiz_answers
          WHERE question_id IN ($in)
          ORDER BY position ASC, id ASC
        ");
        $sa->execute($qids);
        foreach ($sa as $a) { $answersByQ[(int)$a['question_id']][] = $a; }
      }

      $noAns=0; $bad=0;
      foreach ($questions as $q) {
        $arr = $answersByQ[(int)$q['id']] ?? [];
        if (count($arr) < 1) $noAns++;
        $c=0; foreach ($arr as $aa) { if ((int)$aa['is_correct'] === 1) $c++; }
        if ($c !== 1) $bad++;
      }

      json_response(200, [
        'ok'=>true,
        'quiz'=>[
          'title'=>(string)($quiz['title'] ?? ''),
          'status'=>(string)($quiz['status'] ?? 'DRAFT'),
          'open_at'=>$quiz['open_at'],
          'close_at'=>$quiz['close_at'],
          'attempts_allowed'=>(int)($quiz['attempts_allowed'] ?? 1),
          'time_limit_sec'=>$quiz['time_limit_sec'] ? (int)$quiz['time_limit_sec'] : 0,
          'shuffle_questions'=>(int)($quiz['shuffle_questions'] ?? 0),
          'shuffle_answers'=>(int)($quiz['shuffle_answers'] ?? 0),
          'description'=>(string)($quiz['description'] ?? ''),
        ],
        'questions'=>$questions,
        'answers'=>$answersByQ,
        'health'=>[
          'qCount'=>count($questions),
          'noAnswerQs'=>$noAns,
          'badCorrectQs'=>$bad,
          'canPublish'=>(count($questions)>0 && $noAns===0 && $bad===0),
        ],
      ]);
    }

    elseif ($action === 'create_question') {
      $text = trim((string)($_POST['question'] ?? ''));
      if ($text === '') throw new Exception('Shkruaj pyetjen.');
      $pstmt = $pdo->prepare("SELECT COALESCE(MAX(position),0)+1 FROM quiz_questions WHERE quiz_id=?");
      $pstmt->execute([$quiz_id]); $pos = (int)$pstmt->fetchColumn();
      $ins = $pdo->prepare("INSERT INTO quiz_questions (quiz_id, question, position) VALUES (?,?,?)");
      $ins->execute([$quiz_id, $text, $pos]);
      $qid = (int)$pdo->lastInsertId();
      json_response(200, ['ok'=>true,'question'=>['id'=>$qid,'question'=>$text,'position'=>$pos]]);
    }

    elseif ($action === 'duplicate_question') {
      $qid = (int)($_POST['question_id'] ?? 0);
      if ($qid <= 0) throw new Exception('Pyetja mungon.');
      $chk = $pdo->prepare("SELECT id FROM quiz_questions WHERE id=? AND quiz_id=?");
      $chk->execute([$qid,$quiz_id]);
      if (!$chk->fetch()) throw new Exception('Pyetja nuk u gjet.');

      $pdo->beginTransaction();
      $posStmt = $pdo->prepare("SELECT COALESCE(MAX(position),0)+1 FROM quiz_questions WHERE quiz_id=?");
      $posStmt->execute([$quiz_id]); $pos = (int)$posStmt->fetchColumn();

      $pdo->prepare("
        INSERT INTO quiz_questions (quiz_id, question, position)
        SELECT quiz_id, CONCAT(question,' (kopje)'), ?
        FROM quiz_questions
        WHERE id=?
      ")->execute([$pos, $qid]);

      $newQid = (int)$pdo->lastInsertId();

      $pdo->prepare("
        INSERT INTO quiz_answers (question_id, answer_text, is_correct, position)
        SELECT ?, answer_text, is_correct, position
        FROM quiz_answers
        WHERE question_id=?
      ")->execute([$newQid, $qid]);

      $pdo->commit();
      json_response(200, ['ok'=>true, 'question'=>['id'=>$newQid,'position'=>$pos]]);
    }

    elseif ($action === 'update_question') {
      $qid = (int)($_POST['question_id'] ?? 0);
      $text = trim((string)($_POST['question'] ?? ''));
      if ($qid <= 0 || $text === '') throw new Exception('Të dhëna të paplota.');
      $u = $pdo->prepare("UPDATE quiz_questions SET question=? WHERE id=? AND quiz_id=?");
      $u->execute([$text,$qid,$quiz_id]);
      json_response(200, ['ok'=>true]);
    }

    elseif ($action === 'delete_question') {
      $qid = (int)($_POST['question_id'] ?? 0);
      $d = $pdo->prepare("DELETE FROM quiz_questions WHERE id=? AND quiz_id=?");
      $d->execute([$qid,$quiz_id]);
      json_response(200, ['ok'=>true]);
    }

    elseif ($action === 'reorder_questions') {
      $ids = json_decode((string)($_POST['order'] ?? '[]'), true);
      if (!is_array($ids)) throw new Exception('Format renditjeje i pasaktë.');
      $pdo->beginTransaction();
      $pos = 1;
      $u = $pdo->prepare("UPDATE quiz_questions SET position=? WHERE id=? AND quiz_id=?");
      foreach ($ids as $qid) { $u->execute([$pos++, (int)$qid, $quiz_id]); }
      $pdo->commit();
      json_response(200, ['ok'=>true]);
    }

    elseif ($action === 'create_answer') {
      $qid = (int)($_POST['question_id'] ?? 0);
      $text = trim((string)($_POST['answer_text'] ?? ''));
      if ($qid<=0 || $text==='') throw new Exception('Shkruaj alternativën.');
      $chk = $pdo->prepare("SELECT id FROM quiz_questions WHERE id=? AND quiz_id=?");
      $chk->execute([$qid,$quiz_id]);
      if (!$chk->fetch()) throw new Exception('Pyetja është e pavlefshme.');
      $p = $pdo->prepare("SELECT COALESCE(MAX(position),0)+1 FROM quiz_answers WHERE question_id=?");
      $p->execute([$qid]); $pos=(int)$p->fetchColumn();
      $ins = $pdo->prepare("INSERT INTO quiz_answers (question_id, answer_text, position) VALUES (?,?,?)");
      $ins->execute([$qid,$text,$pos]);
      $aid = (int)$pdo->lastInsertId();
      json_response(200, ['ok'=>true,'answer'=>['id'=>$aid,'question_id'=>$qid,'answer_text'=>$text,'is_correct'=>0,'position'=>$pos]]);
    }

    elseif ($action === 'update_answer') {
      $aid = (int)($_POST['answer_id'] ?? 0);
      $text = trim((string)($_POST['answer_text'] ?? ''));
      if ($aid<=0 || $text==='') throw new Exception('Alt. bosh.');
      $u = $pdo->prepare("
        UPDATE quiz_answers qa
        JOIN quiz_questions qq ON qq.id=qa.question_id
        SET qa.answer_text=?
        WHERE qa.id=? AND qq.quiz_id=?
      ");
      $u->execute([$text,$aid,$quiz_id]);
      json_response(200, ['ok'=>true]);
    }

    elseif ($action === 'reorder_answers') {
      $qid = (int)($_POST['question_id'] ?? 0);
      $order = json_decode((string)($_POST['order'] ?? '[]'), true);
      if ($qid<=0 || !is_array($order)) throw new Exception('Të dhëna të pavlefshme.');
      $chk = $pdo->prepare("SELECT id FROM quiz_questions WHERE id=? AND quiz_id=?");
      $chk->execute([$qid,$quiz_id]);
      if (!$chk->fetch()) throw new Exception('Pyetja nuk i përket këtij kuizi.');
      $pdo->beginTransaction();
      $pos=1; $u=$pdo->prepare("UPDATE quiz_answers SET position=? WHERE id=? AND question_id=?");
      foreach ($order as $aid){ $u->execute([$pos++, (int)$aid, $qid]); }
      $pdo->commit();
      json_response(200, ['ok'=>true]);
    }

    elseif ($action === 'delete_answer') {
      $aid = (int)($_POST['answer_id'] ?? 0);
      $d = $pdo->prepare("
        DELETE qa FROM quiz_answers qa
        JOIN quiz_questions qq ON qq.id=qa.question_id
        WHERE qa.id=? AND qq.quiz_id=?
      ");
      $d->execute([$aid,$quiz_id]);
      json_response(200, ['ok'=>true]);
    }

    elseif ($action === 'mark_correct') {
      $aid = (int)($_POST['answer_id'] ?? 0);
      $st = $pdo->prepare("
        SELECT qa.question_id
        FROM quiz_answers qa
        JOIN quiz_questions qq ON qq.id=qa.question_id
        WHERE qa.id=? AND qq.quiz_id=?
        LIMIT 1
      ");
      $st->execute([$aid,$quiz_id]);
      $row = $st->fetch(PDO::FETCH_ASSOC);
      if (!$row) throw new Exception('Alternativa nuk u gjet.');
      $qid = (int)$row['question_id'];

      $pdo->beginTransaction();
      $pdo->prepare("UPDATE quiz_answers SET is_correct=0 WHERE question_id=?")->execute([$qid]);
      $pdo->prepare("UPDATE quiz_answers SET is_correct=1 WHERE id=?")->execute([$aid]);
      $pdo->commit();
      json_response(200, ['ok'=>true,'question_id'=>$qid,'answer_id'=>$aid]);
    }

    elseif ($action === 'publish_quiz') {
      $h = count_health($pdo, $quiz_id);
      if (!$h['canPublish']) throw new Exception('Quiz nuk është gati për publikim (kontrollo “Health”).');
      $pdo->prepare("UPDATE quizzes SET status='PUBLISHED' WHERE id=?")->execute([$quiz_id]);
      json_response(200, ['ok'=>true,'status'=>'PUBLISHED']);
    }

    elseif ($action === 'unpublish_quiz') {
      $pdo->prepare("UPDATE quizzes SET status='DRAFT' WHERE id=?")->execute([$quiz_id]);
      json_response(200, ['ok'=>true,'status'=>'DRAFT']);
    }

    elseif ($action === 'update_meta') {
      $title = trim((string)($_POST['title'] ?? ''));
      $description = (string)($_POST['description'] ?? '');
      $open_at = (string)($_POST['open_at'] ?? '');
      $close_at = (string)($_POST['close_at'] ?? '');
      $time_limit_min = (int)($_POST['time_limit_min'] ?? 0);
      $attempts = max(1, (int)($_POST['attempts_allowed'] ?? 1));
      $sq = isset($_POST['shuffle_questions']) ? 1 : 0;
      $sa = isset($_POST['shuffle_answers']) ? 1 : 0;

      if ($title === '') throw new Exception('Titulli i detyrueshëm.');
      if ($open_at && $close_at && strtotime($close_at) <= strtotime($open_at)) throw new Exception('Mbyllja duhet pas hapjes.');

      $stmtU = $pdo->prepare("
        UPDATE quizzes
        SET title=?, description=?, open_at=?, close_at=?, time_limit_sec=?, attempts_allowed=?, shuffle_questions=?, shuffle_answers=?
        WHERE id=?
      ");
      $stmtU->execute([
        $title,
        $description,
        $open_at ?: null,
        $close_at ?: null,
        $time_limit_min > 0 ? $time_limit_min * 60 : null,
        $attempts,
        $sq,
        $sa,
        $quiz_id
      ]);

      // Rifresko $quiz lokal (për UI të parë)
      $quiz['title'] = $title;
      $quiz['description'] = $description;
      $quiz['open_at'] = $open_at ?: null;
      $quiz['close_at'] = $close_at ?: null;
      $quiz['time_limit_sec'] = $time_limit_min > 0 ? $time_limit_min * 60 : null;
      $quiz['attempts_allowed'] = $attempts;
      $quiz['shuffle_questions'] = $sq;
      $quiz['shuffle_answers'] = $sa;

      json_response(200, ['ok'=>true]);
    }

    else {
      json_response(400, ['ok'=>false,'error'=>'Veprim i panjohur.']);
    }

  } catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    json_response(400, ['ok'=>false,'error'=>$e->getMessage()]);
  }
}
?>
<!DOCTYPE html>
<html lang="sq" data-bs-theme="light">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <title>Quiz Builder — kurseinformatike.com</title>

  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
  <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css" rel="stylesheet">
  <link rel="icon" href="image/favicon.ico" type="image/x-icon" />

  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">
  <link rel="icon" href="image/favicon.ico" type="image/x-icon" />

  <style>
    /* ==========================================================
      Quiz Builder v2 — scoped styles (no global collisions)
      Gjithçka është nën .qb-page
    ========================================================== */
    .qb-page{
      --qb-bg:#f6f8fc;
      --qb-surface:#ffffff;
      --qb-surface-2:#f1f5f9;
      --qb-text:#0f172a;
      --qb-muted:#64748b;
      --qb-border:#e5e7eb;
      --qb-primary:#4f46e5;
      --qb-primary-2:#1d4ed8;
      --qb-success:#16a34a;
      --qb-warning:#f59e0b;
      --qb-danger:#dc2626;
      --qb-radius:16px;
      --qb-shadow:0 10px 28px rgba(0,0,0,.08);
      --qb-shadow-sm:0 6px 18px rgba(0,0,0,.06);
      background: var(--qb-bg);
      color: var(--qb-text);
      min-height: 100vh;
      font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, "Helvetica Neue", Arial, "Noto Sans", "Liberation Sans", sans-serif;
    }

    .qb-wrap{ padding: 18px 0 34px; }
    .qb-card{
      background: var(--qb-surface);
      border: 1px solid var(--qb-border);
      border-radius: var(--qb-radius);
      box-shadow: var(--qb-shadow-sm);
    }
    .qb-card .qb-card-h{
      padding: 14px 16px;
      border-bottom: 1px solid var(--qb-border);
    }
    .qb-card .qb-card-b{ padding: 14px 16px; }

    .qb-pill{
      display:inline-flex;
      align-items:center;
      gap:.4rem;
      border: 1px solid var(--qb-border);
      background: #fff;
      border-radius: 999px;
      padding: .28rem .65rem;
      font-weight: 600;
      font-size: .85rem;
      color: var(--qb-text);
    }
    .qb-pill.qb-pill-draft{ border-color: #cbd5e1; background:#f8fafc; }
    .qb-pill.qb-pill-pub{ border-color: #bbf7d0; background:#f0fdf4; color:#14532d; }
    .qb-muted{ color: var(--qb-muted); }

    /* Header */
    .qb-header{
      padding: 14px 16px;
      background: linear-gradient(135deg, #ffffff, #f8fafc);
      border: 1px solid var(--qb-border);
      border-radius: var(--qb-radius);
      box-shadow: var(--qb-shadow-sm);
    }
    .qb-title{
      font-size: 1.15rem;
      font-weight: 800;
      margin: 0;
      line-height: 1.2;
    }
    .qb-subtitle{
      margin: 2px 0 0;
      color: var(--qb-muted);
      font-size: .95rem;
    }
    .qb-title-input{
      border-radius: 12px;
      border: 1px solid var(--qb-border);
      padding: .55rem .7rem;
      font-weight: 700;
    }

    /* Left list */
    .qb-qlist{
      max-height: calc(100vh - 320px);
      overflow: auto;
      padding-right: 6px;
    }
    .qb-qitem{
      display:flex;
      align-items:flex-start;
      gap:10px;
      padding: 10px 10px;
      border: 1px solid var(--qb-border);
      border-radius: 14px;
      background: #fff;
      margin-bottom: 10px;
      transition: transform .05s ease, box-shadow .15s ease, border-color .15s ease;
    }
    .qb-qitem:hover{ box-shadow: var(--qb-shadow-sm); }
    .qb-qitem.active{
      border-color: rgba(79,70,229,.45);
      box-shadow: 0 0 0 .18rem rgba(79,70,229,.10);
    }
    .qb-qdrag{ color:#94a3b8; cursor: grab; padding-top: 2px; }
    .qb-qmeta{ font-size: .82rem; color: var(--qb-muted); }
    .qb-qtitle{ font-weight: 700; margin: 0; }
    .qb-qbtn{ border-radius: 12px; }

    /* Workspace */
    .qb-tabs .nav-link{
      border-radius: 999px;
      font-weight: 700;
      color: #334155;
    }
    .qb-tabs .nav-link.active{
      background: rgba(79,70,229,.12);
      color: #111827;
    }

    .qb-empty{
      border: 1px dashed var(--qb-border);
      border-radius: 16px;
      padding: 28px 16px;
      text-align: center;
      color: var(--qb-muted);
      background: #fff;
    }

    /* Answers */
    .qb-ans{
      display:flex;
      align-items:center;
      gap: 10px;
      border: 1px solid var(--qb-border);
      border-radius: 14px;
      padding: 10px;
      background: #fff;
      margin-bottom: 10px;
    }
    .qb-ans.correct{
      border-color: rgba(22,163,74,.35);
      background: #f0fdf4;
    }
    .qb-ans-handle{ cursor: grab; color:#94a3b8; }
    .qb-ans input.form-control{ border-radius: 12px; }

    /* Toast */
    .qb-toast{
      position: fixed;
      right: 16px;
      bottom: 16px;
      z-index: 1080;
      background: #0f172a;
      color: #fff;
      padding: 10px 14px;
      border-radius: 12px;
      box-shadow: var(--qb-shadow);
      display: none;
      max-width: min(420px, 92vw);
      font-weight: 600;
    }
    .qb-toast.bad{ background: #7f1d1d; }

    /* Small helper for sticky on large screens */
    @media (min-width: 992px){
      .qb-sticky{ position: sticky; top: 12px; }
    }
  </style>
</head>

<body class="qb-page">
<?php
  // Navbars existing
  if ($ROLE === 'Administrator') include __DIR__ . '/../navbar_logged_administrator.php';
  else                           include __DIR__ . '/../navbar_logged_instruktor.php';
?>

<main class="container qb-wrap">

  <!-- Header -->
  <div class="qb-header mb-3">
    <div class="d-flex flex-wrap align-items-start justify-content-between gap-3">
      <div class="me-auto">
        <div class="d-flex flex-wrap align-items-center gap-2">
          <a class="btn btn-outline-secondary btn-sm qb-qbtn"
             href="../course_details.php?course_id=<?= (int)$course_id ?>&tab=materials">
            <i class="bi bi-arrow-left"></i> Kthehu
          </a>

          <?php $st = (string)($quiz['status'] ?? 'DRAFT'); ?>
          <span class="qb-pill <?= $st==='PUBLISHED' ? 'qb-pill-pub' : 'qb-pill-draft' ?>" id="qbStatusPill">
            <i class="bi <?= $st==='PUBLISHED' ? 'bi-broadcast' : 'bi-pencil' ?>"></i>
            <span id="qbStatusText"><?= h($st) ?></span>
          </span>
        </div>

        <h1 class="qb-title mt-2 mb-1">Ndërtuesi i Kuizit</h1>
        <div class="qb-subtitle">
          Kursi: <strong><?= h($courseTitle) ?></strong>
          <span class="qb-muted">•</span>
          Quiz ID: <strong>#<?= (int)$quiz_id ?></strong>
        </div>
      </div>

      <div class="d-flex flex-wrap align-items-center gap-2">
        <div class="input-group input-group-sm" style="min-width: min(520px, 92vw);">
          <span class="input-group-text bg-white"><i class="bi bi-type"></i></span>
          <input id="qbTitleInline" class="form-control qb-title-input" value="<?= h((string)($quiz['title'] ?? '')) ?>" placeholder="Titulli i kuizit…">
          <button id="qbSaveTitle" class="btn btn-outline-primary" type="button" title="Ruaj titullin">
            <i class="bi bi-floppy"></i>
          </button>
        </div>

        <button id="qbPublishBtn" class="btn btn-success btn-sm"
                <?= $health['canPublish'] ? '' : 'disabled' ?>>
          <i class="bi bi-upload me-1"></i> Publiko
        </button>

        <button id="qbUnpublishBtn" class="btn btn-outline-secondary btn-sm"
                style="<?= $st==='PUBLISHED' ? '' : 'display:none;' ?>">
          <i class="bi bi-eye-slash me-1"></i> Draft
        </button>
      </div>
    </div>
  </div>

  <div class="row g-3">

    <!-- LEFT: Questions -->
    <div class="col-lg-4">
      <div class="qb-card qb-sticky">
        <div class="qb-card-h d-flex align-items-center justify-content-between">
          <div class="d-flex align-items-center gap-2">
            <i class="bi bi-list-check"></i>
            <strong>Pyetjet</strong>
          </div>
          <span class="qb-muted small"><span id="qbQCount"><?= (int)$health['qCount'] ?></span> gjithsej</span>
        </div>

        <div class="qb-card-b">
          <div class="mb-2">
            <label class="form-label small qb-muted">Shto pyetje</label>
            <input id="qbNewQInput" class="form-control" placeholder="Shkruaj pyetjen dhe shtyp Enter…">
            <div class="small qb-muted mt-1">
              Enter = krijo. Zvarrit për renditje.
            </div>
          </div>

          <div class="input-group mb-2">
            <span class="input-group-text bg-white"><i class="bi bi-search"></i></span>
            <input id="qbFilterQ" class="form-control" placeholder="Filtro pyetjet…">
          </div>

          <div id="qbQList" class="qb-qlist" aria-live="polite"></div>
        </div>
      </div>
    </div>

    <!-- RIGHT: Workspace -->
    <div class="col-lg-8">
      <div class="qb-card">
        <div class="qb-card-h">
          <ul class="nav nav-pills qb-tabs gap-2" role="tablist">
            <li class="nav-item" role="presentation">
              <button class="nav-link active" data-bs-toggle="pill" data-bs-target="#qbTabEditor" type="button" role="tab">
                <i class="bi bi-pencil-square me-1"></i> Editor
              </button>
            </li>
            <li class="nav-item" role="presentation">
              <button class="nav-link" data-bs-toggle="pill" data-bs-target="#qbTabMeta" type="button" role="tab">
                <i class="bi bi-gear me-1"></i> Parametra
              </button>
            </li>
            <li class="nav-item" role="presentation">
              <button class="nav-link" data-bs-toggle="pill" data-bs-target="#qbTabHealth" type="button" role="tab">
                <i class="bi bi-heart-pulse me-1"></i> Health
              </button>
            </li>
          </ul>
        </div>

        <div class="qb-card-b">
          <div class="tab-content">

            <!-- Editor Tab -->
            <div class="tab-pane fade show active" id="qbTabEditor" role="tabpanel">

              <div class="d-flex flex-wrap align-items-center justify-content-between gap-2 mb-2">
                <div class="d-flex align-items-center gap-2">
                  <span class="qb-pill"><i class="bi bi-cursor"></i> Pyetja aktive: <span id="qbActiveQLabel">—</span></span>
                </div>

                <div class="btn-group">
                  <button id="qbDupQBtn" class="btn btn-outline-secondary btn-sm" disabled>
                    <i class="bi bi-files me-1"></i> Kopjo
                  </button>
                  <button id="qbDelQBtn" class="btn btn-outline-danger btn-sm" disabled>
                    <i class="bi bi-trash me-1"></i> Fshi
                  </button>
                </div>
              </div>

              <div id="qbEmptyState" class="qb-empty">
                <div class="display-6 mb-2"><i class="bi bi-square-plus"></i></div>
                Zgjidh ose krijo një pyetje nga kolona e majtë.
              </div>

              <div id="qbEditor" style="display:none;">
                <div class="mb-2">
                  <label class="form-label qb-muted">Teksti i pyetjes</label>
                  <textarea id="qbQText" class="form-control" rows="2" placeholder="Shkruaj tekstin e pyetjes…"></textarea>
                  <div class="d-flex gap-2 mt-2">
                    <button id="qbSaveQBtn" class="btn btn-primary">
                      <i class="bi bi-save me-1"></i> Ruaj pyetjen
                    </button>
                    <span class="qb-muted small align-self-center">Shkurtore: Ctrl/⌘ + S</span>
                  </div>
                </div>

                <hr class="my-3">

                <div class="d-flex flex-wrap align-items-center justify-content-between gap-2 mb-2">
                  <h6 class="mb-0"><i class="bi bi-list-ul me-1"></i> Alternativat</h6>
                  <span class="qb-muted small">Zgjidh njërën si të saktë (duhet 1 e vetme)</span>
                </div>

                <div id="qbAnswersBox"></div>

                <div class="d-flex gap-2 mt-2">
                  <input id="qbNewAnsInput" class="form-control" placeholder="Shto alternativë dhe shtyp Enter…">
                  <button id="qbAddAnsBtn" class="btn btn-outline-primary" type="button" title="Shto">
                    <i class="bi bi-plus-lg"></i>
                  </button>
                </div>
              </div>
            </div>

            <!-- Meta Tab -->
            <div class="tab-pane fade" id="qbTabMeta" role="tabpanel">
              <form id="qbMetaForm" class="row g-3">
                <input type="hidden" name="csrf" value="<?= h($CSRF) ?>">

                <div class="col-12">
                  <label class="form-label">Titulli</label>
                  <input class="form-control" name="title" value="<?= h((string)($quiz['title'] ?? '')) ?>" required>
                </div>

                <div class="col-md-4">
                  <label class="form-label">Tentativa</label>
                  <input class="form-control" type="number" min="1" name="attempts_allowed" value="<?= (int)($quiz['attempts_allowed'] ?? 1) ?>">
                </div>

                <div class="col-md-4">
                  <label class="form-label">Kohëzgjatja (min)</label>
                  <input class="form-control" type="number" min="0" name="time_limit_min" value="<?= ($quiz['time_limit_sec'] ? ((int)$quiz['time_limit_sec']/60) : 0) ?>">
                </div>

                <div class="col-md-6">
                  <label class="form-label">Hapet</label>
                  <input class="form-control" type="datetime-local" name="open_at"
                    value="<?= $quiz['open_at'] ? date('Y-m-d\TH:i', strtotime((string)$quiz['open_at'])) : '' ?>">
                </div>

                <div class="col-md-6">
                  <label class="form-label">Mbyllet</label>
                  <input class="form-control" type="datetime-local" name="close_at"
                    value="<?= $quiz['close_at'] ? date('Y-m-d\TH:i', strtotime((string)$quiz['close_at'])) : '' ?>">
                </div>

                <div class="col-12 d-flex flex-wrap gap-3">
                  <div class="form-check form-switch">
                    <input class="form-check-input" type="checkbox" name="shuffle_questions" id="qbSQ" <?= (int)($quiz['shuffle_questions'] ?? 0) ? 'checked' : '' ?>>
                    <label class="form-check-label" for="qbSQ">Përziej pyetjet</label>
                  </div>
                  <div class="form-check form-switch">
                    <input class="form-check-input" type="checkbox" name="shuffle_answers" id="qbSA" <?= (int)($quiz['shuffle_answers'] ?? 0) ? 'checked' : '' ?>>
                    <label class="form-check-label" for="qbSA">Përziej alternativat</label>
                  </div>
                </div>

                <div class="col-12">
                  <label class="form-label">Përshkrimi</label>
                  <textarea class="form-control" rows="4" name="description"><?= h((string)($quiz['description'] ?? '')) ?></textarea>
                </div>

                <div class="col-12 d-grid">
                  <button class="btn btn-outline-primary" type="submit">
                    <i class="bi bi-floppy me-1"></i> Ruaj parametrat
                  </button>
                </div>
              </form>
            </div>

            <!-- Health Tab -->
            <div class="tab-pane fade" id="qbTabHealth" role="tabpanel">
              <div class="row g-2">
                <div class="col-sm-4">
                  <div class="qb-card" style="box-shadow:none;">
                    <div class="qb-card-b">
                      <div class="qb-muted small">Pyetje gjithsej</div>
                      <div class="fs-4 fw-bold" id="qbHQCount"><?= (int)$health['qCount'] ?></div>
                    </div>
                  </div>
                </div>
                <div class="col-sm-4">
                  <div class="qb-card" style="box-shadow:none;">
                    <div class="qb-card-b">
                      <div class="qb-muted small">Pa alternativa</div>
                      <div class="fs-4 fw-bold" id="qbHNoAns"><?= (int)$health['noAnswerQs'] ?></div>
                    </div>
                  </div>
                </div>
                <div class="col-sm-4">
                  <div class="qb-card" style="box-shadow:none;">
                    <div class="qb-card-b">
                      <div class="qb-muted small">Pa 1 të saktë</div>
                      <div class="fs-4 fw-bold" id="qbHBad"><?= (int)$health['badCorrectQs'] ?></div>
                    </div>
                  </div>
                </div>

                <div class="col-12 mt-1">
                  <div class="qb-card" style="box-shadow:none;">
                    <div class="qb-card-b">
                      <div id="qbHReady" class="<?= $health['canPublish'] ? 'text-success' : 'text-danger' ?> fw-bold">
                        <i class="bi bi-<?= $health['canPublish'] ? 'check-circle' : 'x-circle' ?> me-1"></i>
                        <?= $health['canPublish'] ? 'Gati për publikim.' : 'Rregullo pikat për t’u publikuar.' ?>
                      </div>
                      <div class="qb-muted small mt-1">
                        Rregull: çdo pyetje duhet të ketë të paktën 1 alternativë dhe saktësisht 1 të saktë.
                      </div>
                    </div>
                  </div>
                </div>

              </div>
            </div>

          </div><!-- /tab-content -->
        </div><!-- /card-b -->
      </div><!-- /card -->
    </div>
  </div>
</main>

<?php include __DIR__ . '/../footer2.php'; ?>

<div id="qbToast" class="qb-toast" role="status" aria-live="polite"></div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>

<script>
(() => {
  const quizId = <?= (int)$quiz_id ?>;
  const csrf = <?= json_encode($CSRF) ?>;

  // State
  let questions = [];      // [{id, question, position}]
  let answers = {};        // {question_id: [{id, answer_text, is_correct, position}]}
  let selectedQ = null;

  // Elements
  const qList   = document.getElementById('qbQList');
  const qCountE = document.getElementById('qbQCount');
  const newQInp = document.getElementById('qbNewQInput');
  const filterQ = document.getElementById('qbFilterQ');

  const emptyState = document.getElementById('qbEmptyState');
  const editor = document.getElementById('qbEditor');
  const qText = document.getElementById('qbQText');
  const saveQBtn = document.getElementById('qbSaveQBtn');
  const delQBtn = document.getElementById('qbDelQBtn');
  const dupQBtn = document.getElementById('qbDupQBtn');
  const answersBox = document.getElementById('qbAnswersBox');
  const newAnsInput = document.getElementById('qbNewAnsInput');
  const addAnsBtn = document.getElementById('qbAddAnsBtn');
  const activeQLabel = document.getElementById('qbActiveQLabel');

  const publishBtn = document.getElementById('qbPublishBtn');
  const unpublishBtn = document.getElementById('qbUnpublishBtn');
  const statusText = document.getElementById('qbStatusText');
  const statusPill = document.getElementById('qbStatusPill');

  const hQCount = document.getElementById('qbHQCount');
  const hNoAns  = document.getElementById('qbHNoAns');
  const hBad    = document.getElementById('qbHBad');
  const hReady  = document.getElementById('qbHReady');

  const metaForm = document.getElementById('qbMetaForm');
  const titleInline = document.getElementById('qbTitleInline');
  const saveTitleBtn = document.getElementById('qbSaveTitle');

  // Toast
  const toastEl = document.getElementById('qbToast');
  function toast(msg, ok=true){
    toastEl.textContent = msg;
    toastEl.classList.toggle('bad', !ok);
    toastEl.style.display = 'block';
    clearTimeout(toastEl._t);
    toastEl._t = setTimeout(() => { toastEl.style.display = 'none'; }, 1700);
  }

  <?php if ($flashMsg !== ''): ?>
  window.addEventListener('DOMContentLoaded', function(){
    toast(<?= json_encode($flashMsg) ?>, <?= $flashOk ? 'true' : 'false' ?>);
  });
  <?php endif; ?>

  // AJAX helper
  function post(action, data={}) {
    const fd = new FormData();
    fd.append('ajax','1');
    fd.append('csrf', csrf);
    fd.append('action', action);
    for (const [k,v] of Object.entries(data)) {
      fd.append(k, (typeof v === 'object' && !(v instanceof File)) ? JSON.stringify(v) : v);
    }
    return fetch(location.href, { method:'POST', body: fd }).then(r => r.json());
  }

  function setStatus(status){
    statusText.textContent = status;
    const pub = (status === 'PUBLISHED');
    statusPill.classList.toggle('qb-pill-pub', pub);
    statusPill.classList.toggle('qb-pill-draft', !pub);
    statusPill.querySelector('i').className = 'bi ' + (pub ? 'bi-broadcast' : 'bi-pencil');
    unpublishBtn.style.display = pub ? '' : 'none';
  }

  function selectQuestion(qid){
    selectedQ = qid;
    [...qList.querySelectorAll('.qb-qitem')].forEach(el => el.classList.toggle('active', Number(el.dataset.id)===qid));

    const q = questions.find(x => Number(x.id) === Number(qid));
    const hasSel = Boolean(q);

    editor.style.display = hasSel ? 'block' : 'none';
    emptyState.style.display = hasSel ? 'none' : 'block';
    delQBtn.disabled = !hasSel;
    dupQBtn.disabled = !hasSel;

    if (!hasSel){
      activeQLabel.textContent = '—';
      return;
    }
    activeQLabel.textContent = `#${q.position}`;
    qText.value = q.question || '';
    renderAnswers(qid);
    newAnsInput.focus();
  }

  function renderQuestions(){
    qList.innerHTML = '';
    const term = (filterQ.value || '').toLowerCase().trim();
    const list = questions.slice().sort((a,b)=> (a.position - b.position) || (a.id - b.id));
    const filtered = term ? list.filter(q => (q.question||'').toLowerCase().includes(term)) : list;

    if (!filtered.length){
      qList.innerHTML = `<div class="qb-muted small">S’ka pyetje ${term ? 'që përputhen.' : 'ende.'}</div>`;
      if (!term){
        emptyState.style.display='block';
        editor.style.display='none';
      }
      qCountE.textContent = String(questions.length);
      return;
    }

    for (const q of filtered){
      const a = answers[q.id] || [];
      const hasCorrect = a.some(x => Number(x.is_correct) === 1);
      const okBadge = hasCorrect ? `<span class="badge text-bg-success">OK</span>` : `<span class="badge text-bg-warning text-dark">?</span>`;

      const el = document.createElement('div');
      el.className = 'qb-qitem';
      el.draggable = true;
      el.dataset.id = q.id;

      el.innerHTML = `
        <div class="qb-qdrag" title="Zvarrit për renditje"><i class="bi bi-grip-vertical"></i></div>
        <div class="flex-grow-1" role="button">
          <div class="qb-qtitle">${escapeHtml(q.question || '(pa titull)')}</div>
          <div class="qb-qmeta">#${q.position} • ${a.length} alt. • ${okBadge}</div>
        </div>
        <button class="btn btn-sm btn-outline-danger qb-qbtn qb-delq" title="Fshi"><i class="bi bi-trash"></i></button>
      `;

      el.querySelector('.flex-grow-1').addEventListener('click', () => selectQuestion(q.id));

      el.querySelector('.qb-delq').addEventListener('click', async () => {
        if (!confirm('Të fshihet pyetja dhe alternativat e saj?')) return;
        const res = await post('delete_question', {question_id: q.id});
        if (res.ok){
          questions = questions.filter(x => Number(x.id) !== Number(q.id));
          delete answers[q.id];
          if (selectedQ && Number(selectedQ) === Number(q.id)) {
            selectedQ = null;
            selectQuestion(null);
          }
          renderQuestions();
          refreshHealth();
          toast('U fshi pyetja.');
        } else toast(res.error, false);
      });

      // DnD ordering
      el.addEventListener('dragstart', e => { e.dataTransfer.setData('text/plain', String(q.id)); });
      el.addEventListener('dragover', e => e.preventDefault());
      el.addEventListener('drop', async (e) => {
        e.preventDefault();
        const draggedId = Number(e.dataTransfer.getData('text/plain'));
        const targetId  = Number(q.id);
        if (!draggedId || draggedId === targetId) return;

        const order = list.map(x=>Number(x.id));
        const from = order.indexOf(draggedId);
        const to = order.indexOf(targetId);
        order.splice(to, 0, order.splice(from, 1)[0]);

        questions = order.map((id, idx) => {
          const obj = list.find(x => Number(x.id) === id);
          return {...obj, position: idx+1};
        });

        renderQuestions();
        const rr = await post('reorder_questions', {order});
        if (!rr.ok) toast(rr.error, false);
        else toast('Renditja u ruajt.');
      });

      qList.appendChild(el);
    }

    qCountE.textContent = String(questions.length);
    if (selectedQ) {
      const selEl = qList.querySelector(`.qb-qitem[data-id="${selectedQ}"]`);
      if (selEl) selEl.classList.add('active');
    }
  }

  function renderAnswers(qid){
    const arr = (answers[qid] || []).slice().sort((a,b)=> (a.position - b.position) || (a.id - b.id));
    answersBox.innerHTML = '';

    for (const a of arr){
      const row = document.createElement('div');
      row.className = 'qb-ans' + (Number(a.is_correct)===1 ? ' correct' : '');
      row.draggable = true;
      row.dataset.id = a.id;

      row.innerHTML = `
        <span class="qb-ans-handle" title="Zvarrit për renditje"><i class="bi bi-grip-vertical"></i></span>
        <div class="form-check mb-0">
          <input class="form-check-input qb-mark" type="radio" name="correct_${qid}" ${Number(a.is_correct)===1?'checked':''} title="Shëno si e saktë">
        </div>
        <input type="text" class="form-control qb-atext" value="${escapeAttr(a.answer_text)}">
        <button class="btn btn-sm btn-outline-danger qb-qbtn qb-dela" title="Fshi"><i class="bi bi-x-lg"></i></button>
      `;

      // Drag reorder answers
      row.addEventListener('dragstart', e => { e.dataTransfer.setData('text/plain', String(a.id)); });
      row.addEventListener('dragover', e => e.preventDefault());
      row.addEventListener('drop', async (e) => {
        e.preventDefault();
        const draggedId = Number(e.dataTransfer.getData('text/plain'));
        const targetId  = Number(a.id);
        if (!draggedId || draggedId === targetId) return;

        const order = arr.map(x=>Number(x.id));
        const from = order.indexOf(draggedId);
        const to = order.indexOf(targetId);
        order.splice(to, 0, order.splice(from, 1)[0]);

        (answers[qid] = answers[qid] || []).sort((x,y)=> order.indexOf(Number(x.id)) - order.indexOf(Number(y.id)));
        renderAnswers(qid);

        const rr = await post('reorder_answers', {question_id: qid, order});
        if (!rr.ok) toast(rr.error, false);
        else toast('Renditja e alternativave u ruajt.');
      });

      // Mark correct
      row.querySelector('.qb-mark').addEventListener('change', async () => {
        const res = await post('mark_correct', {answer_id: a.id});
        if (res.ok){
          (answers[qid] || []).forEach(x => x.is_correct = (Number(x.id)===Number(a.id) ? 1 : 0));
          renderAnswers(qid);
          refreshHealth();
          toast('U shënua e saktë.');
        } else toast(res.error, false);
      });

      // Update answer text
      row.querySelector('.qb-atext').addEventListener('change', async (e) => {
        const txt = (e.target.value || '').trim();
        const res = await post('update_answer', {answer_id: a.id, answer_text: txt});
        if (res.ok){ a.answer_text = txt; toast('U ruajt alternativa.'); }
        else toast(res.error, false);
      });

      // Delete answer
      row.querySelector('.qb-dela').addEventListener('click', async () => {
        if (!confirm('Fshi këtë alternativë?')) return;
        const res = await post('delete_answer', {answer_id: a.id});
        if (res.ok){
          answers[qid] = (answers[qid] || []).filter(x => Number(x.id) !== Number(a.id));
          renderAnswers(qid);
          refreshHealth();
          toast('U fshi alternativa.');
        } else toast(res.error, false);
      });

      answersBox.appendChild(row);
    }
  }

  async function refreshHealth(){
    const res = await post('state');
    if (!res.ok) return;

    questions = res.questions || [];
    answers = res.answers || {};

    renderQuestions();
    if (selectedQ) {
      const exists = questions.some(q => Number(q.id) === Number(selectedQ));
      if (exists) selectQuestion(selectedQ);
      else selectQuestion(null);
    }

    if (hQCount) hQCount.textContent = String(res.health.qCount);
    if (hNoAns)  hNoAns.textContent  = String(res.health.noAnswerQs);
    if (hBad)    hBad.textContent    = String(res.health.badCorrectQs);

    if (hReady){
      const ok = !!res.health.canPublish;
      hReady.className = (ok ? 'text-success' : 'text-danger') + ' fw-bold';
      hReady.innerHTML = `<i class="bi bi-${ok?'check-circle':'x-circle'} me-1"></i>${ok?'Gati për publikim.':'Rregullo pikat për t’u publikuar.'}`;
    }

    publishBtn.disabled = !res.health.canPublish;
  }

  // Create question
  newQInp.addEventListener('keydown', async (e) => {
    if (e.key === 'Enter'){
      e.preventDefault();
      const text = (newQInp.value || '').trim();
      if (!text) return;
      const res = await post('create_question', {question: text});
      if (res.ok){
        questions.push(res.question);
        answers[res.question.id] = [];
        newQInp.value = '';
        renderQuestions();
        selectQuestion(res.question.id);
        refreshHealth();
        toast('U shtua pyetja.');
      } else toast(res.error, false);
    }
  });

  filterQ.addEventListener('input', renderQuestions);

  // Save question
  saveQBtn.addEventListener('click', async () => {
    if (!selectedQ) return;
    const txt = (qText.value || '').trim();
    if (!txt){ toast('Pyetja nuk mund të jetë bosh.', false); return; }
    const res = await post('update_question', {question_id: selectedQ, question: txt});
    if (res.ok){
      const q = questions.find(x => Number(x.id) === Number(selectedQ));
      if (q) q.question = txt;
      renderQuestions();
      toast('U ruajt pyetja.');
    } else toast(res.error, false);
  });

  // Delete question (main)
  delQBtn.addEventListener('click', async () => {
    if (!selectedQ) return;
    if (!confirm('Të fshihet kjo pyetje?')) return;
    const res = await post('delete_question', {question_id: selectedQ});
    if (res.ok){
      questions = questions.filter(x => Number(x.id) !== Number(selectedQ));
      delete answers[selectedQ];
      selectedQ = null;
      selectQuestion(null);
      renderQuestions();
      refreshHealth();
      toast('U fshi pyetja.');
    } else toast(res.error, false);
  });

  // Duplicate question
  dupQBtn.addEventListener('click', async () => {
    if (!selectedQ) return;
    const res = await post('duplicate_question', {question_id: selectedQ});
    if (res.ok){
      await refreshHealth();
      const newId = res.question?.id;
      if (newId) selectQuestion(newId);
      toast('U kopjua pyetja.');
    } else toast(res.error, false);
  });

  // Add answer
  async function addAnswer(){
    if (!selectedQ) return;
    const txt = (newAnsInput.value || '').trim();
    if (!txt) return;
    const res = await post('create_answer', {question_id: selectedQ, answer_text: txt});
    if (res.ok){
      (answers[selectedQ] = answers[selectedQ] || []).push(res.answer);
      newAnsInput.value = '';
      renderAnswers(selectedQ);
      refreshHealth();
      toast('U shtua alternativa.');
    } else toast(res.error, false);
  }
  newAnsInput.addEventListener('keydown', e => { if (e.key === 'Enter'){ e.preventDefault(); addAnswer(); }});
  addAnsBtn.addEventListener('click', e => { e.preventDefault(); addAnswer(); });

  // Publish / Unpublish
  publishBtn.addEventListener('click', async () => {
    const res = await post('publish_quiz');
    if (res.ok){
      setStatus(res.status);
      toast('Quiz u publikua.');
    } else toast(res.error, false);
  });
  unpublishBtn.addEventListener('click', async () => {
    const res = await post('unpublish_quiz');
    if (res.ok){
      setStatus(res.status);
      toast('Quiz kaloi në Draft.');
    } else toast(res.error, false);
  });

  // Meta form submit
  function payloadFromForm(form){
    const fd = new FormData(form);
    const payload = Object.fromEntries(fd.entries());
    payload.shuffle_questions = form.querySelector('input[name="shuffle_questions"]')?.checked ? 1 : 0;
    payload.shuffle_answers  = form.querySelector('input[name="shuffle_answers"]')?.checked ? 1 : 0;
    return payload;
  }
  metaForm.addEventListener('submit', async (e) => {
    e.preventDefault();
    const payload = payloadFromForm(metaForm);
    const res = await post('update_meta', payload);
    if (res.ok){
      titleInline.value = payload.title || '';
      toast('Parametrat u ruajtën.');
      // Publish availability might change if attempts/time changes? (health depends on Q/A, still refresh for consistency)
      refreshHealth();
    } else toast(res.error, false);
  });

  // Inline title save
  async function saveInlineTitle(){
    const title = (titleInline.value || '').trim();
    if (!title){ toast('Titulli nuk mund të jetë bosh.', false); return; }
    const payload = payloadFromForm(metaForm);
    payload.title = title;
    const res = await post('update_meta', payload);
    if (res.ok){
      // Sync meta tab title field too
      const metaTitle = metaForm.querySelector('input[name="title"]');
      if (metaTitle) metaTitle.value = title;
      toast('Titulli u ruajt.');
    } else toast(res.error, false);
  }
  saveTitleBtn.addEventListener('click', saveInlineTitle);
  titleInline.addEventListener('keydown', (e) => {
    if (e.key === 'Enter'){ e.preventDefault(); saveInlineTitle(); }
  });

  // Shortcuts
  window.addEventListener('keydown', (e) => {
    if ((e.ctrlKey || e.metaKey) && e.key.toLowerCase() === 's'){
      e.preventDefault();
      if (editor.style.display !== 'none') saveQBtn.click();
    }
    if ((e.ctrlKey || e.metaKey) && e.key.toLowerCase() === 'd'){
      e.preventDefault();
      if (!dupQBtn.disabled) dupQBtn.click();
    }
    if (e.key === 'Delete'){
      if (!delQBtn.disabled) { e.preventDefault(); delQBtn.click(); }
    }
    if ((e.ctrlKey || e.metaKey) && e.key.toLowerCase() === 'n'){
      e.preventDefault();
      newQInp.focus();
    }
  });

  // Initial load
  (async () => {
    const res = await post('state');
    if (res.ok){
      questions = res.questions || [];
      answers   = res.answers || {};
      renderQuestions();
      setStatus(res.quiz?.status || 'DRAFT');
      publishBtn.disabled = !(res.health && res.health.canPublish);

      if (questions.length){
        selectQuestion(questions[0].id);
      } else {
        selectQuestion(null);
      }

      // fill health
      if (hQCount) hQCount.textContent = String(res.health.qCount);
      if (hNoAns)  hNoAns.textContent  = String(res.health.noAnswerQs);
      if (hBad)    hBad.textContent    = String(res.health.badCorrectQs);
    }
  })();

  // Helpers
  function escapeHtml(s){
    return (s||'').replace(/[&<>"']/g, m => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[m]));
  }
  function escapeAttr(s){
    return escapeHtml(s).replace(/\n/g,'&#10;');
  }
})();
</script>
</body>
</html>
