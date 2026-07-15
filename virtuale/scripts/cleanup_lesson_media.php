<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

$app = require dirname(__DIR__) . '/bootstrap/app.php';
$query = $app['pdo']->query(
    "SELECT id, storage_path FROM lesson_media
     WHERE lesson_id IS NULL AND created_at < (NOW() - INTERVAL 24 HOUR)
     ORDER BY id LIMIT 1000"
);
$rows = $query->fetchAll() ?: [];
$delete = $app['pdo']->prepare('DELETE FROM lesson_media WHERE id=? AND lesson_id IS NULL');
$removed = 0;
foreach ($rows as $row) {
    $delete->execute([(int) $row['id']]);
    if ($delete->rowCount() === 1) {
        if (!$app['lesson_media_storage']->delete((string) $row['storage_path'])) {
            error_log('Orphan media row deleted but file could not be removed: ' . $row['storage_path']);
        }
        $removed++;
    }
}
fwrite(STDOUT, "Removed {$removed} orphan lesson media records.\n");
