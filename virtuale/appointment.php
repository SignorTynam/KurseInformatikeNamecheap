<?php
// appointment.php — Kalendar / Orar i takimeve (ADMIN)
// Pamje: Sot (day), Këtë javë (week), Ky muaj (month)

declare(strict_types=1);
session_start();
require_once __DIR__ . '/lib/bootstrap.php';

/* ------------------------------- RBAC ------------------------------- */
if (empty($_SESSION['user']) || (string)($_SESSION['user']['role'] ?? '') !== 'Administrator') {
  header('Location: login.php'); exit;
}
$ME_ID = (int)($_SESSION['user']['id'] ?? 0);

/* ------------------------------ Config ------------------------------ */
$LIVE_DURATION_MIN = 60; // sa zgjat një takim (për LIVE window)

/* ------------------------------ Helpers ----------------------------- */
function h(?string $s): string { return htmlspecialchars($s ?? '', ENT_QUOTES, 'UTF-8'); }

function ensure_csrf(): void {
  if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
  }
}
function check_csrf(string $token): bool {
  return isset($_SESSION['csrf_token']) && hash_equals($_SESSION['csrf_token'], $token);
}

function parse_dtlocal(?string $dt): ?string {
  if (!$dt) return null;
  try { $d = new DateTime($dt); return $d->format('Y-m-d H:i:s'); }
  catch (Throwable $e) { return null; }
}
function fmt_dtlocal(string $sqlDt): string {
  try { $d = new DateTime($sqlDt); return $d->format('Y-m-d\TH:i'); }
  catch (Throwable $e) { return ''; }
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

// Shfaq orën në format 12h si "3 PM"
function hour_label(int $h): string {
  $ampm = ($h >= 12) ? 'PM' : 'AM';
  $hh12 = $h % 12;
  if ($hh12 === 0) { $hh12 = 12; }
  return $hh12 . ' ' . $ampm;
}

ensure_csrf();

/* ------------------------ ICS download (early) ---------------------- */
if (isset($_GET['action'], $_GET['appointment_id']) && $_GET['action'] === 'ical') {
  $aid = (int)$_GET['appointment_id'];
  try {
    $stmt = $pdo->prepare("
      SELECT a.title AS appointment_title, a.description, a.appointment_date,
             a.link AS appointment_link, c.title AS course_title
      FROM appointments a
      JOIN courses c ON a.course_id = c.id
      WHERE a.id = ?
      LIMIT 1
    ");
    $stmt->execute([$aid]);
    $a = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$a) { http_response_code(404); echo 'Takimi nuk u gjet.'; exit; }

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
    error_log('ICS generation error (admin): '.$e->getMessage());
    http_response_code(500); echo 'Gabim serveri.'; exit;
  }
}

/* ------------------------------- Inputs ----------------------------- */
$q            = trim((string)($_GET['q'] ?? ''));
$courseFilter = (isset($_GET['course_id']) && is_numeric($_GET['course_id'])) ? (int)$_GET['course_id'] : null;

$now          = new DateTimeImmutable('now');
$todayYmd     = $now->format('Y-m-d');
$curMonth     = (int)$now->format('n');
$curYear      = (int)$now->format('Y');

// Pamja (day | week | month)
$viewRaw = $_GET['view'] ?? 'month';
$view = in_array($viewRaw, ['day','week','month'], true) ? $viewRaw : 'month';

// Fokus data (për day/week). default sot
$focusDateRaw = $_GET['date'] ?? $todayYmd;
try {
  $focusDateObj = new DateTimeImmutable($focusDateRaw);
} catch (Throwable $e) {
  $focusDateObj = $now;
}

// Muaji / viti (për month); default current
$month = (int)($_GET['month'] ?? $curMonth);
$year  = (int)($_GET['year']  ?? $curYear);
if ($month < 1 || $month > 12) $month = $curMonth;
if ($year < 2000 || $year > 2100) $year = $curYear;

// Llogarit intervalin kohor sipas pamjes
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

  $gridDays  = [];
} else {
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

  $prevTs    = strtotime('-1 month', $firstOfMonthTs);
  $nextTsNav = strtotime('+1 month', $firstOfMonthTs);
  $prevMonth = (int)date('n', $prevTs);
  $prevYear  = (int)date('Y', $prevTs);
  $nextMonth = (int)date('n', $nextTsNav);
  $nextYear  = (int)date('Y', $nextTsNav);
}

// string për SQL
$periodStartDT = $focusStart->format('Y-m-d H:i:s');
$periodEndDT   = $focusEndExclusive->format('Y-m-d H:i:s');

/* --------------------------- Courses list --------------------------- */
try {
  $stmtCourses = $pdo->query("SELECT id, title FROM courses ORDER BY title");
  $allCourses  = $stmtCourses->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) { $allCourses = []; }

/* ----------- Ngjyrat për çdo kurs (course-color-1..N) --------------- */
$courseColorClasses = [
  'course-color-1',
  'course-color-2',
  'course-color-3',
  'course-color-4',
  'course-color-5',
  'course-color-6',
  'course-color-7',
  'course-color-8',
  'course-color-9',
  'course-color-10',
  'course-color-11',
  'course-color-12',
];
$courseColors = [];
$idxColor = 0;
foreach ($allCourses as $c) {
  $cid = (int)$c['id'];
  $courseColors[$cid] = $courseColorClasses[$idxColor % count($courseColorClasses)];
  $idxColor++;
}

