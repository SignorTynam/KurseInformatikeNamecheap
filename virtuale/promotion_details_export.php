<?php
declare(strict_types=1);
session_start();
require_once __DIR__ . '/lib/database.php';

if (!isset($_SESSION['user']) || ($_SESSION['user']['role']??'')!=='Administrator') {
  http_response_code(403); die('Forbidden');
}
if (!isset($_GET['id']) || !ctype_digit((string)$_GET['id'])) { die('Mungon id.'); }
$id = (int)$_GET['id'];

header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="promo_'.$id.'_enrollments.csv"');

$out = fopen('php://output', 'w');
fputcsv($out, ['#','First Name','Last Name','Email','Phone','Note','Created At']);

$st = $pdo->prepare("SELECT first_name,last_name,email,phone,note,created_at FROM promoted_course_enrollments WHERE promotion_id=:p ORDER BY created_at DESC");
$st->execute([':p'=>$id]);
$i=1;
while($r = $st->fetch(PDO::FETCH_ASSOC)){
  fputcsv($out, [$i++, $r['first_name'],$r['last_name'],$r['email'],$r['phone'],$r['note'],$r['created_at']]);
}
fclose($out);
