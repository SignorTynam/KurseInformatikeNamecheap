<?php
/**
 * copy_utils.php
 * Utilitare për kopjimin e seksioneve dhe elementeve ndër-kurse.
 */
declare(strict_types=1);

require_once __DIR__ . '/database.php';
require_once __DIR__ . '/sections_utils.php';
require_once __DIR__ . '/lesson_videos.php';

if (!function_exists('table_has_column')) {
  function table_has_column(PDO $pdo, string $table, string $column): bool {
    static $cache = [];
    $k = $table . '.' . $column;
    if (array_key_exists($k, $cache)) return (bool)$cache[$k];
    try {
      $st = $pdo->query("SHOW COLUMNS FROM `{$table}` LIKE " . $pdo->quote($column));
      $cache[$k] = (bool)$st->fetch(PDO::FETCH_ASSOC);
      return (bool)$cache[$k];
    } catch (Throwable $e) {
      $cache[$k] = false;
      return false;
    }
  }
}

function table_exists(PDO $pdo, string $table): bool {
  static $cache = [];
  if (array_key_exists($table, $cache)) return (bool)$cache[$table];
  try {
    $st = $pdo->prepare("SHOW TABLES LIKE ?");
    $st->execute([$table]);
    $cache[$table] = (bool)$st->fetchColumn();
    return (bool)$cache[$table];
  } catch (Throwable $e) {
    $cache[$table] = false;
    return false;
  }
}

function _sections_has_area(PDO $pdo): bool {
  static $has = null;
  if ($has === null) $has = table_has_column($pdo, 'sections', 'area');
  return (bool)$has;
}

function _section_items_has_area(PDO $pdo): bool {
  static $has = null;
  if ($has === null) $has = table_has_column($pdo, 'section_items', 'area');
  return (bool)$has;
}

/* ===================== TX helper ===================== */
function _tx_start_if_needed(PDO $pdo): bool {
  if ($pdo->inTransaction()) return false;
  $pdo->beginTransaction();
  return true;
}
function _tx_commit_if_started(PDO $pdo, bool $started): void {
  if ($started && $pdo->inTransaction()) $pdo->commit();
}
function _tx_rollback_if_started(PDO $pdo, bool $started): void {
  if ($started && $pdo->inTransaction()) $pdo->rollBack();
}

/* ===================== Deep-copy helpers ===================== */

