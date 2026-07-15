<?php

declare(strict_types=1);

namespace KurseInformatike\Tests\Unit;

use KurseInformatike\Shared\Security\HtmlSanitizer;
use PHPUnit\Framework\TestCase;

final class HtmlSanitizerTest extends TestCase
{
    public function testAllowsFormattingAndHttpLinksOnly(): void
    {
        $sanitizer = new HtmlSanitizer();
        $safe = $sanitizer->inline('<b onclick="x">Bold</b><a href="https://example.com" target="x">Link</a><a href="javascript:alert(1)">Bad</a>');
        self::assertStringContainsString('<b>Bold</b>', $safe);
        self::assertStringContainsString('rel="noopener noreferrer"', $safe);
        self::assertStringNotContainsString('onclick', $safe);
        self::assertStringNotContainsString('javascript:', $safe);
        self::assertTrue($sanitizer->isHttpUrl('http://example.com'));
        self::assertFalse($sanitizer->isHttpUrl('data:text/html,x'));
    }

    public function testSanitizesAllowedDescendantsInsideUnknownTags(): void
    {
        $safe = (new HtmlSanitizer())->inline('<span><a href="javascript:alert(1)">test</a></span>');
        self::assertStringNotContainsString('javascript:', $safe);
    }
}
