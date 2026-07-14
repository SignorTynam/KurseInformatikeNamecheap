<?php

declare(strict_types=1);

namespace KurseInformatike\Lessons\Application;

use KurseInformatike\Lessons\Domain\LessonBlock;
use KurseInformatike\Lessons\Domain\LessonBlockCollection;
use KurseInformatike\Lessons\Infrastructure\PdoLessonBlockRepository;
use KurseInformatike\Lessons\Infrastructure\PdoLessonMediaRepository;
use KurseInformatike\Shared\Storage\FileStorage;
use PDO;

final class PrepareLessonDraftCopy
{
    public function __construct(
        private readonly PDO $pdo,
        private readonly PdoLessonBlockRepository $blocks,
        private readonly PdoLessonMediaRepository $media,
        private readonly FileStorage $storage,
    ) {
    }

    public function handle(int $sourceLessonId, int $ownerId, string $draftToken): LessonBlockCollection
    {
        $sourceBlocks = $this->blocks->getForLesson($sourceLessonId);
        $createdPaths = [];
        $map = [];
        $this->pdo->beginTransaction();
        try {
            foreach ($this->media->forLesson($sourceLessonId) as $source) {
                $path = $this->storage->duplicate((string)$source['storage_path'], 'draft-' . str_replace('-', '', $draftToken));
                $createdPaths[] = $path;
                $map[(int)$source['id']] = $this->media->create([
                    'owner_user_id' => $ownerId, 'draft_token' => $draftToken,
                    'media_type' => $source['media_type'], 'storage_path' => $path,
                    'original_name' => $source['original_name'], 'mime_type' => $source['mime_type'],
                    'size_bytes' => $source['size_bytes'], 'width' => $source['width'], 'height' => $source['height'],
                ]);
            }
            $copied = [];
            foreach ($sourceBlocks as $block) {
                $data = $block->data;
                if ($block->type === 'image' && isset($map[(int)($data['mediaId'] ?? 0)])) $data['mediaId'] = $map[(int)$data['mediaId']];
                $copied[] = new LessonBlock($block->uid, $block->type, $block->position, $data);
            }
            $this->pdo->commit();
            return new LessonBlockCollection($copied);
        } catch (\Throwable $exception) {
            if ($this->pdo->inTransaction()) $this->pdo->rollBack();
            foreach ($createdPaths as $path) $this->storage->delete($path);
            throw $exception;
        }
    }
}
