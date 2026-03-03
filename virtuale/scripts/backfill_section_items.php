<?php
declare(strict_types=1);
session_start();
require_once __DIR__ . '/../lib/database.php';

if (!isset($_SESSION['user']) || !in_array($_SESSION['user']['role'] ?? '', ['Administrator','Instruktor'], true)) {
  die('403');
}
if (empty($_SESSION['csrf_token'])) { $_SESSION['csrf_token'] = bin2hex(random_bytes(32)); }
$CSRF = $_SESSION['csrf_token'];

$courseId = (int)($_POST['course_id'] ?? 0);
if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !hash_equals($CSRF, (string)($_POST['csrf'] ?? '')) || $courseId<=0) {
  ?>
  <form method="POST">
    <input type="hidden" name="csrf" value="<?= htmlspecialchars($CSRF,ENT_QUOTES) ?>">
    <label>course_id</label>
    <input name="course_id" type="number" required>
    <button>Backfill</button>
  </form>
  <?php exit;
}

function nextPos(PDO $pdo, int $courseId, int $sectionId): int {
  $q=$pdo->prepare("SELECT IFNULL(MAX(position),0)+1 FROM section_items WHERE course_id=? AND section_id=?");
  $q->execute([$courseId,$sectionId]); return (int)$q->fetchColumn();
}

$types = [
  ['LESSON','lessons','id','section_id'],
  ['ASSIGNMENT','assignments','id','section_id'],
  ['QUIZ','quizzes','id','section_id'],
];

$inserted=0;
try {
  foreach ($types as [$tt,$tbl,$idCol,$secCol]) {
    $q = $pdo->prepare("SELECT id, COALESCE($secCol,0) as sid FROM $tbl WHERE course_id=? AND NOT EXISTS (
      SELECT 1 FROM section_items si WHERE si.item_type=? AND si.item_ref_id=$tbl.$idCol
    ) ORDER BY id ASC");
    $q->execute([$courseId, $tt]);
    while($r=$q->fetch(PDO::FETCH_ASSOC)){
      $sid = (int)$r['sid'];
      $pos = nextPos($pdo, $courseId, $sid);
      $ins = $pdo->prepare("INSERT INTO section_items (course_id,section_id,item_type,item_ref_id,position) VALUES (?,?,?,?,?)");
      if ($ins->execute([$courseId,$sid,$tt,(int)$r['id'],$pos])) $inserted++;
    }
  }
  echo "OK. U futën $inserted rreshta.";
} catch (PDOException $e) { echo "DB error: ".$e->getMessage(); }
