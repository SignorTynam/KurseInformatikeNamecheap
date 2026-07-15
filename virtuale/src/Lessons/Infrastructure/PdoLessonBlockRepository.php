<?php

declare(strict_types=1);

namespace KurseInformatike\Lessons\Infrastructure;

use KurseInformatike\Lessons\Domain\LessonBlock;
use KurseInformatike\Lessons\Domain\LessonBlockCollection;
use PDO;

final class PdoLessonBlockRepository
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    public function getForLesson(int $lessonId): LessonBlockCollection
    {
        $query = $this->pdo->prepare(
            'SELECT block_uid, block_type, position, data_json
             FROM lesson_blocks WHERE lesson_id = ? ORDER BY position, id'
        );
        $query->execute([$lessonId]);
        $blocks = [];
        foreach ($query->fetchAll() as $row) {
            try {
                $data = json_decode((string) $row['data_json'], true, 20, JSON_THROW_ON_ERROR);
            } catch (\JsonException) {
                throw new \UnexpectedValueException('Stored lesson block JSON is invalid.');
            }
            if (!is_array($data)) {
                throw new \UnexpectedValueException('Stored lesson block data is invalid.');
            }
            $blocks[] = new LessonBlock(
                (string) $row['block_uid'],
                (string) $row['block_type'],
                (int) $row['position'],
                $data
            );
        }
        return new LessonBlockCollection($blocks);
    }

    public function replaceForLesson(int $lessonId, LessonBlockCollection $blocks): void
    {
        $this->pdo->prepare('DELETE FROM lesson_blocks WHERE lesson_id = ?')->execute([$lessonId]);
        $insert = $this->pdo->prepare(
            'INSERT INTO lesson_blocks (lesson_id, block_uid, block_type, position, data_json)
             VALUES (?,?,?,?,?)'
        );
        foreach ($blocks as $block) {
            $insert->execute([
                $lessonId,
                $block->uid,
                $block->type,
                $block->position,
                json_encode($block->data, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            ]);
        }
    }
}