function copy_lesson_deep(PDO $pdo, int $sourceLessonId, int $targetCourseId, ?int $targetSectionId): int {
  $q = $pdo->prepare("SELECT * FROM lessons WHERE id = ?");
  $q->execute([$sourceLessonId]);
  $src = $q->fetch(PDO::FETCH_ASSOC);
  if (!$src) throw new RuntimeException('Leksioni burim nuk u gjet.');

  $ins = $pdo->prepare("
    INSERT INTO lessons (course_id, section_id, title, description, URL, category, notebook_path, uploaded_at, updated_at)
    VALUES (?, ?, ?, ?, ?, ?, ?, NOW(), NOW())
  ");
  $ins->execute([
    $targetCourseId,
    $targetSectionId,
    $src['title'],
    $src['description'],
    $src['URL'],
    $src['category'],
    $src['notebook_path'],
  ]);
  $newLessonId = (int)$pdo->lastInsertId();

  // lesson_files (nëse ekziston)
  if (table_exists($pdo, 'lesson_files')) {
    $qf = $pdo->prepare("SELECT * FROM lesson_files WHERE lesson_id = ?");
    $qf->execute([$sourceLessonId]);
    $insf = $pdo->prepare("INSERT INTO lesson_files (lesson_id, file_path, file_type, uploaded_at) VALUES (?,?,?,NOW())");
    while ($f = $qf->fetch(PDO::FETCH_ASSOC)) {
      $insf->execute([$newLessonId, $f['file_path'], $f['file_type']]);
    }
  }

  // lesson_images (nëse ekziston)
  if (table_exists($pdo, 'lesson_images')) {
    $qi = $pdo->prepare("SELECT * FROM lesson_images WHERE lesson_id=? ORDER BY position ASC, id ASC");
    $qi->execute([$sourceLessonId]);
    $insi = $pdo->prepare("INSERT INTO lesson_images (lesson_id, file_path, alt_text, position, created_at) VALUES (?,?,?,?,NOW())");
    while ($img = $qi->fetch(PDO::FETCH_ASSOC)) {
      $insi->execute([$newLessonId, $img['file_path'], $img['alt_text'], (int)$img['position']]);
    }
  }

  lv_copy_lesson_videos($pdo, $sourceLessonId, $newLessonId);

  return $newLessonId;
}

function copy_assignment_deep(PDO $pdo, int $sourceAssignmentId, int $targetCourseId, ?int $targetSectionId): int {
  $q = $pdo->prepare("SELECT * FROM assignments WHERE id = ?");
  $q->execute([$sourceAssignmentId]);
  $src = $q->fetch(PDO::FETCH_ASSOC);
  if (!$src) throw new RuntimeException('Detyra burim nuk u gjet.');

  // Për kopjim: e bëjmë hidden=1 që të mos dalë menjëherë (UI kontrollon section_items.hidden gjithsesi)
  $ins = $pdo->prepare("
    INSERT INTO assignments (course_id, section_id, title, description, resource_path, solution_path, due_date, status, hidden, uploaded_at, updated_at)
    VALUES (?,?,?,?,?,?,?,?,?,NOW(),NOW())
  ");
  $ins->execute([
    $targetCourseId,
    $targetSectionId,
    $src['title'],
    $src['description'],
    $src['resource_path'] ?? null,
    $src['solution_path'] ?? null,
    $src['due_date'],
    $src['status'] ?? 'PENDING',
    1,
  ]);
  $newId = (int)$pdo->lastInsertId();

  // assignments_files (nëse ekziston)
  if (table_exists($pdo, 'assignments_files')) {
    $qf = $pdo->prepare("SELECT * FROM assignments_files WHERE assignment_id = ?");
    $qf->execute([$sourceAssignmentId]);
    $insf = $pdo->prepare("INSERT INTO assignments_files (assignment_id, file_path, uploaded_at) VALUES (?,?,NOW())");
    while ($f = $qf->fetch(PDO::FETCH_ASSOC)) {
      $insf->execute([$newId, $f['file_path']]);
    }
  }

  return $newId;
}

function copy_quiz_deep(PDO $pdo, int $sourceQuizId, int $targetCourseId, ?int $targetSectionId): int {
  $q = $pdo->prepare("SELECT * FROM quizzes WHERE id = ?");
  $q->execute([$sourceQuizId]);
  $src = $q->fetch(PDO::FETCH_ASSOC);
  if (!$src) throw new RuntimeException('Quiz burim nuk u gjet.');

  // Për kopjim: hidden=1 + status=DRAFT (për të shmangur publikimin aksidental)
  $ins = $pdo->prepare("
    INSERT INTO quizzes
    (course_id, section_id, title, description, open_at, close_at, time_limit_sec, attempts_allowed, shuffle_questions, shuffle_answers, hidden, status, created_at, updated_at)
    VALUES (?,?,?,?,?,?,?,?,?,?,?,?,NOW(),NOW())
  ");
  $ins->execute([
    $targetCourseId,
    $targetSectionId,
    $src['title'],
    $src['description'],
    $src['open_at'],
    $src['close_at'],
    $src['time_limit_sec'],
    $src['attempts_allowed'],
    $src['shuffle_questions'],
    $src['shuffle_answers'],
    1,
    'DRAFT',
  ]);
  $newQuizId = (int)$pdo->lastInsertId();

  // Questions & answers
  $qq = $pdo->prepare("SELECT * FROM quiz_questions WHERE quiz_id=? ORDER BY position ASC, id ASC");
  $qq->execute([$sourceQuizId]);

  $insQ = $pdo->prepare("
    INSERT INTO quiz_questions (quiz_id, question, explanation, points, position, created_at, updated_at)
    VALUES (?,?,?,?,?,NOW(),NOW())
  ");
  $insA = $pdo->prepare("
    INSERT INTO quiz_answers (question_id, answer_text, is_correct, position, created_at, updated_at)
    VALUES (?,?,?,?,NOW(),NOW())
  ");

  while ($qrow = $qq->fetch(PDO::FETCH_ASSOC)) {
    $insQ->execute([$newQuizId, $qrow['question'], $qrow['explanation'], $qrow['points'], $qrow['position']]);
    $newQuestionId = (int)$pdo->lastInsertId();

    $qa = $pdo->prepare("SELECT * FROM quiz_answers WHERE question_id=? ORDER BY position ASC, id ASC");
    $qa->execute([(int)$qrow['id']]);
    while ($ar = $qa->fetch(PDO::FETCH_ASSOC)) {
      $insA->execute([$newQuestionId, $ar['answer_text'], $ar['is_correct'], $ar['position']]);
    }
  }

  return $newQuizId;
}

/* ===================== section_items helpers ===================== */

function create_text_block(PDO $pdo, int $courseId, int $sectionId, string $contentMd, int $hidden = 1): int {
  $pos = si_next_pos($pdo, $courseId, $sectionId);

  if (_section_items_has_area($pdo)) {
    $ins = $pdo->prepare("
      INSERT INTO section_items (course_id, area, section_id, item_type, item_ref_id, content_md, hidden, position, created_at, updated_at)
      VALUES (?,?,?,?,NULL,?,?,?,NOW(),NOW())
    ");
    $ins->execute([$courseId, 'GENERAL', $sectionId, 'TEXT', $contentMd, $hidden, $pos]);
  } else {
    $ins = $pdo->prepare("
      INSERT INTO section_items (course_id, section_id, item_type, item_ref_id, content_md, hidden, position, created_at, updated_at)
      VALUES (?,?,?,NULL,?,?,?,NOW(),NOW())
    ");
    $ins->execute([$courseId, $sectionId, 'TEXT', $contentMd, $hidden, $pos]);
  }

  return (int)$pdo->lastInsertId();
}

function link_item_to_section(PDO $pdo, int $courseId, int $sectionId, string $itemType, int $itemRefId, int $hidden = 1): int {
  $pos = si_next_pos($pdo, $courseId, $sectionId);

  if (_section_items_has_area($pdo)) {
    $ins = $pdo->prepare("
      INSERT INTO section_items (course_id, area, section_id, item_type, item_ref_id, hidden, position, created_at, updated_at)
      VALUES (?,?,?,?,?,?,?,NOW(),NOW())
    ");
    $ins->execute([$courseId, 'GENERAL', $sectionId, $itemType, $itemRefId, $hidden, $pos]);
  } else {
    $ins = $pdo->prepare("
      INSERT INTO section_items (course_id, section_id, item_type, item_ref_id, hidden, position, created_at, updated_at)
      VALUES (?,?,?,?,?,?,NOW(),NOW())
    ");
    $ins->execute([$courseId, $sectionId, $itemType, $itemRefId, $hidden, $pos]);
  }

  return (int)$pdo->lastInsertId();
}

/* ===================== Wrapper-a të sigurtë (me TX-guard) ===================== */

function copy_single_lesson(PDO $pdo, int $sourceLessonId, int $targetCourseId, int $targetSectionId): array {
  $started = _tx_start_if_needed($pdo);
  try {
    $newId = copy_lesson_deep($pdo, $sourceLessonId, $targetCourseId, $targetSectionId);
    $siId  = link_item_to_section($pdo, $targetCourseId, $targetSectionId, 'LESSON', $newId, 1);
    _tx_commit_if_started($pdo, $started);
    return ['ok'=>true, 'item_id'=>$newId, 'si_id'=>$siId];
  } catch (Throwable $e) {
    _tx_rollback_if_started($pdo, $started);
    return ['ok'=>false, 'error'=>$e->getMessage()];
  }
}

function copy_single_assignment(PDO $pdo, int $sourceAssignmentId, int $targetCourseId, int $targetSectionId): array {
  $started = _tx_start_if_needed($pdo);
  try {
    $newId = copy_assignment_deep($pdo, $sourceAssignmentId, $targetCourseId, $targetSectionId);
    $siId  = link_item_to_section($pdo, $targetCourseId, $targetSectionId, 'ASSIGNMENT', $newId, 1);
    _tx_commit_if_started($pdo, $started);
    return ['ok'=>true, 'item_id'=>$newId, 'si_id'=>$siId];
  } catch (Throwable $e) {
    _tx_rollback_if_started($pdo, $started);
    return ['ok'=>false, 'error'=>$e->getMessage()];
  }
}

function copy_single_quiz(PDO $pdo, int $sourceQuizId, int $targetCourseId, int $targetSectionId): array {
  $started = _tx_start_if_needed($pdo);
  try {
    $newId = copy_quiz_deep($pdo, $sourceQuizId, $targetCourseId, $targetSectionId);
    $siId  = link_item_to_section($pdo, $targetCourseId, $targetSectionId, 'QUIZ', $newId, 1);
    _tx_commit_if_started($pdo, $started);
    return ['ok'=>true, 'item_id'=>$newId, 'si_id'=>$siId];
  } catch (Throwable $e) {
    _tx_rollback_if_started($pdo, $started);
    return ['ok'=>false, 'error'=>$e->getMessage()];
  }
}

/** Për TEXT: burimi është section_items.id (TEXT) në kursin burim. */
function copy_single_text(PDO $pdo, int $sourceCourseId, int $sourceSectionItemId, int $targetCourseId, int $targetSectionId): array {
  $started = _tx_start_if_needed($pdo);
  try {
    if (_section_items_has_area($pdo)) {
      $q = $pdo->prepare("SELECT content_md FROM section_items WHERE id=? AND course_id=? AND item_type='TEXT'");
      $q->execute([$sourceSectionItemId, $sourceCourseId]);
    } else {
      $q = $pdo->prepare("SELECT content_md FROM section_items WHERE id=? AND course_id=? AND item_type='TEXT'");
      $q->execute([$sourceSectionItemId, $sourceCourseId]);
    }

    $row = $q->fetch(PDO::FETCH_ASSOC);
    if (!$row) throw new RuntimeException('TEXT burim nuk u gjet.');

    $siId = create_text_block($pdo, $targetCourseId, $targetSectionId, (string)$row['content_md'], 1);

    _tx_commit_if_started($pdo, $started);
    return ['ok'=>true, 'item_id'=>0, 'si_id'=>$siId];
  } catch (Throwable $e) {
    _tx_rollback_if_started($pdo, $started);
    return ['ok'=>false, 'error'=>$e->getMessage()];
  }
}

function copy_single_text_block(PDO $pdo, int $sourceCourseId, int $sourceSectionItemId, int $targetCourseId, int $targetSectionId): array {
  return copy_single_text($pdo, $sourceCourseId, $sourceSectionItemId, $targetCourseId, $targetSectionId);
}

/* ===================== API e përgjithshme ===================== */

function copy_single_item(PDO $pdo, int $targetCourseId, int $targetSectionId, string $itemType, int $sourceCourseId, int $sourceItemId): array {
  $itemType = strtoupper(trim($itemType));
  if (!in_array($itemType, ['LESSON','ASSIGNMENT','QUIZ','TEXT'], true)) {
    throw new InvalidArgumentException('Lloj elementi i pavlefshëm.');
  }
  if ($itemType === 'LESSON')     return copy_single_lesson($pdo, $sourceItemId, $targetCourseId, $targetSectionId);
  if ($itemType === 'ASSIGNMENT') return copy_single_assignment($pdo, $sourceItemId, $targetCourseId, $targetSectionId);
  if ($itemType === 'QUIZ')       return copy_single_quiz($pdo, $sourceItemId, $targetCourseId, $targetSectionId);
  return copy_single_text($pdo, $sourceCourseId, $sourceItemId, $targetCourseId, $targetSectionId);
}

function copy_section_with_items(PDO $pdo, int $sourceCourseId, int $sourceSectionId, int $targetCourseId): array {
  $qs = $pdo->prepare("SELECT * FROM sections WHERE id=? AND course_id=?");
  $qs->execute([$sourceSectionId, $sourceCourseId]);

  $sec = $qs->fetch(PDO::FETCH_ASSOC);
  if (!$sec) return ['ok'=>false, 'error'=>'Seksioni burim nuk u gjet.'];

  $started = _tx_start_if_needed($pdo);
  try {
    $pos = sec_next_pos($pdo, $targetCourseId);

    if (_sections_has_area($pdo)) {
      $insSec = $pdo->prepare("
        INSERT INTO sections (course_id, title, description, position, hidden, highlighted, area, created_at, updated_at)
        VALUES (?,?,?,?,1,0,?,NOW(),NOW())
      ");
      $insSec->execute([$targetCourseId, $sec['title'], $sec['description'], $pos, 'GENERAL']);
    } else {
      $insSec = $pdo->prepare("
        INSERT INTO sections (course_id, title, description, position, hidden, highlighted, created_at, updated_at)
        VALUES (?,?,?,?,1,0,NOW(),NOW())
      ");
      $insSec->execute([$targetCourseId, $sec['title'], $sec['description'], $pos]);
    }

    $newSectionId = (int)$pdo->lastInsertId();

    $qi = $pdo->prepare("
      SELECT * FROM section_items
      WHERE course_id=? AND section_id=?
      ORDER BY position ASC, id ASC
    ");
    $qi->execute([$sourceCourseId, $sourceSectionId]);

    $resultMap = ['LESSON'=>[], 'ASSIGNMENT'=>[], 'QUIZ'=>[], 'TEXT'=>[]];

    while ($it = $qi->fetch(PDO::FETCH_ASSOC)) {
      $type = (string)$it['item_type'];

      if ($type === 'LESSON') {
        $newId = copy_lesson_deep($pdo, (int)$it['item_ref_id'], $targetCourseId, $newSectionId);
        $newSi = link_item_to_section($pdo, $targetCourseId, $newSectionId, 'LESSON', $newId, 1);
        $resultMap['LESSON'][] = ['from'=>$it['item_ref_id'], 'to'=>$newId, 'si'=>$newSi];
      } elseif ($type === 'ASSIGNMENT') {
        $newId = copy_assignment_deep($pdo, (int)$it['item_ref_id'], $targetCourseId, $newSectionId);
        $newSi = link_item_to_section($pdo, $targetCourseId, $newSectionId, 'ASSIGNMENT', $newId, 1);
        $resultMap['ASSIGNMENT'][] = ['from'=>$it['item_ref_id'], 'to'=>$newId, 'si'=>$newSi];
      } elseif ($type === 'QUIZ') {
        $newId = copy_quiz_deep($pdo, (int)$it['item_ref_id'], $targetCourseId, $newSectionId);
        $newSi = link_item_to_section($pdo, $targetCourseId, $newSectionId, 'QUIZ', $newId, 1);
        $resultMap['QUIZ'][] = ['from'=>$it['item_ref_id'], 'to'=>$newId, 'si'=>$newSi];
      } elseif ($type === 'TEXT') {
        $newSi = create_text_block($pdo, $targetCourseId, $newSectionId, (string)$it['content_md'], 1);
        $resultMap['TEXT'][] = ['from'=>$it['id'], 'si'=>$newSi];
      }
    }

    _tx_commit_if_started($pdo, $started);
    return ['ok'=>true, 'new_section_id'=>$newSectionId, 'map'=>$resultMap];
  } catch (Throwable $e) {
    _tx_rollback_if_started($pdo, $started);
    return ['ok'=>false, 'error'=>$e->getMessage()];
  }
}