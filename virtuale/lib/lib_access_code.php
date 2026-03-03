<?php
declare(strict_types=1);

/**
 * Access code helpers for courses.
 *
 * - Access code is a 5-digit numeric string (e.g. "12345").
 * - Stored in `courses.access_code` (nullable) with UNIQUE index.
 */

function ki_table_has_column(PDO $pdo, string $table, string $column): bool {
    try {
        $stmt = $pdo->prepare("SHOW COLUMNS FROM `$table` LIKE ?");
        $stmt->execute([$column]);
        return (bool)$stmt->fetch(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        return false;
    }
}

function ki_normalize_access_code(string $raw): ?string {
    $raw = trim($raw);
    if ($raw === '') return null;
    // keep digits only
    $digits = preg_replace('/\D+/', '', $raw) ?? '';
    if (!preg_match('/^\d{5}$/', $digits)) return null;
    return $digits;
}

function ki_generate_access_code_5digits(): string {
    return (string)random_int(10000, 99999);
}

function ki_generate_unique_course_access_code(PDO $pdo, int $maxAttempts = 40): string {
    for ($i = 0; $i < $maxAttempts; $i++) {
        $code = ki_generate_access_code_5digits();
        $stmt = $pdo->prepare('SELECT 1 FROM courses WHERE access_code = ? LIMIT 1');
        $stmt->execute([$code]);
        if (!$stmt->fetchColumn()) {
            return $code;
        }
    }
    throw new RuntimeException('Nuk u arrit të gjenerohej një access code unik. Provo sërish.');
}

/**
 * Sets a new unique access code for a course, but only if it is currently empty.
 * Returns the created code, or null if code already existed.
 */
function ki_set_course_access_code_if_empty(PDO $pdo, int $courseId): ?string {
    if ($courseId <= 0) {
        throw new InvalidArgumentException('courseId i pavlefshëm');
    }
    if (!ki_table_has_column($pdo, 'courses', 'access_code')) {
        return null;
    }

    $stmt = $pdo->prepare('SELECT access_code FROM courses WHERE id = ? LIMIT 1');
    $stmt->execute([$courseId]);
    $existing = $stmt->fetchColumn();
    $existing = is_string($existing) ? trim($existing) : '';
    if ($existing !== '') return null;

    for ($i = 0; $i < 8; $i++) {
        $code = ki_generate_access_code_5digits();
        try {
            $upd = $pdo->prepare("UPDATE courses SET access_code = ? WHERE id = ? AND (access_code IS NULL OR access_code='')");
            $upd->execute([$code, $courseId]);

            if ($upd->rowCount() < 1) {
                // Another request might have set it concurrently.
                return null;
            }
            return $code;
        } catch (PDOException $e) {
            // 23000 = duplicate key (unique index)
            if ($e->getCode() === '23000') {
                continue;
            }
            throw $e;
        }
    }

    // As a last resort, use the stricter unique-check generator.
    $code = ki_generate_unique_course_access_code($pdo);
    $upd = $pdo->prepare("UPDATE courses SET access_code = ? WHERE id = ? AND (access_code IS NULL OR access_code='')");
    $upd->execute([$code, $courseId]);
    return $upd->rowCount() > 0 ? $code : null;
}
