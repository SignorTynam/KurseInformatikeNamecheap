<?php
declare(strict_types=1);

session_start();
require_once __DIR__ . '/../lib/database.php';
require_once __DIR__ . '/../lib/auth.php';

$u = require_role(['Student']);

function h(?string $s): string { return htmlspecialchars($s ?? '', ENT_QUOTES, 'UTF-8'); }
function utc_to_local(?string $s): string {
  if (!$s) return '';
  $dt = new DateTime($s, new DateTimeZone('UTC'));
  $dt->setTimezone(new DateTimeZone('Europe/Rome'));
  return $dt->format('Y-m-d H:i');
}

$meId = (int)$u['id'];

$st = $pdo->prepare(
  "SELECT t.*, c.title AS course_title
   FROM tests t
   JOIN enroll e ON e.course_id = t.course_id AND e.user_id = ?
   JOIN courses c ON c.id = t.course_id
   WHERE t.status = 'PUBLISHED'
   ORDER BY t.start_at DESC, t.created_at DESC"
);
$st->execute([$meId]);
$tests = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];

?>
<!DOCTYPE html>
<html lang="sq">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Testet e mia</title>
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css">
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css">
  <link rel="stylesheet" href="../css/km-lms-forms.css">
  <link rel="stylesheet" href="../css/km-tests-forms.css">
</head>
<body class="km-body">
<?php include __DIR__ . '/../navbar_logged_student.php'; ?>
<div class="container km-page-shell">

  <div class="km-page-header">
    <div class="d-flex align-items-start align-items-lg-center justify-content-between gap-3 flex-wrap">
      <div class="flex-grow-1">
        <div class="km-breadcrumb small">
          <span class="km-breadcrumb-current">Student / Testet</span>
        </div>
        <h1 class="km-page-title">
          <i class="fa-regular fa-clipboard me-2 text-primary"></i>
          Testet e mia
        </h1>
        <div class="km-page-subtitle">Testet e publikuara për kurset ku je i regjistruar.</div>
      </div>
      <span class="km-pill-meta">
        <i class="fa-solid fa-list"></i>
        Total: <strong><?= (int)count($tests) ?></strong>
      </span>
    </div>
  </div>

  <div class="km-card mt-3">
    <div class="km-card-header">
      <div>
        <h2 class="km-card-title"><span class="km-step-badge">1</span> Lista</h2>
        <div class="km-card-subtitle">Hap testin kur je gati</div>
      </div>
    </div>
    <div class="km-card-body">
      <div class="table-responsive">
        <table class="table km-table mb-0">
      <thead>
        <tr>
          <th>Titulli</th>
          <th>Kursi</th>
          <th>Start</th>
          <th>Due</th>
          <th>Veprime</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($tests as $t): ?>
          <tr>
            <td><?= h($t['title']) ?></td>
            <td><?= h($t['course_title']) ?></td>
            <td><?= h(utc_to_local((string)$t['start_at'])) ?></td>
            <td><?= h(utc_to_local((string)$t['due_at'])) ?></td>
            <td>
              <a class="btn btn-sm btn-primary km-btn-pill" href="test.php?test_id=<?= (int)$t['id'] ?>">Hap</a>
            </td>
          </tr>
        <?php endforeach; ?>
      </tbody>
        </table>
      </div>
    </div>
  </div>
</div>

<?php include __DIR__ . '/../footer2.php'; ?>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
