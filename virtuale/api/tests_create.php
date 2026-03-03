<?php
declare(strict_types=1);

date_default_timezone_set('UTC');

require_once __DIR__ . '/../lib/database.php';
require_once __DIR__ . '/../lib/auth.php';
require_once __DIR__ . '/../lib/csrf.php';
require_once __DIR__ . '/../lib/json_response.php';

$u = require_role_json(['Administrator','Instruktor']);
verify_csrf_json();

$input = read_json_body();
$course_id = (int)($input['course_id'] ?? $_POST['course_id'] ?? 0);
$title = trim((string)($input['title'] ?? $_POST['title'] ?? ''));
$description = trim((string)($input['description'] ?? $_POST['description'] ?? ''));
$section_id = (int)($input['section_id'] ?? $_POST['section_id'] ?? 0) ?: null;
$lesson_id = (int)($input['lesson_id'] ?? $_POST['lesson_id'] ?? 0) ?: null;
$time_limit_minutes = (int)($input['time_limit_minutes'] ?? $_POST['time_limit_minutes'] ?? 0);
$pass_score = (float)($input['pass_score'] ?? $_POST['pass_score'] ?? 0);
$max_attempts = (int)($input['max_attempts'] ?? $_POST['max_attempts'] ?? 1);
$shuffle_questions = !empty($input['shuffle_questions'] ?? $_POST['shuffle_questions']) ? 1 : 0;
$shuffle_choices = !empty($input['shuffle_choices'] ?? $_POST['shuffle_choices']) ? 1 : 0;
$show_results_mode = (string)($input['show_results_mode'] ?? $_POST['show_results_mode'] ?? 'IMMEDIATE');
$show_correct_answers_mode = (string)($input['show_correct_answers_mode'] ?? $_POST['show_correct_answers_mode'] ?? 'NEVER');
$start_at = trim((string)($input['start_at'] ?? $_POST['start_at'] ?? ''));
$due_at = trim((string)($input['due_at'] ?? $_POST['due_at'] ?? ''));

function to_utc(?string $val): ?string {
  $val = trim((string)$val);
  if ($val === '') return null;
  $dt = DateTime::createFromFormat('Y-m-d\TH:i', $val, new DateTimeZone('Europe/Rome'));
  if (!$dt) {
    $dt = new DateTime($val, new DateTimeZone('Europe/Rome'));
  }
  $dt->setTimezone(new DateTimeZone('UTC'));
  return $dt->format('Y-m-d H:i:s');
}

if ($course_id <= 0 || $title === '') {
  json_error('Invalid input');
}

$validShow = ['IMMEDIATE','AFTER_DUE','MANUAL'];
$validCorrect = ['IMMEDIATE','AFTER_DUE','NEVER'];
if (!in_array($show_results_mode, $validShow, true)) $show_results_mode = 'IMMEDIATE';
if (!in_array($show_correct_answers_mode, $validCorrect, true)) $show_correct_answers_mode = 'NEVER';

try {
  $tbl = $pdo->query("SHOW TABLES LIKE 'tests'")->fetchColumn();
  if (!$tbl) {
    json_error('Missing table: tests. Import db/tests_module.sql');
  }

  require_course_owner($pdo, $course_id, (int)$u['id'], true);

  $st = $pdo->prepare(
    "INSERT INTO tests
      (course_id, section_id, lesson_id, title, description, time_limit_minutes, pass_score, max_attempts,
       shuffle_questions, shuffle_choices, show_results_mode, show_correct_answers_mode, start_at, due_at,
       status, created_by, updated_by, created_at, updated_at)
     VALUES
      (:course_id, :section_id, :lesson_id, :title, :description, :time_limit_minutes, :pass_score, :max_attempts,
       :shuffle_questions, :shuffle_choices, :show_results_mode, :show_correct_answers_mode, :start_at, :due_at,
       'DRAFT', :created_by, :updated_by, NOW(), NOW())"
  );
  $st->execute([
    ':course_id' => $course_id,
    ':section_id' => $section_id,
    ':lesson_id' => $lesson_id,
    ':title' => $title,
    ':description' => $description,
    ':time_limit_minutes' => max(0, $time_limit_minutes),
    ':pass_score' => max(0, $pass_score),
    ':max_attempts' => max(0, $max_attempts),
    ':shuffle_questions' => $shuffle_questions,
    ':shuffle_choices' => $shuffle_choices,
    ':show_results_mode' => $show_results_mode,
    ':show_correct_answers_mode' => $show_correct_answers_mode,
    ':start_at' => to_utc($start_at),
    ':due_at' => to_utc($due_at),
    ':created_by' => (int)$u['id'],
    ':updated_by' => (int)$u['id'],
  ]);
  $test_id = (int)$pdo->lastInsertId();

  $pdo->prepare('INSERT INTO test_audit_log (test_id, user_id, action, details) VALUES (?,?,?,?)')
      ->execute([$test_id, (int)$u['id'], 'create', null]);

  json_ok(['test_id' => $test_id]);
} catch (Throwable $e) {
  json_error('Database error: ' . $e->getMessage());
}
