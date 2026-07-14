<?php

declare(strict_types=1);

use KurseInformatike\Lessons\Infrastructure\PdoLessonMediaRepository;
use KurseInformatike\Shared\Http\Csrf;
use KurseInformatike\Shared\Http\Response;

try {
    $app = require dirname(__DIR__, 3) . '/bootstrap/app.php';
    Csrf::requireValid();
    $user = $app['authorization']->requireUser();
    $mediaId = (int) ($_POST['media_id'] ?? 0);
    $draftToken = strtolower(trim((string) ($_POST['draft_token'] ?? '')));
    $repository = new PdoLessonMediaRepository($app['pdo']);
    $media = $repository->find($mediaId);
    if (!$media || (int) $media['owner_user_id'] !== (int) $user['id']) {
        throw new DomainException('Fotoja nuk u gjet.');
    }
    if ($media['lesson_id'] !== null) {
        $app['authorization']->requireLessonManager((int) $media['lesson_id']);
    } elseif (!hash_equals((string) ($media['draft_token'] ?? ''), $draftToken)) {
        throw new DomainException('Drafti nuk përputhet.');
    }
    $app['pdo']->beginTransaction();
    $repository->delete($mediaId);
    $app['pdo']->commit();
    if (!$app['lesson_media_storage']->delete((string) $media['storage_path'])) {
        error_log('Lesson media metadata deleted but file remained: ' . $media['storage_path']);
    }
    Response::json(['success' => 1]);
} catch (DomainException $exception) {
    Response::json(['success' => 0, 'message' => $exception->getMessage()], 422);
} catch (Throwable $exception) {
    if (isset($app['pdo']) && $app['pdo']->inTransaction()) $app['pdo']->rollBack();
    error_log('Lesson media delete failed: ' . $exception->getMessage());
    Response::json(['success' => 0, 'message' => 'Fotoja nuk mund të fshihet tani.'], 500);
}
