<?php

declare(strict_types=1);

namespace Modules\Import\Public\Dto;

use Spatie\LaravelData\Data;

/**
 * Outcome of the preview phase. `accountsToName` is non-empty when at least
 * one source row references an IBAN the user has not yet linked to an
 * Account — the wizard renders an inline naming prompt before showing the
 * preview table. `enrichedCount` summarises preview rows whose status is
 * `enriched` so the wizard can render a four-state count line before the
 * user confirms.
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
