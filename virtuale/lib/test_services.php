<?php
declare(strict_types=1);

class TestService {
  public static function getTest(PDO $pdo, int $test_id): ?array {
    $st = $pdo->prepare("SELECT t.*, c.id_creator FROM tests t JOIN courses c ON c.id=t.course_id WHERE t.id=? LIMIT 1");
    $st->execute([$test_id]);
    $row = $st->fetch(PDO::FETCH_ASSOC);
    return $row ?: null;
  }

  public static function ensureInstructorAccess(PDO $pdo, int $test_id, int $user_id, bool $isAdmin): array {
    $test = self::getTest($pdo, $test_id);
    if (!$test) {
      http_response_code(404);
      exit('Not found');
    }
    if (!$isAdmin && (int)$test['id_creator'] !== $user_id) {
      http_response_code(403);
      exit('Forbidden');
    }
    return $test;
  }

  public static function ensureStudentAccess(PDO $pdo, int $test_id, int $user_id): array {
    $st = $pdo->prepare("SELECT t.*, c.title AS course_title FROM tests t JOIN courses c ON c.id=t.course_id WHERE t.id=? LIMIT 1");
    $st->execute([$test_id]);
    $test = $st->fetch(PDO::FETCH_ASSOC);
    if (!$test) {
      http_response_code(404);
      exit('Testi nuk u gjet.');
    }
    if (($test['status'] ?? '') !== 'PUBLISHED') {
      http_response_code(403);
      exit('Testi nuk është publik.');
    }
    $st2 = $pdo->prepare('SELECT 1 FROM enroll WHERE course_id = ? AND user_id = ? LIMIT 1');
    $st2->execute([(int)$test['course_id'], $user_id]);
    if (!$st2->fetchColumn()) {
      http_response_code(403);
      exit('Nuk jeni i regjistruar në këtë kurs.');
    }
    return $test;
  }
}

class QuestionService {
  public static function getQuestionsForTest(PDO $pdo, int $test_id): array {
    $st = $pdo->prepare(
      "SELECT q.*, tq.position, tq.points_override
       FROM test_questions tq
       JOIN question_bank q ON q.id = tq.question_id
       WHERE tq.test_id = ?
       ORDER BY tq.position ASC, tq.id ASC"
    );
    $st->execute([$test_id]);
    $questions = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
    if (!$questions) return [];

    $ids = array_map(fn($q) => (int)$q['id'], $questions);
    $in  = implode(',', array_fill(0, count($ids), '?'));
    $stOpt = $pdo->prepare("SELECT * FROM question_options WHERE question_id IN ($in) ORDER BY position ASC, id ASC");
    $stOpt->execute($ids);
    $options = $stOpt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    $byQ = [];
    foreach ($options as $opt) {
      $byQ[(int)$opt['question_id']][] = $opt;
    }
    foreach ($questions as &$q) {
      $qid = (int)$q['id'];
      $q['options'] = $byQ[$qid] ?? [];
    }
    unset($q);
    return $questions;
  }
}

class AttemptService {
  public static function getOpenAttempt(PDO $pdo, int $test_id, int $user_id): ?array {
    $st = $pdo->prepare("SELECT * FROM test_attempts WHERE test_id=? AND user_id=? AND status='IN_PROGRESS' LIMIT 1");
    $st->execute([$test_id, $user_id]);
    $row = $st->fetch(PDO::FETCH_ASSOC);
    return $row ?: null;
  }

  public static function countAttempts(PDO $pdo, int $test_id, int $user_id): int {
    $st = $pdo->prepare('SELECT COUNT(*) FROM test_attempts WHERE test_id=? AND user_id=?');
    $st->execute([$test_id, $user_id]);
    return (int)$st->fetchColumn();
  }

  public static function getAttemptAnswers(PDO $pdo, int $attempt_id): array {
    $st = $pdo->prepare('SELECT * FROM attempt_answers WHERE attempt_id=?');
    $st->execute([$attempt_id]);
    $rows = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
    $byQ = [];
    foreach ($rows as $r) {
      $qid = (int)$r['question_id'];
      if (!isset($byQ[$qid])) $byQ[$qid] = [];
      $byQ[$qid][] = $r;
    }
    return $byQ;
  }
}
