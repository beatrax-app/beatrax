<?php

declare(strict_types=1);

namespace Modules\EmailScan\Public\Dto;

use Spatie\LaravelData\Data;

/**
 * Single row from the curated known_senders list.
 *
 * `userId` is nullable so the same DTO carries both system-seeded
 * patterns (PayPal / ICS Cards / Google Play, user_id = NULL) and
 * per-user additions. `source` mirrors the column and is the basis
 * for the UI's "shipped seed" vs "added by you" distinction.
 *
 * The downstream consumer (Phase 7 parser registry) maps the
 * email_pattern + label pair to a matcher key; this DTO does not
 * carry the matcher key column because it does not exist yet in the
 * Phase 6 schema.
 */
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
