<?php

declare(strict_types=1);

use KurseInformatike\Lessons\Application\LessonBlockValidator;
use KurseInformatike\Lessons\Application\LessonValidationException;
use KurseInformatike\Lessons\Presentation\LessonBlockRenderer;
use KurseInformatike\Shared\Http\Csrf;
use KurseInformatike\Shared\Http\Response;
use KurseInformatike\Shared\Security\HtmlSanitizer;

try {
    $app = require dirname(__DIR__, 2) . '/bootstrap/app.php';
    Csrf::requireValid();
    $app['authorization']->requireUser();
    $raw = file_get_contents('php://input') ?: '{}';
    $validator = new LessonBlockValidator(new HtmlSanitizer());
    $blocks = $validator->validateJson($raw);
    $renderer = new LessonBlockRenderer(new HtmlSanitizer());
    Response::json(['success' => 1, 'html' => $renderer->render($blocks)]);
} catch (LessonValidationException $exception) {
    Response::json(['success' => 0, 'message' => $exception->getMessage(), 'errors' => $exception->errors], 422);
} catch (Throwable $exception) {
    error_log('Lesson preview failed: ' . $exception->getMessage());
    Response::json(['success' => 0, 'message' => 'Pamja paraprake nuk mund të krijohet.'], 500);
}
