<?php

declare(strict_types=1);

use KurseInformatike\Lessons\Application\CreateLesson;
use KurseInformatike\Lessons\Application\DraftToken;
use KurseInformatike\Lessons\Application\LessonBlockValidator;
use KurseInformatike\Lessons\Application\LessonFormData;
use KurseInformatike\Lessons\Application\LessonValidationException;
use KurseInformatike\Lessons\Infrastructure\PdoLessonBlockRepository;
use KurseInformatike\Lessons\Infrastructure\PdoLessonMediaRepository;
use KurseInformatike\Lessons\Infrastructure\PdoLessonRepository;
use KurseInformatike\Shared\Http\Csrf;
use KurseInformatike\Shared\Http\Response;
use KurseInformatike\Shared\Security\HtmlSanitizer;

$app = require dirname(__DIR__) . '/bootstrap/app.php';
$courseId = (int) ($_POST['course_id'] ?? $_GET['course_id'] ?? 0);
$user = $app['authorization']->requireCourseManager($courseId);
$formData = new LessonFormData($app['pdo']);
$course = $formData->course($courseId);
$sections = $formData->sections($courseId);
$csrfToken = Csrf::token();
$draftToken = DraftToken::normalize((string) ($_POST['draft_token'] ?? ''));
$copyLessonId = (int) ($_POST['copy_lesson_id'] ?? $_GET['copy_lesson_id'] ?? 0);
$errors = [];
$values = [
    'title' => (string) ($_POST['title'] ?? ''), 'category' => (string) ($_POST['category'] ?? 'LEKSION'),
    'section_id' => (int) ($_POST['section_id'] ?? $_GET['section_id'] ?? 0), 'url' => (string) ($_POST['url'] ?? ''),
    'video_urls_text' => (string) ($_POST['video_urls'] ?? ''), 'notebook_path' => (string) ($_POST['notebook_path'] ?? ''),
];
$initialBlocks = [['type' => 'paragraph', 'data' => ['text' => '']]];
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        Csrf::requireValid();
        $formData->assertSection($courseId, (int) $values['section_id']);
        $media = new PdoLessonMediaRepository($app['pdo']);
        $validator = new LessonBlockValidator(new HtmlSanitizer(), static fn (int $id): bool => $media->isAvailable($id, (int) $user['id'], $draftToken, null));
        $blocks = $validator->validateJson((string) ($_POST['blocks_json'] ?? ''));
        $service = new CreateLesson(
            $app['pdo'], new PdoLessonRepository($app['pdo']), new PdoLessonBlockRepository($app['pdo']),
            $media, new HtmlSanitizer(), $app['lesson_attachment_storage']
        );
        $lessonId = $service->handle([
            ...$values, 'course_id' => $courseId,
            'video_urls' => preg_split('/\R/u', $values['video_urls_text']) ?: [],
        ], $blocks, (int) $user['id'], $draftToken, $_FILES['lesson_file'] ?? []);
        $_SESSION['flash'] = ['msg' => 'Leksioni u ruajt me sukses.', 'type' => 'success'];
        Response::redirect('../lesson_details.php?lesson_id=' . $lessonId);
    } catch (LessonValidationException $exception) {
        $errors[] = $exception->getMessage();
        foreach ($exception->errors as $position => $messages) foreach ($messages as $message) $errors[] = 'Blloku ' . ((int) $position + 1) . ': ' . $message;
    } catch (Throwable $exception) {
        if (!$exception instanceof DomainException) error_log('Create lesson failed: ' . $exception->getMessage());
        $errors[] = $exception instanceof DomainException ? $exception->getMessage() : 'Leksioni nuk mund të ruhet tani.';
    }
    $decoded = json_decode((string) ($_POST['blocks_json'] ?? ''), true);
    if (is_array($decoded['blocks'] ?? null)) $initialBlocks = $decoded['blocks'];
}
require dirname(__DIR__) . '/src/Lessons/Presentation/lesson_form.php';
