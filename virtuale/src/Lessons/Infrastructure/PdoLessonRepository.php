<?php

declare(strict_types=1);

namespace KurseInformatike\Lessons\Infrastructure;

use PDO;

final class PdoLessonRepository
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    public function find(int $lessonId, bool $forUpdate = false): ?array
    {
        $query = $this->pdo->prepare('SELECT * FROM lessons WHERE id = ?' . ($forUpdate ? ' FOR UPDATE' : ''));
        $query->execute([$lessonId]);
        $lesson = $query->fetch();
        return $lesson ?: null;
    }

    public function create(array $lesson): int
    {
        $insert = $this->pdo->prepare(
            "INSERT INTO lessons
             (course_id, section_id, title, description, content_format, content_version,
              URL, category, notebook_path, uploaded_at, updated_at)
             VALUES (?,?,?,?, 'blocks_v1', 1, ?,?,?, NOW(), NOW())"
        );
        $insert->execute([
            (int) $lesson['course_id'],
            !empty($lesson['section_id']) ? (int) $lesson['section_id'] : null,
            (string) $lesson['title'],
            (string) $lesson['description'],
            $lesson['url'] !== '' ? $lesson['url'] : null,
            (string) $lesson['category'],
            $lesson['notebook_path'] !== '' ? $lesson['notebook_path'] : null,
        ]);
        return (int) $this->pdo->lastInsertId();
    }

    public function update(int $lessonId, array $lesson): void
    {
        $update = $this->pdo->prepare(
            "UPDATE lessons SET course_id=?, section_id=?, title=?, description=?,
             content_format='blocks_v1', content_version=1, URL=?, category=?, notebook_path=?, updated_at=NOW()
             WHERE id=?"
        );
        $update->execute([
            (int) $lesson['course_id'],
            !empty($lesson['section_id']) ? (int) $lesson['section_id'] : null,
            (string) $lesson['title'],
            (string) $lesson['description'],
            $lesson['url'] !== '' ? $lesson['url'] : null,
            (string) $lesson['category'],
            $lesson['notebook_path'] !== '' ? $lesson['notebook_path'] : null,
            $lessonId,
        ]);
    }

    public function replaceVideos(int $lessonId, array $urls): void
    {
        $this->pdo->prepare('DELETE FROM lesson_videos WHERE lesson_id = ?')->execute([$lessonId]);
        $insert = $this->pdo->prepare(
            'INSERT INTO lesson_videos (lesson_id, video_url, position, created_at, updated_at)
             VALUES (?,?,?,NOW(),NOW())'
        );
        foreach (array_values($urls) as $position => $url) {
            $insert->execute([$lessonId, $url, $position + 1]);
        }
    }

    public function linkSection(int $lessonId, int $courseId, int $sectionId): void
    {
        $lookup = $this->pdo->prepare(
            "SELECT id FROM section_items WHERE item_type='LESSON' AND item_ref_id=? ORDER BY id LIMIT 1"
        );
        $lookup->execute([$lessonId]);
        $itemId = (int) $lookup->fetchColumn();
        if ($itemId > 0) {
            $update = $this->pdo->prepare('UPDATE section_items SET course_id=?, section_id=?, updated_at=NOW() WHERE id=?');
            $update->execute([$courseId, $sectionId, $itemId]);
            $this->pdo->prepare("DELETE FROM section_items WHERE item_type='LESSON' AND item_ref_id=? AND id<>?")
                ->execute([$lessonId, $itemId]);
            return;
        }
        $position = $this->pdo->prepare('SELECT COALESCE(MAX(position),0)+1 FROM section_items WHERE course_id=? AND section_id=?');
        $position->execute([$courseId, $sectionId]);
        $insert = $this->pdo->prepare(
            "INSERT INTO section_items (course_id, section_id, item_type, item_ref_id, position, created_at, updated_at)
             VALUES (?,?,'LESSON',?,?,NOW(),NOW())"
        );
        $insert->execute([$courseId, $sectionId, $lessonId, (int) $position->fetchColumn()]);
    }
}
