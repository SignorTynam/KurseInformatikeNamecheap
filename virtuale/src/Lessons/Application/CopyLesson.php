<?php

declare(strict_types=1);

namespace KurseInformatike\Lessons\Application;

use KurseInformatike\Lessons\Domain\LessonBlock;
use KurseInformatike\Lessons\Domain\LessonBlockCollection;
use KurseInformatike\Lessons\Infrastructure\PdoLessonBlockRepository;
use KurseInformatike\Lessons\Infrastructure\PdoLessonMediaRepository;
use KurseInformatike\Lessons\Infrastructure\PdoLessonRepository;
use KurseInformatike\Shared\Storage\FileStorage;
use PDO;

final class CopyLesson
{
    public function __construct(
        private readonly PDO $pdo,
        private readonly PdoLessonRepository $lessons,
        private readonly PdoLessonBlockRepository $blocks,
        private readonly PdoLessonMediaRepository $media,
        private readonly FileStorage $storage,
        private readonly string $virtualeRoot,
    ) {
    }

    public function handle(int $sourceLessonId, int $targetCourseId, ?int $targetSectionId, int $ownerId, bool $linkSection = true): int
    {
        $createdPaths = [];
        $startedTransaction = !$this->pdo->inTransaction();
        if ($startedTransaction) $this->pdo->beginTransaction();
        try {
            $source = $this->lessons->find($sourceLessonId, true);
            if (!$source) throw new \DomainException('Leksioni burim nuk u gjet.');
            $sourceFormat = (string) ($source['content_format'] ?? 'legacy_markdown');
            $insert = $this->pdo->prepare(
                'INSERT INTO lessons (course_id, section_id, title, description, content_format,
                 content_version, legacy_description, URL, category, notebook_path, uploaded_at, updated_at)
                 VALUES (?,?,?,?,?,?,?,?,?,?,NOW(),NOW())'
            );
            $insert->execute([
                $targetCourseId,
                $targetSectionId ?: null,
                (string) $source['title'] . ' (Kopje)',
                $source['description'],
                $sourceFormat,
                (int) ($source['content_version'] ?? 1),
                $source['legacy_description'] ?? null,
                $source['URL'] ?? null,
                $source['category'] ?? 'LEKSION',
                $source['notebook_path'] ?? null,
            ]);
            $newLessonId = (int) $this->pdo->lastInsertId();

            if ($sourceFormat === 'blocks_v1') {
                $sourceBlocks = $this->blocks->getForLesson($sourceLessonId);
                $mediaMap = [];
                foreach ($this->media->forLesson($sourceLessonId) as $sourceMedia) {
                    $newPath = $this->storage->duplicate((string) $sourceMedia['storage_path'], 'lesson-' . $newLessonId);
                    $createdPaths[] = $newPath;
                    $newMediaId = $this->media->create([
                        'lesson_id' => $newLessonId,
                        'owner_user_id' => $ownerId,
                        'media_type' => $sourceMedia['media_type'],
                        'storage_path' => $newPath,
                        'original_name' => $sourceMedia['original_name'],
                        'mime_type' => $sourceMedia['mime_type'],
                        'size_bytes' => $sourceMedia['size_bytes'],
                        'width' => $sourceMedia['width'],
                        'height' => $sourceMedia['height'],
                    ]);
                    $mediaMap[(int) $sourceMedia['id']] = $newMediaId;
                }
                $copied = [];
                foreach ($sourceBlocks as $block) {
                    $data = $block->data;
                    if ($block->type === 'image' && isset($mediaMap[(int) ($data['mediaId'] ?? 0)])) {
                        $data['mediaId'] = $mediaMap[(int) $data['mediaId']];
                    }
                    $copied[] = new LessonBlock($block->uid, $block->type, $block->position, $data);
                }
                $this->blocks->replaceForLesson($newLessonId, new LessonBlockCollection($copied));
            }

            $this->copyRelatedRows($sourceLessonId, $newLessonId, $createdPaths);
            if ($linkSection) $this->lessons->linkSection($newLessonId, $targetCourseId, $targetSectionId ?: 0);
            if ($startedTransaction) $this->pdo->commit();
            return $newLessonId;
        } catch (\Throwable $exception) {
            if ($startedTransaction && $this->pdo->inTransaction()) $this->pdo->rollBack();
            foreach ($createdPaths as $path) $this->deleteAnyKnownPath($path);
            throw $exception;
        }
    }

    private function copyRelatedRows(int $sourceId, int $targetId, array &$createdPaths): void
    {
        if ($this->tableExists('lesson_videos')) {
            $query = $this->pdo->prepare('SELECT video_url, title, position FROM lesson_videos WHERE lesson_id=? ORDER BY position,id');
            $query->execute([$sourceId]);
            $insert = $this->pdo->prepare('INSERT INTO lesson_videos (lesson_id,video_url,title,position,created_at,updated_at) VALUES (?,?,?,?,NOW(),NOW())');
            foreach ($query->fetchAll() as $row) $insert->execute([$targetId, $row['video_url'], $row['title'], $row['position']]);
        }
        foreach (['lesson_files' => ['file_path', 'file_type'], 'lesson_images' => ['file_path', 'alt_text', 'position']] as $table => $columns) {
            if (!$this->tableExists($table)) continue;
            $query = $this->pdo->prepare('SELECT ' . implode(',', $columns) . " FROM {$table} WHERE lesson_id=?");
            $query->execute([$sourceId]);
            foreach ($query->fetchAll() as $row) {
                $newPath = $this->duplicateLegacyPath((string) $row['file_path'], $targetId);
                if ($newPath === null) continue;
                $createdPaths[] = $newPath;
                if ($table === 'lesson_files') {
                    $this->pdo->prepare('INSERT INTO lesson_files (lesson_id,file_path,file_type,uploaded_at) VALUES (?,?,?,NOW())')
                        ->execute([$targetId, $newPath, $row['file_type']]);
                } else {
                    $this->pdo->prepare('INSERT INTO lesson_images (lesson_id,file_path,alt_text,position,created_at) VALUES (?,?,?,?,NOW())')
                        ->execute([$targetId, $newPath, $row['alt_text'], $row['position']]);
                }
            }
        }
    }

    private function duplicateLegacyPath(string $relativePath, int $lessonId): ?string
    {
        $normalized = ltrim(str_replace('\\', '/', $relativePath), '/');
        if (!str_starts_with($normalized, 'uploads/')) return null;
        $source = realpath($this->virtualeRoot . '/' . $normalized);
        $uploads = realpath($this->virtualeRoot . '/uploads');
        if ($source === false || $uploads === false || !is_file($source)) return null;
        if (!str_starts_with(strtolower(str_replace('\\', '/', $source)), strtolower(str_replace('\\', '/', $uploads)) . '/')) return null;
        $directory = $this->virtualeRoot . '/uploads/lessons/copies/' . $lessonId;
        if (!is_dir($directory) && !mkdir($directory, 0775, true) && !is_dir($directory)) return null;
        $extension = pathinfo($source, PATHINFO_EXTENSION);
        $name = bin2hex(random_bytes(20)) . ($extension !== '' ? '.' . strtolower($extension) : '');
        if (!copy($source, $directory . '/' . $name)) return null;
        return 'uploads/lessons/copies/' . $lessonId . '/' . $name;
    }

    private function deleteAnyKnownPath(string $path): void
    {
        if (str_starts_with($path, 'uploads/lesson-media/')) {
            $this->storage->delete($path);
            return;
        }
        $candidate = realpath($this->virtualeRoot . '/' . ltrim($path, '/'));
        $uploads = realpath($this->virtualeRoot . '/uploads');
        if ($candidate && $uploads && is_file($candidate)
            && str_starts_with(strtolower(str_replace('\\', '/', $candidate)), strtolower(str_replace('\\', '/', $uploads)) . '/')) {
            unlink($candidate);
        }
    }

    private function tableExists(string $table): bool
    {
        $query = $this->pdo->prepare('SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=?');
        $query->execute([$table]);
        return (bool) $query->fetchColumn();
    }
}
