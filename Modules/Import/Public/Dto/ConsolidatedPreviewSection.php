<?php

declare(strict_types=1);

namespace Modules\Import\Public\Dto;

use Modules\Import\Public\Enums\PreviewSectionStatus;
use Spatie\LaravelData\Data;

final class ConsolidatedPreviewSection extends Data
{
    /**
     * @param  list<int>  $importRunIds  The runs this section would commit, which is not every run it was built from: one whose file stopped being read is refused by ConfirmImport, so it is left out here rather than offered and then failed.
     * @param  list<PreviewRowDto>  $sampleRows
     */
    public function __construct(
        public readonly string $sourceFormat,
        public readonly array $importRunIds,
        public readonly int $totalRows,
        public readonly array $sampleRows,
        public readonly PreviewSectionStatus $status,
        // The parser's own words on a failed parse — which format it
        // expected, what to re-download — as opposed to a lost cache, which
        // is also `error` but has nothing to say.
        public readonly ?string $error = null,
    ) {}
}
