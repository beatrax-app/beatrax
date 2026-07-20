<?php

declare(strict_types=1);

namespace Modules\Import\Public\Dto;

use Spatie\LaravelData\Data;

/**
 * @link ../../../../.docs/architecture/ingestion-pipeline.md#preview-vs-confirm
 */
final class ImportPreviewResult extends Data
{
    /**
     * @param  array<int, PreviewRowDto>  $rows
     * @param  array<int, UnknownIban>  $accountsToName
     */
    public function __construct(
        public readonly int $importRunId,
        public readonly array $rows,
        public readonly array $accountsToName,
        public readonly int $enrichedCount = 0,
    ) {}
}
