<?php

declare(strict_types=1);

namespace KurseInformatike\Lessons\Domain;

use ArrayIterator;
use Countable;
use IteratorAggregate;
use KurseInformatike\Shared\Security\HtmlSanitizer;
use Traversable;

/** @implements IteratorAggregate<int, LessonBlock> */
final class LessonBlockCollection implements Countable, IteratorAggregate
{
    /** @param list<LessonBlock> $blocks */
    public function __construct(private readonly array $blocks)
    {
    }

    /** @return Traversable<int, LessonBlock> */
    public function getIterator(): Traversable
    {
        return new ArrayIterator($this->blocks);
    }

    public function count(): int
    {
        return count($this->blocks);
    }

    /** @return list<array{id:string,type:string,data:array}> */
    public function toEditorData(): array
    {
        return array_map(static fn (LessonBlock $block): array => $block->toArray(), $this->blocks);
    }

    /** @return list<LessonBlock> */
    public function all(): array
    {
        return $this->blocks;
    }

    public function plainText(HtmlSanitizer $sanitizer, bool $includeCode = true): string
    {
        $parts = [];
        foreach ($this->blocks as $block) {
            $data = $block->data;
            switch ($block->type) {
                case 'paragraph':
                case 'heading':
                    $parts[] = $sanitizer->plainText((string) ($data['text'] ?? ''));
                    break;
                case 'list':
                    foreach ($data['items'] ?? [] as $item) {
                        $parts[] = $sanitizer->plainText((string) $item);
                    }
                    break;
                case 'table':
                    foreach ($data['content'] ?? [] as $row) {
                        foreach ($row as $cell) {
                            $parts[] = $sanitizer->plainText((string) $cell);
                        }
                    }
                    break;
                case 'image':
                    $parts[] = (string) ($data['caption'] ?? '');
                    $parts[] = (string) ($data['alt'] ?? '');
                    break;
                case 'quote':
                    $parts[] = $sanitizer->plainText((string) ($data['text'] ?? ''));
                    $parts[] = (string) ($data['caption'] ?? '');
                    break;
                case 'callout':
                    $parts[] = (string) ($data['title'] ?? '');
                    $parts[] = $sanitizer->plainText((string) ($data['text'] ?? ''));
                    break;
                case 'code':
                    if ($includeCode) {
                        $parts[] = (string) ($data['code'] ?? '');
                    }
                    break;
            }
        }

        $text = implode("\n", array_filter(array_map('trim', $parts), static fn (string $part): bool => $part !== ''));
        return trim((string) preg_replace('/[ \t]+/u', ' ', $text));
    }

    public function wordCount(HtmlSanitizer $sanitizer): int
    {
        $text = $this->plainText($sanitizer, false);
        if ($text === '') {
            return 0;
        }
        preg_match_all('/[\p{L}\p{N}]+(?:[\'’\-][\p{L}\p{N}]+)*/u', $text, $matches);
        return count($matches[0]);
    }

    public function readingMinutes(HtmlSanitizer $sanitizer, int $wordsPerMinute = 180): int
    {
        $words = $this->wordCount($sanitizer);
        return $words > 0 ? max(1, (int) ceil($words / max(1, $wordsPerMinute))) : 0;
    }
}
