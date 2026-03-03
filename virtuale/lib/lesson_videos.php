<?php
declare(strict_types=1);

function lv_table_exists(PDO $pdo, string $table): bool {
    if (!preg_match('/^[A-Za-z0-9_]+$/', $table)) return false;
    try {
        $stmt = $pdo->prepare(
            'SELECT 1
             FROM information_schema.TABLES
             WHERE TABLE_SCHEMA = DATABASE()
               AND TABLE_NAME = ?
             LIMIT 1'
        );
        $stmt->execute([$table]);
        return (bool)$stmt->fetchColumn();
    } catch (Throwable $e) {
        return false;
    }
}

function lv_ensure_schema(PDO $pdo): bool {
    if (lv_table_exists($pdo, 'lesson_videos')) return true;

    $sql = "
      CREATE TABLE IF NOT EXISTS lesson_videos (
        id INT(11) NOT NULL AUTO_INCREMENT,
        lesson_id INT(11) NOT NULL,
        video_url VARCHAR(1024) NOT NULL,
        title VARCHAR(255) DEFAULT NULL,
        position INT(11) NOT NULL DEFAULT 1,
        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        KEY idx_lv_lesson (lesson_id),
        KEY idx_lv_lesson_pos (lesson_id, position)
      ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
    ";

    try {
        $pdo->exec($sql);
    } catch (Throwable $e) {
        return false;
    }

    return lv_table_exists($pdo, 'lesson_videos');
}

function lv_normalize_urls_text(string $raw): array {
    $lines = preg_split('/\r\n|\r|\n/', $raw) ?: [];
    $out = [];
    $seen = [];

    foreach ($lines as $line) {
        $url = trim((string)$line);
        if ($url === '') continue;
        if (!filter_var($url, FILTER_VALIDATE_URL)) continue;

        $key = mb_strtolower($url, 'UTF-8');
        if (isset($seen[$key])) continue;

        $seen[$key] = true;
        $out[] = $url;

        if (count($out) >= 50) break;
    }

    return $out;
}

function lv_get_lesson_videos(PDO $pdo, int $lessonId, bool $includeLegacy = true): array {
    if ($lessonId <= 0) return [];

    $items = [];

    if (lv_table_exists($pdo, 'lesson_videos')) {
        try {
            $stmt = $pdo->prepare('SELECT id, video_url, title, position FROM lesson_videos WHERE lesson_id=? ORDER BY position ASC, id ASC');
            $stmt->execute([$lessonId]);
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
            foreach ($rows as $row) {
                $url = trim((string)($row['video_url'] ?? ''));
                if ($url === '') continue;
                $items[] = [
                    'id' => (int)($row['id'] ?? 0),
                    'url' => $url,
                    'title' => trim((string)($row['title'] ?? '')),
                    'position' => (int)($row['position'] ?? 0),
                    'source' => 'table',
                ];
            }
        } catch (Throwable $e) {
            // ignore
        }
    }

    if ($includeLegacy) {
        try {
            $stmt = $pdo->prepare('SELECT URL FROM lessons WHERE id=? LIMIT 1');
            $stmt->execute([$lessonId]);
            $legacy = trim((string)$stmt->fetchColumn());
            if ($legacy !== '' && filter_var($legacy, FILTER_VALIDATE_URL)) {
                $hasSame = false;
                foreach ($items as $it) {
                    if (($it['url'] ?? '') === $legacy) {
                        $hasSame = true;
                        break;
                    }
                }
                if (!$hasSame) {
                    $items[] = [
                        'id' => 0,
                        'url' => $legacy,
                        'title' => '',
                        'position' => 999999,
                        'source' => 'legacy',
                    ];
                }
            }
        } catch (Throwable $e) {
            // ignore
        }
    }

    usort($items, static function (array $a, array $b): int {
        return ((int)($a['position'] ?? 0)) <=> ((int)($b['position'] ?? 0));
    });

    return $items;
}

function lv_replace_lesson_videos(PDO $pdo, int $lessonId, array $urls): void {
    if ($lessonId <= 0) return;
    if (!lv_ensure_schema($pdo)) return;

    $clean = [];
    $seen = [];
    foreach ($urls as $u) {
        $url = trim((string)$u);
        if ($url === '' || !filter_var($url, FILTER_VALIDATE_URL)) continue;
        $key = mb_strtolower($url, 'UTF-8');
        if (isset($seen[$key])) continue;
        $seen[$key] = true;
        $clean[] = $url;
        if (count($clean) >= 50) break;
    }

    $del = $pdo->prepare('DELETE FROM lesson_videos WHERE lesson_id = ?');
    $del->execute([$lessonId]);

    if (!$clean) return;

    $ins = $pdo->prepare('INSERT INTO lesson_videos (lesson_id, video_url, position, created_at, updated_at) VALUES (?,?,?,?,?)');
    $now = date('Y-m-d H:i:s');
    $pos = 1;
    foreach ($clean as $url) {
        $ins->execute([$lessonId, $url, $pos++, $now, $now]);
    }
}

function lv_copy_lesson_videos(PDO $pdo, int $sourceLessonId, int $targetLessonId): void {
    if ($sourceLessonId <= 0 || $targetLessonId <= 0) return;
    if (!lv_ensure_schema($pdo)) return;

    try {
        $q = $pdo->prepare('SELECT video_url, title, position FROM lesson_videos WHERE lesson_id=? ORDER BY position ASC, id ASC');
        $q->execute([$sourceLessonId]);
        $rows = $q->fetchAll(PDO::FETCH_ASSOC) ?: [];
        if (!$rows) return;

        $ins = $pdo->prepare('INSERT INTO lesson_videos (lesson_id, video_url, title, position, created_at, updated_at) VALUES (?,?,?,?,?,?)');
        $now = date('Y-m-d H:i:s');
        foreach ($rows as $r) {
            $ins->execute([
                $targetLessonId,
                (string)($r['video_url'] ?? ''),
                (string)($r['title'] ?? ''),
                (int)($r['position'] ?? 0),
                $now,
                $now,
            ]);
        }
    } catch (Throwable $e) {
        // ignore
    }
}
