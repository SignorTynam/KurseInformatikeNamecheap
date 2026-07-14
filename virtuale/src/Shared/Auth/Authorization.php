<?php

declare(strict_types=1);

namespace KurseInformatike\Shared\Auth;

use PDO;

final class Authorization
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    public function requireUser(): array
    {
        $user = $_SESSION['user'] ?? null;
        if (!is_array($user) || (int) ($user['id'] ?? 0) < 1) {
            throw new \DomainException('Duhet të identifikoheni.');
        }

        return $user;
    }

    public function requireCourseManager(int $courseId): array
    {
        $user = $this->requireUser();
        $role = (string) ($user['role'] ?? '');
        if ($role === 'Administrator') {
            return $user;
        }
        if ($role !== 'Instruktor') {
            throw new \DomainException('Nuk keni leje për këtë veprim.');
        }

        $query = $this->pdo->prepare('SELECT id_creator FROM courses WHERE id = ?');
        $query->execute([$courseId]);
        if ((int) $query->fetchColumn() !== (int) $user['id']) {
            throw new \DomainException('Nuk mund të menaxhoni këtë kurs.');
        }

        return $user;
    }

    public function requireLessonManager(int $lessonId): array
    {
        $query = $this->pdo->prepare('SELECT course_id FROM lessons WHERE id = ?');
        $query->execute([$lessonId]);
        $courseId = (int) $query->fetchColumn();
        if ($courseId < 1) {
            throw new \DomainException('Leksioni nuk u gjet.');
        }

        return $this->requireCourseManager($courseId);
    }

    public function requireLessonViewer(int $lessonId): array
    {
        $user = $this->requireUser();
        $query = $this->pdo->prepare(
            'SELECT l.course_id, l.section_id, COALESCE(l.hidden, 0) AS lesson_hidden,
                    COALESCE(s.hidden, 0) AS section_hidden, c.id_creator
             FROM lessons l
             JOIN courses c ON c.id = l.course_id
             LEFT JOIN sections s ON s.id = l.section_id
             WHERE l.id = ?'
        );
        try {
            $query->execute([$lessonId]);
        } catch (\PDOException) {
            $query = $this->pdo->prepare(
                'SELECT l.course_id, l.section_id, 0 AS lesson_hidden,
                        COALESCE(s.hidden, 0) AS section_hidden, c.id_creator
                 FROM lessons l
                 JOIN courses c ON c.id = l.course_id
                 LEFT JOIN sections s ON s.id = l.section_id
                 WHERE l.id = ?'
            );
            $query->execute([$lessonId]);
        }
        $lesson = $query->fetch();
        if (!$lesson) {
            throw new \DomainException('Leksioni nuk u gjet.');
        }

        $role = (string) ($user['role'] ?? '');
        if ($role === 'Administrator'
            || ($role === 'Instruktor' && (int) $lesson['id_creator'] === (int) $user['id'])) {
            return $user;
        }
        if ($role !== 'Student' || (int) $lesson['section_hidden'] === 1 || (int) $lesson['lesson_hidden'] === 1) {
            throw new \DomainException('Nuk keni qasje në këtë leksion.');
        }

        $enrollment = $this->pdo->prepare('SELECT 1 FROM enroll WHERE course_id = ? AND user_id = ? LIMIT 1');
        $enrollment->execute([(int) $lesson['course_id'], (int) $user['id']]);
        if (!$enrollment->fetchColumn()) {
            throw new \DomainException('Nuk jeni regjistruar në këtë kurs.');
        }

        return $user;
    }
}
