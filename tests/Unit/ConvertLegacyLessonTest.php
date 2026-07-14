<?php

declare(strict_types=1);

namespace KurseInformatike\Tests\Unit;

use KurseInformatike\Lessons\Application\ConvertLegacyLesson;
use KurseInformatike\Lessons\Application\LessonBlockValidator;
use KurseInformatike\Shared\Security\HtmlSanitizer;
use PDO;
use PHPUnit\Framework\TestCase;

final class ConvertLegacyLessonTest extends TestCase
{
    public function testRecognizesCoreLegacyMarkdownStructures(): void
    {
        $sanitizer = new HtmlSanitizer();
        $converter = new ConvertLegacyLesson(
            $this->createStub(PDO::class),
            new LessonBlockValidator($sanitizer),
            $sanitizer
        );
        $blocks = $converter->preview("## Titull\n\nParagraf\n\n- Një\n- Dy\n\n> Citim\n\n```php\necho 1;\n```\n\n---");
        self::assertSame(['heading', 'paragraph', 'list', 'quote', 'code', 'delimiter'], array_map(static fn ($block) => $block->type, $blocks->all()));
    }
}
