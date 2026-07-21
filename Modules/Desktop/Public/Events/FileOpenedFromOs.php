<?php

declare(strict_types=1);

namespace Modules\Desktop\Public\Events;

// A dumb DTO — validation (file exists, extension allow-listed)
// happens at the FileOpenIntake emission boundary, never re-checked
// here.
final readonly class FileOpenedFromOs
{
    public function __construct(
        public string $path,
        public string $extension,
    ) {}
}
