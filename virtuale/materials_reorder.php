<?php
/**
 * materials_reorder.php
 * Ruajtja e renditjes së materialeve (section_items) dhe zhvendosjes midis seksioneve.
 *
 * Payload (JSON):
 * {
 *   csrf, course_id,
 *   moves: [
 *     { section_id: <int>, order: [<si_id>, ...] }
 *   ]
 * }
 *
 * Shënim: frontendi mund të dërgojë edhe "area", por këtu e injorojmë.
 */
declare(strict_types=1);
session_start();
header('Content-Type: application/json');
require_once __DIR__ . '/lib/database.php';

if (!isset($_SESSION['user']) || !in_array($_SESSION['user']['role'] ?? '', ['Administrator','Instruktor'], true)) {
  echo json_encode(['ok'=>false,'error'=>'Unauthenticated']); exit;
}
$ROLE  = $_SESSION['user']['role'];
$ME_ID = (int)($_SESSION['user']['id'] ?? 0);

$raw  = file_get_contents('php://input');
$data = json_decode($raw, true) ?: [];

$csrf = (string)($data['csrf'] ?? '');
if (!hash_equals($_SESSION['csrf_token'] ?? '', $csrf)) {
  echo json_encode(['ok'=>false,'error'=>'Invalid CSRF']); exit;
}

$course_id = (int)($data['course_id'] ?? 0);
$moves     = $data['moves'] ?? [];

if ($course_id <= 0 || !is_array($moves) || !$moves) {
  echo json_encode(['ok'=>false,'error'=>'Bad payload']); exit;
}

/* verify course & instructor ownership */
try {
  $q = $pdo->prepare("SELECT id_creator FROM courses WHERE id=?");
  $q->execute([$course_id]);
  $row = $q->fetch(PDO::FETCH_ASSOC);
  if (!$row) {
    echo json_encode(['ok'=>false,'error'=>'Course not found']); exit;
  }
  if ($ROLE === 'Instruktor' && (int)$row['id_creator'] !== $ME_ID) {
    echo json_encode(['ok'=>false,'error'=>'No access']); exit;
  }
} catch (PDOException $e) {
  echo json_encode(['ok'=>false,'error'=>'DB']); exit;
}

try {
  $pdo->beginTransaction();

  // Seksionet e lejuara për këtë kurs (+ 0 = "jashtë seksioneve")
  $allowedSections = [0 => true];
  $stSec = $pdo->prepare("SELECT id FROM sections WHERE course_id=?");
  $stSec->execute([$course_id]);
  while ($r = $stSec->fetch(PDO::FETCH_ASSOC)) {
    $allowedSections[(int)$r['id']] = true;
  }

  // Map i shpejtë i gjithë section_items të kursit (për validim)
  $stmt = $pdo->prepare("
    SELECT id, section_id
    FROM section_items
    WHERE course_id=?
  ");
  $stmt->execute([$course_id]);
  $siInfo = [];
  while ($r = $stmt->fetch(PDO::FETCH_ASSOC)) {
    $siInfo[(int)$r['id']] = (int)$r['section_id'];
  }

  // Update position (+ updated_at)
  $updPos = $pdo->prepare("
    UPDATE section_items
    SET position=?, updated_at=NOW()
    WHERE id=? AND course_id=? AND section_id=?
  ");

  // Update section (+ updated_at)
  $updSec = $pdo->prepare("
    UPDATE section_items
    SET section_id=?, updated_at=NOW()
    WHERE id=? AND course_id=?
  ");

  foreach ($moves as $mv) {
    $sid = (int)($mv['section_id'] ?? 0); // mund të jetë 0 (jashtë seksioneve)

    // ✅ sigurim: sid duhet të jetë 0 ose seksion i këtij kursi
    if (!isset($allowedSections[$sid])) {
      throw new RuntimeException('Invalid section');
    }

    $order = is_array($mv['order'] ?? null)
      ? array_values(array_unique(array_map('intval', $mv['order'])))
      : [];

    // Filtro vetëm id që na përkasin këtij kursi
    $order = array_values(array_filter($order, fn($id) => isset($siInfo[$id])));

    // 1) Vendos seksionin e ri për çdo item (nëse është zhvendosur)
    foreach ($order as $si_id) {
      if (($siInfo[$si_id] ?? null) !== $sid) {
        $updSec->execute([$sid, $si_id, $course_id]);
        $siInfo[$si_id] = $sid; // rifresko cache
      }
    }

    // 2) Rend final: fillimisht "order", pastaj pjesa tjetër e këtij seksioni
    $stmtCur = $pdo->prepare("
      SELECT id
      FROM section_items
      WHERE course_id=? AND section_id=?
      ORDER BY position ASC, id ASC
    ");
    $stmtCur->execute([$course_id, $sid]);
    $curIds = array_map('intval', array_column($stmtCur->fetchAll(PDO::FETCH_ASSOC), 'id'));

    $rest  = array_values(array_diff($curIds, $order));
    $final = array_merge($order, $rest);

    // 3) Shkruaj 1..N për këtë seksion
    $pos = 1;
    foreach ($final as $si_id) {
      $updPos->execute([$pos++, $si_id, $course_id, $sid]);
    }
  }

  $pdo->commit();
  echo json_encode(['ok'=>true]);
} catch (Throwable $e) {
  if ($pdo->inTransaction()) $pdo->rollBack();
  // opsionale: log internal $e->getMessage()
  echo json_encode(['ok'=>false,'error'=>'DB update failed']);
}
