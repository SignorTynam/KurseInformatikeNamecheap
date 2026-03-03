<?php
// ==========================
// thread_create.php  (forum per kurs)
// ==========================
declare(strict_types=1);
session_start();

$ROOT = dirname(__DIR__);
$scriptDir = str_replace('\\', '/', dirname((string)($_SERVER['SCRIPT_NAME'] ?? '')));
$BASE_URL = $scriptDir;
foreach (['/threads', '/quizzes', '/sections'] as $suffix) {
  if ($suffix !== '/' && str_ends_with($BASE_URL, $suffix)) {
    $BASE_URL = substr($BASE_URL, 0, -strlen($suffix));
  }
}
if ($BASE_URL === '') $BASE_URL = '/';

require_once $ROOT . '/lib/database.php';

/* ------------------------- Helpers ------------------------- */
function h(?string $s): string { return htmlspecialchars($s ?? '', ENT_QUOTES, 'UTF-8'); }
function redirect_forum(int $course_id, string $msg = '', string $type = 'ok'): void {
  if ($msg !== '') {
    $t = ($type === 'error') ? 'danger' : 'success';
    $_SESSION['flash'] = ['msg'=>$msg, 'type'=>$t];
  }
  global $BASE_URL;
  header('Location: ' . $BASE_URL . '/course_details.php?course_id=' . $course_id . '&tab=forum');
  exit;
}
function post_str(string $key, string $default = ''): string { return isset($_POST[$key]) ? (string)$_POST[$key] : $default; }

/* --------------------------- Auth -------------------------- */
if (!isset($_SESSION['user'])) { header('Location: ' . $BASE_URL . '/login.php'); exit; }
$user     = $_SESSION['user'];
$user_id  = (int)($user['id'] ?? 0);
$userRole = (string)($user['role'] ?? '');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); exit('Metoda jo e lejuar.'); }

/* --------------------------- CSRF -------------------------- */
$csrf = (string)($_POST['csrf'] ?? '');
$csrfValid = false;
foreach (['csrf_token','csrf'] as $k) {
  if (!empty($_SESSION[$k]) && hash_equals((string)$_SESSION[$k], $csrf)) { $csrfValid = true; break; }
}
if (!$csrfValid) { http_response_code(403); exit('CSRF verifikimi dështoi.'); }

/* --------------------------- Inputs ------------------------ */
$course_id = (int)($_POST['course_id'] ?? 0);
$title     = trim(post_str('title'));
$content   = trim(post_str('content'));

if ($course_id <= 0) { exit('Kursi mungon.'); }

/* --------------------- Course + RBAC ----------------------- */
try {
  $stmt = $pdo->prepare('SELECT id, id_creator FROM courses WHERE id = ? LIMIT 1');
  $stmt->execute([$course_id]);
  $course = $stmt->fetch(PDO::FETCH_ASSOC);
  if (!$course) { exit('Kursi nuk u gjet.'); }
  $owner_id = (int)$course['id_creator'];
} catch (PDOException $e) { exit('Gabim DB: ' . h($e->getMessage())); }

$allowed = false;
if ($userRole === 'Administrator') {
  $allowed = true;
} elseif ($userRole === 'Instruktor') {
  $allowed = ($owner_id === $user_id);
} elseif ($userRole === 'Student') {
  $chk = $pdo->prepare('SELECT 1 FROM enroll WHERE course_id = ? AND user_id = ? LIMIT 1');
  $chk->execute([$course_id, $user_id]);
  $allowed = (bool)$chk->fetchColumn();
}
if (!$allowed) { http_response_code(403); exit('Nuk keni të drejta për të krijuar temë në këtë kurs.'); }

/* ----------------------- Validate -------------------------- */
if ($title === '' || $content === '') { redirect_forum($course_id, 'Titulli dhe përmbajtja janë të detyrueshme.', 'error'); }
if (mb_strlen($title, 'UTF-8') > 255)   { redirect_forum($course_id, 'Titulli është shumë i gjatë (max 255).', 'error'); }
if (mb_strlen($content,'UTF-8') > 20000){ redirect_forum($course_id, 'Përmbajtja është shumë e gjatë (max 20,000).', 'error'); }

/* -------------------- Optional attachment ------------------ */
if (!empty($_FILES['attachment']) && is_array($_FILES['attachment']) && (int)$_FILES['attachment']['error'] === UPLOAD_ERR_OK) {
  $tmp  = (string)$_FILES['attachment']['tmp_name'];
  $name = (string)$_FILES['attachment']['name'];
  $size = (int)$_FILES['attachment']['size'];

  // 10MB limit
  if ($size > 10 * 1024 * 1024) { redirect_forum($course_id, 'Skedari është më i madh se 10MB.', 'error'); }

  $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
  $allowed = ['pdf','png','jpg','jpeg','gif','doc','docx','ppt','pptx','xls','xlsx','txt','csv','zip','rar','mp4'];
  if (!in_array($ext, $allowed, true)) { redirect_forum($course_id, 'Tipi i skedarit nuk lejohet.', 'error'); }

  $dir = $ROOT . '/uploads/thread_attachments';
  if (!is_dir($dir)) { @mkdir($dir, 0755, true); }
  if (!is_dir($dir) || !is_writable($dir)) { redirect_forum($course_id, 'Nuk arrita të ruaj bashkëngjitjen.', 'error'); }

  $safeBase = preg_replace('/[^a-zA-Z0-9._-]+/','_', pathinfo($name, PATHINFO_FILENAME));
  $filename = date('Ymd_His') . '_' . $user_id . '_' . bin2hex(random_bytes(5)) . '_' . $safeBase . '.' . $ext;
  $destFs  = $dir . '/' . $filename;
  $destUrl = rtrim($BASE_URL, '/') . '/uploads/thread_attachments/' . $filename;

  if (!move_uploaded_file($tmp, $destFs)) { redirect_forum($course_id, 'Ngarkimi dështoi.', 'error'); }

  // Shto linkun në fund të përmbajtjes (Markdown)
  $content .= "\n\n**Bashkëngjitje:** [" . $name . "](" . $destUrl . ")";
}

/* ------------------------- Insert -------------------------- */
try {
  // Tani threads ka course_id
  $ins = $pdo->prepare('INSERT INTO threads (course_id, user_id, title, content) VALUES (?,?,?,?)');
  $ins->execute([$course_id, $user_id, $title, $content]);
  $newId = (int)$pdo->lastInsertId();
} catch (PDOException $e) {
  redirect_forum($course_id, 'Gabim gjatë krijimit të temës: ' . $e->getMessage(), 'error');
}

/* ------------------------ Redirect ------------------------- */
header('Location: ' . $BASE_URL . '/threads/thread_view.php?thread_id=' . $newId);
exit;
