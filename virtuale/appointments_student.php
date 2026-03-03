<?php
// appointments_student.php — Orari im (Student)
// Pamje: Sot (day), Këtë javë (week), Ky muaj (month)
// Layout i ri i kalendarit + stil si courses_student.php
declare(strict_types=1);
session_start();
require_once __DIR__ . '/lib/database.php';

/* ------------------------------- RBAC ------------------------------- */
if (empty($_SESSION['user']) || (string)($_SESSION['user']['role'] ?? '') !== 'Student') {
  header('Location: login.php'); exit;
}
$ME_ID = (int)($_SESSION['user']['id'] ?? 0);

/* ------------------------------ Config ------------------------------ */
$LIVE_DURATION_MIN = 60; // sa konsiderohet "LIVE" pas nisjes së takimit

/* ------------------------------ Helpers ----------------------------- */
function h(?string $s): string {
  return htmlspecialchars($s ?? '', ENT_QUOTES, 'UTF-8');
}

function month_name_sq(int $m): string {
  static $names = [
    1=>'Janar',2=>'Shkurt',3=>'Mars',4=>'Prill',5=>'Maj',6=>'Qershor',
    7=>'Korrik',8=>'Gusht',9=>'Shtator',10=>'Tetor',11=>'Nëntor',12=>'Dhjetor'
  ];
  return $names[$m] ?? '';
}
function dow_name_sq(int $n): string {
  static $names = [
    1=>'Hënë',2=>'Mar',3=>'Mër',4=>'Enj',5=>'Pre',6=>'Sht',7=>'Die'
  ];
  return $names[$n] ?? '';
}
// Ora në stil 12h për kolonën majtas (p.sh. "3 PM")
function hour_label(int $h): string {
  $ampm = ($h >= 12) ? 'PM' : 'AM';
  $hh12 = $h % 12;
  if ($hh12 === 0) { $hh12 = 12; }
  return $hh12 . ' ' . $ampm;
}

/* ------------------------ ICS download (early) ---------------------- */
if (isset($_GET['action'], $_GET['appointment_id']) && $_GET['action'] === 'ical') {
  $aid = (int)$_GET['appointment_id'];
  try {
    $stmt = $pdo->prepare("
      SELECT a.title AS appointment_title, a.description, a.appointment_date,
             a.link AS appointment_link, c.title AS course_title
      FROM appointments a
      JOIN courses c ON a.course_id = c.id
      JOIN enroll  e ON e.course_id = c.id
      WHERE a.id = ? AND e.user_id = ?
      LIMIT 1
    ");
    $stmt->execute([$aid, $ME_ID]);
    $a = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$a) { http_response_code(404); echo 'Takimi nuk u gjet ose nuk keni qasje.'; exit; }

    $dt    = new DateTime($a['appointment_date']);
    $dtEnd = (clone $dt)->add(new DateInterval('PT' . (int)$LIVE_DURATION_MIN . 'M'));

    $startUtc = (clone $dt)->setTimezone(new DateTimeZone('UTC'))->format('Ymd\THis\Z');
    $endUtc   = (clone $dtEnd)->setTimezone(new DateTimeZone('UTC'))->format('Ymd\THis\Z');

    $uid  = 'appt-'.$aid.'@kurseinformatike.com';
    $sum  = preg_replace("/[\r\n]+/", ' ', (string)$a['appointment_title']);
    $desc = preg_replace("/[\r\n]+/", '\\n', (string)($a['description'] ?? ''));
    $loc  = (string)($a['appointment_link'] ?? '');

    $ics  = "BEGIN:VCALENDAR\r\nVERSION:2.0\r\nPRODID:-//kurseinformatike.com//EN\r\nCALSCALE:GREGORIAN\r\nBEGIN:VEVENT\r\n";
    $ics .= "UID:$uid\r\nDTSTAMP:".gmdate('Ymd\THis\Z')."\r\nDTSTART:$startUtc\r\nDTEND:$endUtc\r\n";
    $ics .= "SUMMARY:".addcslashes($sum, "\n,;")."\r\n";
    if ($desc !== '') $ics .= "DESCRIPTION:".addcslashes($desc, "\n,;")."\r\n";
    if ($loc  !== '') $ics .= "LOCATION:".addcslashes($loc,  "\n,;")."\r\n";
    $ics .= "END:VEVENT\r\nEND:VCALENDAR\r\n";

    header('Content-Type: text/calendar; charset=utf-8');
    header('Content-Disposition: attachment; filename=appointment-'.$aid.'.ics');
    echo $ics; exit;
  } catch (Throwable $e) {
    error_log('ICS generation error (student): '.$e->getMessage());
    http_response_code(500); echo 'Gabim serveri.'; exit;
  }
}

/* ------------------------------- Inputs ----------------------------- */
$q            = trim((string)($_GET['q'] ?? ''));
$courseFilter = (isset($_GET['course_id']) && is_numeric($_GET['course_id'])) ? (int)$_GET['course_id'] : null;

// Pamja (day | week | month). Default: javore (praktike për studentin)
$viewRaw = $_GET['view'] ?? 'week';
$view    = in_array($viewRaw, ['day','week','month'], true) ? $viewRaw : 'week';

$now      = new DateTimeImmutable('now');
$todayYmd = $now->format('Y-m-d');

$focusDateRaw = $_GET['date'] ?? $todayYmd;
try {
  $focusDateObj = new DateTimeImmutable($focusDateRaw);
} catch (Throwable $e) {
  $focusDateObj = $now;
}

// Muaji / viti për pamjen mujore; default aktuali
$curMonth = (int)$now->format('n');
$curYear  = (int)$now->format('Y');
$month    = (int)($_GET['month'] ?? $curMonth);
$year     = (int)($_GET['year']  ?? $curYear);
if ($month < 1 || $month > 12) { $month = $curMonth; }
if ($year  < 2000 || $year  > 2100) { $year = $curYear; }

/* ----------------------- Periudha kohore sipas pamjes --------------- */

