<?php

declare(strict_types=1);

namespace KurseInformatike\Tests\Integration;

use KurseInformatike\Lessons\Application\LessonBlockValidator;
use KurseInformatike\Lessons\Infrastructure\PdoLessonBlockRepository;
use KurseInformatike\Shared\Security\HtmlSanitizer;
use PDO;
use PHPUnit\Framework\TestCase;

final class LessonBlockRepositoryIntegrationTest extends TestCase
{
    public function testReplaceAndReorderBlocksInsideRollbackFixture(): void
    {
        $dsn = getenv('TEST_DB_DSN') ?: '';
        $lessonId = (int) (getenv('LESSONS_TEST_LESSON_ID') ?: 0);
        if ($dsn === '' || $lessonId < 1) {
            self::markTestSkipped('Set TEST_DB_DSN and LESSONS_TEST_LESSON_ID for a disposable MariaDB fixture.');
        }
        $pdo = new PDO($dsn, getenv('TEST_DB_USER') ?: '', getenv('TEST_DB_PASSWORD') ?: '', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $pdo->beginTransaction();
        try {
            $blocks = (new LessonBlockValidator(new HtmlSanitizer()))->validate([
                ['id' => 'second001', 'type' => 'paragraph', 'data' => ['text' => 'Second']],
                ['id' => 'first0001', 'type' => 'heading', 'data' => ['text' => 'First', 'level' => 2]],
            ]);
            $repository = new PdoLessonBlockRepository($pdo);
            $repository->replaceForLesson($lessonId, $blocks);
            $loaded = $repository->getForLesson($lessonId);
            self::assertSame(['second001', 'first0001'], array_map(static fn ($block) => $block->uid, $loaded->all()));
        } finally {
            $pdo->rollBack();
        }
    }
}
