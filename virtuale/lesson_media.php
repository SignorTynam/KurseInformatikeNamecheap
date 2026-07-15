<?php

declare(strict_types=1);

use KurseInformatike\Lessons\Infrastructure\PdoLessonMediaRepository;

try {
    $app = require __DIR__ . '/bootstrap/app.php';
    $repository = new PdoLessonMediaRepository($app['pdo']);
    $media = $repository->find((int) ($_GET['id'] ?? 0));
    if (!$media) {
        http_response_code(404);
        exit;
    }
    $user = $app['authorization']->requireUser();
    if ($media['lesson_id'] !== null) {
        $app['authorization']->requireLessonViewer((int) $media['lesson_id']);
    } elseif ((int) $media['owner_user_id'] !== (int) $user['id']) {
        throw new DomainException('Nuk keni qasje në këtë foto.');
    }
    $path = $app['lesson_media_storage']->absolutePath((string) $media['storage_path']);
    if ($path === null || !is_file($path)) {
        http_response_code(404);
        exit;
    }
    header('Content-Type: ' . (string) $media['mime_type']);
    header('Content-Length: ' . (string) filesize($path));
    header('Content-Disposition: inline; filename="' . rawurlencode((string) $media['original_name']) . '"');
    header('X-Content-Type-Options: nosniff');
    header('Cache-Control: private, max-age=3600');
    readfile($path);
} catch (DomainException) {
    http_response_code(403);
} catch (Throwable $exception) {
    error_log('Lesson media read failed: ' . $exception->getMessage());
    http_response_code(500);
}
