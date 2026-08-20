<?php

declare(strict_types=1);

namespace Modules\Import\Public\Dto;

use Spatie\LaravelData\Data;

/**
 * @link ../../../../.docs/features/import/architecture.md#consolidated-preview-multi-run-commit
 */
final class ConsolidatedPreviewSection extends Data
{
    /**
     * @param  list<int>  $importRunIds
     * @param  list<PreviewRowDto>  $sampleRows
     */
    public function __construct(
        public readonly string $sourceFormat,
        public readonly array $importRunIds,
        public readonly int $totalRows,
        public readonly array $sampleRows,
        public readonly string $status,
        // The parser's own words on a failed parse — which format it
        // expected, what to re-download — as opposed to a lost cache, which
        // is also `error` but has nothing to say.
        public readonly ?string $error = null,
    ) {}
}
