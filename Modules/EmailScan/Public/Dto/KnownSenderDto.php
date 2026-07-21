<?php

declare(strict_types=1);

namespace Modules\EmailScan\Public\Dto;

use Spatie\LaravelData\Data;

// Single row from the curated known_senders list. userId is nullable
// so the same DTO carries both system-seeded patterns (user_id =
// NULL) and per-user additions; source mirrors the column and backs
// the UI's "shipped seed" vs "added by you" distinction.
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
