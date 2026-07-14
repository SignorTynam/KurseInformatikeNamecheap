<?php

declare(strict_types=1);

use KurseInformatike\Lessons\Application\DraftToken;
use KurseInformatike\Lessons\Application\GetLesson;
use KurseInformatike\Lessons\Application\LessonBlockValidator;
use KurseInformatike\Lessons\Application\LessonFormData;
use KurseInformatike\Lessons\Application\LessonValidationException;
use KurseInformatike\Lessons\Application\UpdateLesson;
use KurseInformatike\Lessons\Infrastructure\PdoLessonBlockRepository;
use KurseInformatike\Lessons\Infrastructure\PdoLessonMediaRepository;
use KurseInformatike\Lessons\Infrastructure\PdoLessonRepository;
use KurseInformatike\Shared\Http\Csrf;
use KurseInformatike\Shared\Http\Response;
use KurseInformatike\Shared\Security\HtmlSanitizer;

$app = require dirname(__DIR__) . '/bootstrap/app.php';
$lessonId = (int) ($_POST['lesson_id'] ?? $_GET['lesson_id'] ?? 0);
$user = $app['authorization']->requireLessonManager($lessonId);
$lessonRepository = new PdoLessonRepository($app['pdo']);
$blockRepository = new PdoLessonBlockRepository($app['pdo']);
$lesson = (new GetLesson($lessonRepository, $blockRepository))->handle($lessonId);
if ($lesson['content_format'] !== 'blocks_v1') {
    Response::redirect('convert_lesson.php?lesson_id=' . $lessonId);
}
$courseId = (int) $lesson['course_id'];
$formData = new LessonFormData($app['pdo']);
$course = $formData->course($courseId);
$sections = $formData->sections($courseId);
$csrfToken = Csrf::token();
$draftToken = DraftToken::normalize((string) ($_POST['draft_token'] ?? ''));
$copyLessonId = 0;
$errors = [];
$values = [
    'title' => (string) ($_POST['title'] ?? $lesson['title']), 'category' => (string) ($_POST['category'] ?? $lesson['category']),
    'section_id' => (int) ($_POST['section_id'] ?? $lesson['section_id'] ?? 0), 'url' => (string) ($_POST['url'] ?? $lesson['URL'] ?? ''),
    'video_urls_text' => (string) ($_POST['video_urls'] ?? implode("\n", $formData->videos($lessonId))),
    'notebook_path' => (string) ($_POST['notebook_path'] ?? $lesson['notebook_path'] ?? ''),
];
$initialBlocks = $lesson['blocks']->toEditorData();
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        Csrf::requireValid();
        $formData->assertSection($courseId, (int) $values['section_id']);
        $media = new PdoLessonMediaRepository($app['pdo']);
        $validator = new LessonBlockValidator(new HtmlSanitizer(), static fn (int $id): bool => $media->isAvailable($id, (int) $user['id'], $draftToken, $lessonId, ($user['role'] ?? '') === 'Administrator'));
        $blocks = $validator->validateJson((string) ($_POST['blocks_json'] ?? ''));
        $service = new UpdateLesson(
            $app['pdo'], $lessonRepository, $blockRepository, $media, new HtmlSanitizer(),
            $app['lesson_media_storage'], $app['lesson_attachment_storage']
        );
        $service->handle($lessonId, [...$values, 'course_id' => $courseId, 'video_urls' => preg_split('/\R/u', $values['video_urls_text']) ?: []], $blocks, (int) $user['id'], $draftToken, $_FILES['lesson_file'] ?? []);
        $_SESSION['flash'] = ['msg' => 'Ndryshimet u ruajtën.', 'type' => 'success'];
        Response::redirect('../lesson_details.php?lesson_id=' . $lessonId);
    } catch (LessonValidationException $exception) {
        $errors[] = $exception->getMessage();
        foreach ($exception->errors as $position => $messages) foreach ($messages as $message) $errors[] = 'Blloku ' . ((int) $position + 1) . ': ' . $message;
    } catch (Throwable $exception) {
        if (!$exception instanceof DomainException) error_log('Update lesson failed: ' . $exception->getMessage());
        $errors[] = $exception instanceof DomainException ? $exception->getMessage() : 'Ndryshimet nuk mund të ruhen tani.';
    }
    $decoded = json_decode((string) ($_POST['blocks_json'] ?? ''), true);
    if (is_array($decoded['blocks'] ?? null)) $initialBlocks = $decoded['blocks'];
}
require dirname(__DIR__) . '/src/Lessons/Presentation/lesson_form.php';
