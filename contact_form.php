<?php
// contact_form.php — CSRF + HP (random) + Time-trap + Throttling + reCAPTCHA v2 + Validim + INSERT
declare(strict_types=1);
session_start();
require_once __DIR__ . '/database.php'; // $pdo

$RECAPTCHA_SECRET = getenv('RECAPTCHA_SECRET') ?: '6LcT_OErAAAAAOc5yjvFTDmcOtk6scOD7wigzUek';

/* ---------- Helpers ---------- */
function redirect_back(string $fallback = 'contact.php#contact'): void {
  $to = $fallback;
  if (!empty($_SERVER['HTTP_REFERER'])) {
    $ref = $_SERVER['HTTP_REFERER'];
    $host = $_SERVER['HTTP_HOST'] ?? '';
    if (parse_url($ref, PHP_URL_HOST) === $host || !parse_url($ref, PHP_URL_HOST)) {
      $to = $ref . (str_contains($ref, '#') ? '' : '#contact');
    }
  }
  header('Location: ' . $to);
  exit;
}
function back_with_error(string $msg, array $old = []): void {
  $_SESSION['error_message'] = $msg;
  foreach (['name','email','subject','message'] as $k) {
    $_SESSION['old_'.$k] = $old[$k] ?? ($_POST[$k] ?? '');
  }
  redirect_back();
}
function back_with_success(string $msg): void {
  $_SESSION['success_message'] = $msg;
  unset($_SESSION['old_name'], $_SESSION['old_email'], $_SESSION['old_subject'], $_SESSION['old_message']);
  redirect_back();
}
function verify_recaptcha_v2(string $secret, string $response): array {
  if ($response === '' || $secret === '') return [false, 'Captcha nuk u verifikua.'];
  $post = http_build_query([
    'secret'   => $secret,
    'response' => $response,
    'remoteip' => $_SERVER['REMOTE_ADDR'] ?? null
  ]);
  if (function_exists('curl_init')) {
    $ch = curl_init('https://www.google.com/recaptcha/api/siteverify');
    curl_setopt_array($ch, [
      CURLOPT_POST => true, CURLOPT_POSTFIELDS => $post,
      CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 8,
    ]);
    $out = curl_exec($ch); $err = curl_error($ch); curl_close($ch);
    if ($out === false) { error_log("[contact] reCAPTCHA cURL error: $err"); return [false, 'Captcha nuk u verifikua.']; }
  } else {
    $ctx = stream_context_create(['http'=>['method'=>'POST','header'=>"Content-type: application/x-www-form-urlencoded\r\n",'content'=>$post,'timeout'=>8]]);
    $out = @file_get_contents('https://www.google.com/recaptcha/api/siteverify', false, $ctx);
    if ($out === false) { error_log("[contact] reCAPTCHA fopen error"); return [false, 'Captcha nuk u verifikua.']; }
  }
  $j = json_decode($out, true);
  if (!is_array($j) || empty($j['success'])) return [false, 'Captcha dështoi, provo sërish.'];
  return [true, null];
}

/* ---------- Vetëm POST ---------- */
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') redirect_back();

/* ---------- Honeypot (random me trim) ---------- */
$hpField = $_SESSION['hp_field'] ?? null;
if ($hpField !== null) {
  $hpVal = isset($_POST[$hpField]) ? trim((string)$_POST[$hpField]) : '';
  // Nëse është mbushur realisht, trajtoje si bot
  if ($hpVal !== '') {
    // mos e shfaq "Bad request"; kthehu me mesazh i padëmshëm
    back_with_error('Formulari nuk kaloi verifikimin. Ju lutem provoni përsëri.');
  }
}
// rrotullo emrin e honeypot pas përdorimit
unset($_SESSION['hp_field']);

/* ---------- Time-trap minimal (2s) ---------- */
$issuedAt = (int)($_SESSION['form_issued_at'] ?? 0);
unset($_SESSION['form_issued_at']);
if ($issuedAt === 0 || (time() - $issuedAt) < 2) {
  back_with_error('Ju lutem plotësoni formularin dhe provoni përsëri.');
}

