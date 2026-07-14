<?php

declare(strict_types=1);

use KurseInformatike\Lessons\Application\CopyLesson;
use KurseInformatike\Lessons\Application\CreateLesson;
use KurseInformatike\Lessons\Application\DeleteLesson;
use KurseInformatike\Lessons\Application\DraftToken;
use KurseInformatike\Lessons\Application\LessonBlockValidator;
use KurseInformatike\Lessons\Application\UpdateLesson;
use KurseInformatike\Lessons\Infrastructure\PdoLessonBlockRepository;
use KurseInformatike\Lessons\Infrastructure\PdoLessonMediaRepository;
use KurseInformatike\Lessons\Infrastructure\PdoLessonRepository;
use KurseInformatike\Shared\Security\HtmlSanitizer;

$app = require dirname(__DIR__) . '/virtuale/bootstrap/app.php';
$course = $app['pdo']->query('SELECT id,id_creator FROM courses ORDER BY id LIMIT 1')->fetch();
if (!$course) throw new RuntimeException('No course fixture available.');
$lessonIds = [];
$lessons = new PdoLessonRepository($app['pdo']);
$blocksRepository = new PdoLessonBlockRepository($app['pdo']);
$media = new PdoLessonMediaRepository($app['pdo']);
$sanitizer = new HtmlSanitizer();
$delete = new DeleteLesson($app['pdo'], $app['lesson_media_storage'], dirname(__DIR__) . '/virtuale');
try {
    $blocks = (new LessonBlockValidator($sanitizer))->validate([
        ['id' => 'smokehead', 'type' => 'heading', 'data' => ['text' => 'Smoke heading', 'level' => 2]],
        ['id' => 'smokepara', 'type' => 'paragraph', 'data' => ['text' => '<b>Smoke paragraph</b>']],
    ]);
    $create = new CreateLesson($app['pdo'], $lessons, $blocksRepository, $media, $sanitizer, $app['lesson_attachment_storage']);
    $lessonIds[] = $create->handle([
        'course_id' => (int)$course['id'], 'section_id' => 0, 'title' => 'Codex smoke lesson',
        'category' => 'LEKSION', 'url' => '', 'notebook_path' => '', 'video_urls' => [],
    ], $blocks, (int)$course['id_creator'], DraftToken::generate());
    $stored = $lessons->find($lessonIds[0]);
    if (($stored['content_format'] ?? '') !== 'blocks_v1' || $blocksRepository->getForLesson($lessonIds[0])->count() !== 2) {
        throw new RuntimeException('Create assertion failed.');
    }
    $reordered = (new LessonBlockValidator($sanitizer))->validate([
        ['id' => 'smokepara', 'type' => 'paragraph', 'data' => ['text' => 'Updated']],
        ['id' => 'smokehead', 'type' => 'heading', 'data' => ['text' => 'Smoke heading', 'level' => 2]],
    ]);
    $update = new UpdateLesson($app['pdo'], $lessons, $blocksRepository, $media, $sanitizer, $app['lesson_media_storage'], $app['lesson_attachment_storage']);
    $update->handle($lessonIds[0], [
        'course_id' => (int)$course['id'], 'section_id' => 0, 'title' => 'Codex smoke lesson updated',
        'category' => 'LEKSION', 'url' => '', 'notebook_path' => '', 'video_urls' => [],
    ], $reordered, (int)$course['id_creator'], DraftToken::generate());
    if ($blocksRepository->getForLesson($lessonIds[0])->all()[0]->uid !== 'smokepara') throw new RuntimeException('Update assertion failed.');
    $copy = new CopyLesson($app['pdo'], $lessons, $blocksRepository, $media, $app['lesson_media_storage'], dirname(__DIR__) . '/virtuale');
    $lessonIds[] = $copy->handle($lessonIds[0], (int)$course['id'], null, (int)$course['id_creator']);
    if ($blocksRepository->getForLesson($lessonIds[1])->count() !== 2) throw new RuntimeException('Copy assertion failed.');
    fwrite(STDOUT, "Lessons CRUD smoke passed.\n");
} finally {
    foreach (array_reverse($lessonIds) as $lessonId) {
        if ($lessons->find($lessonId)) $delete->handle($lessonId);
    }
}
