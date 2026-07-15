<?php

declare(strict_types=1);

namespace KurseInformatike\Lessons\Application;

use KurseInformatike\Shared\Storage\FileStorage;
use PDO;

final class DeleteLesson
{
    public function __construct(
        private readonly PDO $pdo,
        private readonly FileStorage $mediaStorage,
        private readonly string $virtualeRoot,
    ) {
    }

    /** @return list<string> paths that could not be deleted */
    public function handle(int $lessonId): array
    {
        $this->pdo->beginTransaction();
        try {
            $plan = $this->deleteRecords($lessonId);
            $this->pdo->commit();
        } catch (\Throwable $exception) {
            if ($this->pdo->inTransaction()) $this->pdo->rollBack();
            throw $exception;
        }

        return $this->cleanupFiles($plan);
    }

    /** Call only inside a caller-owned transaction, then cleanupFiles after commit. */
    public function deleteRecords(int $lessonId): array
    {
        $lessonQuery = $this->pdo->prepare('SELECT id FROM lessons WHERE id = ? FOR UPDATE');
        $lessonQuery->execute([$lessonId]);
        if (!$lessonQuery->fetchColumn()) throw new \DomainException('Leksioni nuk u gjet.');
        $mediaPaths = [];
        $legacyPaths = [];
        if ($this->tableExists('lesson_media')) {
            $query = $this->pdo->prepare('SELECT storage_path FROM lesson_media WHERE lesson_id = ?');
            $query->execute([$lessonId]);
            $mediaPaths = array_map('strval', $query->fetchAll(PDO::FETCH_COLUMN) ?: []);
            $this->pdo->prepare('DELETE FROM lesson_media WHERE lesson_id = ?')->execute([$lessonId]);
        }
        foreach (['lesson_files' => 'file_path', 'lesson_images' => 'file_path'] as $table => $column) {
            if (!$this->tableExists($table)) continue;
            $query = $this->pdo->prepare("SELECT {$column} FROM {$table} WHERE lesson_id = ?");
            $query->execute([$lessonId]);
            $legacyPaths = [...$legacyPaths, ...array_map('strval', $query->fetchAll(PDO::FETCH_COLUMN) ?: [])];
            $this->pdo->prepare("DELETE FROM {$table} WHERE lesson_id = ?")->execute([$lessonId]);
        }
        foreach (['lesson_blocks', 'lesson_videos'] as $table) {
            if ($this->tableExists($table)) $this->pdo->prepare("DELETE FROM {$table} WHERE lesson_id = ?")->execute([$lessonId]);
        }
        if ($this->tableExists('thread_replies') && $this->tableExists('threads')) {
            $this->pdo->prepare('DELETE tr FROM thread_replies tr JOIN threads t ON t.id=tr.thread_id WHERE t.lesson_id=?')->execute([$lessonId]);
        }
        foreach (['notes', 'threads'] as $table) {
            if ($this->tableExists($table)) $this->pdo->prepare("DELETE FROM {$table} WHERE lesson_id=?")->execute([$lessonId]);
        }
        $this->pdo->prepare("DELETE FROM section_items WHERE item_type='LESSON' AND item_ref_id=?")->execute([$lessonId]);
        $this->pdo->prepare("DELETE FROM user_reads WHERE item_type='LESSON' AND item_id=?")->execute([$lessonId]);
        $this->pdo->prepare('DELETE FROM lessons WHERE id = ?')->execute([$lessonId]);
        return ['media' => $mediaPaths, 'legacy' => $legacyPaths];
    }

    public function cleanupFiles(array $plan): array
    {
        $failures = [];
        foreach (($plan['media'] ?? []) as $path) {
            if (!$this->mediaStorage->delete($path)) $failures[] = $path;
        }
        foreach (($plan['legacy'] ?? []) as $path) {
            if (!$this->deleteLegacyUpload($path)) $failures[] = $path;
        }
        foreach ($failures as $path) error_log('Lesson deleted but physical file could not be removed: ' . $path);
        return $failures;
    }

    private function tableExists(string $table): bool
    {
        $query = $this->pdo->prepare('SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=?');
        $query->execute([$table]);
        return (bool) $query->fetchColumn();
    }

    private function deleteLegacyUpload(string $relativePath): bool
    {
        $uploadsRoot = realpath($this->virtualeRoot . '/uploads');
        if ($uploadsRoot === false) return false;
        $normalized = ltrim(str_replace('\\', '/', $relativePath), '/');
        if (!str_starts_with($normalized, 'uploads/')) return false;
        $candidate = realpath($this->virtualeRoot . '/' . $normalized);
        if ($candidate === false || !is_file($candidate)) return false;
        $rootPrefix = strtolower(str_replace('\\', '/', $uploadsRoot)) . '/';
        if (!str_starts_with(strtolower(str_replace('\\', '/', $candidate)), $rootPrefix)) return false;
        return unlink($candidate);
    }
}