if ($view === 'day') {
  $focusStart        = $focusDateObj->setTime(0,0,0);
  $focusEndExclusive = $focusStart->add(new DateInterval('P1D'));

  $periodLabel = ($focusStart->format('Y-m-d') === $todayYmd)
    ? ('Sot, '.$focusStart->format('d.m.Y'))
    : $focusStart->format('d.m.Y');

  $weekStart = null;
  $weekDays  = [];
  $gridDays  = [];
}
elseif ($view === 'week') {
  $dow       = (int)$focusDateObj->format('N'); // 1=Hënë
  $weekStart = $focusDateObj->sub(new DateInterval('P'.($dow-1).'D'))->setTime(0,0,0);

  $focusStart        = $weekStart;
  $focusEndExclusive = $weekStart->add(new DateInterval('P7D'));

  $weekEndDisp = $weekStart->add(new DateInterval('P6D'));
  $periodLabel = 'Java '.$weekStart->format('d.m').'–'.$weekEndDisp->format('d.m.Y');

  $weekDays = [];
  for ($i=0; $i<7; $i++) {
    $weekDays[] = $weekStart->add(new DateInterval('P'.$i.'D'));
  }

  $gridDays = [];
}
else {
  // Pamja mujore
  $firstOfMonthTs = strtotime(sprintf('%04d-%02d-01', $year, $month));
  $nextTs         = strtotime('+1 month', $firstOfMonthTs);

  $focusStart        = new DateTimeImmutable(date('Y-m-01 00:00:00', $firstOfMonthTs));
  $focusEndExclusive = new DateTimeImmutable(date('Y-m-01 00:00:00', $nextTs));

  $periodLabel = month_name_sq($month).' '.$year;

  $daysInMonth  = (int)date('t', $firstOfMonthTs);
  $startWeekday = (int)date('N', $firstOfMonthTs); // 1=Hënë ... 7=Die

  $padStart   = $startWeekday - 1;
  $totalCells = $padStart + $daysInMonth;
  $rows       = (int)ceil($totalCells/7);

  $gridDays = [];
  for ($i=0; $i<$rows*7; $i++) {
    $dayNum = $i - $padStart + 1;
    $gridDays[] = ($dayNum >= 1 && $dayNum <= $daysInMonth) ? $dayNum : 0;
  }

  $dowNames = ['Hënë','Mar','Mër','Enj','Pre','Sht','Die'];

  // Prev / Next muaj
  $prevTs    = strtotime('-1 month', $firstOfMonthTs);
  $nextTsNav = strtotime('+1 month', $firstOfMonthTs);
  $prevMonth = (int)date('n', $prevTs);
  $prevYear  = (int)date('Y', $prevTs);
  $nextMonth = (int)date('n', $nextTsNav);
  $nextYear  = (int)date('Y', $nextTsNav);
}

$periodStartDT = $focusStart->format('Y-m-d H:i:s');
$periodEndDT   = $focusEndExclusive->format('Y-m-d H:i:s');

