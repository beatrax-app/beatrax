<?php

declare(strict_types=1);

namespace Modules\Import\Internal\Dto;

use Modules\Import\Internal\Enums\ConfirmRefusal;
use Modules\Import\Public\Dto\PreviewRowDto;
use Modules\Import\Public\Enums\ImportFailureReason;
use Spatie\LaravelData\Data;

/**
 * @link ../../../../.docs/architecture/ingestion-pipeline.md#preview-vs-confirm
 */
final class PreviewSectionSummary extends Data
{
    /**
     * @param  list<PreviewRowDto>  $sampleRows  The head of the run's showable rows, error rows excluded, in the order the file yielded them.
     * @param  bool  $sampleComplete  True when $sampleRows already holds every showable row, so a request for more is answered without reading the rows back.
     * @param  ImportFailureReason|null  $firstRowErrorReason  The reason carried by the first failed row that names one, which is the reason a section reports.
     * @param  ConfirmRefusal|null  $confirmRefusal  Why ConfirmImport would refuse this run, so a screen offering it reads the confirm's own rule rather than a second copy of part of it.
     */
    public function __construct(
        public readonly int $rowCount,
        public readonly int $committableCount,
        public readonly int $duplicateCount,
        public readonly int $errorCount,
        public readonly array $sampleRows,
        public readonly bool $sampleComplete,
        public readonly ?ImportFailureReason $firstRowErrorReason = null,
        public readonly ?ImportFailureReason $fileFailureReason = null,
        public readonly ?string $fileFailureDetail = null,
        public readonly ?ConfirmRefusal $confirmRefusal = null,
    ) {}
}
