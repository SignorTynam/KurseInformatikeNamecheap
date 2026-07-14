<?php

declare(strict_types=1);

namespace KurseInformatike\Lessons\Application;

use KurseInformatike\Lessons\Domain\LessonBlockCollection;
use KurseInformatike\Lessons\Infrastructure\PdoLessonBlockRepository;
use KurseInformatike\Lessons\Infrastructure\PdoLessonMediaRepository;
use KurseInformatike\Lessons\Infrastructure\PdoLessonRepository;
use KurseInformatike\Lessons\Infrastructure\LessonAttachmentStorage;
use KurseInformatike\Shared\Security\HtmlSanitizer;
use PDO;

final class CreateLesson
{
    public function __construct(
        private readonly PDO $pdo,
        private readonly PdoLessonRepository $lessons,
        private readonly PdoLessonBlockRepository $blocks,
        private readonly PdoLessonMediaRepository $media,
        private readonly HtmlSanitizer $sanitizer,
        private readonly LessonAttachmentStorage $attachmentStorage,
    ) {
    }

    public function handle(array $input, LessonBlockCollection $blocks, int $ownerId, string $draftToken, array $uploadedFile = []): int
    {
        $lesson = LessonInput::normalize($input);
        if ($lesson['course_id'] < 1) throw new \DomainException('Kursi nuk është i vlefshëm.');
        $lesson['description'] = $blocks->plainText($this->sanitizer);
        $mediaIds = self::mediaIds($blocks);
        $attachment = $this->attachmentStorage->store($uploadedFile);
        if ($lesson['category'] === 'FILE' && $attachment === null) {
            throw new \DomainException('Ngarkoni një skedar për kategorinë FILE.');
        }

        $this->pdo->beginTransaction();
        try {
            $lessonId = $this->lessons->create($lesson);
            $this->blocks->replaceForLesson($lessonId, $blocks);
            $this->media->attachDraftMedia($lessonId, $ownerId, $draftToken, $mediaIds);
            $this->lessons->replaceVideos($lessonId, $lesson['video_urls']);
            if ($attachment !== null) {
                $insertFile = $this->pdo->prepare('INSERT INTO lesson_files (lesson_id,file_path,file_type,uploaded_at) VALUES (?,?,?,NOW())');
                $insertFile->execute([$lessonId, $attachment['file_path'], $attachment['file_type']]);
            }
            $this->lessons->linkSection($lessonId, $lesson['course_id'], $lesson['section_id']);
            $this->pdo->commit();
            return $lessonId;
        } catch (\Throwable $exception) {
            if ($this->pdo->inTransaction()) $this->pdo->rollBack();
            if ($attachment !== null) $this->attachmentStorage->delete($attachment['file_path']);
            throw $exception;
        }
    }

    /** @return list<int> */
    public static function mediaIds(LessonBlockCollection $blocks): array
    {
        $ids = [];
        foreach ($blocks as $block) {
            if ($block->type === 'image') $ids[] = (int) ($block->data['mediaId'] ?? 0);
        }
        return array_values(array_unique(array_filter($ids)));
    }
}
