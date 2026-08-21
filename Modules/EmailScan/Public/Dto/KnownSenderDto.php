<?php

declare(strict_types=1);

namespace Modules\EmailScan\Public\Dto;

use Spatie\LaravelData\Data;

// A null $userId is a system-seeded pattern; the UI reads $source to tell
// "shipped seed" from "added by you".
final class KnownSenderDto extends Data
{
    public function __construct(
        public readonly int $id,
        public readonly ?int $userId,
        public readonly string $emailPattern,
        public readonly string $label,
        public readonly string $source,
    ) {}
}
