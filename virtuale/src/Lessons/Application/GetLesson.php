<?php

declare(strict_types=1);

namespace KurseInformatike\Lessons\Application;

use KurseInformatike\Lessons\Infrastructure\PdoLessonBlockRepository;
use KurseInformatike\Lessons\Infrastructure\PdoLessonRepository;

final class GetLesson
{
    public function __construct(
        private readonly PdoLessonRepository $lessons,
        private readonly PdoLessonBlockRepository $blocks,
    ) {
    }

    public function handle(int $lessonId): array
    {
        $lesson = $this->lessons->find($lessonId);
        if (!$lesson) {
            throw new \DomainException('Leksioni nuk u gjet.');
        }
        $lesson['content_format'] = (string) ($lesson['content_format'] ?? 'legacy_markdown');
        $lesson['blocks'] = $lesson['content_format'] === 'blocks_v1'
            ? $this->blocks->getForLesson($lessonId)
            : null;
        return $lesson;
    }
}
