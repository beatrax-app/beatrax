<?php

declare(strict_types=1);

namespace Modules\Import\Public\Dto;

use Spatie\LaravelData\Data;

/**
 * @link ../../../../.docs/features/import/architecture.md#consolidated-preview-multi-run-commit
 *
 * `status` is one of `ready`, `empty`, `error`, `filtered`:
 *      - `ready` — at least one cached preview row is available.
 *      - `empty` — every contributing run cached an empty preview
 *        (legitimate: a statement whose every row was already in the
 *        ledger before this upload).
 *      - `error` — at least one contributing run's preview cache is
 *        missing / expired. The wizard surfaces a re-upload prompt.
 *      - `filtered` — reserved for future per-section opt-outs; the
 *        current query never emits it but the field exists so the
 *        UI contract stays forwards-compatible.
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
        // The parser's own words when status is `error` and the cause was a
        // failed parse rather than a lost cache. The app writes a genuinely
        // useful message here — which format it expected, and what to
        // re-download — and it was being logged and then thrown away.
        public readonly ?string $error = null,
    ) {}
}
