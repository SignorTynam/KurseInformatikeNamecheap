<?php

declare(strict_types=1);

namespace KurseInformatike\Lessons\Application;

use KurseInformatike\Lessons\Domain\LessonBlockCollection;
use KurseInformatike\Lessons\Infrastructure\PdoLessonBlockRepository;
use KurseInformatike\Lessons\Infrastructure\PdoLessonMediaRepository;
use KurseInformatike\Lessons\Infrastructure\PdoLessonRepository;
use KurseInformatike\Shared\Security\HtmlSanitizer;
use KurseInformatike\Shared\Storage\FileStorage;
use KurseInformatike\Lessons\Infrastructure\LessonAttachmentStorage;
use PDO;

final class UpdateLesson
{
    public function __construct(
        private readonly PDO $pdo,
        private readonly PdoLessonRepository $lessons,
        private readonly PdoLessonBlockRepository $blocks,
        private readonly PdoLessonMediaRepository $media,
        private readonly HtmlSanitizer $sanitizer,
        private readonly FileStorage $storage,
        private readonly LessonAttachmentStorage $attachmentStorage,
    ) {
    }

    public function handle(int $lessonId, array $input, LessonBlockCollection $blocks, int $ownerId, string $draftToken, array $uploadedFile = []): void
    {
        $lesson = LessonInput::normalize($input);
        $lesson['description'] = $blocks->plainText($this->sanitizer);
        $mediaIds = CreateLesson::mediaIds($blocks);
        $deleteAfterCommit = [];
        $attachment = $this->attachmentStorage->store($uploadedFile);

        $this->pdo->beginTransaction();
        try {
            if (!$this->lessons->find($lessonId, true)) throw new \DomainException('Leksioni nuk u gjet.');
            $this->lessons->update($lessonId, $lesson);
            $this->blocks->replaceForLesson($lessonId, $blocks);
            $this->media->attachDraftMedia($lessonId, $ownerId, $draftToken, $mediaIds);
            $deleteAfterCommit = $this->media->removeUnreferenced($lessonId, $mediaIds);
            $this->lessons->replaceVideos($lessonId, $lesson['video_urls']);
            if ($attachment !== null) {
                $insertFile = $this->pdo->prepare('INSERT INTO lesson_files (lesson_id,file_path,file_type,uploaded_at) VALUES (?,?,?,NOW())');
                $insertFile->execute([$lessonId, $attachment['file_path'], $attachment['file_type']]);
            }
            $this->lessons->linkSection($lessonId, $lesson['course_id'], $lesson['section_id']);
            $this->pdo->commit();
        } catch (\Throwable $exception) {
            if ($this->pdo->inTransaction()) $this->pdo->rollBack();
            if ($attachment !== null) $this->attachmentStorage->delete($attachment['file_path']);
            throw $exception;
        }
        foreach ($deleteAfterCommit as $media) {
            if (!$this->storage->delete($media['storage_path'])) {
                error_log('Could not remove unreferenced lesson media: ' . $media['storage_path']);
            }
        }
    }
}
