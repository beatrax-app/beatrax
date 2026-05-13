<?php

declare(strict_types=1);

namespace Modules\Import\Public\Dto;

use Spatie\LaravelData\Data;

/**
 * Buffered work item produced by the Import preview pipeline and replayed
 * by the enrichment writer during ConfirmImport. Each entry corresponds
 * to one existing transactions row that will be UPDATE-d with a stronger
 * source_ref and a new provenance entry appended to enriched_from.
 */
final class PendingEnrichment extends Data
{
    public function __construct(
        public readonly int $existingTransactionId,
        public readonly string $newSourceRef,
        public readonly int $importRunId,
        public readonly string $sourceFormat,
    ) {}
}
