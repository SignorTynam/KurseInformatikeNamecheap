<?php

declare(strict_types=1);

namespace KurseInformatike\Lessons\Application;

use PDO;

final class LessonFormData
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    public function course(int $courseId): array
    {
        $query = $this->pdo->prepare('SELECT id, title, id_creator FROM courses WHERE id=?');
        $query->execute([$courseId]);
        $course = $query->fetch();
        if (!$course) throw new \DomainException('Kursi nuk u gjet.');
        return $course;
    }

    public function sections(int $courseId): array
    {
        $query = $this->pdo->prepare('SELECT id,title,area FROM sections WHERE course_id=? ORDER BY position,id');
        $query->execute([$courseId]);
        return $query->fetchAll() ?: [];
    }

    public function videos(int $lessonId): array
    {
        $query = $this->pdo->prepare('SELECT video_url FROM lesson_videos WHERE lesson_id=? ORDER BY position,id');
        $query->execute([$lessonId]);
        return array_map('strval', $query->fetchAll(PDO::FETCH_COLUMN) ?: []);
    }

    public function assertSection(int $courseId, int $sectionId): void
    {
        if ($sectionId === 0) return;
        $query = $this->pdo->prepare('SELECT 1 FROM sections WHERE id=? AND course_id=?');
        $query->execute([$sectionId, $courseId]);
        if (!$query->fetchColumn()) throw new \DomainException('Seksioni nuk i përket këtij kursi.');
    }
}