/* -------- Seanca e fundit për çdo kurs (për modal) ----------------- */
$lastSessions = [];
try {
  $stmtLast = $pdo->query("
    SELECT course_id, MAX(appointment_date) AS last_dt
    FROM appointments
    WHERE appointment_date <= NOW()
    GROUP BY course_id
  ");
  while ($row = $stmtLast->fetch(PDO::FETCH_ASSOC)) {
    if (!empty($row['last_dt'])) {
      $lastSessions[(int)$row['course_id']] = $row['last_dt'];
    }
  }
} catch (Throwable $e) {
  $lastSessions = [];
}

/* ------------------------------ Actions ----------------------------- */
$flash  = ['ok'=>[], 'err'=>[]];
$errors = [];

// URL-based flash (PRG). Keep tokens short and safe.
$flashToken = (string)($_GET['flash'] ?? '');
if ($flashToken !== '') {
  if ($flashToken === 'created') {
    $flash['ok'][] = 'Takimi u krijua me sukses.';
  } elseif ($flashToken === 'updated') {
    $flash['ok'][] = 'Takimi u përditësua.';
  } elseif ($flashToken === 'deleted') {
    $flash['ok'][] = 'Takimi u fshi.';
  } elseif ($flashToken === 'csrf') {
    $flash['err'][] = 'Sesioni i pavlefshëm (CSRF). Provo edhe një herë.';
  }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $mode = (string)($_POST['mode'] ?? '');
  $csrf = (string)($_POST['csrf_token'] ?? '');
  if (!check_csrf($csrf)) {
    // Prefer PRG even on CSRF to avoid resubmit loops
    $qs = $_GET;
    unset($qs['flash']);
    $qs['flash'] = 'csrf';
    header('Location: appointment.php?'.http_build_query($qs));
    exit;
  } else {
    $aid = isset($_POST['appointment_id']) ? (int)$_POST['appointment_id'] : 0;

    // DELETE: mos valido fusha që s'kanë lidhje me fshirjen
    if ($mode === 'delete') {
      if ($aid <= 0) {
        $flash['err'][] = 'ID e takimit mungon.';
      } else {
        try {
          $del = $pdo->prepare("DELETE FROM appointments WHERE id = ?");
          $del->execute([$aid]);

          // PRG + URL flash
          $qs = $_GET;
          unset($qs['flash']);
          $qs['flash'] = 'deleted';
          header('Location: appointment.php?'.http_build_query($qs));
          exit;
        } catch (Throwable $e) {
          $flash['err'][] = 'Gabim gjatë fshirjes: '.$e->getMessage();
        }
      }
    } else {
      // CREATE / UPDATE
      $course_id   = isset($_POST['course_id']) ? (int)$_POST['course_id'] : 0;
      $title       = trim((string)($_POST['title'] ?? ''));
      $description = trim((string)($_POST['description'] ?? ''));
      $link        = trim((string)($_POST['link'] ?? ''));
      $dtlocal     = (string)($_POST['appointment_dt'] ?? '');
      $sqlDate     = parse_dtlocal($dtlocal);

      if ($course_id <= 0)         $errors[] = 'Zgjidh kursin.';
      if ($title === '')           $errors[] = 'Titulli është i detyrueshëm.';
      if ($sqlDate === null)       $errors[] = 'Data/ora është e pavlefshme.';
      if ($link !== '' && filter_var($link, FILTER_VALIDATE_URL) === false) {
        $errors[] = 'Linku i takimit nuk është URL e vlefshme.';
      }

      if (!$errors) {
        try {
          $stmtCheck = $pdo->prepare("SELECT COUNT(*) FROM courses WHERE id = ?");
          $stmtCheck->execute([$course_id]);
          $existsCourse = (int)$stmtCheck->fetchColumn();
          if ($existsCourse !== 1) $errors[] = 'Kursi nuk ekziston.';
        } catch (Throwable $e) { $errors[] = 'Nuk mund të validohet kursi.'; }
      }

      if ($mode === 'create' && !$errors) {
        try {
          $ins = $pdo->prepare("
            INSERT INTO appointments (course_id, title, description, appointment_date, link)
            VALUES (?,?,?,?,?)
          ");
          $ins->execute([$course_id, $title, $description ?: null, $sqlDate, $link ?: null]);

          $qs = $_GET;
          unset($qs['flash']);
          $qs['flash'] = 'created';
          header('Location: appointment.php?'.http_build_query($qs));
          exit;
        } catch (Throwable $e) {
          $flash['err'][] = 'Gabim gjatë krijimit: '.$e->getMessage();
        }
      } elseif ($mode === 'update' && !$errors) {
        if ($aid <= 0) { $errors[] = 'ID e takimit mungon.'; }
        if (!$errors) {
          try {
            $upd = $pdo->prepare("
              UPDATE appointments
              SET course_id = ?, title = ?, description = ?, appointment_date = ?, link = ?
              WHERE id = ?
            ");
            $upd->execute([$course_id, $title, $description ?: null, $sqlDate, $link ?: null, $aid]);

            $qs = $_GET;
            unset($qs['flash']);
            $qs['flash'] = 'updated';
            header('Location: appointment.php?'.http_build_query($qs));
            exit;
          } catch (Throwable $e) {
            $flash['err'][] = 'Gabim gjatë përditësimit: '.$e->getMessage();
          }
        }
      }
    }
  }
}

/* --------------------------- Fetch period --------------------------- */
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
  WHERE a.appointment_date >= ? AND a.appointment_date < ?
";
$paramsPeriod = [$periodStartDT, $periodEndDT];

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
  error_log('appointments_calendar fetch error: '.$e->getMessage());
  $appointmentsPeriod = [];
}

/* --------------------------- Stats / Group -------------------------- */
$byDay            = [];  // 'Y-m-d' => [rows...]
$eventsByDayHour  = [];  // 'Y-m-d' => [hour(int0-23) => [rows...]]
$liveNowCount     = 0;
$noLinkCount      = 0;
$periodCount      = count($appointmentsPeriod);
$coursesInPeriod  = [];  // course_id => course_title

foreach ($appointmentsPeriod as $row) {
  $startDT = new DateTimeImmutable($row['appointment_date']);
  $cellKey = $startDT->format('Y-m-d');
  $hourKey = (int)$startDT->format('G'); // 0-23
  $cid     = (int)$row['course_id'];

  if (!isset($byDay[$cellKey])) $byDay[$cellKey] = [];
  $byDay[$cellKey][] = $row;

  if (!isset($eventsByDayHour[$cellKey])) $eventsByDayHour[$cellKey] = [];
  if (!isset($eventsByDayHour[$cellKey][$hourKey])) $eventsByDayHour[$cellKey][$hourKey] = [];
  $eventsByDayHour[$cellKey][$hourKey][] = $row;

  if (!isset($coursesInPeriod[$cid])) {
    $coursesInPeriod[$cid] = (string)$row['course_title'];
  }

  $endDT   = $startDT->add(new DateInterval('PT' . (int)$LIVE_DURATION_MIN . 'M'));
  $meetingLink = $row['appointment_link'] ?: $row['course_meeting'];

  if ($meetingLink && filter_var($meetingLink, FILTER_VALIDATE_URL) === false) {
    $noLinkCount++;
  } elseif (!$meetingLink) {
    $noLinkCount++;
  }

  if ($now >= $startDT && $now <= $endDT) {
    $liveNowCount++;
  }
}

// Orët në view javore
$hoursList = range(8, 21); // p.sh. 8:00–21:00, mund ta ndryshosh

?>
<!DOCTYPE html>
<html lang="sq" data-bs-theme="light">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <title>Takimet — <?= h($periodLabel) ?></title>

  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
  <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css" rel="stylesheet">
  <link rel="stylesheet" href="css/appointment_calendar.css?v=2">
  <link rel="icon" href="image/favicon.ico" type="image/x-icon" />
</head>
<body class="course-body">

<?php include __DIR__ . '/navbar_logged_administrator.php'; ?>

<!-- HERO (i njëjtë me faqet e kurseve) -->
<section class="course-hero">
  <div class="container">
    <div class="course-breadcrumb">
      <i class="fa-solid fa-house me-1"></i>
      <a href="dashboard_admin.php" class="text-decoration-none text-muted">Paneli</a> / Takimet
    </div>

    <div class="d-flex flex-column flex-lg-row align-items-start align-items-lg-center justify-content-between gap-3">
      <div>
        <h1>Takimet</h1>
        <p>
          Kalendar i takimeve sipas kursit. Shiko pamjen ditore, javore ose mujore
          dhe menaxho konsultimet online.
        </p>
      </div>

      <div class="d-flex flex-wrap gap-2">
        <div class="course-stat" title="Takime në këtë pamje">
          <div class="icon"><i class="fa-regular fa-calendar-days"></i></div>
          <div>
            <div class="label">Takime në këtë pamje</div>
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

        <div class="course-stat" title="Takime pa link ose link i pavlefshëm">
          <div class="icon"><i class="fa-solid fa-link-slash"></i></div>
          <div>
            <div class="label">Pa link valid</div>
            <div class="value"><?= (int)$noLinkCount ?></div>
          </div>
        </div>
      </div>
    </div>
  </div>
</section>

<main class="app-main">
  <div class="container-fluid px-3 px-lg-4">

    <div class="app-shell">

    <!-- Toolbar e thjeshtë (si faqet e tjera) -->
    <?php
      // URL “Sot”
      $qsDay = $_GET;
      unset($qsDay['flash']);
      $qsDay['view'] = 'day';
      $qsDay['date'] = $todayYmd;
      unset($qsDay['month'],$qsDay['year']);
      $dayUrl = '?'.http_build_query($qsDay);

      // URL “Këtë javë”
      $qsWeek = $_GET;
      unset($qsWeek['flash']);
      $qsWeek['view'] = 'week';
      $qsWeek['date'] = $todayYmd;
      unset($qsWeek['month'],$qsWeek['year']);
      $weekUrl = '?'.http_build_query($qsWeek);

      // URL “Ky muaj”
      $qsMonth = $_GET;
      unset($qsMonth['flash']);
      $qsMonth['view']  = 'month';
      $qsMonth['month'] = $curMonth;
      $qsMonth['year']  = $curYear;
      unset($qsMonth['date']);
      $monthUrl = '?'.http_build_query($qsMonth);
    ?>

    <div class="app-toolbar mb-3">
      <form method="get" class="row g-2 align-items-end">
        <!-- mbaj pamjen aktuale dhe datën -->
        <input type="hidden" name="view" value="<?= h($view) ?>">
        <input type="hidden" name="date" value="<?= h($focusStart->format('Y-m-d')) ?>">

        <div class="col-12 col-lg-4">
          <label class="form-label small text-muted mb-1">
            Kërko (titull / kurs / përshkrim)
          </label>
          <div class="input-group">
            <span class="input-group-text bg-white"><i class="fa-solid fa-search"></i></span>
            <input type="search" class="form-control" name="q" value="<?= h($q) ?>" placeholder="p.sh. 'Konsultim Python'">
          </div>
        </div>

        <div class="col-6 col-md-3 col-lg-2">
          <label class="form-label small text-muted mb-1">Kursi</label>
          <select name="course_id" class="form-select">
            <option value="">Të gjitha</option>
            <?php foreach ($allCourses as $c): ?>
              <option value="<?= (int)$c['id'] ?>" <?= $courseFilter===(int)$c['id']?'selected':'' ?>>
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
          <div class="col-6 col-md-3 col-lg-3">
            <label class="form-label small text-muted mb-1">Muaji / Viti</label>
            <div class="input-group">
              <a class="btn btn-outline-secondary btn-sm" href="<?= h($prevUrl) ?>" title="Muaji i kaluar">
                <i class="fa-solid fa-chevron-left"></i>
              </a>
              <select name="month" class="form-select form-select-sm" style="max-width:110px">
                <?php for ($m=1; $m<=12; $m++): ?>
                  <option value="<?= $m ?>" <?= $m===$month?'selected':'' ?>>
                    <?= h(month_name_sq($m)) ?>
                  </option>
                <?php endfor; ?>
              </select>
              <input type="number" class="form-control form-control-sm" name="year"
                     value="<?= (int)$year ?>" min="2000" max="2100" style="max-width:90px">
              <a class="btn btn-outline-secondary btn-sm" href="<?= h($nextUrl) ?>" title="Muaji i ardhshëm">
                <i class="fa-solid fa-chevron-right"></i>
              </a>
            </div>
          </div>
        <?php else: ?>
          <div class="col-6 col-md-3 col-lg-3">
            <label class="form-label small text-muted mb-1">Periudha</label>
            <input type="text" class="form-control form-control-sm" value="<?= h($periodLabel) ?>" readonly>
            <input type="hidden" name="month" value="<?= (int)$curMonth ?>">
            <input type="hidden" name="year"  value="<?= (int)$curYear ?>">
          </div>
        <?php endif; ?>

        <div class="col-12 col-lg-3 d-flex flex-wrap gap-2 justify-content-lg-end">
          <div class="app-view-switch btn-group btn-group-sm" role="group">
            <a href="<?= h($dayUrl) ?>" class="btn btn-outline-secondary<?= $view==='day'?' active':'' ?>">
              <i class="fa-solid fa-calendar-day me-1"></i>Sot
            </a>
            <a href="<?= h($weekUrl) ?>" class="btn btn-outline-secondary<?= $view==='week'?' active':'' ?>">
              <i class="fa-solid fa-calendar-week me-1"></i>Javë
            </a>
            <a href="<?= h($monthUrl) ?>" class="btn btn-outline-secondary<?= $view==='month'?' active':'' ?>">
              <i class="fa-solid fa-calendar-days me-1"></i>Muaj
            </a>
          </div>

          <button class="btn btn-primary btn-sm" type="submit">
            <i class="fa-solid fa-magnifying-glass me-1"></i> Rifiltro
          </button>
          <a class="btn btn-outline-secondary btn-sm" href="appointment.php">
            <i class="fa-solid fa-eraser me-1"></i> Pastro
          </a>

          <button type="button" class="btn btn-success btn-sm" data-bs-toggle="modal" data-bs-target="#modalForm">
            <i class="fa-solid fa-plus me-1"></i> Shto takim
          </button>
        </div>
      </form>

      <?php if ($coursesInPeriod): ?>
        <div class="course-color-legend mt-2">
          <?php foreach ($coursesInPeriod as $cid => $cTitle):
            $cc = $courseColors[$cid] ?? 'course-color-1';
          ?>
            <span class="legend-item">
              <span class="legend-dot <?= h($cc) ?>"></span>
              <span class="legend-label">
                <?= h(mb_strimwidth($cTitle, 0, 28, '…', 'UTF-8')) ?>
              </span>
            </span>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>
    </div>

    <?php if (($flash['ok'] || $flash['err'])): ?>
      <noscript>
        <?php foreach ($flash['ok'] as $msg): ?>
          <div class="alert alert-success d-flex align-items-center gap-2 alert-shadow">
            <i class="fa-regular fa-circle-check"></i>
            <div><?= h($msg) ?></div>
          </div>
        <?php endforeach; ?>
        <?php foreach ($flash['err'] as $msg): ?>
          <div class="alert alert-danger d-flex align-items-center gap-2 alert-shadow">
            <i class="fa-solid fa-triangle-exclamation"></i>
            <div><?= h($msg) ?></div>
          </div>
        <?php endforeach; ?>
      </noscript>
    <?php endif; ?>

    <?php if ($errors): ?>
      <div class="alert alert-danger alert-shadow">
        <ul class="mb-0">
          <?php foreach ($errors as $e): ?><li><?= h($e) ?></li><?php endforeach; ?>
        </ul>
      </div>
    <?php endif; ?>

    <!-- ================= PAMJET ================= -->
    <?php if ($view === 'month'): ?>
      <!-- ===== PAMJA MUJORE ===== -->
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
            <div class="cal-cell <?= $isToday ? 'today' : '' ?>" <?= $d ? ('data-ymd="'.h($cellDateYmd).'"') : '' ?>>
              <?php if ($d): ?>
                <div class="cal-day"><?= (int)$d ?></div>
                <button class="cal-add" type="button" title="Shto takim" data-ymd="<?= h($cellDateYmd) ?>">
                  <i class="fa-solid fa-plus"></i>
                </button>
                <div class="cal-list">
                  <?php if (!$items): ?>
                    <div class="empty-day">S’ka takime</div>
                  <?php else: ?>
                    <?php foreach ($items as $a):
                      $id          = (int)$a['appointment_id'];
                      $title       = (string)$a['appointment_title'];
                      $courseTitle = (string)$a['course_title'];
                      $desc        = (string)($a['description'] ?? '');
                      $startDT     = new DateTimeImmutable($a['appointment_date']);
                      $endDT       = $startDT->add(new DateInterval('PT' . (int)$LIVE_DURATION_MIN . 'M'));
                      $cid         = (int)$a['course_id'];

                      $meetingLink = $a['appointment_link'] ?: $a['course_meeting'];
                      $hasValidLink= $meetingLink && filter_var($meetingLink, FILTER_VALIDATE_URL);

                      $isLive      = ($now >= $startDT && $now <= $endDT);
                      $isFuture    = ($startDT > $now);

                      $clsBubble   = $isLive ? 'live' : ($isFuture ? 'next' : 'past');
                      $colorClass  = $courseColors[$cid] ?? 'course-color-1';
                    ?>
                      <div class="ev <?= $clsBubble ?> <?= h($colorClass) ?>">
                        <div class="ev-topline">
                          <span>
                            <?= h($startDT->format('H:i')) ?>–<?= h($endDT->format('H:i')) ?>
                            • <?= h(mb_strimwidth($courseTitle, 0, 32, '…', 'UTF-8')) ?>
                          </span>
                          <?php if ($isLive): ?>
                            <span class="badge-live"><i class="fa-solid fa-broadcast-tower me-1"></i>LIVE</span>
                          <?php endif; ?>
                        </div>

                        <div class="ev-midline">
                          <?= h(mb_strimwidth($title, 0, 60, '…', 'UTF-8')) ?>
                          <?php if ($desc !== ''): ?>
                            <div class="text-muted">
                              <?= h(mb_strimwidth($desc, 0, 60, '…', 'UTF-8')) ?>
                            </div>
                          <?php endif; ?>
                        </div>

                        <div class="ev-actions">
                          <?php if ($hasValidLink): ?>
                            <a class="ev-act" target="_blank" rel="noopener" href="<?= h($meetingLink) ?>">
                              <i class="fa-solid fa-video"></i><?= $isLive ? 'Hyr' : ($isFuture ? 'Lidhu' : 'Link') ?>
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

                          <button
                            class="btn-link-small"
                            data-bs-toggle="modal"
                            data-bs-target="#modalForm"
                            data-mode="update"
                            data-id="<?= $id ?>"
                            data-title="<?= h($title) ?>"
                            data-course_id="<?= (int)$a['course_id'] ?>"
                            data-description="<?= h($desc) ?>"
                            data-dt="<?= h(fmt_dtlocal($a['appointment_date'])) ?>"
                            data-link="<?= h((string)($a['appointment_link'] ?? '')) ?>">
                            <i class="fa-regular fa-pen-to-square"></i>Ndrysho
                          </button>

                          <form method="post" class="d-inline" onsubmit="return confirm('Të fshihet takimi?');">
                            <input type="hidden" name="csrf_token" value="<?= h($_SESSION['csrf_token']) ?>">
                            <input type="hidden" name="mode" value="delete">
                            <input type="hidden" name="appointment_id" value="<?= $id ?>">
                            <button class="btn-link-small text-danger">
                              <i class="fa-regular fa-trash-can"></i>Fshi
                            </button>
                          </form>
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
      <!-- ===== PAMJA JAVORE ===== -->
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
                $ymd      = $dayObj->format('Y-m-d');
                $isTodayW = ($ymd === $todayYmd);
                $slotEvents = $eventsByDayHour[$ymd][$h] ?? [];
              ?>
                <div class="time-cell <?= $isTodayW ? 'today' : '' ?>" data-ymd="<?= h($ymd) ?>" data-hour="<?= (int)$h ?>">
                  <?php if ($slotEvents): ?>
                    <?php foreach ($slotEvents as $a):
                      $id          = (int)$a['appointment_id'];
                      $title       = (string)$a['appointment_title'];
                      $courseTitle = (string)$a['course_title'];
                      $desc        = (string)($a['description'] ?? '');
                      $startDT     = new DateTimeImmutable($a['appointment_date']);
                      $endDT       = $startDT->add(new DateInterval('PT' . (int)$LIVE_DURATION_MIN . 'M'));
                      $cid         = (int)$a['course_id'];

                      $meetingLink = $a['appointment_link'] ?: $a['course_meeting'];
                      $hasValidLink= $meetingLink && filter_var($meetingLink, FILTER_VALIDATE_URL);

                      $isLive      = ($now >= $startDT && $now <= $endDT);
                      $isFuture    = ($startDT > $now);

                      $clsBubble   = $isLive ? 'live' : ($isFuture ? 'next' : 'past');
                      $colorClass  = $courseColors[$cid] ?? 'course-color-1';
                    ?>
                      <div class="ev <?= $clsBubble ?> <?= h($colorClass) ?>">
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
                            <div class="text-muted">
                              <?= h(mb_strimwidth($desc, 0, 60, '…', 'UTF-8')) ?>
                            </div>
                          <?php endif; ?>
                        </div>

                        <div class="ev-actions">
                          <?php if ($hasValidLink): ?>
                            <a class="ev-act" target="_blank" rel="noopener" href="<?= h($meetingLink) ?>">
                              <i class="fa-solid fa-video"></i><?= $isLive ? 'Hyr' : ($isFuture ? 'Lidhu' : 'Link') ?>
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

                          <button
                            class="btn-link-small"
                            data-bs-toggle="modal"
                            data-bs-target="#modalForm"
                            data-mode="update"
                            data-id="<?= $id ?>"
                            data-title="<?= h($title) ?>"
                            data-course_id="<?= (int)$a['course_id'] ?>"
                            data-description="<?= h($desc) ?>"
                            data-dt="<?= h(fmt_dtlocal($a['appointment_date'])) ?>"
                            data-link="<?= h((string)($a['appointment_link'] ?? '')) ?>">
                            <i class="fa-regular fa-pen-to-square"></i>Ndrysho
                          </button>

                          <form method="post" class="d-inline" onsubmit="return confirm('Të fshihet takimi?');">
                            <input type="hidden" name="csrf_token" value="<?= h($_SESSION['csrf_token']) ?>">
                            <input type="hidden" name="mode" value="delete">
                            <input type="hidden" name="appointment_id" value="<?= $id ?>">
                            <button class="btn-link-small text-danger">
                              <i class="fa-regular fa-trash-can"></i>Fshi
                            </button>
                          </form>
                        </div>
                      </div>
                    <?php endforeach; ?>
                  <?php endif; ?>
                </div>
              <?php endforeach; ?>
            </div>
          <?php endforeach; ?>
        </div>
      </div>

    <?php else: ?>
      <!-- ===== PAMJA DITORE ===== -->
      <div class="calendar-card mb-4">
        <div class="day-wrapper">
          <div class="day-headline">
            <?= h(dow_name_sq((int)$focusStart->format('N'))) ?>,
            <?= h($focusStart->format('d.m.Y')) ?>
          </div>

          <?php if (!$appointmentsPeriod): ?>
            <div class="empty-day">S'ka takime për këtë ditë.</div>
          <?php else: ?>
            <?php foreach ($appointmentsPeriod as $a):
              $id          = (int)$a['appointment_id'];
              $title       = (string)$a['appointment_title'];
              $courseTitle = (string)$a['course_title'];
              $desc        = (string)($a['description'] ?? '');
              $startDT     = new DateTimeImmutable($a['appointment_date']);
              $endDT       = $startDT->add(new DateInterval('PT' . (int)$LIVE_DURATION_MIN . 'M'));
              $cid         = (int)$a['course_id'];

              $meetingLink = $a['appointment_link'] ?: $a['course_meeting'];
              $hasValidLink= $meetingLink && filter_var($meetingLink, FILTER_VALIDATE_URL);

              $isLive      = ($now >= $startDT && $now <= $endDT);
              $isFuture    = ($startDT > $now);

              $clsBubble   = $isLive ? 'live' : ($isFuture ? 'next' : 'past');
              $colorClass  = $courseColors[$cid] ?? 'course-color-1';
            ?>
              <div class="ev <?= $clsBubble ?> <?= h($colorClass) ?>">
                <div class="ev-topline">
                  <span>
                    <?= h($startDT->format('H:i')) ?>–<?= h($endDT->format('H:i')) ?>
                    • <?= h(mb_strimwidth($courseTitle, 0, 40, '…', 'UTF-8')) ?>
                  </span>
                  <?php if ($isLive): ?>
                    <span class="badge-live"><i class="fa-solid fa-broadcast-tower me-1"></i>LIVE</span>
                  <?php endif; ?>
                </div>

                <div class="ev-midline">
                  <?= h(mb_strimwidth($title, 0, 80, '…', 'UTF-8')) ?>
                  <?php if ($desc !== ''): ?>
                    <div class="text-muted">
                      <?= h(mb_strimwidth($desc, 0, 90, '…', 'UTF-8')) ?>
                    </div>
                  <?php endif; ?>
                </div>

                <div class="ev-actions">
                  <?php if ($hasValidLink): ?>
                    <a class="ev-act" target="_blank" rel="noopener" href="<?= h($meetingLink) ?>">
                      <i class="fa-solid fa-video"></i><?= $isLive ? 'Hyr' : ($isFuture ? 'Lidhu' : 'Link') ?>
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

                  <button
                    class="btn-link-small"
                    data-bs-toggle="modal"
                    data-bs-target="#modalForm"
                    data-mode="update"
                    data-id="<?= $id ?>"
                    data-title="<?= h($title) ?>"
                    data-course_id="<?= (int)$a['course_id'] ?>"
                    data-description="<?= h($desc) ?>"
                    data-dt="<?= h(fmt_dtlocal($a['appointment_date'])) ?>"
                    data-link="<?= h((string)($a['appointment_link'] ?? '')) ?>">
                    <i class="fa-regular fa-pen-to-square"></i>Ndrysho
                  </button>

                  <form method="post" class="d-inline" onsubmit="return confirm('Të fshihet takimi?');">
                    <input type="hidden" name="csrf_token" value="<?= h($_SESSION['csrf_token']) ?>">
                    <input type="hidden" name="mode" value="delete">
                    <input type="hidden" name="appointment_id" value="<?= $id ?>">
                    <button class="btn-link-small text-danger">
                      <i class="fa-regular fa-trash-can"></i>Fshi
                    </button>
                  </form>
                </div>
              </div>
            <?php endforeach; ?>
          <?php endif; ?>
        </div>
      </div>
    <?php endif; ?>

    <?php if ($periodCount === 0): ?>
      <div class="alert alert-secondary alert-shadow text-center">
        <i class="fa-regular fa-calendar-xmark me-2"></i>
        Nuk ka takime në këtë pamje (me këto filtra).
      </div>
    <?php endif; ?>

    </div>

  </div>
</main>

<?php include __DIR__ . '/footer2.php'; ?>

<!-- Toasts -->
<div class="toast-container position-fixed bottom-0 end-0 p-3" id="toastStack" aria-live="polite" aria-atomic="true"></div>

<!-- Modal Create / Update -->
<div class="modal fade" id="modalForm" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-lg modal-dialog-scrollable">
    <div class="modal-content">
      <form method="post">
        <input type="hidden" name="csrf_token" value="<?= h($_SESSION['csrf_token']) ?>">
        <input type="hidden" name="mode" id="form-mode" value="create">
        <input type="hidden" name="appointment_id" id="form-id" value="">
        <div class="modal-header">
          <h5 class="modal-title">
            <i class="fa-regular fa-calendar-plus me-1"></i>
            <span id="form-title">Shto takim</span>
          </h5>
          <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Mbyll"></button>
        </div>
        <div class="modal-body">
          <div class="row g-3">
            <div class="col-12 col-md-6">
              <label class="form-label">Kursi</label>
              <select class="form-select" name="course_id" id="form-course" required>
                <option value="">— Zgjidh kursin —</option>
                <?php foreach ($allCourses as $c): ?>
                  <option value="<?= (int)$c['id'] ?>"><?= h($c['title']) ?></option>
                <?php endforeach; ?>
              </select>
              <div class="form-text" id="form-last-session">
                Zgjidh një kurs për të parë seancën e fundit (nëse ekziston).
              </div>
            </div>
            <div class="col-12 col-md-6">
              <label class="form-label">Data & Ora</label>
              <input type="datetime-local" class="form-control" name="appointment_dt" id="form-dt" required>
            </div>
            <div class="col-12">
              <label class="form-label">Titulli</label>
              <input type="text" class="form-control" name="title" id="form-name" maxlength="255" required>
            </div>
            <div class="col-12">
              <label class="form-label">Përshkrimi (opsionale)</label>
              <textarea class="form-control" name="description" id="form-desc" rows="3"></textarea>
            </div>
            <div class="col-12">
              <label class="form-label">Linku i takimit (opsionale)</label>
              <input type="url" class="form-control" name="link" id="form-link" placeholder="https://…">
              <div class="form-text">
                Nëse lihet bosh, do të përdoret linku i kursit (AulaVirtuale) kur ekziston.
              </div>
            </div>
          </div>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Mbyll</button>
          <button type="submit" class="btn btn-primary">
            <i class="fa-regular fa-floppy-disk me-1"></i>Ruaj
          </button>
        </div>
      </form>
    </div>
  </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script>
// Map me seancën e fundit për çdo kurs (nga PHP)
const lastSessions = <?=
  json_encode($lastSessions, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT)
?>;

// Flash mesazhet nga PHP (për toast)
const serverFlashOk = <?=
  json_encode($flash['ok'], JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT)
?>;
const serverFlashErr = <?=
  json_encode($flash['err'], JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT)
?>;

const courseSelect    = document.getElementById('form-course');
const lastSessionElem = document.getElementById('form-last-session');

function formatLastSession(courseId) {
  if (!courseId || !lastSessions[courseId]) {
    return 'Seanca e fundit për këtë kurs: nuk ka të regjistruar.';
  }
  const raw = lastSessions[courseId]; // "YYYY-MM-DD HH:MM:SS"
  const d   = new Date(raw.replace(' ', 'T'));
  if (isNaN(d.getTime())) {
    return 'Seanca e fundit: ' + raw;
  }
  const dd   = String(d.getDate()).padStart(2, '0');
  const mm   = String(d.getMonth() + 1).padStart(2, '0');
  const yyyy = d.getFullYear();
  const hh   = String(d.getHours()).padStart(2, '0');
  const min  = String(d.getMinutes()).padStart(2, '0');
  return `Seanca e fundit për këtë kurs: ${dd}.${mm}.${yyyy} ${hh}:${min}`;
}

function refreshLastSessionText() {
  if (!lastSessionElem || !courseSelect) return;
  const cid = courseSelect.value;
  if (!cid) {
    lastSessionElem.textContent = 'Zgjidh një kurs për të parë seancën e fundit (nëse ekziston).';
  } else {
    lastSessionElem.textContent = formatLastSession(cid);
  }
}

courseSelect?.addEventListener('change', refreshLastSessionText);

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

// Shfaq flash-in si toast dhe hiq `flash` nga URL (që të mos përsëritet)
window.addEventListener('DOMContentLoaded', () => {
  if (Array.isArray(serverFlashOk)) {
    serverFlashOk.forEach((m) => showToast(m, 'success'));
  }
  if (Array.isArray(serverFlashErr)) {
    serverFlashErr.forEach((m) => showToast(m, 'danger'));
  }

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

// UX: klik për të shtuar takim (muaj/javë)
(function(){
  const modalEl = document.getElementById('modalForm');
  if (!modalEl) return;
  const modalApi = () => bootstrap.Modal.getOrCreateInstance(modalEl);

  function openCreateAt(ymd, hour) {
    const modeEl = document.getElementById('form-mode');
    const idEl   = document.getElementById('form-id');
    const titleEl= document.getElementById('form-title');
    const dtEl   = document.getElementById('form-dt');
    const nameEl = document.getElementById('form-name');
    const descEl = document.getElementById('form-desc');
    const linkEl = document.getElementById('form-link');
    const courseEl = document.getElementById('form-course');

    if (modeEl) modeEl.value = 'create';
    if (idEl) idEl.value = '';
    if (titleEl) titleEl.textContent = 'Shto takim';
    if (nameEl) nameEl.value = '';
    if (descEl) descEl.value = '';
    if (linkEl) linkEl.value = '';

    const hh = String(Math.min(23, Math.max(0, hour ?? 10))).padStart(2,'0');
    const dt = `${ymd}T${hh}:00`;
    if (dtEl) dtEl.value = dt;

    // Prefill kursi nga filtri, nëse është zgjedhur
    const qs = new URLSearchParams(window.location.search);
    const filterCid = qs.get('course_id');
    if (courseEl && filterCid) {
      courseEl.value = filterCid;
      refreshLastSessionText();
    }

    modalApi().show();
    setTimeout(() => nameEl?.focus(), 150);
  }

  document.querySelectorAll('.cal-add[data-ymd]').forEach(btn => {
    btn.addEventListener('click', (e) => {
      e.preventDefault();
      e.stopPropagation();
      const ymd = btn.getAttribute('data-ymd');
      if (!ymd) return;
      openCreateAt(ymd, 10);
    });
  });

  document.querySelectorAll('.cal-cell[data-ymd]').forEach(cell => {
    cell.addEventListener('dblclick', (e) => {
      if (e.target.closest('.ev, .ev-actions, a, button, form')) return;
      const ymd = cell.getAttribute('data-ymd');
      if (!ymd) return;
      openCreateAt(ymd, 10);
    });
  });

  document.querySelectorAll('.time-cell').forEach(cell => {
    cell.addEventListener('dblclick', (e) => {
      if (e.target.closest('.ev, .ev-actions, a, button, form')) return;
      const ymd = cell.getAttribute('data-ymd');
      const hour = cell.getAttribute('data-hour');
      if (!ymd) return;
      openCreateAt(ymd, hour ? parseInt(hour, 10) : 10);
    });
  });
})();

// UX: auto-submit filtrat
(function(){
  const form = document.querySelector('.app-toolbar form');
  if (!form) return;

  const courseSel = form.querySelector('select[name="course_id"]');
  const monthSel  = form.querySelector('select[name="month"]');
  const yearInp   = form.querySelector('input[name="year"]');

  courseSel?.addEventListener('change', () => form.requestSubmit());
  monthSel?.addEventListener('change', () => form.requestSubmit());
  yearInp?.addEventListener('blur', () => {
    const v = (yearInp.value || '').trim();
    if (v !== '') form.requestSubmit();
  });
})();

// Modal populate (create / update)
const modal = document.getElementById('modalForm');
modal?.addEventListener('show.bs.modal', function (event) {
  const btn = event.relatedTarget;
  const isUpdate = btn && btn.getAttribute('data-mode') === 'update';
  const titleEl = document.getElementById('form-title');

  document.getElementById('form-mode').value = isUpdate ? 'update' : 'create';
  document.getElementById('form-id').value   = isUpdate ? (btn.getAttribute('data-id')||'') : '';

  const name  = isUpdate ? (btn.getAttribute('data-title')||'') : '';
  const desc  = isUpdate ? (btn.getAttribute('data-description')||'') : '';
  const dt    = isUpdate ? (btn.getAttribute('data-dt')||'') : '';
  const cid   = isUpdate ? (btn.getAttribute('data-course_id')||'') : '';
  const link  = isUpdate ? (btn.getAttribute('data-link')||'') : '';

  document.getElementById('form-name').value   = name;
  document.getElementById('form-desc').value   = desc;
  document.getElementById('form-dt').value     = dt;
  document.getElementById('form-course').value = cid;
  document.getElementById('form-link').value   = link;

  titleEl.textContent = isUpdate ? 'Ndrysho takim' : 'Shto takim';

  // rifresko mesazhin “seanca e fundit” sipas kursit të zgjedhur
  refreshLastSessionText();
});
</script>
</body>
</html>
