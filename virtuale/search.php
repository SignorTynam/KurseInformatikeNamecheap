<?php
// search.php — Universal Admin Search (NEW, clean + correct SQL)
declare(strict_types=1);

session_start();
require_once __DIR__ . '/lib/bootstrap.php'; // duhet të setojë $pdo (si te faqet e tjera)

/* ------------------------------ RBAC ------------------------------ */
if (empty($_SESSION['user']) || (($_SESSION['user']['role'] ?? '') !== 'Administrator')) {
  header('Location: login.php'); exit;
}

/* ------------------------------ Helpers ------------------------------ */
function h(?string $s): string { return htmlspecialchars($s ?? '', ENT_QUOTES, 'UTF-8'); }

function likePattern(string $q): string {
  $q = trim($q);
  if ($q === '') return '%';
  $q = preg_replace('/\s+/u', '%', $q);
  return '%' . $q . '%';
}

function valid_date(?string $d): bool {
  if (!$d) return false;
  $t = strtotime($d);
  return $t !== false && date('Y-m-d', $t) === $d;
}

/**
 * Safe date range clause.
 * $prefix duhet të jetë pa pika / karaktere speciale (p.sh. "p_payment_date")
 */
function addDateRange(array &$whereParts, array &$params, ?string $from, ?string $to, string $column, string $prefix): void {
  if (valid_date($from)) {
    $whereParts[] = "$column >= :{$prefix}_from";
    $params[":{$prefix}_from"] = $from . ' 00:00:00';
  }
  if (valid_date($to)) {
    $whereParts[] = "$column <= :{$prefix}_to";
    $params[":{$prefix}_to"] = $to . ' 23:59:59';
  }
}

function paginate(int $page, int $perPage): array {
  $page = max(1, $page);
  $perPage = max(1, min(100, $perPage));
  return [$perPage, ($page - 1) * $perPage];
}

/** Highlight i sigurt: punon në text raw dhe escapon çdo segment */
function highlight(?string $text, string $q): string {
  $text = (string)($text ?? '');
  $q = trim($q);
  if ($q === '' || $text === '') return h($text);

  $re = '/' . preg_quote($q, '/') . '/iu';
  $parts = preg_split($re, $text, -1, PREG_SPLIT_DELIM_CAPTURE);

  if ($parts === false || count($parts) === 1) return h($text);

  $out = '';
  foreach ($parts as $i => $part) {
    if ($i % 2 === 1) $out .= '<mark>' . h($part) . '</mark>';
    else $out .= h($part);
  }
  return $out;
}

function parse_id(?string $q): ?int {
  $q = trim((string)$q);
  if (preg_match('/^\#?(\d{1,10})$/', $q, $m)) return (int)$m[1];
  return null;
}

function table_has_column(PDO $pdo, string $table, string $column): bool {
  if (!preg_match('/^[A-Za-z0-9_]+$/', $table) || !preg_match('/^[A-Za-z0-9_]+$/', $column)) {
    return false;
  }
  try {
    $st = $pdo->prepare(
      'SELECT 1
       FROM information_schema.COLUMNS
       WHERE TABLE_SCHEMA = DATABASE()
         AND TABLE_NAME = ?
         AND COLUMN_NAME = ?
       LIMIT 1'
    );
    $st->execute([$table, $column]);
    return (bool)$st->fetchColumn();
  } catch (Throwable $e) {
    return false;
  }
}

/* ------------------------------ Inputs ------------------------------ */
$scope = strtolower((string)($_GET['scope'] ?? 'all'));
$allowedScopes = ['all','users','courses','payments','messages','lessons','assignments','quizzes'];
if (!in_array($scope, $allowedScopes, true)) $scope = 'all';

$q = trim((string)($_GET['q'] ?? ''));
$qLike = likePattern($q);

$page = (int)($_GET['page'] ?? 1);
$perPage = (int)($_GET['per_page'] ?? 20);

$from = (string)($_GET['from'] ?? '');
$to   = (string)($_GET['to']   ?? '');

// Filters
$roleFilter       = (string)($_GET['role'] ?? '');
$userStatusFilter = (string)($_GET['user_status'] ?? '');
$courseStatus     = (string)($_GET['course_status'] ?? '');
$courseCategory   = (string)($_GET['course_category'] ?? '');
$paymentStatus    = (string)($_GET['payment_status'] ?? '');
$msgRead          = (string)($_GET['msg_read'] ?? ''); // unread|read|''
$assignmentStatus = (string)($_GET['assignment_status'] ?? '');
$quizStatus       = (string)($_GET['quiz_status'] ?? '');
$hiddenFlag       = (string)($_GET['hidden'] ?? ''); // 0|1|''

$idValue   = parse_id($q);
$isNumeric = is_numeric($q);
$LESSONS_HAS_HIDDEN = table_has_column($pdo, 'lessons', 'hidden');

