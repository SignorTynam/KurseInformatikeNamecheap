<?php

declare(strict_types=1);

use KurseInformatike\Lessons\Application\DeleteLesson;
use KurseInformatike\Lessons\Infrastructure\PdoLessonRepository;
use KurseInformatike\Shared\Http\Csrf;
use KurseInformatike\Shared\Http\Response;

$app = require dirname(__DIR__) . '/bootstrap/app.php';
$lessonId = (int) ($_POST['lesson_id'] ?? $_GET['lesson_id'] ?? 0);
$lesson = (new PdoLessonRepository($app['pdo']))->find($lessonId);
if (!$lesson) throw new DomainException('Leksioni nuk u gjet.');
$app['authorization']->requireLessonManager($lessonId);
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    exit('Përdorni formularin e konfirmimit për fshirje.');
}
try {
    Csrf::requireValid();
    $failures = (new DeleteLesson($app['pdo'], $app['lesson_media_storage'], dirname(__DIR__)))->handle($lessonId);
    $_SESSION['flash'] = [
        'msg' => $failures === [] ? 'Leksioni u fshi.' : 'Leksioni u fshi, por disa skedarë kërkojnë pastrim manual.',
        'type' => $failures === [] ? 'success' : 'warning',
    ];
} catch (Throwable $exception) {
    error_log('Delete lesson failed: ' . $exception->getMessage());
    $_SESSION['flash'] = ['msg' => 'Leksioni nuk mund të fshihet tani.', 'type' => 'danger'];
}
Response::redirect('../course_details.php?course_id=' . (int) $lesson['course_id'] . '&tab=materials');
