<?php

declare(strict_types=1);

namespace KurseInformatike\Lessons\Presentation;

use KurseInformatike\Lessons\Domain\LessonBlock;
use KurseInformatike\Lessons\Domain\LessonBlockCollection;
use KurseInformatike\Shared\Security\HtmlSanitizer;

final class LessonBlockRenderer
{
    private HeadingAnchorGenerator $anchors;

    public function __construct(
        private readonly HtmlSanitizer $sanitizer,
        private readonly string $mediaUrl = '/virtuale/lesson_media.php?id=',
    ) {
        $this->anchors = new HeadingAnchorGenerator();
    }

    public function render(LessonBlockCollection $blocks): string
    {
        $this->anchors = new HeadingAnchorGenerator();
        $html = '';
        foreach ($blocks as $block) {
            $html .= $this->renderBlock($block);
        }
        return $html;
    }

    /** @return list<array{level:int,text:string,anchor:string}> */
    public function outline(LessonBlockCollection $blocks): array
    {
        $generator = new HeadingAnchorGenerator();
        $outline = [];
        foreach ($blocks as $block) {
            if ($block->type !== 'heading') {
                continue;
            }
            $text = $this->sanitizer->plainText((string) ($block->data['text'] ?? ''));
            $outline[] = [
                'level' => (int) ($block->data['level'] ?? 2),
                'text' => $text,
                'anchor' => $generator->next($text),
            ];
        }
        return $outline;
    }

    private function renderBlock(LessonBlock $block): string
    {
        $data = $block->data;
        $sanitizer = $this->sanitizer;
        $mediaUrl = $this->mediaUrl;
        $anchor = $block->type === 'heading'
            ? $this->anchors->next($sanitizer->plainText((string) ($data['text'] ?? '')))
            : '';
        ob_start();
        require __DIR__ . '/blocks/' . $block->type . '.php';
        return (string) ob_get_clean();
    }
}
