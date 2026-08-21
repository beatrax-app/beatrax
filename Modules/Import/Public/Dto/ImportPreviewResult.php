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
     * @param  string|null  $fileFailureReason  An `ImportFailureReason` backing value when the file itself failed, as opposed to a row of it. Parsing stopped where it was raised, so rows past that point are absent from $rows rather than present and failed. It is not a row and must never be rendered as one.
     * @param  string|null  $fileFailureDetail  The parser's own words, carried only from an exception that declares it names no user data.
     * @param  int|null  $fileFailureRowIndex  Where the read stopped, as the index of the row it never produced. Null when it stopped before producing any. Derived from the rows themselves rather than from the message, so it survives an exception whose text cannot be shown.
     */
    public function __construct(
        public readonly int $importRunId,
        public readonly array $rows,
        public readonly array $accountsToName,
        public readonly int $enrichedCount = 0,
        public readonly ?string $fileFailureReason = null,
        public readonly ?string $fileFailureDetail = null,
        public readonly ?int $fileFailureRowIndex = null,
    ) {}
}
