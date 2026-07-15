<?php

declare(strict_types=1);

namespace KurseInformatike\Lessons\Presentation;

final class HeadingAnchorGenerator
{
    /** @var array<string, int> */
    private array $seen = [];

    public function next(string $text): string
    {
        $plain = html_entity_decode(strip_tags($text), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $plain = strtr($plain, ['ë' => 'e', 'Ë' => 'E', 'ç' => 'c', 'Ç' => 'C']);
        $transliterated = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $plain) ?: $plain;
        $slug = strtolower((string) preg_replace('/[^a-zA-Z0-9]+/', '-', $transliterated));
        $slug = trim($slug, '-');
        $slug = $slug !== '' ? $slug : 'seksion';
        $this->seen[$slug] = ($this->seen[$slug] ?? 0) + 1;
        return $this->seen[$slug] === 1 ? $slug : $slug . '-' . $this->seen[$slug];
    }
}
