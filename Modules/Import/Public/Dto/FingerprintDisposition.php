<?php

declare(strict_types=1);

namespace Modules\Import\Public\Dto;

use Modules\Import\Public\Enums\PreviewRowStatus;
use Spatie\LaravelData\Data;

/**
 * @link ../../../../.docs/architecture/ingestion-pipeline.md#8-fingerprint-fingerprintstage
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
     * @param  array<array-key, array{stored: mixed, incoming: mixed}>  $conflictingFields
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

    abstract public function status(): PreviewRowStatus;

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
