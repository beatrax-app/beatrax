<?php

declare(strict_types=1);

namespace Modules\Import\Public\Dto;

use Spatie\LaravelData\Data;

/**
 * Discriminated outcome of looking up an incoming canonical transaction's
 * fingerprint against the existing transactions table.
 *
 * Three concrete variants:
 *  - NewRowDisposition    — no existing row; the canonical will be
 *                           inserted by the recorder.
 *  - DuplicateDisposition — a row already exists and the incoming
 *                           source_ref is no stronger; the canonical is
 *                           discarded.
 *  - EnrichedDisposition  — a row already exists but the incoming
 *                           source_ref is stronger (EndToEndId beats
 *                           everything); the existing row will be
 *                           UPDATE-d to write the new source_ref and
 *                           append a provenance entry to enriched_from.
 */
abstract class FingerprintDisposition extends Data
{
    public static function newRow(): NewRowDisposition
    {
        return new NewRowDisposition;
    }

    public static function duplicate(): DuplicateDisposition
    {
        return new DuplicateDisposition;
    }

    /**
     * @param  array<string, array{stored: mixed, incoming: mixed}>  $conflictingFields
     */
    public static function enriched(int $existingId, ?string $fromSourceRef, string $toSourceRef, array $conflictingFields = []): EnrichedDisposition
    {
        return new EnrichedDisposition(
            existingTransactionId: $existingId,
            fromSourceRef: $fromSourceRef,
            toSourceRef: $toSourceRef,
            conflictingFields: $conflictingFields,
        );
    }

    abstract public function status(): string;

    public function isNew(): bool
    {
        return $this instanceof NewRowDisposition;
    }

    public function isDuplicate(): bool
    {
        return $this instanceof DuplicateDisposition;
    }

    public function isEnriched(): bool
    {
        return $this instanceof EnrichedDisposition;
    }
}
