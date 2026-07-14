<?php

declare(strict_types=1);

namespace KurseInformatike\Lessons\Infrastructure;

use PDO;

final class PdoLessonMediaRepository
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    public function create(array $media): int
    {
        $insert = $this->pdo->prepare(
            'INSERT INTO lesson_media
             (lesson_id, owner_user_id, draft_token, media_type, storage_path, original_name,
              mime_type, size_bytes, width, height)
             VALUES (?,?,?,?,?,?,?,?,?,?)'
        );
        $insert->execute([
            $media['lesson_id'] ?? null,
            (int) $media['owner_user_id'],
            $media['draft_token'] ?? null,
            (string) $media['media_type'],
            (string) $media['storage_path'],
            (string) $media['original_name'],
            (string) $media['mime_type'],
            (int) $media['size_bytes'],
            $media['width'] ?? null,
            $media['height'] ?? null,
        ]);
        return (int) $this->pdo->lastInsertId();
    }

    public function find(int $mediaId): ?array
    {
        $query = $this->pdo->prepare('SELECT * FROM lesson_media WHERE id = ?');
        $query->execute([$mediaId]);
        $row = $query->fetch();
        return $row ?: null;
    }

    public function isAvailable(int $mediaId, int $ownerId, string $draftToken, ?int $lessonId, bool $admin = false): bool
    {
        $media = $this->find($mediaId);
        if (!$media) return false;
        if ($lessonId !== null && (int)($media['lesson_id'] ?? 0) === $lessonId) {
            return $admin || (int)$media['owner_user_id'] === $ownerId;
        }
        return $media['lesson_id'] === null
            && (int)$media['owner_user_id'] === $ownerId
            && hash_equals((string)($media['draft_token'] ?? ''), $draftToken);
    }

    /** @param list<int> $mediaIds */
    public function attachDraftMedia(int $lessonId, int $ownerId, string $draftToken, array $mediaIds): void
    {
        if ($mediaIds === []) {
            return;
        }
        $placeholders = implode(',', array_fill(0, count($mediaIds), '?'));
        $parameters = [$lessonId, ...$mediaIds, $ownerId, $draftToken];
        $update = $this->pdo->prepare(
            "UPDATE lesson_media SET lesson_id = ?, draft_token = NULL
             WHERE id IN ({$placeholders}) AND owner_user_id = ?
               AND lesson_id IS NULL AND draft_token = ?"
        );
        $update->execute($parameters);
        if ($update->rowCount() > count($mediaIds)) {
            throw new \RuntimeException('Unexpected media association count.');
        }
        $verify = $this->pdo->prepare(
            "SELECT COUNT(*) FROM lesson_media WHERE id IN ({$placeholders}) AND lesson_id = ?"
        );
        $verify->execute([...$mediaIds, $lessonId]);
        if ((int) $verify->fetchColumn() !== count(array_unique($mediaIds))) {
            throw new \DomainException('Një ose më shumë foto nuk mund të lidhen me leksionin.');
        }
    }

    /** @param list<int> $keepIds @return list<array{id:int,storage_path:string}> */
    public function removeUnreferenced(int $lessonId, array $keepIds): array
    {
        $parameters = [$lessonId];
        $where = '';
        if ($keepIds !== []) {
            $where = ' AND id NOT IN (' . implode(',', array_fill(0, count($keepIds), '?')) . ')';
            $parameters = [$lessonId, ...$keepIds];
        }
        $query = $this->pdo->prepare('SELECT id, storage_path FROM lesson_media WHERE lesson_id = ?' . $where);
        $query->execute($parameters);
        $rows = $query->fetchAll() ?: [];
        if ($rows !== []) {
            $delete = $this->pdo->prepare('DELETE FROM lesson_media WHERE lesson_id = ?' . $where);
            $delete->execute($parameters);
        }
        return array_map(static fn (array $row): array => ['id' => (int) $row['id'], 'storage_path' => (string) $row['storage_path']], $rows);
    }

    /** @return list<array> */
    public function forLesson(int $lessonId): array
    {
        $query = $this->pdo->prepare('SELECT * FROM lesson_media WHERE lesson_id = ? ORDER BY id');
        $query->execute([$lessonId]);
        return $query->fetchAll() ?: [];
    }

    public function delete(int $mediaId): void
    {
        $query = $this->pdo->prepare('DELETE FROM lesson_media WHERE id = ?');
        $query->execute([$mediaId]);
    }
}
