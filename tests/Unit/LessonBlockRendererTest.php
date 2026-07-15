<?php

declare(strict_types=1);

namespace KurseInformatike\Tests\Unit;

use KurseInformatike\Lessons\Application\LessonBlockValidator;
use KurseInformatike\Lessons\Presentation\HeadingAnchorGenerator;
use KurseInformatike\Lessons\Presentation\LessonBlockRenderer;
use KurseInformatike\Shared\Security\HtmlSanitizer;
use PHPUnit\Framework\TestCase;

final class LessonBlockRendererTest extends TestCase
{
    public function testRendererEscapesCodeAndBuildsMediaUrlFromId(): void
    {
        $sanitizer = new HtmlSanitizer();
        $blocks = (new LessonBlockValidator($sanitizer, static fn (int $id): bool => true))->validate([
            ['id' => 'heading01', 'type' => 'heading', 'data' => ['text' => 'Një titull', 'level' => 2]],
            ['id' => 'image0001', 'type' => 'image', 'data' => ['mediaId' => 42, 'alt' => '"><script>x</script>']],
            ['id' => 'code00001', 'type' => 'code', 'data' => ['language' => 'php', 'code' => '<script>alert(1)</script>']],
        ]);
        $html = (new LessonBlockRenderer($sanitizer))->render($blocks);
        self::assertStringContainsString('id="nje-titull"', $html);
        self::assertStringContainsString('/virtuale/lesson_media.php?id=42', $html);
        self::assertStringContainsString('&lt;script&gt;alert(1)&lt;/script&gt;', $html);
        self::assertStringNotContainsString('<script>', $html);
    }

    public function testAnchorsAreDeterministicAndUnique(): void
    {
        $anchors = new HeadingAnchorGenerator();
        self::assertSame('pershendetje-bote', $anchors->next('Përshëndetje botë'));
        self::assertSame('pershendetje-bote-2', $anchors->next('Përshëndetje botë'));
    }
}
