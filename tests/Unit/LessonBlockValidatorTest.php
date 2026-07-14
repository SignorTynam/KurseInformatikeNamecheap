<?php

declare(strict_types=1);

namespace KurseInformatike\Tests\Unit;

use KurseInformatike\Lessons\Application\LessonBlockValidator;
use KurseInformatike\Lessons\Application\LessonValidationException;
use KurseInformatike\Shared\Security\HtmlSanitizer;
use PHPUnit\Framework\TestCase;

final class LessonBlockValidatorTest extends TestCase
{
    private LessonBlockValidator $validator;

    protected function setUp(): void
    {
        $this->validator = new LessonBlockValidator(new HtmlSanitizer(), static fn (int $id): bool => $id === 123);
    }

    public function testNormalizesEverySupportedBlock(): void
    {
        $blocks = $this->validator->validate([
            ['id' => 'paragraph1', 'type' => 'paragraph', 'data' => ['text' => '<b>Tekst</b>']],
            ['id' => 'heading01', 'type' => 'heading', 'data' => ['text' => 'Titull', 'level' => 2]],
            ['id' => 'list00001', 'type' => 'list', 'data' => ['style' => 'checklist', 'items' => ['Një']]],
            ['id' => 'table0001', 'type' => 'table', 'data' => ['withHeadings' => true, 'content' => [['A'], ['B']]]],
            ['id' => 'image0001', 'type' => 'image', 'data' => ['mediaId' => 123, 'alt' => 'Foto']],
            ['id' => 'quote0001', 'type' => 'quote', 'data' => ['text' => 'Citim', 'caption' => 'Autor']],
            ['id' => 'callout01', 'type' => 'callout', 'data' => ['variant' => 'tip', 'title' => 'Këshillë', 'text' => 'Mbaje mend']],
            ['id' => 'code00001', 'type' => 'code', 'data' => ['language' => 'php', 'code' => '<?php echo 1;']],
            ['id' => 'delimiter1', 'type' => 'delimiter', 'data' => ['ignored' => true]],
        ]);

        self::assertCount(9, $blocks);
        self::assertSame([], $blocks->all()[8]->data);
        self::assertSame(123, $blocks->all()[4]->data['mediaId']);
    }

    public function testRejectsDuplicateIdsAndInvalidHeading(): void
    {
        try {
            $this->validator->validate([
                ['id' => 'duplicate1', 'type' => 'heading', 'data' => ['text' => 'A', 'level' => 1]],
                ['id' => 'duplicate1', 'type' => 'paragraph', 'data' => ['text' => 'B']],
            ]);
            self::fail('Validation exception expected.');
        } catch (LessonValidationException $exception) {
            self::assertArrayHasKey(0, $exception->errors);
            self::assertArrayHasKey(1, $exception->errors);
        }
    }

    public function testRejectsOversizedTableAndUnauthorizedMedia(): void
    {
        $rows = array_fill(0, 31, array_fill(0, 21, 'x'));
        $this->expectException(LessonValidationException::class);
        $this->validator->validate([
            ['id' => 'tablelong', 'type' => 'table', 'data' => ['content' => $rows]],
            ['id' => 'badimage1', 'type' => 'image', 'data' => ['mediaId' => 999, 'alt' => 'x']],
        ]);
    }

    public function testProjectionAndReadingTimeUseBlockText(): void
    {
        $blocks = $this->validator->validate([
            ['id' => 'paragraph1', 'type' => 'paragraph', 'data' => ['text' => '<b>Përshëndetje botë</b>']],
            ['id' => 'list00001', 'type' => 'list', 'data' => ['style' => 'unordered', 'items' => ['Një dy']]],
            ['id' => 'code00001', 'type' => 'code', 'data' => ['language' => 'text', 'code' => 'echo three']],
        ]);
        $sanitizer = new HtmlSanitizer();
        self::assertStringContainsString('Përshëndetje botë', $blocks->plainText($sanitizer));
        self::assertSame(4, $blocks->wordCount($sanitizer));
        self::assertSame(1, $blocks->readingMinutes($sanitizer));
    }
}
