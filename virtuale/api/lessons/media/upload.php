<?php

declare(strict_types=1);

use KurseInformatike\Lessons\Application\UploadLessonMedia;
use KurseInformatike\Lessons\Infrastructure\PdoLessonMediaRepository;
use KurseInformatike\Shared\Http\Csrf;
use KurseInformatike\Shared\Http\Response;

try {
    $app = require dirname(__DIR__, 3) . '/bootstrap/app.php';
    Csrf::requireValid();
    $courseId = (int) ($_POST['course_id'] ?? 0);
    $user = $app['authorization']->requireCourseManager($courseId);
    $draftToken = strtolower(trim((string) ($_POST['draft_token'] ?? '')));
    $file = $_FILES['image'] ?? [];
    $service = new UploadLessonMedia(
        new PdoLessonMediaRepository($app['pdo']),
        $app['lesson_media_storage'],
        $app['lesson_media_max_bytes'],
        $app['lesson_media_max_width'],
        $app['lesson_media_max_height'],
        $app['lesson_media_max_pixels'],
    );
    $mediaId = $service->handle($file, (int) $user['id'], $draftToken);
    Response::json([
        'success' => 1,
        'file' => ['url' => '/virtuale/lesson_media.php?id=' . $mediaId, 'mediaId' => $mediaId],
    ]);
} catch (DomainException $exception) {
    Response::json(['success' => 0, 'message' => $exception->getMessage()], 422);
} catch (Throwable $exception) {
    error_log('Lesson media upload failed: ' . $exception->getMessage());
    Response::json(['success' => 0, 'message' => 'Fotoja nuk mund të ngarkohet tani.'], 500);
}
