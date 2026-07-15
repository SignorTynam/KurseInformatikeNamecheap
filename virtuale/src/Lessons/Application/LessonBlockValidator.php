<?php

declare(strict_types=1);

namespace KurseInformatike\Lessons\Application;

use KurseInformatike\Lessons\Domain\LessonBlock;
use KurseInformatike\Lessons\Domain\LessonBlockCollection;
use KurseInformatike\Shared\Security\HtmlSanitizer;

final class LessonBlockValidator
{
    public const MAX_BLOCKS = 200;
    public const MAX_JSON_BYTES = 1_048_576;
    private const TYPES = ['paragraph', 'heading', 'list', 'table', 'image', 'quote', 'callout', 'code', 'delimiter'];
    private const LANGUAGES = ['text', 'bash', 'css', 'html', 'javascript', 'json', 'php', 'python', 'sql', 'typescript', 'xml'];

    /** @var null|callable(int):bool */
    private $mediaAuthorizer;

    /** @param null|callable(int):bool $mediaAuthorizer */
    public function __construct(
        private readonly HtmlSanitizer $sanitizer,
        ?callable $mediaAuthorizer = null,
    ) {
        $this->mediaAuthorizer = $mediaAuthorizer;
    }

    public function validateJson(string $json): LessonBlockCollection
    {
        if (strlen($json) > self::MAX_JSON_BYTES) {
            throw new LessonValidationException(['document' => ['Përmbajtja tejkalon kufirin 1 MB.']]);
        }
        try {
            $decoded = json_decode($json, true, 20, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw new LessonValidationException(['document' => ['Formati JSON nuk është i vlefshëm.']]);
        }
        $blocks = is_array($decoded) && isset($decoded['blocks']) ? $decoded['blocks'] : $decoded;
        if (!is_array($blocks)) {
            throw new LessonValidationException(['document' => ['Lista e blloqeve mungon.']]);
        }
        return $this->validate($blocks);
    }

    public function validate(array $input): LessonBlockCollection
    {
        if (count($input) > self::MAX_BLOCKS) {
            throw new LessonValidationException(['document' => ['Lejohen maksimumi 200 blloqe.']]);
        }

        $errors = [];
        $seen = [];
        $blocks = [];
        foreach (array_values($input) as $position => $raw) {
            if (!is_array($raw)) {
                $errors[$position][] = 'Blloku nuk është objekt i vlefshëm.';
                continue;
            }
            $type = (string) ($raw['type'] ?? '');
            if ($type === 'header') {
                $type = 'heading';
            }
            if (!in_array($type, self::TYPES, true)) {
                $errors[$position][] = 'Lloji i bllokut nuk lejohet.';
                continue;
            }
            $uid = (string) ($raw['id'] ?? $raw['block_uid'] ?? '');
            if ($uid === '') {
                $uid = bin2hex(random_bytes(12));
            }
            if (!preg_match('/^[A-Za-z0-9_-]{8,64}$/', $uid)) {
                $errors[$position][] = 'Identifikuesi i bllokut nuk është i vlefshëm.';
            } elseif (isset($seen[$uid])) {
                $errors[$position][] = 'Identifikuesi i bllokut është i dyfishtë.';
            }
            $seen[$uid] = true;

            $data = is_array($raw['data'] ?? null) ? $raw['data'] : [];
            $clean = $this->cleanData($type, $data, $position, $errors);
            $blocks[] = new LessonBlock($uid, $type, $position, $clean);
        }

        if ($errors !== []) {
            throw new LessonValidationException($errors);
        }
        return new LessonBlockCollection($blocks);
    }

    /** @param array<int|string, list<string>> $errors */
    private function cleanData(string $type, array $data, int $position, array &$errors): array
    {
        return match ($type) {
            'paragraph' => $this->paragraph($data, $position, $errors),
            'heading' => $this->heading($data, $position, $errors),
            'list' => $this->listBlock($data, $position, $errors),
            'table' => $this->table($data, $position, $errors),
            'image' => $this->image($data, $position, $errors),
            'quote' => $this->quote($data, $position, $errors),
            'callout' => $this->callout($data, $position, $errors),
            'code' => $this->code($data, $position, $errors),
            'delimiter' => [],
        };
    }

    private function paragraph(array $data, int $position, array &$errors): array
    {
        $text = $this->sanitizer->inline((string) ($data['text'] ?? ''));
        $this->limit($text, 20_000, 'Teksti është shumë i gjatë.', $position, $errors);
        return ['text' => $text];
    }

    private function heading(array $data, int $position, array &$errors): array
    {
        $text = $this->sanitizer->inline((string) ($data['text'] ?? ''));
        $level = (int) ($data['level'] ?? 2);
        if (!in_array($level, [2, 3, 4], true)) {
            $errors[$position][] = 'Niveli i titullit duhet të jetë 2, 3 ose 4.';
        }
        $this->limit($text, 500, 'Titulli është shumë i gjatë.', $position, $errors);
        return ['text' => $text, 'level' => $level];
    }

    private function listBlock(array $data, int $position, array &$errors): array
    {
        $style = (string) ($data['style'] ?? 'unordered');
        if (!in_array($style, ['ordered', 'unordered', 'checklist'], true)) {
            $errors[$position][] = 'Stili i listës nuk lejohet.';
        }
        $items = is_array($data['items'] ?? null) ? array_values($data['items']) : [];
        if (count($items) > 100) {
            $errors[$position][] = 'Lista lejon maksimumi 100 elemente.';
            $items = array_slice($items, 0, 100);
        }
        $clean = [];
        foreach ($items as $item) {
            if (is_array($item)) {
                $item = $item['content'] ?? $item['text'] ?? '';
            }
            $value = $this->sanitizer->inline((string) $item);
            $this->limit($value, 2_000, 'Një element i listës është shumë i gjatë.', $position, $errors);
            $clean[] = $value;
        }
        return ['style' => $style, 'items' => $clean];
    }

    private function table(array $data, int $position, array &$errors): array
    {
        $rows = is_array($data['content'] ?? null) ? array_values($data['content']) : [];
        if (count($rows) > 30) {
            $errors[$position][] = 'Tabela lejon maksimumi 30 rreshta.';
            $rows = array_slice($rows, 0, 30);
        }
        $clean = [];
        foreach ($rows as $row) {
            if (!is_array($row)) {
                $errors[$position][] = 'Një rresht i tabelës nuk është i vlefshëm.';
                continue;
            }
            if (count($row) > 20) {
                $errors[$position][] = 'Tabela lejon maksimumi 20 kolona.';
                $row = array_slice($row, 0, 20);
            }
            $cleanRow = [];
            foreach ($row as $cell) {
                $value = $this->sanitizer->inline((string) $cell);
                $this->limit($value, 500, 'Një qelizë tejkalon 500 karaktere.', $position, $errors);
                $cleanRow[] = $value;
            }
            $clean[] = $cleanRow;
        }
        return ['withHeadings' => (bool) ($data['withHeadings'] ?? false), 'content' => $clean];
    }

    private function image(array $data, int $position, array &$errors): array
    {
        $mediaId = (int) ($data['mediaId'] ?? ($data['file']['mediaId'] ?? 0));
        if ($mediaId < 1 || ($this->mediaAuthorizer !== null && !($this->mediaAuthorizer)($mediaId))) {
            $errors[$position][] = 'Fotoja nuk ekziston ose nuk ju përket.';
        }
        $alt = trim((string) ($data['alt'] ?? ''));
        if ($alt === '') {
            $errors[$position][] = 'Teksti alternativ i fotos është i detyrueshëm.';
        }
        $this->limit($alt, 500, 'Teksti alternativ është shumë i gjatë.', $position, $errors);
        $caption = trim((string) ($data['caption'] ?? ''));
        $this->limit($caption, 1_000, 'Përshkrimi i fotos është shumë i gjatë.', $position, $errors);
        return [
            'mediaId' => $mediaId,
            'caption' => $caption,
            'alt' => $alt,
            'withBorder' => (bool) ($data['withBorder'] ?? false),
            'withBackground' => (bool) ($data['withBackground'] ?? false),
            'stretched' => (bool) ($data['stretched'] ?? false),
        ];
    }

    private function quote(array $data, int $position, array &$errors): array
    {
        $text = $this->sanitizer->inline((string) ($data['text'] ?? ''));
        $caption = trim((string) ($data['caption'] ?? ''));
        $alignment = in_array(($data['alignment'] ?? ''), ['left', 'center'], true) ? (string) $data['alignment'] : 'left';
        $this->limit($text, 10_000, 'Citimi është shumë i gjatë.', $position, $errors);
        $this->limit($caption, 500, 'Burimi i citimit është shumë i gjatë.', $position, $errors);
        return compact('text', 'caption', 'alignment');
    }

    private function callout(array $data, int $position, array &$errors): array
    {
        $variant = (string) ($data['variant'] ?? 'info');
        if (!in_array($variant, ['info', 'success', 'warning', 'danger', 'tip'], true)) {
            $errors[$position][] = 'Varianti i njoftimit nuk lejohet.';
        }
        $title = trim((string) ($data['title'] ?? ''));
        $text = $this->sanitizer->inline((string) ($data['text'] ?? ''));
        $this->limit($title, 500, 'Titulli i njoftimit është shumë i gjatë.', $position, $errors);
        $this->limit($text, 10_000, 'Njoftimi është shumë i gjatë.', $position, $errors);
        return compact('variant', 'title', 'text');
    }

    private function code(array $data, int $position, array &$errors): array
    {
        $language = strtolower(trim((string) ($data['language'] ?? 'text')));
        if (!in_array($language, self::LANGUAGES, true)) {
            $language = 'text';
        }
        $code = (string) ($data['code'] ?? '');
        $this->limit($code, 100_000, 'Blloku i kodit është shumë i gjatë.', $position, $errors);
        return compact('language', 'code');
    }

    private function limit(string $value, int $max, string $message, int $position, array &$errors): void
    {
        if (mb_strlen($value, 'UTF-8') > $max) {
            $errors[$position][] = $message;
        }
    }
}
