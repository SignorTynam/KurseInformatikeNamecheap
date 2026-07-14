<?php

declare(strict_types=1);

namespace KurseInformatike\Lessons\Domain;

final readonly class LessonBlock
{
    public function __construct(
        public string $uid,
        public string $type,
        public int $position,
        public array $data,
    ) {
    }

    public function toArray(): array
    {
        return ['id' => $this->uid, 'type' => $this->type, 'data' => $this->data];
    }
}
