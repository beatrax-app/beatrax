<?php

declare(strict_types=1);

namespace Modules\Import\Public\Dto;

use Modules\Import\Public\Enums\ImportFailureReason;
use Modules\Import\Public\Enums\PreviewRowStatus;
use Spatie\LaravelData\Data;

/**
 * @link ../../../../.docs/architecture/ingestion-pipeline.md#preview-vs-confirm
 */
final class ImportPreviewResult extends Data
{
    /**
     * @param  array<int, PreviewRowDto>  $rows
     * @param  array<int, UnknownIban>  $accountsToName
     * @param  ImportFailureReason|null  $fileFailureReason  Set when the file itself failed, as opposed to a row of it. Parsing stopped where it was raised, so rows past that point are absent from $rows rather than present and failed. It is not a row and must never be rendered as one.
     * @param  string|null  $fileFailureDetail  The parser's own words, carried only from an exception that declares it names no user data.
     * @param  int|null  $fileFailureRowIndex  Where the read stopped, as the index of the row it never produced. Null when it stopped before producing any. Derived from the rows themselves rather than from the message, so it survives an exception whose text cannot be shown.
     * @param  int|null  $totalRowCount  How many rows the run has, which $rows is a window onto. Null when the result was built from its rows and the two are the same number.
     */
    public function __construct(
        public readonly int $importRunId,
        public readonly array $rows,
        public readonly array $accountsToName,
        public readonly int $enrichedCount = 0,
        public readonly ?ImportFailureReason $fileFailureReason = null,
        public readonly ?string $fileFailureDetail = null,
        public readonly ?int $fileFailureRowIndex = null,
        public readonly ?int $totalRowCount = null,
        public readonly ?int $errorRowCount = null,
        public readonly ?int $duplicateRowCount = null,
    ) {}

    // The counts are read off the head, which counted them once as the rows
    // went past. Falling back to the window is what a result built directly
    // from its own rows means, and there the window IS the run.
    public function totalRows(): int
    {
        return $this->totalRowCount ?? count($this->rows);
    }

    public function errorRows(): int
    {
        return $this->errorRowCount ?? $this->countWindow(PreviewRowStatus::Error);
    }

    public function duplicateRows(): int
    {
        return $this->duplicateRowCount ?? $this->countWindow(PreviewRowStatus::Duplicate);
    }

    public function importableRows(): int
    {
        return $this->totalRows() - $this->errorRows();
    }

    // Anything that would walk $rows to answer a question about the run has to
    // ask this first: past PreviewCache::RESULT_ROW_WINDOW the window is a page.
    public function rowsAreComplete(): bool
    {
        return count($this->rows) === $this->totalRows();
    }

    private function countWindow(PreviewRowStatus $status): int
    {
        $found = 0;

        foreach ($this->rows as $row) {
            if ($row->status === $status) {
                $found++;
            }
        }

        return $found;
    }
}
