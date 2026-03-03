<?php
/**
 * sections_utils.php
 * Helper-at e përbashkët për renditje/pozicione (SKEMA E RE: pa "area").
 */
declare(strict_types=1);

/**
 * Pozicioni i radhës për items brenda (course_id, section_id).
 * Gap-aware: 1,2,4 -> kthen 3.
 */
function si_next_pos(PDO $pdo, int $courseId, int $sectionId): int {
  $q = $pdo->prepare("
    SELECT position
    FROM section_items
    WHERE course_id = ? AND section_id = ?
    ORDER BY position ASC
  ");
  $q->execute([$courseId, $sectionId]);

  $pos = 1;
  while ($row = $q->fetch(PDO::FETCH_ASSOC)) {
    $p = (int)($row['position'] ?? 0);
    if ($p === $pos) {
      $pos++;
    } elseif ($p > $pos) {
      break;
    }
  }
  return $pos;
}

/**
 * Pozicioni i radhës për seksion brenda kursit.
 */
function sec_next_pos(PDO $pdo, int $courseId): int {
  $q = $pdo->prepare("SELECT COALESCE(MAX(position),0)+1 FROM sections WHERE course_id=?");
  $q->execute([$courseId]);
  return (int)$q->fetchColumn();
}

/** Helper për ikonat e kategorive të leksionit. Kthen [ikonë, ngjyrë]. */
function catMeta(string $cat, array $iconMap): array {
  $cat = strtoupper(trim($cat !== '' ? $cat : 'TJETER'));
  return $iconMap[$cat] ?? ($iconMap['TJETER'] ?? ['bi-collection', '#6c757d']);
}
