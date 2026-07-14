<?php

declare(strict_types=1);

namespace KurseInformatike\Lessons\Domain;

enum LessonContentFormat: string
{
    case LegacyMarkdown = 'legacy_markdown';
    case BlocksV1 = 'blocks_v1';
}
