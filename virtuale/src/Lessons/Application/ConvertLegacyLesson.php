<?php

declare(strict_types=1);

namespace KurseInformatike\Lessons\Application;

use KurseInformatike\Lessons\Domain\LessonBlockCollection;
use KurseInformatike\Shared\Security\HtmlSanitizer;
use PDO;

final class ConvertLegacyLesson
{
    public function __construct(
        private readonly PDO $pdo,
        private readonly LessonBlockValidator $validator,
        private readonly HtmlSanitizer $sanitizer,
    ) {
    }

    public function preview(string $markdown): LessonBlockCollection
    {
        $lines = preg_split('/\R/u', $markdown) ?: [];
        $blocks = [];
        $paragraph = [];
        $flush = static function () use (&$paragraph, &$blocks): void {
            $text = trim(implode("\n", $paragraph));
            if ($text !== '') {
                $blocks[] = ['type' => 'paragraph', 'data' => ['text' => nl2br(htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'))]];
            }
            $paragraph = [];
        };

        for ($i = 0, $count = count($lines); $i < $count; $i++) {
            $line = (string) $lines[$i];
            if (preg_match('/^```([a-zA-Z0-9_-]*)\s*$/', $line, $match)) {
                $flush();
                $code = [];
                while (++$i < $count && !preg_match('/^```\s*$/', (string) $lines[$i])) {
                    $code[] = (string) $lines[$i];
                }
                $blocks[] = ['type' => 'code', 'data' => ['language' => $match[1] ?: 'text', 'code' => implode("\n", $code)]];
                continue;
            }
            if (preg_match('/^(#{2,4})\s+(.+)$/u', $line, $match)) {
                $flush();
                $blocks[] = ['type' => 'heading', 'data' => ['level' => strlen($match[1]), 'text' => htmlspecialchars($match[2], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')]];
                continue;
            }
            if (preg_match('/^\s*((?:\d+\.)|[-*+])\s+(.+)$/u', $line, $match)) {
                $flush();
                $ordered = str_ends_with($match[1], '.');
                $items = [htmlspecialchars($match[2], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')];
                while ($i + 1 < $count && preg_match('/^\s*((?:\d+\.)|[-*+])\s+(.+)$/u', (string) $lines[$i + 1], $next)) {
                    if (str_ends_with($next[1], '.') !== $ordered) break;
                    $items[] = htmlspecialchars($next[2], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
                    $i++;
                }
                $blocks[] = ['type' => 'list', 'data' => ['style' => $ordered ? 'ordered' : 'unordered', 'items' => $items]];
                continue;
            }
            if (preg_match('/^>\s?(.*)$/u', $line, $match)) {
                $flush();
                $blocks[] = ['type' => 'quote', 'data' => ['text' => htmlspecialchars($match[1], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'), 'caption' => '', 'alignment' => 'left']];
                continue;
            }
            if (preg_match('/^\s*(?:---+|\*\*\*+)\s*$/', $line)) {
                $flush();
                $blocks[] = ['type' => 'delimiter', 'data' => []];
                continue;
            }
            if (preg_match('/!\[([^]]*)]\(([^)]+)\)/u', $line, $match)) {
                $flush();
                $blocks[] = ['type' => 'paragraph', 'data' => ['text' => htmlspecialchars('[Foto legacy: ' . ($match[1] ?: $match[2]) . ']', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')]];
                continue;
            }
            if (trim($line) === '') {
                $flush();
            } else {
                $paragraph[] = $line;
            }
        }
        $flush();
        return $this->validator->validate($blocks);
    }

    public function convert(int $lessonId): LessonBlockCollection
    {
        $query = $this->pdo->prepare('SELECT description, content_format FROM lessons WHERE id = ? FOR UPDATE');
        $this->pdo->beginTransaction();
        try {
            $query->execute([$lessonId]);
            $lesson = $query->fetch();
            if (!$lesson) throw new \DomainException('Leksioni nuk u gjet.');
            if (($lesson['content_format'] ?? 'legacy_markdown') === 'blocks_v1') {
                throw new \DomainException('Leksioni është konvertuar tashmë.');
            }
            $blocks = $this->preview((string) ($lesson['description'] ?? ''));
            $this->pdo->prepare('DELETE FROM lesson_blocks WHERE lesson_id = ?')->execute([$lessonId]);
            $insert = $this->pdo->prepare('INSERT INTO lesson_blocks (lesson_id, block_uid, block_type, position, data_json) VALUES (?,?,?,?,?)');
            foreach ($blocks as $block) {
                $insert->execute([$lessonId, $block->uid, $block->type, $block->position, json_encode($block->data, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)]);
            }
            $update = $this->pdo->prepare("UPDATE lessons SET legacy_description = description, description = ?, content_format = 'blocks_v1', content_version = 1, updated_at = NOW() WHERE id = ?");
            $update->execute([$blocks->plainText($this->sanitizer), $lessonId]);
            $this->pdo->commit();
            return $blocks;
        } catch (\Throwable $exception) {
            if ($this->pdo->inTransaction()) $this->pdo->rollBack();
            throw $exception;
        }
    }
}