/* --------------------------- Kursët e mia --------------------------- */
try {
  $stmt = $pdo->prepare("
    SELECT c.id, c.title
    FROM courses c
    JOIN enroll e ON e.course_id = c.id
    WHERE e.user_id = ?
    ORDER BY c.title
  ");
  $stmt->execute([$ME_ID]);
  $enrolledCourses = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
  $enrolledCourses = [];
}

/* --------------------------- Merr takimet --------------------------- */
$sqlPeriod = "
  SELECT a.id AS appointment_id,
         a.title AS appointment_title,
         a.description,
         a.appointment_date,
         a.link AS appointment_link,
         c.id AS course_id,
         c.title AS course_title,
         c.AulaVirtuale AS course_meeting
  FROM appointments a
  JOIN courses c ON a.course_id = c.id
  JOIN enroll  e ON e.course_id = c.id
  WHERE e.user_id = ?
    AND a.appointment_date >= ?
    AND a.appointment_date < ?
";
$paramsPeriod = [$ME_ID, $periodStartDT, $periodEndDT];

if ($courseFilter) { $sqlPeriod .= " AND c.id = ?"; $paramsPeriod[] = $courseFilter; }
if ($q !== '') {
  $sqlPeriod .= " AND (a.title LIKE ? OR c.title LIKE ? OR a.description LIKE ?)";
  $like = "%{$q}%";
  array_push($paramsPeriod, $like, $like, $like);
}

$sqlPeriod .= " ORDER BY a.appointment_date ASC LIMIT 500";

try {
  $stmtP = $pdo->prepare($sqlPeriod);
  $stmtP->execute($paramsPeriod);
  $appointmentsPeriod = $stmtP->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
  error_log('appointments_student period fetch error: '.$e->getMessage());
  $appointmentsPeriod = [];
}

/* --------------------------- Stats / Group -------------------------- */
$byDay           = [];  // 'Y-m-d' => [rows...]
$eventsByDayHour = [];  // 'Y-m-d' => [hour => [rows...]]
$liveNowCount    = 0;
$periodCount     = count($appointmentsPeriod);

// për legjendën & ngjyrat e kurseve
$courseTitlesForLegend = []; // course_id => title

foreach ($appointmentsPeriod as $row) {
  $startDT = new DateTimeImmutable($row['appointment_date']);
  $cellKey = $startDT->format('Y-m-d');
  $hourKey = (int)$startDT->format('G'); // 0-23

  if (!isset($byDay[$cellKey])) $byDay[$cellKey] = [];
  $byDay[$cellKey][] = $row;

  if (!isset($eventsByDayHour[$cellKey])) $eventsByDayHour[$cellKey] = [];
  if (!isset($eventsByDayHour[$cellKey][$hourKey])) $eventsByDayHour[$cellKey][$hourKey] = [];
  $eventsByDayHour[$cellKey][$hourKey][] = $row;

  $endDT = $startDT->add(new DateInterval('PT' . (int)$LIVE_DURATION_MIN . 'M'));
  if ($now >= $startDT && $now <= $endDT) {
    $liveNowCount++;
  }

  $cid = (int)$row['course_id'];
  if (!isset($courseTitlesForLegend[$cid])) {
    $courseTitlesForLegend[$cid] = (string)$row['course_title'];
  }
}

// Orët vertikale në grid javore
$hoursList = range(0,23);

/* ---------------------- Ngjyrat sipas kursit ------------------------ */
// Do të kemi deri në 12 ngjyra të ndryshme, të cikluara sipas titullit
$courseColorMap = []; // course_id => 'course-color-X'
if ($courseTitlesForLegend) {
  asort($courseTitlesForLegend, SORT_NATURAL | SORT_FLAG_CASE); // sipas titullit
  $colorIndex = 1;
  foreach ($courseTitlesForLegend as $cid => $title) {
    $courseColorMap[$cid] = 'course-color-'.$colorIndex;
    $colorIndex++;
    if ($colorIndex > 12) $colorIndex = 1;
  }
}

/* ------------------------------ URLs view --------------------------- */
// Link "Sot"
$qsDay = $_GET;
unset($qsDay['flash']);
$qsDay['view'] = 'day';
$qsDay['date'] = $todayYmd;
unset($qsDay['month'],$qsDay['year']);
$dayUrl = '?'.http_build_query($qsDay);

// Link "Këtë javë"
$qsWeek = $_GET;
unset($qsWeek['flash']);
$qsWeek['view'] = 'week';
$qsWeek['date'] = $todayYmd;
unset($qsWeek['month'],$qsWeek['year']);
$weekUrl = '?'.http_build_query($qsWeek);

// Link "Ky muaj"
$qsMonth = $_GET;
unset($qsMonth['flash']);
$qsMonth['view']  = 'month';
$qsMonth['month'] = $curMonth;
$qsMonth['year']  = $curYear;
unset($qsMonth['date']);
$monthUrl = '?'.http_build_query($qsMonth);

?>
<!DOCTYPE html>
<html lang="sq">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <title>Orari im — <?= h($periodLabel) ?></title>

  <link rel="icon" href="image/favicon.ico" type="image/x-icon" />
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
  <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css" rel="stylesheet">

  <!-- STYLE SPECIFIKE PËR ORARIN E STUDENTIT -->
  <style>
<?= trim('
:root {
  --primary:#2A4B7C;
  --primary-dark:#1d3a63;
  --secondary:#F0B323;
  --accent:#FF6B6B;
  --muted:#6b7280;
  --border:#e5e7eb;
  --card-radius:18px;
  --shadow-soft:0 8px 22px rgba(15,23,42,.06);
  --brand-font:-apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,"Helvetica Neue",Arial,"Noto Sans","Liberation Sans",sans-serif,"Apple Color Emoji","Segoe UI Emoji","Segoe UI Symbol","Noto Color Emoji";
}

/* BAZA */
.course-body{
  background:#f3f4f6;
  font-family:var(--brand-font);
}

/* HERO */
.course-hero{
  background-color: #ffffff;
  border-bottom:1px solid var(--border);
  padding:1.3rem 0 1.1rem;
  margin-bottom:1rem;
}
.course-hero h1{
  margin:0;
  font-weight:700;
  letter-spacing:.01em;
  font-size:clamp(1.5rem, 2vw + 1rem, 2.1rem);
  color:#111827;
}
.course-hero p{
  margin:.2rem 0 0;
  color:#4b5563;
  font-size:.93rem;
}
.course-breadcrumb{
  font-size:.85rem;
  color: var(--muted);
  margin-bottom:.15rem;
}

/* STAT CARDS */
.course-stat{
  background:#fff;
  border-radius:14px;
  border:1px solid var(--border);
  padding:.5rem .75rem;
  display:flex;
  align-items:center;
  gap:.55rem;
  box-shadow:0 6px 18px rgba(15,23,42,.04);
}
.course-stat .icon{
  width:34px;
  height:34px;
  border-radius:10px;
  display:flex;
  align-items:center;
  justify-content:center;
  background:#eff6ff;
  color: var(--primary);
  font-size:1rem;
}
.course-stat .label{
  font-size:.78rem;
  color:#6b7280;
}
.course-stat .value{
  font-size:1.1rem;
  font-weight:700;
}

/* MAIN WRAPPER */
.app-main{
  padding-bottom:2rem;
}

/* Constrain content width like admin calendar */
.app-shell{
  max-width:1320px;
  margin:0 auto;
}

/* TOOLBAR (si faqet e tjera, por më e thjeshtë) */
.app-toolbar{
  background:#ffffff;
  border-radius:16px;
  border:1px solid var(--border);
  padding:.75rem .9rem;
  box-shadow:var(--shadow-soft);
}

@media (min-width: 992px){
  .app-toolbar{
    position:sticky;
    top:12px;
    z-index:20;
  }
}

/* View switch (Sot / Javë / Muaj) */
.app-view-switch .btn{
  font-size:.8rem;
}

/* Flash */
.alert-shadow{
  border-radius: var(--card-radius);
  box-shadow: var(--shadow-soft);
}

/* LEGEND për ngjyrat e kurseve */
.course-color-legend{
  display:flex;
  flex-wrap:wrap;
  gap:.35rem;
}
.legend-item{
  display:inline-flex;
  align-items:center;
  gap:.35rem;
  padding:2px 8px;
  border-radius:999px;
  background:#ffffff;
  border:1px solid var(--border);
  font-size:.75rem;
  color:#4b5563;
}
.legend-dot{
  width:10px;
  height:10px;
  border-radius:999px;
  background:#cbd5e1;
}
.legend-label{
  white-space:nowrap;
}

/* GENERIC CARD */
.calendar-card{
  background:#ffffff;
  border-radius:var(--card-radius);
  box-shadow:var(--shadow-soft);
  border:1px solid var(--border);
  overflow:hidden;
}

/* MONTH VIEW GRID */
.cal-head{
  display:grid;
  grid-template-columns:repeat(7,1fr);
  background:#f2f5fb;
  border-bottom:1px solid #e5e7eb;
}
.cal-head div{
  padding:10px;
  text-transform:uppercase;
  font-size:.8rem;
  font-weight:700;
  color:#53607a;
  letter-spacing:.04em;
  text-align:center;
}

.cal-grid{
  display:grid;
  grid-template-columns:repeat(7,1fr);
}

.cal-cell{
  min-height:150px;
  border-right:1px solid #e5e7eb;
  border-bottom:1px solid #e5e7eb;
  padding:8px;
  position:relative;
  background:#fff;
  font-size:.9rem;
}
.cal-cell:nth-child(7n){
  border-right:none;
}
.cal-cell.today{
  background:linear-gradient(180deg,#fff,#f7fbff);
}
.cal-day{
  position:absolute;
  top:6px;
  right:8px;
  font-weight:600;
  color:#64748b;
  font-size:.9rem;
}
.cal-cell.today .cal-day{
  color:#111827;
  font-weight:700;
}
.cal-list{
  margin-top:22px;
  display:flex;
  flex-direction:column;
  gap:6px;
  max-height:110px;
  overflow:auto;
  padding-right:4px;
}
.empty-day{
  color:#9ca3af;
  font-size:.8rem;
  font-style:italic;
}

/* WEEK VIEW */
.week-timegrid-wrapper{
  background:#ffffff;
  border-radius:var(--card-radius);
  box-shadow:var(--shadow-soft);
  border:1px solid var(--border);
  overflow:hidden;
  font-size:.8rem;
  line-height:1.3;
}
.week-timegrid-header{
  display:grid;
  grid-template-columns:70px repeat(7,1fr);
  background:#f2f5fb;
  border-bottom:1px solid #e5e7eb;
}
.time-col-head{
  padding:8px 6px;
  font-size:.7rem;
  font-weight:600;
  color:#53607a;
  text-align:right;
  border-right:1px solid #e5e7eb;
  white-space:nowrap;
}
.day-col-head{
  padding:8px 6px;
  border-right:1px solid #e5e7eb;
  text-align:center;
  line-height:1.2;
}
.day-col-head:last-child{
  border-right:none;
}
.day-col-head .day-num{
  font-size:1rem;
  font-weight:600;
  color:#111827;
}
.day-col-head .day-name{
  font-size:.7rem;
  font-weight:400;
  color:#6b7280;
}
.day-col-head.today{
  background:#ffffff;
}
.day-col-head.today .day-num{
  color:var(--primary-dark);
  font-weight:700;
}

.week-timegrid-body{
  max-height:600px;
  overflow-y:auto;
  background:#fff;
}
.time-row{
  display:grid;
  grid-template-columns:70px repeat(7,1fr);
  border-bottom:1px solid #e5e7eb;
  min-height:60px;
  position:relative;
}
.time-row:last-child{
  border-bottom:none;
}
.time-col-label{
  border-right:1px solid #e5e7eb;
  padding:4px 6px;
  font-size:.7rem;
  color:#6b7280;
  text-align:right;
  white-space:nowrap;
}
.time-cell{
  border-right:1px solid #e5e7eb;
  position:relative;
  padding:4px;
  font-size:.75rem;
  background:#fff;
  min-height:60px;
}
.time-row .time-cell:last-child{
  border-right:none;
}
.time-cell.today{
  background:linear-gradient(180deg,#fff,#f7fbff);
}

/* DAY VIEW */
.day-wrapper{
  padding:16px;
}
.day-headline{
  font-weight:600;
  color:#111827;
  margin-bottom:12px;
}

/* EVENT BUBBLE */
.ev{
  background:#f8fafc;
  border:1px solid #e5e7eb;
  border-left-width:4px;
  border-left-color:#cbd5e1; /* default, override by course-color-X */
  border-radius:12px;
  padding:6px 8px;
  font-size:.75rem;
  line-height:1.3;
  color:#111827;
  text-decoration:none;
  display:block;
  margin-bottom:6px;
  transition:transform .12s ease, box-shadow .12s ease, background .12s ease, border-color .12s ease;
}
.ev:last-child{
  margin-bottom:0;
}
.ev:hover{
  background:#eef6ff;
  border-color:#dbeafe;
  box-shadow:0 10px 22px rgba(15,23,42,.06);
  transform:translateY(-1px);
  text-decoration:none;
}
.ev.live{
  box-shadow:0 0 0 1px rgba(220,38,38,.15);
}
.ev.past{
  opacity:.9;
}

/* Ngjyrat sipas kursit (border-left + legend dot) */
.ev.course-color-1{ border-left-color:#ef4444; }
.ev.course-color-2{ border-left-color:#3b82f6; }
.ev.course-color-3{ border-left-color:#10b981; }
.ev.course-color-4{ border-left-color:#f97316; }
.ev.course-color-5{ border-left-color:#8b5cf6; }
.ev.course-color-6{ border-left-color:#ec4899; }
.ev.course-color-7{ border-left-color:#0ea5e9; }
.ev.course-color-8{ border-left-color:#22c55e; }
.ev.course-color-9{ border-left-color:#eab308; }
.ev.course-color-10{ border-left-color:#6366f1; }
.ev.course-color-11{ border-left-color:#14b8a6; }
.ev.course-color-12{ border-left-color:#f43f5e; }

.legend-dot.course-color-1{ background:#ef4444; }
.legend-dot.course-color-2{ background:#3b82f6; }
.legend-dot.course-color-3{ background:#10b981; }
.legend-dot.course-color-4{ background:#f97316; }
.legend-dot.course-color-5{ background:#8b5cf6; }
.legend-dot.course-color-6{ background:#ec4899; }
.legend-dot.course-color-7{ background:#0ea5e9; }
.legend-dot.course-color-8{ background:#22c55e; }
.legend-dot.course-color-9{ background:#eab308; }
.legend-dot.course-color-10{ background:#6366f1; }
.legend-dot.course-color-11{ background:#14b8a6; }
.legend-dot.course-color-12{ background:#f43f5e; }

.ev-topline{
  font-weight:600;
  color:#111827;
  display:flex;
  flex-wrap:wrap;
  align-items:center;
  gap:4px;
  font-size:.75rem;
  line-height:1.3;
}
.ev-topline .badge-live{
  background:#fee2e2;
  color:#991b1b;
  font-size:.6rem;
  border-radius:.4rem;
  padding:1px 5px;
  font-weight:600;
  line-height:1.2;
}
.ev-midline{
  color:#4b5563;
  font-size:.7rem;
  line-height:1.3;
}
.ev-actions{
  margin-top:6px;
  display:flex;
  flex-wrap:wrap;
  gap:6px;
}

.ev-act{
  display:inline-flex;
  align-items:center;
  gap:6px;
  padding:4px 10px;
  border-radius:999px;
  border:1px solid var(--border);
  background:#ffffff;
  color:#374151;
  font-size:.7rem;
  line-height:1.2;
  text-decoration:none;
}
.ev-act:hover{
  background:#f8fafc;
  border-color:#d1d5db;
  color:#111827;
  text-decoration:none;
}
button.ev-act{
  cursor:pointer;
}
.ev-act-muted{
  background:#f3f4f6;
  color:#6b7280;
  border-color:#e5e7eb;
  pointer-events:none;
}

/* RESPONSIVE */
@media (max-width: 768px){
  .cal-cell{
    min-height:130px;
  }
  .week-timegrid-body{
    max-height:420px;
  }
}
@media (max-width: 576px){
  .course-hero{
    padding:1rem 0 .9rem;
  }
  .calendar-card{
    border-radius:12px;
  }
}
') . PHP_EOL ?>
  </style>
</head>
<body class="course-body">

<?php include __DIR__ . '/navbar_logged_student.php'; ?>

<!-- HERO -->
<section class="course-hero">
  <div class="container">
    <div class="d-flex flex-column flex-lg-row gap-3 align-items-start align-items-lg-center justify-content-between">
      <div>
        <div class="course-breadcrumb">
          <i class="fa-solid fa-house me-1"></i>
          <a href="dashboard_student.php" class="text-decoration-none text-reset">Paneli</a> / Orari im
        </div>
        <h1>Orari im</h1>
        <p>
          Shiko leksionet dhe takimet e kurseve ku je regjistruar.
          <span class="text-muted">Pamja: <?= h($periodLabel) ?></span>
        </p>
      </div>

      <div class="d-flex flex-wrap gap-2">
        <div class="course-stat" title="Sa takime ka në këtë pamje">
          <div class="icon"><i class="fa-regular fa-calendar-days"></i></div>
          <div>
            <div class="label">Në këtë pamje</div>
            <div class="value"><?= (int)$periodCount ?></div>
          </div>
        </div>
        <div class="course-stat" title="LIVE tani (brenda <?= (int)$LIVE_DURATION_MIN ?> min)">
          <div class="icon"><i class="fa-solid fa-broadcast-tower"></i></div>
          <div>
            <div class="label">LIVE tani</div>
            <div class="value"><?= (int)$liveNowCount ?></div>
          </div>
        </div>
      </div>
    </div>
  </div>
</section>

<main class="app-main">
  <div class="container-fluid px-3 px-lg-4">

    <div class="app-shell">

    <!-- View switch: Sot / Javë / Muaj -->
    <div class="d-flex flex-column flex-md-row align-items-md-center justify-content-between gap-2 mb-2">
      <div class="btn-group app-view-switch" role="group" aria-label="Ndrysho pamjen">
        <a href="<?= h($dayUrl) ?>" class="btn btn-sm <?= $view==='day' ? 'btn-primary' : 'btn-outline-secondary' ?>">
          <i class="fa-solid fa-calendar-day me-1"></i> Sot
        </a>
        <a href="<?= h($weekUrl) ?>" class="btn btn-sm <?= $view==='week' ? 'btn-primary' : 'btn-outline-secondary' ?>">
          <i class="fa-solid fa-calendar-week me-1"></i> Këtë javë
        </a>
        <a href="<?= h($monthUrl) ?>" class="btn btn-sm <?= $view==='month' ? 'btn-primary' : 'btn-outline-secondary' ?>">
          <i class="fa-solid fa-calendar-days me-1"></i> Ky muaj
        </a>
      </div>

      <?php if ($periodCount > 0): ?>
        <div class="text-muted small">
          <i class="fa-regular fa-clock me-1"></i>
          Intervali: <?= h($periodStartDT) ?> – <?= h($periodEndDT) ?>
        </div>
      <?php endif; ?>
    </div>

    <!-- Toolbar / Filtrat -->
    <div class="app-toolbar mb-3">
      <form method="get" class="row g-2 align-items-end">
        <input type="hidden" name="view" value="<?= h($view) ?>">
        <input type="hidden" name="date" value="<?= h($focusStart->format('Y-m-d')) ?>">

        <div class="col-12 col-md-4">
          <label class="form-label small text-muted mb-1">Kërko (titull / kurs / përshkrim)</label>
          <div class="input-group">
            <span class="input-group-text bg-white"><i class="fa-solid fa-search"></i></span>
            <input type="search" class="form-control" name="q" value="<?= h($q) ?>" placeholder="p.sh. 'JavaScript live'">
          </div>
        </div>

        <div class="col-6 col-md-3 col-lg-2">
          <label class="form-label small text-muted mb-1">Kursi</label>
          <select name="course_id" class="form-select">
            <option value="">Të gjitha</option>
            <?php foreach ($enrolledCourses as $c): ?>
              <option value="<?= (int)$c['id'] ?>" <?= $courseFilter===(int)$c['id'] ? 'selected' : '' ?>>
                <?= h($c['title']) ?>
              </option>
            <?php endforeach; ?>
          </select>
        </div>

        <?php if ($view === 'month'): ?>
          <?php
            $qsNavPrev = $_GET;
            unset($qsNavPrev['flash']);
            $qsNavPrev['view']  = 'month';
            $qsNavPrev['month'] = $prevMonth;
            $qsNavPrev['year']  = $prevYear;
            unset($qsNavPrev['date']);
            $prevUrl = '?'.http_build_query($qsNavPrev);

            $qsNavNext = $_GET;
            unset($qsNavNext['flash']);
            $qsNavNext['view']  = 'month';
            $qsNavNext['month'] = $nextMonth;
            $qsNavNext['year']  = $nextYear;
            unset($qsNavNext['date']);
            $nextUrl = '?'.http_build_query($qsNavNext);
          ?>
          <div class="col-12 col-md-5 col-lg-4">
            <label class="form-label small text-muted mb-1">Muaji / Viti</label>
            <div class="input-group">
              <a class="btn btn-outline-secondary" href="<?= h($prevUrl) ?>" title="Muaji i kaluar">
                <i class="fa-solid fa-chevron-left"></i>
              </a>
              <select name="month" class="form-select" style="max-width:130px">
                <?php for ($m=1; $m<=12; $m++): ?>
                  <option value="<?= $m ?>" <?= $m===$month ? 'selected' : '' ?>><?= h(month_name_sq($m)) ?></option>
                <?php endfor; ?>
              </select>
              <input type="number" class="form-control" name="year" value="<?= (int)$year ?>" min="2000" max="2100" style="max-width:90px">
              <a class="btn btn-outline-secondary" href="<?= h($nextUrl) ?>" title="Muaji i ardhshëm">
                <i class="fa-solid fa-chevron-right"></i>
              </a>
            </div>
          </div>
        <?php else: ?>
          <div class="col-12 col-md-5 col-lg-4">
            <label class="form-label small text-muted mb-1">Periudha</label>
            <div class="input-group">
              <span class="input-group-text bg-white"><i class="fa-regular fa-calendar"></i></span>
              <input type="text" class="form-control" value="<?= h($periodLabel) ?>" readonly>
            </div>
          </div>
          <input type="hidden" name="month" value="<?= (int)$curMonth ?>">
          <input type="hidden" name="year"  value="<?= (int)$curYear ?>">
        <?php endif; ?>

        <div class="col-12 col-md-3 d-flex gap-2 justify-content-md-end">
          <button class="btn btn-primary" type="submit">
            <i class="fa-solid fa-magnifying-glass me-1"></i> Rifiltro
          </button>
          <a class="btn btn-outline-secondary" href="appointments_student.php">
            <i class="fa-solid fa-eraser me-1"></i> Pastro
          </a>
        </div>
      </form>

      <!-- Chips / Info të shpejta -->
      <div class="mt-2 d-flex flex-wrap align-items-center gap-2">
        <span class="course-chip">
          <i class="fa-regular fa-calendar me-1"></i><?= h($periodLabel) ?>
        </span>
        <span class="course-chip">
          <i class="fa-solid fa-folder-open me-1"></i>
          Rezultate: <strong><?= (int)$periodCount ?></strong>
        </span>
        <?php if ($courseFilter): ?>
          <span class="course-chip">
            <i class="fa-solid fa-book me-1"></i> Kurs #<?= (int)$courseFilter ?>
          </span>
        <?php endif; ?>
        <?php if ($q !== ''): ?>
          <span class="course-chip">
            <i class="fa-solid fa-magnifying-glass me-1"></i> “<?= h($q) ?>”
          </span>
        <?php endif; ?>
      </div>
    </div>

    <!-- Legjenda e ngjyrave të kurseve -->
    <?php if ($courseTitlesForLegend): ?>
      <div class="mb-3">
        <div class="small fw-semibold mb-1">
          <i class="fa-solid fa-circle-dot me-1"></i> Legjenda e kurseve
        </div>
        <div class="course-color-legend">
          <?php foreach ($courseTitlesForLegend as $cid => $title):
            $colorClass = $courseColorMap[$cid] ?? '';
          ?>
            <span class="legend-item">
              <span class="legend-dot <?= h($colorClass) ?>"></span>
              <span class="legend-label"><?= h($title) ?></span>
            </span>
          <?php endforeach; ?>
        </div>
      </div>
    <?php endif; ?>

    <!-- ================= RENDER SIPAS PAMJES ================= -->
    <?php if ($view === 'month'): ?>
      <!-- PAMJA MUJORE -->
      <div class="calendar-card mb-4">
        <div class="cal-head">
          <?php foreach ($dowNames as $dn): ?>
            <div><?= h($dn) ?></div>
          <?php endforeach; ?>
        </div>
        <div class="cal-grid">
          <?php foreach ($gridDays as $d):
            $cellDateYmd = $d ? sprintf('%04d-%02d-%02d', $year, $month, $d) : '';
            $isToday     = ($d && $cellDateYmd === $todayYmd);
            $items       = $d ? ($byDay[$cellDateYmd] ?? []) : [];
          ?>
            <div class="cal-cell <?= $isToday ? 'today' : '' ?>">
              <?php if ($d): ?>
                <div class="cal-day"><?= (int)$d ?></div>
                <div class="cal-list">
                  <?php if (!$items): ?>
                    <div class="empty-day">—</div>
                  <?php else: ?>
                    <?php foreach ($items as $a):
                      $id          = (int)$a['appointment_id'];
                      $title       = (string)$a['appointment_title'];
                      $courseTitle = (string)$a['course_title'];
                      $desc        = (string)($a['description'] ?? '');
                      $startDT     = new DateTimeImmutable($a['appointment_date']);
                      $endDT       = $startDT->add(new DateInterval('PT' . (int)$LIVE_DURATION_MIN . 'M'));

                      $meetingLink  = $a['appointment_link'] ?: $a['course_meeting'];
                      $hasValidLink = $meetingLink && filter_var($meetingLink, FILTER_VALIDATE_URL);

                      $isLive    = ($now >= $startDT && $now <= $endDT);
                      $isFuture  = ($startDT > $now);
                      $stateCls  = $isLive ? 'live' : ($isFuture ? 'next' : 'past');
                      $colorCls  = $courseColorMap[(int)$a['course_id']] ?? '';
                    ?>
                      <div class="ev <?= h($colorCls) ?> <?= h($stateCls) ?>">
                        <div class="ev-topline">
                          <span>
                            <?= h($startDT->format('H:i')) ?>–<?= h($endDT->format('H:i')) ?>
                            • <?= h(mb_strimwidth($courseTitle, 0, 32, '…', 'UTF-8')) ?>
                          </span>
                          <?php if ($isLive): ?>
                            <span class="badge-live">
                              <i class="fa-solid fa-broadcast-tower me-1"></i>LIVE
                            </span>
                          <?php endif; ?>
                        </div>
                        <div class="ev-midline">
                          <?= h(mb_strimwidth($title, 0, 60, '…', 'UTF-8')) ?>
                          <?php if ($desc !== ''): ?>
                            <div class="text-muted"><?= h(mb_strimwidth($desc, 0, 60, '…', 'UTF-8')) ?></div>
                          <?php endif; ?>
                        </div>
                        <div class="ev-actions">
                          <span class="ev-act ev-act-muted">
                            <i class="fa-regular fa-clock"></i>
                            <?= $startDT->format('d.m.Y, H:i') ?>–<?= $endDT->format('H:i') ?>
                          </span>
                          <?php if ($hasValidLink): ?>
                            <a class="ev-act" target="_blank" rel="noopener" href="<?= h($meetingLink) ?>">
                              <i class="fa-solid fa-video"></i><?= $isLive ? ' HYR' : ($isFuture ? ' Lidhu' : ' Hap linkun') ?>
                            </a>
                            <button class="ev-act" type="button" onclick="copyLink('<?= h($meetingLink) ?>')">
                              <i class="fa-solid fa-link"></i>Kopjo
                            </button>
                          <?php else: ?>
                            <span class="ev-act ev-act-muted"><i class="fa-solid fa-link-slash"></i>Pa link</span>
                          <?php endif; ?>

                          <a class="ev-act" href="?action=ical&amp;appointment_id=<?= $id ?>">
                            <i class="fa-regular fa-calendar-plus"></i>.ics
                          </a>
                        </div>
                      </div>
                    <?php endforeach; ?>
                  <?php endif; ?>
                </div>
              <?php endif; ?>
            </div>
          <?php endforeach; ?>
        </div>
      </div>

    <?php elseif ($view === 'week'): ?>
      <!-- PAMJA JAVORE -->
      <div class="week-timegrid-wrapper mb-4">
        <div class="week-timegrid-header">
          <div class="time-col-head">&nbsp;</div>
          <?php foreach ($weekDays as $dayObj):
            $ymd      = $dayObj->format('Y-m-d');
            $isTodayW = ($ymd === $todayYmd);
          ?>
            <div class="day-col-head <?= $isTodayW ? 'today' : '' ?>">
              <div class="day-num"><?= h($dayObj->format('d')) ?></div>
              <div class="day-name"><?= h(dow_name_sq((int)$dayObj->format('N'))) ?></div>
            </div>
          <?php endforeach; ?>
        </div>

        <div class="week-timegrid-body">
          <?php foreach ($hoursList as $h): ?>
            <div class="time-row">
              <div class="time-col-label"><?= h(hour_label($h)) ?></div>

              <?php foreach ($weekDays as $dayObj):
                $ymd        = $dayObj->format('Y-m-d');
                $isTodayW   = ($ymd === $todayYmd);
                $slotEvents = $eventsByDayHour[$ymd][$h] ?? [];
              ?>
                <div class="time-cell <?= $isTodayW ? 'today' : '' ?>">
                  <?php foreach ($slotEvents as $a):
                    $id          = (int)$a['appointment_id'];
                    $title       = (string)$a['appointment_title'];
                    $courseTitle = (string)$a['course_title'];
                    $desc        = (string)($a['description'] ?? '');
                    $startDT     = new DateTimeImmutable($a['appointment_date']);
                    $endDT       = $startDT->add(new DateInterval('PT' . (int)$LIVE_DURATION_MIN . 'M'));

                    $meetingLink  = $a['appointment_link'] ?: $a['course_meeting'];
                    $hasValidLink = $meetingLink && filter_var($meetingLink, FILTER_VALIDATE_URL);

                    $isLive   = ($now >= $startDT && $now <= $endDT);
                    $isFuture = ($startDT > $now);
                    $stateCls = $isLive ? 'live' : ($isFuture ? 'next' : 'past');
                    $colorCls = $courseColorMap[(int)$a['course_id']] ?? '';
                  ?>
                    <div class="ev <?= h($colorCls) ?> <?= h($stateCls) ?>">
                      <div class="ev-topline">
                        <span>
                          <?= h($startDT->format('H:i')) ?>–<?= h($endDT->format('H:i')) ?>
                          • <?= h(mb_strimwidth($courseTitle, 0, 32, '…', 'UTF-8')) ?>
                        </span>
                        <?php if ($isLive): ?>
                          <span class="badge-live">
                            <i class="fa-solid fa-broadcast-tower me-1"></i>LIVE
                          </span>
                        <?php endif; ?>
                      </div>
                      <div class="ev-midline">
                        <?= h(mb_strimwidth($title, 0, 60, '…', 'UTF-8')) ?>
                        <?php if ($desc !== ''): ?>
                          <div class="text-muted"><?= h(mb_strimwidth($desc, 0, 60, '…', 'UTF-8')) ?></div>
                        <?php endif; ?>
                      </div>
                      <div class="ev-actions">
                        <span class="ev-act ev-act-muted">
                          <i class="fa-regular fa-clock"></i>
                          <?= $startDT->format('d.m.Y, H:i') ?>–<?= $endDT->format('H:i') ?>
                        </span>
                        <?php if ($hasValidLink): ?>
                          <a class="ev-act" target="_blank" rel="noopener" href="<?= h($meetingLink) ?>">
                            <i class="fa-solid fa-video"></i><?= $isLive ? ' HYR' : ($isFuture ? ' Lidhu' : ' Hap linkun') ?>
                          </a>
                          <button class="ev-act" type="button" onclick="copyLink('<?= h($meetingLink) ?>')">
                            <i class="fa-solid fa-link"></i>Kopjo
                          </button>
                        <?php else: ?>
                          <span class="ev-act ev-act-muted"><i class="fa-solid fa-link-slash"></i>Pa link</span>
                        <?php endif; ?>
                        <a class="ev-act" href="?action=ical&amp;appointment_id=<?= $id ?>">
                          <i class="fa-regular fa-calendar-plus"></i>.ics
                        </a>
                      </div>
                    </div>
                  <?php endforeach; ?>
                </div>
              <?php endforeach; ?>
            </div>
          <?php endforeach; ?>
        </div>
      </div>

    <?php else: ?>
      <!-- PAMJA DITORE -->
      <div class="calendar-card mb-4">
        <div class="day-wrapper">
          <div class="day-headline">
            <?= h(dow_name_sq((int)$focusStart->format('N'))) ?>,
            <?= h($focusStart->format('d.m.Y')) ?>
          </div>

          <?php if (!$appointmentsPeriod): ?>
            <div class="text-center text-muted">
              <div class="display-6 mb-2"><i class="fa-regular fa-calendar-xmark"></i></div>
              <div class="fw-semibold mb-1">Nuk ka takime për këtë ditë.</div>
              <div class="text-secondary">Çlodhu ose shiko materialet e kursit 😎</div>
            </div>
          <?php else: ?>
            <?php foreach ($appointmentsPeriod as $a):
              $id          = (int)$a['appointment_id'];
              $title       = (string)$a['appointment_title'];
              $courseTitle = (string)$a['course_title'];
              $desc        = (string)($a['description'] ?? '');
              $startDT     = new DateTimeImmutable($a['appointment_date']);
              $endDT       = $startDT->add(new DateInterval('PT' . (int)$LIVE_DURATION_MIN . 'M'));

              $meetingLink  = $a['appointment_link'] ?: $a['course_meeting'];
              $hasValidLink = $meetingLink && filter_var($meetingLink, FILTER_VALIDATE_URL);

              $isLive   = ($now >= $startDT && $now <= $endDT);
              $isFuture = ($startDT > $now);
              $stateCls = $isLive ? 'live' : ($isFuture ? 'next' : 'past');
              $colorCls = $courseColorMap[(int)$a['course_id']] ?? '';
            ?>
              <div class="ev <?= h($colorCls) ?> <?= h($stateCls) ?>">
                <div class="ev-topline">
                  <span>
                    <?= h($startDT->format('H:i')) ?>–<?= h($endDT->format('H:i')) ?>
                    • <?= h(mb_strimwidth($courseTitle, 0, 40, '…', 'UTF-8')) ?>
                  </span>
                  <?php if ($isLive): ?>
                    <span class="badge-live">
                      <i class="fa-solid fa-broadcast-tower me-1"></i>LIVE
                    </span>
                  <?php endif; ?>
                </div>
                <div class="ev-midline">
                  <?= h(mb_strimwidth($title, 0, 80, '…', 'UTF-8')) ?>
                  <?php if ($desc !== ''): ?>
                    <div class="text-muted"><?= h(mb_strimwidth($desc, 0, 90, '…', 'UTF-8')) ?></div>
                  <?php endif; ?>
                </div>
                <div class="ev-actions">
                  <span class="ev-act ev-act-muted">
                    <i class="fa-regular fa-clock"></i>
                    <?= $startDT->format('d.m.Y, H:i') ?>–<?= $endDT->format('H:i') ?>
                  </span>
                  <?php if ($hasValidLink): ?>
                    <a class="ev-act" target="_blank" rel="noopener" href="<?= h($meetingLink) ?>">
                      <i class="fa-solid fa-video"></i><?= $isLive ? ' HYR' : ($isFuture ? ' Lidhu' : ' Hap linkun') ?>
                    </a>
                    <button class="ev-act" type="button" onclick="copyLink('<?= h($meetingLink) ?>')">
                      <i class="fa-solid fa-link"></i>Kopjo
                    </button>
                  <?php else: ?>
                    <span class="ev-act ev-act-muted"><i class="fa-solid fa-link-slash"></i>Pa link</span>
                  <?php endif; ?>
                  <a class="ev-act" href="?action=ical&amp;appointment_id=<?= $id ?>">
                    <i class="fa-regular fa-calendar-plus"></i>.ics
                  </a>
                </div>
              </div>
            <?php endforeach; ?>
          <?php endif; ?>
        </div>
      </div>
    <?php endif; ?>

    <?php if ($periodCount === 0): ?>
      <div class="calendar-card p-4 mb-4 text-center text-muted">
        <div class="display-6 mb-2"><i class="fa-regular fa-calendar-xmark"></i></div>
        <div class="fw-semibold mb-1">Nuk ka takime në këtë pamje.</div>
        <div class="text-secondary">Provo pamjen tjetër (Sot / Javë / Muaj) ose ndrysho filtrat.</div>
      </div>
    <?php else: ?>
      <div class="text-muted small mb-4">
        <i class="fa-regular fa-lightbulb me-1"></i>
        Kliko “HYR / Lidhu” për të hapur takimin në klasën virtuale,
        ose “.ics” për ta shtuar në kalendarin tënd personal.
      </div>
    <?php endif; ?>

    </div>

  </div>
</main>

<?php include __DIR__ . '/footer2.php'; ?>

<div class="toast-container position-fixed bottom-0 end-0 p-3" id="toastStack" aria-live="polite" aria-atomic="true"></div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script>
function showToast(message, variant = 'dark') {
  const stack = document.getElementById('toastStack');
  if (!stack) return;

  const el = document.createElement('div');
  el.className = 'toast align-items-center text-bg-' + variant + ' border-0';
  el.setAttribute('role', 'status');
  el.setAttribute('aria-live', 'polite');
  el.setAttribute('aria-atomic', 'true');

  const row = document.createElement('div');
  row.className = 'd-flex';

  const body = document.createElement('div');
  body.className = 'toast-body';
  body.textContent = String(message ?? '');

  const close = document.createElement('button');
  close.type = 'button';
  close.className = 'btn-close btn-close-white me-2 m-auto';
  close.setAttribute('data-bs-dismiss', 'toast');
  close.setAttribute('aria-label', 'Mbyll');

  row.appendChild(body);
  row.appendChild(close);
  el.appendChild(row);
  stack.appendChild(el);

  const t = bootstrap.Toast.getOrCreateInstance(el, { delay: 2200 });
  el.addEventListener('hidden.bs.toast', () => el.remove());
  t.show();
}

// Hiq `flash` nga URL nëse vjen nga diku (që të mos përsëritet në refresh)
window.addEventListener('DOMContentLoaded', () => {
  try {
    const url = new URL(window.location.href);
    if (url.searchParams.has('flash')) {
      url.searchParams.delete('flash');
      window.history.replaceState({}, '', url.toString());
    }
  } catch (_) {}
});

function copyLink(link){
  if (!link) { showToast('Nuk ka link për këtë takim.', 'warning'); return; }
  if (navigator.clipboard && navigator.clipboard.writeText) {
    navigator.clipboard.writeText(link)
      .then(()=>showToast('Linku u kopjua.', 'success'))
      .catch(()=>fallbackCopy(link));
  } else {
    fallbackCopy(link);
  }
}
function fallbackCopy(text){
  const ta = document.createElement('textarea');
  ta.value = text;
  document.body.appendChild(ta);
  ta.select();
  try {
    document.execCommand('copy');
    showToast('Linku u kopjua.', 'success');
  } catch(e){
    showToast('Nuk mund të kopjohet linku.', 'danger');
  }
  ta.remove();
}
</script>
</body>
</html>