/* ---------- CSRF ---------- */
if (!hash_equals($_SESSION['csrf_token'] ?? '', $_POST['csrf_token'] ?? '')) {
  back_with_error('Seanca e pavlefshme. Rifreskoni faqen.');
}

/* ---------- Throttling (30s) ---------- */
$now = time();
$minInterval = 30;
if (isset($_SESSION['last_contact_at']) && ($now - (int)$_SESSION['last_contact_at'] < $minInterval)) {
  $wait = $minInterval - ($now - (int)$_SESSION['last_contact_at']);
  back_with_error("Ju lutem provoni pas {$wait} sekondash.");
}

/* ---------- Inputs ---------- */
$name    = trim((string)($_POST['name'] ?? ''));
$email   = trim((string)($_POST['email'] ?? ''));
$subject = trim((string)($_POST['subject'] ?? ''));
$message = trim((string)($_POST['message'] ?? ''));

/* ---------- Validim (në përputhje me DB) ---------- */
$errs = [];
if ($name === ''    || mb_strlen($name) < 2   || mb_strlen($name) > 100)  $errs[] = 'Emri nuk është i vlefshëm.';
if ($email === ''   || !filter_var($email, FILTER_VALIDATE_EMAIL) || mb_strlen($email) > 100) $errs[] = 'Email i pavlefshëm.';
if ($subject === '' || mb_strlen($subject) < 3 || mb_strlen($subject) > 255) $errs[] = 'Subjekti nuk është i vlefshëm.';
if ($message === '' || mb_strlen($message) < 10 || mb_strlen($message) > 2000) $errs[] = 'Mesazhi nuk është i vlefshëm.';
if ($errs) back_with_error($errs[0], compact('name','email','subject','message'));

/* ---------- reCAPTCHA v2 ---------- */
$token = $_POST['g-recaptcha-response'] ?? '';
if ($token === '') back_with_error('Konfirmoni që nuk jeni robot.', compact('name','email','subject','message'));
[$ok, $msg] = verify_recaptcha_v2($RECAPTCHA_SECRET, $token);
if (!$ok) back_with_error($msg ?? 'Captcha dështoi, provo sërish.', compact('name','email','subject','message'));

/* ---------- Anti-duplicate brenda 5 min ---------- */
try {
  $dupStmt = $pdo->prepare("
    SELECT id FROM messages
    WHERE email = :email AND subject = :subject AND message = :message
      AND created_at >= (CURRENT_TIMESTAMP - INTERVAL 5 MINUTE)
    LIMIT 1
  ");
  $dupStmt->execute([':email'=>$email, ':subject'=>$subject, ':message'=>$message]);
  if ($dupStmt->fetch()) {
    $_SESSION['last_contact_at'] = time();
    back_with_success('Mesazhi u pranua (duplikat brenda 5 minutave u shmang).');
  }
} catch (Throwable $e) {
  error_log('[contact] dup-check error: '.$e->getMessage());
}

/* ---------- INSERT ---------- */
try {
  $stmt = $pdo->prepare("
    INSERT INTO messages (name, email, subject, message)
    VALUES (:name, :email, :subject, :message)
  ");
  $stmt->execute([
    ':name'    => $name,
    ':email'   => $email,
    ':subject' => $subject,
    ':message' => $message, // Shfaq me htmlspecialchars kur e lexon
  ]);

  $_SESSION['last_contact_at'] = time();
  back_with_success('Mesazhi u dërgua me sukses! Do t’ju kthejmë përgjigje sa më shpejt.');
} catch (Throwable $e) {
  error_log('[contact] insert error: '.$e->getMessage());
  back_with_error('Gabim gjatë dërgimit të mesazhit. Ju lutem provoni përsëri.', compact('name','email','subject','message'));
}
