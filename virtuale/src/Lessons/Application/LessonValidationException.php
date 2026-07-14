<?php

declare(strict_types=1);

namespace KurseInformatike\Lessons\Application;

final class LessonValidationException extends \DomainException
{
    /** @param array<int|string, list<string>> $errors */
    public function __construct(public readonly array $errors)
    {
        parent::__construct('Përmbajtja e leksionit përmban gabime.');
    }
}
