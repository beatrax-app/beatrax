<?php

declare(strict_types=1);

namespace Modules\Import\Public\Dto;

/**
 * FingerprintDisposition variant returned when an existing transactions
 * row matches the fingerprint but the incoming source_ref is stronger
 * than the stored one (an EndToEndId beats a transaction-reference, which
 * beats a row-index fallback). The Import pipeline updates the existing
 * row with the new source_ref and appends a provenance entry to
 * enriched_from rather than inserting a duplicate canonical.
 */
final class EnrichedDisposition extends FingerprintDisposition
{
    public function __construct(
        public readonly int $existingTransactionId,
        public readonly ?string $fromSourceRef,
        public readonly string $toSourceRef,
    ) {}

    public function status(): string
    {
        return 'enriched';
    }
}