/* ------------------------------ Search builders ------------------------------ */
function search_users(PDO $pdo, string $qLike, string $roleFilter, string $statusFilter, ?string $from, ?string $to, int $page, int $perPage): array {
  [$per, $off] = paginate($page, $perPage);

  $params = [
    ':q_full_name' => $qLike,
    ':q_email' => $qLike,
    ':q_phone' => $qLike,
  ];
  $whereParts = ["(full_name LIKE :q_full_name OR email LIKE :q_email OR phone_number LIKE :q_phone)"];

  if ($roleFilter && in_array($roleFilter, ['Administrator','Instruktor','Student'], true)) {
    $whereParts[] = "role = :role";
    $params[':role'] = $roleFilter;
  }
  if ($statusFilter && in_array($statusFilter, ['APROVUAR','NE SHQYRTIM','REFUZUAR'], true)) {
    $whereParts[] = "status = :ust";
    $params[':ust'] = $statusFilter;
  }

  addDateRange($whereParts, $params, $from, $to, 'created_at', 'u_created');

  $where = 'WHERE ' . implode(' AND ', $whereParts);

  $stmtC = $pdo->prepare("SELECT COUNT(*) FROM users $where");
  $stmtC->execute($params);
  $total = (int)$stmtC->fetchColumn();

  $stmt = $pdo->prepare("
    SELECT id, full_name, email, phone_number, role, status, created_at
    FROM users
    $where
    ORDER BY created_at DESC
    LIMIT $per OFFSET $off
  ");
  $stmt->execute($params);

  return ['total'=>$total, 'rows'=>$stmt->fetchAll(PDO::FETCH_ASSOC) ?: []];
}

function search_courses(PDO $pdo, string $qLike, string $status, string $category, ?string $from, ?string $to, int $page, int $perPage): array {
  [$per, $off] = paginate($page, $perPage);

  $params = [
    ':q_title' => $qLike,
    ':q_description' => $qLike,
    ':q_category' => $qLike,
  ];
  $whereParts = ["(title LIKE :q_title OR description LIKE :q_description OR category LIKE :q_category)"];

  if ($status && in_array($status, ['ACTIVE','INACTIVE','ARCHIVED'], true)) {
    $whereParts[] = "status = :st";
    $params[':st'] = $status;
  }
  if ($category !== '') {
    $whereParts[] = "category = :cat";
    $params[':cat'] = $category;
  }

  addDateRange($whereParts, $params, $from, $to, 'created_at', 'c_created');

  $where = 'WHERE ' . implode(' AND ', $whereParts);

  $stmtC = $pdo->prepare("SELECT COUNT(*) FROM courses $where");
  $stmtC->execute($params);
  $total = (int)$stmtC->fetchColumn();

  $stmt = $pdo->prepare("
    SELECT id, title, category, status, created_at
    FROM courses
    $where
    ORDER BY created_at DESC
    LIMIT $per OFFSET $off
  ");
  $stmt->execute($params);

  return ['total'=>$total, 'rows'=>$stmt->fetchAll(PDO::FETCH_ASSOC) ?: []];
}

function search_payments(PDO $pdo, string $qLike, string $status, ?string $from, ?string $to, int $page, int $perPage, ?int $idValue, bool $isNumeric, string $qRaw): array {
  [$per, $off] = paginate($page, $perPage);

  $params = [
    ':q_user' => $qLike,
    ':q_course' => $qLike,
    ':q_amount' => $qLike,
  ];

  $orParts = [
    "u.full_name LIKE :q_user",
    "c.title LIKE :q_course",
    "CAST(p.amount AS CHAR) LIKE :q_amount"
  ];

  if ($isNumeric) {
    $orParts[] = "p.amount = :amtExact";
    $params[':amtExact'] = (float)$qRaw;
  }
  if ($idValue !== null) {
    $orParts[] = "p.id = :pidExact";
    $params[':pidExact'] = $idValue;
  }

  $whereParts = ['(' . implode(' OR ', $orParts) . ')'];

  if ($status && in_array($status, ['FAILED','COMPLETED'], true)) {
    $whereParts[] = "p.payment_status = :pst";
    $params[':pst'] = $status;
  }

  addDateRange($whereParts, $params, $from, $to, 'p.payment_date', 'p_payment_date');

  $where = 'WHERE ' . implode(' AND ', $whereParts);

  $stmtC = $pdo->prepare("
    SELECT COUNT(*)
    FROM payments p
    JOIN users u   ON u.id = p.user_id
    JOIN courses c ON c.id = p.course_id
    $where
  ");
  $stmtC->execute($params);
  $total = (int)$stmtC->fetchColumn();

  $stmt = $pdo->prepare("
    SELECT p.id, p.amount, p.payment_status, p.payment_date,
           u.full_name, c.title AS course_title
    FROM payments p
    JOIN users u   ON u.id = p.user_id
    JOIN courses c ON c.id = p.course_id
    $where
    ORDER BY p.payment_date DESC
    LIMIT $per OFFSET $off
  ");
  $stmt->execute($params);

  return ['total'=>$total, 'rows'=>$stmt->fetchAll(PDO::FETCH_ASSOC) ?: []];
}

function search_messages(PDO $pdo, string $qLike, string $read, ?string $from, ?string $to, int $page, int $perPage, ?int $idValue): array {
  [$per, $off] = paginate($page, $perPage);

  $params = [
    ':q_name' => $qLike,
    ':q_email' => $qLike,
    ':q_subject' => $qLike,
    ':q_message' => $qLike,
  ];

  $orParts = [
    "name LIKE :q_name",
    "email LIKE :q_email",
    "subject LIKE :q_subject",
    "message LIKE :q_message"
  ];
  if ($idValue !== null) {
    $orParts[] = "id = :midExact";
    $params[':midExact'] = $idValue;
  }

  $whereParts = ['(' . implode(' OR ', $orParts) . ')'];

  if ($read === 'unread') $whereParts[] = "read_status = 0";
  elseif ($read === 'read') $whereParts[] = "read_status = 1";

  addDateRange($whereParts, $params, $from, $to, 'created_at', 'm_created');

  $where = 'WHERE ' . implode(' AND ', $whereParts);

  $stmtC = $pdo->prepare("SELECT COUNT(*) FROM messages $where");
  $stmtC->execute($params);
  $total = (int)$stmtC->fetchColumn();

  $stmt = $pdo->prepare("
    SELECT id, name, email, subject, created_at, read_status
    FROM messages
    $where
    ORDER BY created_at DESC
    LIMIT $per OFFSET $off
  ");
  $stmt->execute($params);

  return ['total'=>$total, 'rows'=>$stmt->fetchAll(PDO::FETCH_ASSOC) ?: []];
}

function search_lessons(PDO $pdo, string $qLike, ?string $from, ?string $to, string $hiddenFlag, int $page, int $perPage, bool $hasHiddenColumn): array {
  [$per, $off] = paginate($page, $perPage);

  $params = [
    ':q_title' => $qLike,
    ':q_description' => $qLike,
    ':q_url' => $qLike,
  ];
  $whereParts = ["(l.title LIKE :q_title OR l.description LIKE :q_description OR l.URL LIKE :q_url)"];

  if ($hasHiddenColumn && ($hiddenFlag === '0' || $hiddenFlag === '1')) {
    $whereParts[] = "l.hidden = :lh";
    $params[':lh'] = (int)$hiddenFlag;
  }

  addDateRange($whereParts, $params, $from, $to, 'l.uploaded_at', 'l_uploaded');

  $where = 'WHERE ' . implode(' AND ', $whereParts);

  $stmtC = $pdo->prepare("SELECT COUNT(*) FROM lessons l JOIN courses c ON c.id=l.course_id $where");
  $stmtC->execute($params);
  $total = (int)$stmtC->fetchColumn();

  $hiddenSelect = $hasHiddenColumn ? 'l.hidden' : '0 AS hidden';

  $stmt = $pdo->prepare("
    SELECT l.id, l.title, l.uploaded_at, {$hiddenSelect},
           c.id AS course_id, c.title AS course_title
    FROM lessons l
    JOIN courses c ON c.id = l.course_id
    $where
    ORDER BY l.uploaded_at DESC
    LIMIT $per OFFSET $off
  ");
  $stmt->execute($params);

  return ['total'=>$total, 'rows'=>$stmt->fetchAll(PDO::FETCH_ASSOC) ?: []];
}

function search_assignments(PDO $pdo, string $qLike, string $status, ?string $from, ?string $to, int $page, int $perPage): array {
  [$per, $off] = paginate($page, $perPage);

  $params = [
    ':q_title' => $qLike,
    ':q_description' => $qLike,
  ];
  $whereParts = ["(a.title LIKE :q_title OR a.description LIKE :q_description)"];

  if ($status && in_array($status, ['SUBMITTED','PENDING','GRADED','EXPIRED'], true)) {
    $whereParts[] = "a.status = :as";
    $params[':as'] = $status;
  }

  addDateRange($whereParts, $params, $from, $to, 'a.uploaded_at', 'a_uploaded');

  $where = 'WHERE ' . implode(' AND ', $whereParts);

  $stmtC = $pdo->prepare("SELECT COUNT(*) FROM assignments a JOIN courses c ON c.id=a.course_id $where");
  $stmtC->execute($params);
  $total = (int)$stmtC->fetchColumn();

  $stmt = $pdo->prepare("
    SELECT a.id, a.title, a.status, a.uploaded_at,
           c.id AS course_id, c.title AS course_title
    FROM assignments a
    JOIN courses c ON c.id = a.course_id
    $where
    ORDER BY a.uploaded_at DESC
    LIMIT $per OFFSET $off
  ");
  $stmt->execute($params);

  return ['total'=>$total, 'rows'=>$stmt->fetchAll(PDO::FETCH_ASSOC) ?: []];
}

function search_quizzes(PDO $pdo, string $qLike, string $status, ?string $from, ?string $to, string $hiddenFlag, int $page, int $perPage): array {
  [$per, $off] = paginate($page, $perPage);

  $params = [
    ':q_title' => $qLike,
    ':q_description' => $qLike,
  ];
  $whereParts = ["(q.title LIKE :q_title OR q.description LIKE :q_description)"];

  if ($status && in_array($status, ['DRAFT','PUBLISHED','ARCHIVED'], true)) {
    $whereParts[] = "q.status = :qs";
    $params[':qs'] = $status;
  }

  if ($hiddenFlag === '0' || $hiddenFlag === '1') {
    $whereParts[] = "q.hidden = :qh";
    $params[':qh'] = (int)$hiddenFlag;
  }

  addDateRange($whereParts, $params, $from, $to, 'q.created_at', 'q_created');

  $where = 'WHERE ' . implode(' AND ', $whereParts);

  $stmtC = $pdo->prepare("SELECT COUNT(*) FROM quizzes q JOIN courses c ON c.id=q.course_id $where");
  $stmtC->execute($params);
  $total = (int)$stmtC->fetchColumn();

  $stmt = $pdo->prepare("
    SELECT q.id, q.title, q.status, q.hidden, q.created_at,
           c.id AS course_id, c.title AS course_title
    FROM quizzes q
    JOIN courses c ON c.id = q.course_id
    $where
    ORDER BY q.created_at DESC
    LIMIT $per OFFSET $off
  ");
  $stmt->execute($params);

  return ['total'=>$total, 'rows'=>$stmt->fetchAll(PDO::FETCH_ASSOC) ?: []];
}

/* ------------------------------ Run searches ------------------------------ */
$summary = [
  'users'       => ['total'=>0,'rows'=>[]],
  'courses'     => ['total'=>0,'rows'=>[]],
  'payments'    => ['total'=>0,'rows'=>[]],
  'messages'    => ['total'=>0,'rows'=>[]],
  'lessons'     => ['total'=>0,'rows'=>[]],
  'assignments' => ['total'=>0,'rows'=>[]],
  'quizzes'     => ['total'=>0,'rows'=>[]],
];

try {
  if ($scope === 'all') {
    $summary['users']       = search_users($pdo, $qLike, $roleFilter, $userStatusFilter, $from, $to, 1, 5);
    $summary['courses']     = search_courses($pdo, $qLike, $courseStatus, $courseCategory, $from, $to, 1, 5);
    $summary['payments']    = search_payments($pdo, $qLike, $paymentStatus, $from, $to, 1, 5, $idValue, $isNumeric, $q);
    $summary['messages']    = search_messages($pdo, $qLike, $msgRead, $from, $to, 1, 5, $idValue);
    $summary['lessons']     = search_lessons($pdo, $qLike, $from, $to, $hiddenFlag, 1, 5, $LESSONS_HAS_HIDDEN);
    $summary['assignments'] = search_assignments($pdo, $qLike, $assignmentStatus, $from, $to, 1, 5);
    $summary['quizzes']     = search_quizzes($pdo, $qLike, $quizStatus, $from, $to, $hiddenFlag, 1, 5);
  } else {
    switch ($scope) {
      case 'users':
        $summary['users'] = search_users($pdo, $qLike, $roleFilter, $userStatusFilter, $from, $to, $page, $perPage);
        break;
      case 'courses':
        $summary['courses'] = search_courses($pdo, $qLike, $courseStatus, $courseCategory, $from, $to, $page, $perPage);
        break;
      case 'payments':
        $summary['payments'] = search_payments($pdo, $qLike, $paymentStatus, $from, $to, $page, $perPage, $idValue, $isNumeric, $q);
        break;
      case 'messages':
        $summary['messages'] = search_messages($pdo, $qLike, $msgRead, $from, $to, $page, $perPage, $idValue);
        break;
      case 'lessons':
        $summary['lessons'] = search_lessons($pdo, $qLike, $from, $to, $hiddenFlag, $page, $perPage, $LESSONS_HAS_HIDDEN);
        break;
      case 'assignments':
        $summary['assignments'] = search_assignments($pdo, $qLike, $assignmentStatus, $from, $to, $page, $perPage);
        break;
      case 'quizzes':
        $summary['quizzes'] = search_quizzes($pdo, $qLike, $quizStatus, $from, $to, $hiddenFlag, $page, $perPage);
        break;
    }
  }
} catch (Throwable $e) {
  error_log('search.php error: ' . $e->getMessage());
}

function tabUrl(string $scope, array $extra=[]): string {
  $params = $_GET;
  $params['scope'] = $scope;
  $params['page']  = 1;
  foreach ($extra as $k=>$v) $params[$k] = $v;
  return 'search.php?' . http_build_query($params);
}

function pager(int $total, int $page, int $perPage): array {
  $pages = max(1, (int)ceil($total / max(1,$perPage)));
  $page  = min(max(1,$page), $pages);
  return [$pages, $page];
}

$tabItems = [
  'all'         => ['icon'=>'fa-grid-2','label'=>'Të gjitha'],
  'users'       => ['icon'=>'fa-user','label'=>'Përdorues','count'=>$summary['users']['total']],
  'courses'     => ['icon'=>'fa-book','label'=>'Kurse','count'=>$summary['courses']['total']],
  'payments'    => ['icon'=>'fa-credit-card','label'=>'Pagesa','count'=>$summary['payments']['total']],
  'messages'    => ['icon'=>'fa-envelope','label'=>'Mesazhe','count'=>$summary['messages']['total']],
  'lessons'     => ['icon'=>'fa-book-open','label'=>'Leksione','count'=>$summary['lessons']['total']],
  'assignments' => ['icon'=>'fa-list-check','label'=>'Detyra','count'=>$summary['assignments']['total']],
  'quizzes'     => ['icon'=>'fa-circle-question','label'=>'Quiz-e','count'=>$summary['quizzes']['total']],
];

$allTotal =
  (int)$summary['users']['total'] +
  (int)$summary['courses']['total'] +
  (int)$summary['payments']['total'] +
  (int)$summary['messages']['total'] +
  (int)$summary['lessons']['total'] +
  (int)$summary['assignments']['total'] +
  (int)$summary['quizzes']['total'];

$activeTotal = $scope === 'all' ? $allTotal : (int)($summary[$scope]['total'] ?? 0);
$activeLabel = $tabItems[$scope]['label'] ?? 'Të gjitha';
$hasDateRange = valid_date($from) || valid_date($to);

?>
<!DOCTYPE html>
<html lang="sq" data-bs-theme="light">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Kërkim — Panel Administrimi</title>

  <link rel="icon" href="image/favicon.ico" type="image/x-icon" />
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
  <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css" rel="stylesheet">
  <link href="css/courses.css?v=1" rel="stylesheet">
  <link href="css/search.css?v=2" rel="stylesheet">

</head>
<body class="course-body search-body">

<?php include __DIR__ . '/navbar_logged_administrator.php'; ?>

<section class="course-hero search-hero">
  <div class="container">
    <div class="d-flex flex-column flex-lg-row align-items-start align-items-lg-center justify-content-between gap-3">
      <div>
        <div class="course-breadcrumb search-breadcrumb">
          <i class="fa-solid fa-house me-1"></i>
          <a href="dashboard_admin.php">Paneli</a> / Kërkim
        </div>
        <h1 class="mb-1">Kërkim</h1>
        <p class="mb-0">Gjej përdorues, kurse, pagesa, mesazhe, leksione, detyra dhe quiz-e.</p>
      </div>

      <div class="d-flex flex-wrap gap-2">
        <div class="course-stat">
          <div class="icon"><i class="fa-solid fa-layer-group"></i></div>
          <div>
            <div class="label">Scope</div>
            <div class="value"><?= h($activeLabel) ?></div>
          </div>
        </div>
        <div class="course-stat">
          <div class="icon"><i class="fa-solid fa-magnifying-glass"></i></div>
          <div>
            <div class="label">Rezultate</div>
            <div class="value"><?= (int)$activeTotal ?></div>
          </div>
        </div>
        <div class="course-stat">
          <div class="icon"><i class="fa-regular fa-calendar"></i></div>
          <div>
            <div class="label">Datat</div>
            <div class="value"><?= $hasDateRange ? h(($from ?: '…') . ' — ' . ($to ?: '…')) : 'Pa filtër' ?></div>
          </div>
        </div>
      </div>
    </div>
  </div>
</section>

<main class="search-main">
  <div class="container">

    <form class="search-panel course-toolbar" method="get" action="search.php">
      <div class="row g-2 align-items-end">
        <div class="col-12 col-lg-6">
          <label class="form-label">Fjalë kyçe</label>
          <div class="input-group">
            <span class="input-group-text bg-white"><i class="fa-solid fa-magnifying-glass"></i></span>
            <input class="form-control" type="search" name="q" value="<?= h($q) ?>" placeholder="p.sh. 'web', 'FAILED', '#125', 'eni beqiri'">
          </div>
        </div>

        <div class="col-6 col-lg-2">
          <label class="form-label">Nga data</label>
          <input class="form-control" type="date" name="from" value="<?= valid_date($from) ? h($from) : '' ?>">
        </div>

        <div class="col-6 col-lg-2">
          <label class="form-label">Deri më</label>
          <input class="form-control" type="date" name="to" value="<?= valid_date($to) ? h($to) : '' ?>">
        </div>

        <div class="col-12 col-lg-2 d-grid">
          <button class="btn search-btn-main" type="submit"><i class="fa-solid fa-magnifying-glass me-1"></i>Kërko</button>
        </div>
      </div>

      <div class="row g-2 mt-2">

        <div class="col-6 col-lg-2">
          <label class="form-label">Fusha</label>
          <select class="form-select" name="scope">
            <?php foreach ($allowedScopes as $sc): ?>
              <option value="<?= $sc ?>" <?= $scope===$sc?'selected':'' ?>><?= h($tabItems[$sc]['label'] ?? ucfirst($sc)) ?></option>
            <?php endforeach; ?>
          </select>
        </div>

        <div class="col-6 col-lg-2">
          <label class="form-label">Roli (Users)</label>
          <select class="form-select" name="role">
            <option value="">—</option>
            <?php foreach (['Administrator','Instruktor','Student'] as $r): ?>
              <option value="<?= $r ?>" <?= $roleFilter===$r?'selected':'' ?>><?= $r ?></option>
            <?php endforeach; ?>
          </select>
        </div>

        <div class="col-6 col-lg-2">
          <label class="form-label">Status (Users)</label>
          <select class="form-select" name="user_status">
            <option value="">—</option>
            <?php foreach (['APROVUAR','NE SHQYRTIM','REFUZUAR'] as $s): ?>
              <option value="<?= $s ?>" <?= $userStatusFilter===$s?'selected':'' ?>><?= $s ?></option>
            <?php endforeach; ?>
          </select>
        </div>

        <div class="col-6 col-lg-2">
          <label class="form-label">Status (Courses)</label>
          <select class="form-select" name="course_status">
            <option value="">—</option>
            <?php foreach (['ACTIVE','INACTIVE','ARCHIVED'] as $s): ?>
              <option value="<?= $s ?>" <?= $courseStatus===$s?'selected':'' ?>><?= $s ?></option>
            <?php endforeach; ?>
          </select>
        </div>

        <div class="col-6 col-lg-2">
          <label class="form-label">Kategori (Courses)</label>
          <input class="form-control" name="course_category" value="<?= h($courseCategory) ?>" placeholder="p.sh. WEB">
        </div>

        <div class="col-6 col-lg-2">
          <label class="form-label">Status (Payments)</label>
          <select class="form-select" name="payment_status">
            <option value="">—</option>
            <?php foreach (['COMPLETED','FAILED'] as $p): ?>
              <option value="<?= $p ?>" <?= $paymentStatus===$p?'selected':'' ?>><?= $p ?></option>
            <?php endforeach; ?>
          </select>
        </div>

        <div class="col-6 col-lg-2">
          <label class="form-label">Mesazhe</label>
          <select class="form-select" name="msg_read">
            <option value="">—</option>
            <option value="unread" <?= $msgRead==='unread'?'selected':'' ?>>Të palexuara</option>
            <option value="read"   <?= $msgRead==='read'?'selected':'' ?>>Të lexuara</option>
          </select>
        </div>

        <div class="col-6 col-lg-2">
          <label class="form-label">Status (Assignments)</label>
          <select class="form-select" name="assignment_status">
            <option value="">—</option>
            <?php foreach (['SUBMITTED','PENDING','GRADED','EXPIRED'] as $s): ?>
              <option value="<?= $s ?>" <?= $assignmentStatus===$s?'selected':'' ?>><?= $s ?></option>
            <?php endforeach; ?>
          </select>
        </div>

        <div class="col-6 col-lg-2">
          <label class="form-label">Status (Quizzes)</label>
          <select class="form-select" name="quiz_status">
            <option value="">—</option>
            <?php foreach (['DRAFT','PUBLISHED','ARCHIVED'] as $s): ?>
              <option value="<?= $s ?>" <?= $quizStatus===$s?'selected':'' ?>><?= $s ?></option>
            <?php endforeach; ?>
          </select>
        </div>

        <div class="col-6 col-lg-2">
          <label class="form-label">Hidden (Lessons/Quizzes)</label>
          <select class="form-select" name="hidden">
            <option value="">—</option>
            <option value="0" <?= $hiddenFlag==='0'?'selected':'' ?>>Jo</option>
            <option value="1" <?= $hiddenFlag==='1'?'selected':'' ?>>Po</option>
          </select>
        </div>

        <div class="col-6 col-lg-2">
          <label class="form-label">Për faqe</label>
          <select class="form-select" name="per_page">
            <?php foreach ([10,20,50,100] as $pp): ?>
              <option value="<?= $pp ?>" <?= $perPage===$pp?'selected':'' ?>><?= $pp ?></option>
            <?php endforeach; ?>
          </select>
        </div>

        <div class="col-6 col-lg-2 d-grid">
          <label class="form-label">&nbsp;</label>
          <a class="btn btn-outline-secondary search-btn-ghost" href="search.php"><i class="fa-solid fa-eraser me-1"></i>Pastro</a>
        </div>

      </div>
    </form>

    <ul class="nav nav-pills search-tabs course-status-tabs mt-3">
      <?php foreach ($tabItems as $key=>$it): ?>
        <li class="nav-item me-1 mb-1">
          <a class="nav-link <?= $scope===$key?'active':'' ?>" href="<?= h(tabUrl($key)) ?>">
            <i class="fa-solid <?= h($it['icon']) ?> me-1"></i><?= h($it['label']) ?>
            <?php if ($key!=='all'): ?>
              <span class="badge text-bg-light ms-1"><?= (int)($it['count'] ?? 0) ?></span>
            <?php endif; ?>
          </a>
        </li>
      <?php endforeach; ?>
    </ul>

    <?php if ($scope === 'all'): ?>

      <div class="row g-3 mt-1">

        <!-- USERS -->
        <div class="col-12">
          <div class="card search-card">
            <div class="card-header d-flex justify-content-between align-items-center">
              <h5 class="mb-0"><i class="fa-solid fa-user me-2"></i>Users</h5>
              <a class="small text-decoration-none" href="<?= h(tabUrl('users')) ?>">Shiko të gjitha</a>
            </div>
            <div class="card-body">
              <?php if ($summary['users']['rows']): ?>
                <div class="table-responsive">
                  <table class="table table-hover align-middle mb-0">
                    <thead><tr class="text-secondary"><th>Emri</th><th>Roli</th><th>Status</th><th>Krijuar</th><th>Email</th></tr></thead>
                    <tbody class="hl">
                      <?php foreach ($summary['users']['rows'] as $r): ?>
                        <tr>
                          <td><?= highlight($r['full_name'], $q) ?></td>
                          <td><span class="badge text-bg-secondary"><?= h($r['role']) ?></span></td>
                          <td>
                            <?php $b=['APROVUAR'=>'success','NE SHQYRTIM'=>'warning','REFUZUAR'=>'danger'][$r['status']] ?? 'secondary'; ?>
                            <span class="badge text-bg-<?= $b ?>"><?= h($r['status']) ?></span>
                          </td>
                          <td><?= $r['created_at'] ? date('d.m.Y H:i', strtotime($r['created_at'])) : '—' ?></td>
                          <td class="text-muted small"><?= highlight($r['email'], $q) ?></td>
                        </tr>
                      <?php endforeach; ?>
                    </tbody>
                  </table>
                </div>
              <?php else: ?>
                <div class="search-empty">Asnjë rezultat.</div>
              <?php endif; ?>
            </div>
          </div>
        </div>

        <!-- COURSES / PAYMENTS / MESSAGES -->
        <div class="col-12 col-lg-6">
          <div class="card search-card h-100">
            <div class="card-header d-flex justify-content-between align-items-center">
              <h5 class="mb-0"><i class="fa-solid fa-book me-2"></i>Courses</h5>
              <a class="small text-decoration-none" href="<?= h(tabUrl('courses')) ?>">Shiko të gjitha</a>
            </div>
            <div class="card-body">
              <?php if ($summary['courses']['rows']): ?>
                <?php foreach ($summary['courses']['rows'] as $c): ?>
                  <div class="result-row hl">
                    <div class="result-title">
                      <a href="course_details.php?course_id=<?= (int)$c['id'] ?>"><?= highlight($c['title'], $q) ?></a>
                    </div>
                    <div class="result-meta">
                      <span class="badge text-bg-secondary"><?= h($c['category']) ?></span>
                      <span class="badge text-bg-light"><?= h($c['status']) ?></span>
                      <span class="ms-2"><i class="fa-regular fa-clock me-1"></i><?= $c['created_at'] ? date('d.m.Y', strtotime($c['created_at'])) : '—' ?></span>
                    </div>
                  </div>
                <?php endforeach; ?>
              <?php else: ?>
                <div class="search-empty">Asnjë rezultat.</div>
              <?php endif; ?>
            </div>
          </div>
        </div>

        <div class="col-12 col-lg-6">
          <div class="card search-card h-100">
            <div class="card-header d-flex justify-content-between align-items-center">
              <h5 class="mb-0"><i class="fa-solid fa-credit-card me-2"></i>Payments</h5>
              <a class="small text-decoration-none" href="<?= h(tabUrl('payments')) ?>">Shiko të gjitha</a>
            </div>
            <div class="card-body">
              <?php if ($summary['payments']['rows']): ?>
                <div class="table-responsive">
                  <table class="table table-hover align-middle mb-0">
                    <thead><tr class="text-secondary"><th>ID</th><th>Përdoruesi</th><th>Kursi</th><th>Shuma</th><th>Status</th></tr></thead>
                    <tbody class="hl">
                      <?php foreach ($summary['payments']['rows'] as $p): ?>
                        <tr>
                          <td>#<?= (int)$p['id'] ?></td>
                          <td><?= highlight($p['full_name'], $q) ?></td>
                          <td><?= highlight($p['course_title'], $q) ?></td>
                          <td>€<?= number_format((float)$p['amount'], 2) ?></td>
                          <td>
                            <?php $b = $p['payment_status']==='COMPLETED' ? 'success' : 'danger'; ?>
                            <span class="badge text-bg-<?= $b ?>"><?= h($p['payment_status']) ?></span>
                          </td>
                        </tr>
                      <?php endforeach; ?>
                    </tbody>
                  </table>
                </div>
              <?php else: ?>
                <div class="search-empty">Asnjë rezultat.</div>
              <?php endif; ?>
            </div>
          </div>
        </div>

        <div class="col-12">
          <div class="card search-card">
            <div class="card-header d-flex justify-content-between align-items-center">
              <h5 class="mb-0"><i class="fa-regular fa-envelope me-2"></i>Messages</h5>
              <a class="small text-decoration-none" href="<?= h(tabUrl('messages')) ?>">Shiko të gjitha</a>
            </div>
            <div class="card-body">
              <?php if ($summary['messages']['rows']): ?>
                <ul class="list-unstyled mb-0 hl">
                  <?php foreach ($summary['messages']['rows'] as $m): ?>
                    <li class="mb-2">
                      <strong><?= highlight($m['subject'], $q) ?></strong>
                      <div class="result-meta">
                        <?= highlight($m['name'], $q) ?>
                        — <?= $m['created_at'] ? date('d.m.Y H:i', strtotime($m['created_at'])) : '—' ?>
                        <?= $m['read_status'] ? '' : ' • <span class="badge text-bg-warning">unread</span>' ?>
                      </div>
                    </li>
                  <?php endforeach; ?>
                </ul>
              <?php else: ?>
                <div class="search-empty">Asnjë rezultat.</div>
              <?php endif; ?>
            </div>
          </div>
        </div>

        <!-- LESSONS / ASSIGNMENTS / QUIZZES -->
        <div class="col-12">
          <div class="row g-3">
            <div class="col-12 col-lg-4">
              <div class="card search-card h-100">
                <div class="card-header d-flex justify-content-between align-items-center">
                  <h6 class="mb-0"><i class="fa-solid fa-book-open me-2"></i>Lessons</h6>
                  <a class="small text-decoration-none" href="<?= h(tabUrl('lessons')) ?>">Të gjitha</a>
                </div>
                <div class="card-body">
                  <?php if ($summary['lessons']['rows']): ?>
                    <ul class="list-unstyled mb-0 hl">
                      <?php foreach ($summary['lessons']['rows'] as $l): ?>
                        <li class="mb-2">
                          <a class="fw-semibold text-decoration-none" href="lesson_details.php?lesson_id=<?= (int)$l['id'] ?>"><?= highlight($l['title'], $q) ?></a>
                          <div class="result-meta">
                            <?= h($l['course_title']) ?> • <?= $l['uploaded_at'] ? date('d.m.Y', strtotime($l['uploaded_at'])) : '—' ?>
                            <?= $l['hidden'] ? ' • <span class="badge text-bg-secondary">hidden</span>' : '' ?>
                          </div>
                        </li>
                      <?php endforeach; ?>
                    </ul>
                  <?php else: ?>
                    <div class="search-empty">Asnjë rezultat.</div>
                  <?php endif; ?>
                </div>
              </div>
            </div>

            <div class="col-12 col-lg-4">
              <div class="card search-card h-100">
                <div class="card-header d-flex justify-content-between align-items-center">
                  <h6 class="mb-0"><i class="fa-solid fa-list-check me-2"></i>Assignments</h6>
                  <a class="small text-decoration-none" href="<?= h(tabUrl('assignments')) ?>">Të gjitha</a>
                </div>
                <div class="card-body">
                  <?php if ($summary['assignments']['rows']): ?>
                    <ul class="list-unstyled mb-0 hl">
                      <?php foreach ($summary['assignments']['rows'] as $a): ?>
                        <li class="mb-2">
                          <a class="fw-semibold text-decoration-none" href="assignment_details.php?assignment_id=<?= (int)$a['id'] ?>"><?= highlight($a['title'], $q) ?></a>
                          <div class="result-meta">
                            <?= h($a['course_title']) ?> • <span class="badge text-bg-light"><?= h($a['status']) ?></span>
                          </div>
                        </li>
                      <?php endforeach; ?>
                    </ul>
                  <?php else: ?>
                    <div class="search-empty">Asnjë rezultat.</div>
                  <?php endif; ?>
                </div>
              </div>
            </div>

            <div class="col-12 col-lg-4">
              <div class="card search-card h-100">
                <div class="card-header d-flex justify-content-between align-items-center">
                  <h6 class="mb-0"><i class="fa-solid fa-circle-question me-2"></i>Quizzes</h6>
                  <a class="small text-decoration-none" href="<?= h(tabUrl('quizzes')) ?>">Të gjitha</a>
                </div>
                <div class="card-body">
                  <?php if ($summary['quizzes']['rows']): ?>
                    <ul class="list-unstyled mb-0 hl">
                      <?php foreach ($summary['quizzes']['rows'] as $qz): ?>
                        <li class="mb-2">
                          <span class="fw-semibold"><?= highlight($qz['title'], $q) ?></span>
                          <div class="result-meta">
                            <?= h($qz['course_title']) ?> • <span class="badge text-bg-light"><?= h($qz['status']) ?></span>
                            <?= $qz['hidden'] ? ' • <span class="badge text-bg-secondary">hidden</span>' : '' ?>
                          </div>
                        </li>
                      <?php endforeach; ?>
                    </ul>
                  <?php else: ?>
                    <div class="search-empty">Asnjë rezultat.</div>
                  <?php endif; ?>
                </div>
              </div>
            </div>

          </div>
        </div>

      </div>

    <?php else:
      $data = $summary[$scope];
      [$pages, $pageFixed] = pager((int)$data['total'], $page, $perPage);
      $page = $pageFixed;
      $queryNoPage = $_GET; unset($queryNoPage['page']);
      $baseUrl = 'search.php?' . http_build_query($queryNoPage);
    ?>

      <div class="card search-card mt-3">
        <div class="card-header d-flex justify-content-between align-items-center">
          <h5 class="mb-0 text-capitalize">
            <i class="fa-solid fa-magnifying-glass me-2"></i><?= h($scope) ?> — <?= (int)$data['total'] ?> rezultate
          </h5>
          <div class="small text-secondary">Faqe <?= (int)$page ?> nga <?= (int)$pages ?></div>
        </div>

        <div class="card-body">

          <?php if (!$data['rows']): ?>
            <div class="search-empty">Asnjë rezultat.</div>
          <?php else: ?>

            <?php if ($scope === 'users'): ?>
              <div class="table-responsive">
                <table class="table table-hover align-middle mb-0">
                  <thead><tr class="text-secondary"><th>Emri</th><th>Roli</th><th>Status</th><th>Krijuar</th><th>Email</th></tr></thead>
                  <tbody class="hl">
                    <?php foreach ($data['rows'] as $r): ?>
                      <tr>
                        <td><?= highlight($r['full_name'], $q) ?></td>
                        <td><span class="badge text-bg-secondary"><?= h($r['role']) ?></span></td>
                        <td>
                          <?php $b=['APROVUAR'=>'success','NE SHQYRTIM'=>'warning','REFUZUAR'=>'danger'][$r['status']] ?? 'secondary'; ?>
                          <span class="badge text-bg-<?= $b ?>"><?= h($r['status']) ?></span>
                        </td>
                        <td><?= $r['created_at'] ? date('d.m.Y H:i', strtotime($r['created_at'])) : '—' ?></td>
                        <td class="text-muted small"><?= highlight($r['email'], $q) ?></td>
                      </tr>
                    <?php endforeach; ?>
                  </tbody>
                </table>
              </div>

            <?php elseif ($scope === 'courses'): ?>
              <?php foreach ($data['rows'] as $c): ?>
                <div class="result-row hl">
                  <div class="result-title">
                    <a href="course_details.php?course_id=<?= (int)$c['id'] ?>"><?= highlight($c['title'], $q) ?></a>
                  </div>
                  <div class="result-meta">
                    <span class="badge text-bg-secondary"><?= h($c['category']) ?></span>
                    <span class="badge text-bg-light"><?= h($c['status']) ?></span>
                    <span class="ms-2"><i class="fa-regular fa-clock me-1"></i><?= $c['created_at'] ? date('d.m.Y', strtotime($c['created_at'])) : '—' ?></span>
                  </div>
                </div>
              <?php endforeach; ?>

            <?php elseif ($scope === 'payments'): ?>
              <div class="table-responsive">
                <table class="table table-hover align-middle mb-0">
                  <thead><tr class="text-secondary"><th>ID</th><th>Përdoruesi</th><th>Kursi</th><th>Shuma</th><th>Status</th><th>Data</th></tr></thead>
                  <tbody class="hl">
                    <?php foreach ($data['rows'] as $p): ?>
                      <tr>
                        <td>#<?= (int)$p['id'] ?></td>
                        <td><?= highlight($p['full_name'], $q) ?></td>
                        <td><?= highlight($p['course_title'], $q) ?></td>
                        <td>€<?= number_format((float)$p['amount'], 2) ?></td>
                        <td>
                          <?php $b = $p['payment_status']==='COMPLETED' ? 'success' : 'danger'; ?>
                          <span class="badge text-bg-<?= $b ?>"><?= h($p['payment_status']) ?></span>
                        </td>
                        <td><?= $p['payment_date'] ? date('d.m.Y H:i', strtotime($p['payment_date'])) : '—' ?></td>
                      </tr>
                    <?php endforeach; ?>
                  </tbody>
                </table>
              </div>

            <?php elseif ($scope === 'messages'): ?>
              <ul class="list-unstyled mb-0 hl">
                <?php foreach ($data['rows'] as $m): ?>
                  <li class="mb-3">
                    <strong><?= highlight($m['subject'], $q) ?></strong>
                    <div class="result-meta">
                      <?= highlight($m['name'], $q) ?> — <?= $m['created_at'] ? date('d.m.Y H:i', strtotime($m['created_at'])) : '—' ?>
                      <?= $m['read_status'] ? '' : ' • <span class="badge text-bg-warning">unread</span>' ?>
                    </div>
                  </li>
                <?php endforeach; ?>
              </ul>

            <?php elseif ($scope === 'lessons'): ?>
              <ul class="list-unstyled mb-0 hl">
                <?php foreach ($data['rows'] as $l): ?>
                  <li class="mb-3">
                    <a class="fw-semibold text-decoration-none" href="lesson_details.php?lesson_id=<?= (int)$l['id'] ?>"><?= highlight($l['title'], $q) ?></a>
                    <div class="result-meta">
                      <?= h($l['course_title']) ?> • <?= $l['uploaded_at'] ? date('d.m.Y', strtotime($l['uploaded_at'])) : '—' ?>
                      <?= $l['hidden'] ? ' • <span class="badge text-bg-secondary">hidden</span>' : '' ?>
                    </div>
                  </li>
                <?php endforeach; ?>
              </ul>

            <?php elseif ($scope === 'assignments'): ?>
              <ul class="list-unstyled mb-0 hl">
                <?php foreach ($data['rows'] as $a): ?>
                  <li class="mb-3">
                    <a class="fw-semibold text-decoration-none" href="assignment_details.php?assignment_id=<?= (int)$a['id'] ?>"><?= highlight($a['title'], $q) ?></a>
                    <div class="result-meta">
                      <?= h($a['course_title']) ?> • <span class="badge text-bg-light"><?= h($a['status']) ?></span>
                    </div>
                  </li>
                <?php endforeach; ?>
              </ul>

            <?php elseif ($scope === 'quizzes'): ?>
              <ul class="list-unstyled mb-0 hl">
                <?php foreach ($data['rows'] as $qz): ?>
                  <li class="mb-3">
                    <span class="fw-semibold"><?= highlight($qz['title'], $q) ?></span>
                    <div class="result-meta">
                      <?= h($qz['course_title']) ?> • <span class="badge text-bg-light"><?= h($qz['status']) ?></span>
                      <?= $qz['hidden'] ? ' • <span class="badge text-bg-secondary">hidden</span>' : '' ?>
                      • <?= $qz['created_at'] ? date('d.m.Y', strtotime($qz['created_at'])) : '—' ?>
                    </div>
                  </li>
                <?php endforeach; ?>
              </ul>
            <?php endif; ?>

          <?php endif; ?>

          <?php if ($pages > 1): ?>
            <nav class="mt-3">
              <ul class="pagination mb-0">
                <li class="page-item <?= $page<=1?'disabled':'' ?>">
                  <a class="page-link" href="<?= h($baseUrl . '&page=' . max(1,$page-1)) ?>">&laquo;</a>
                </li>
                <?php
                  $start = max(1, $page-2);
                  $end   = min($pages, $page+2);
                  for ($i=$start; $i<=$end; $i++):
                ?>
                  <li class="page-item <?= $i===$page?'active':'' ?>">
                    <a class="page-link" href="<?= h($baseUrl . '&page=' . $i) ?>"><?= $i ?></a>
                  </li>
                <?php endfor; ?>
                <li class="page-item <?= $page>=$pages?'disabled':'' ?>">
                  <a class="page-link" href="<?= h($baseUrl . '&page=' . min($pages,$page+1)) ?>">&raquo;</a>
                </li>
              </ul>
            </nav>
          <?php endif; ?>

        </div>
      </div>

    <?php endif; ?>

    <div class="my-4 text-center">
      <a class="btn btn-outline-secondary" href="dashboard_admin.php"><i class="fa-solid fa-arrow-left me-1"></i> Kthehu në Panel</a>
    </div>

  </div>
</main>

<?php include __DIR__ . '/footer2.php'; ?>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
