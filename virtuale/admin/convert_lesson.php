<?php

declare(strict_types=1);

use KurseInformatike\Lessons\Application\ConvertLegacyLesson;
use KurseInformatike\Lessons\Application\LessonBlockValidator;
use KurseInformatike\Lessons\Application\LessonFormData;
use KurseInformatike\Lessons\Infrastructure\PdoLessonRepository;
use KurseInformatike\Lessons\Presentation\LessonBlockRenderer;
use KurseInformatike\Shared\Http\Csrf;
use KurseInformatike\Shared\Http\Response;
use KurseInformatike\Shared\Security\HtmlSanitizer;

$app = require dirname(__DIR__) . '/bootstrap/app.php';
$lessonId = (int) ($_POST['lesson_id'] ?? $_GET['lesson_id'] ?? 0);
$app['authorization']->requireLessonManager($lessonId);
$lesson = (new PdoLessonRepository($app['pdo']))->find($lessonId);
if (!$lesson) throw new DomainException('Leksioni nuk u gjet.');
if (($lesson['content_format'] ?? 'legacy_markdown') === 'blocks_v1') {
    Response::redirect('edit_lesson.php?lesson_id=' . $lessonId);
}
$sanitizer = new HtmlSanitizer();
$converter = new ConvertLegacyLesson($app['pdo'], new LessonBlockValidator($sanitizer), $sanitizer);
$preview = $converter->preview((string) ($lesson['description'] ?? ''));
$previewHtml = (new LessonBlockRenderer($sanitizer))->render($preview);
$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        Csrf::requireValid();
        $converter->convert($lessonId);
        $_SESSION['flash'] = ['msg' => 'Leksioni u konvertua. Kontrolloni blloqet para publikimit.', 'type' => 'success'];
        Response::redirect('edit_lesson.php?lesson_id=' . $lessonId);
    } catch (Throwable $exception) {
        if (!$exception instanceof DomainException) error_log('Legacy lesson conversion failed: ' . $exception->getMessage());
        $error = $exception instanceof DomainException ? $exception->getMessage() : 'Konvertimi nuk mund të përfundojë tani.';
    }
}
$escape = static fn (string $value): string => htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
?>
<!doctype html><html lang="sq"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Konverto leksionin</title><link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css"><link rel="stylesheet" href="../css/km-lesson.css"></head><body class="bg-light"><main class="container py-5"><div class="card border-0 shadow-sm"><div class="card-body p-4 p-lg-5"><h1 class="h3">Konverto në editorin e ri</h1><p class="text-muted">Origjinali ruhet për rikthim. Kontrolloni pamjen paraprake dhe konfirmoni vetëm kur përmbajtja është e saktë.</p><?php if ($error !== ''): ?><div class="alert alert-danger"><?= $escape($error) ?></div><?php endif; ?><article class="lesson-content border rounded p-3 my-4 bg-white"><?= $previewHtml ?></article><form method="post" class="d-flex gap-2 justify-content-end"><input type="hidden" name="lesson_id" value="<?= $lessonId ?>"><input type="hidden" name="csrf_token" value="<?= $escape(Csrf::token()) ?>"><a class="btn btn-outline-secondary" href="../lesson_details.php?lesson_id=<?= $lessonId ?>">Anulo</a><button class="btn btn-primary" type="submit">Konfirmo konvertimin</button></form></div></div></main></body></html>
