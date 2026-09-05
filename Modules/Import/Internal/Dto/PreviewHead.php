<?php

declare(strict_types=1);

namespace Modules\Import\Internal\Dto;

use Modules\Import\Internal\Enums\ConfirmRefusal;
use Modules\Import\Public\Dto\PreviewRowDto;
use Modules\Import\Public\Dto\UnknownIban;
use Modules\Import\Public\Enums\ImportFailureReason;
use Modules\Receipts\Public\Dto\CapturedReceipt;
use Spatie\LaravelData\Data;

// Everything about a preview whose size does not grow with the file: counts,
// the accounts still to name, how it failed, a bounded sample and a bounded
// issue list. The rows live in chunks beside it -- held whole, a 7 MB
// statement's 27,777 of them were 226 MB to build and 195 MB more to write.
/**
 * @link ../../../../.docs/architecture/ingestion-pipeline.md#preview-vs-confirm
 */
final class PreviewHead extends Data
{
    /**
     * @param  list<UnknownIban>  $accountsToName
     * @param  list<PreviewRowDto>  $sampleRows  The head of the run's showable rows, error rows excluded, in the order the file yielded them.
     * @param  list<array{kind: string, row: int|null, reason: string|null, detail: string|null}>  $rowIssues  Stored-shape issues, capped per kind as they are counted, so confirming never reads the rows back to find them.
     * @param  ImportFailureReason|null  $firstRowErrorReason  The reason carried by the first failed row that names one, which is the reason a section reports.
     * @param  list<CapturedReceipt>  $receiptCaptures  The head of the messages a receipt drop filed, capped the way the sample is. A message that yielded no transaction yields no row either, so this is the only record on the preview that it arrived at all.
     * @param  int  $receiptCaptureCount  How many messages the drop filed, which $receiptCaptures is a window onto.
     */
    public function __construct(
        public readonly int $importRunId,
        public readonly array $accountsToName,
        public readonly int $rowCount,
        public readonly int $committableCount,
        public readonly int $duplicateCount,
        public readonly int $errorCount,
        public readonly int $enrichedCount,
        public readonly array $sampleRows,
        public readonly bool $sampleComplete,
        public readonly array $rowIssues,
        public readonly int $rowChunkCount,
        public readonly int $canonicalChunkCount,
        public readonly int $enrichmentChunkCount,
        public readonly ?ImportFailureReason $firstRowErrorReason = null,
        public readonly ?ImportFailureReason $fileFailureReason = null,
        public readonly ?string $fileFailureDetail = null,
        public readonly ?int $fileFailureRowIndex = null,
        public readonly array $receiptCaptures = [],
        public readonly int $receiptCaptureCount = 0,
    ) {}

    public function importableRowCount(): int
    {
        return $this->rowCount - $this->errorCount;
    }

    // The one confirmable rule, on the run rather than the wizard, so a
    // headless caller meets it too. A file-level failure comes first, and
    // nothing later removes it: 499 entries of a 1200-entry statement would
    // otherwise land whole and end the ledger mid-month with nothing saying so.
    public function confirmRefusal(): ?ConfirmRefusal
    {
        if ($this->fileFailureReason !== null) {
            return ConfirmRefusal::FileDidNotReadInFull;
        }

        if ($this->accountsToName !== []) {
            return ConfirmRefusal::AccountsToName;
        }

        return $this->importableRowCount() === 0 ? ConfirmRefusal::NothingImportable : null;
    }

    public function toSectionSummary(): PreviewSectionSummary
    {
        return new PreviewSectionSummary(
            rowCount: $this->rowCount,
            committableCount: $this->committableCount,
            duplicateCount: $this->duplicateCount,
            errorCount: $this->errorCount,
            sampleRows: $this->sampleRows,
            sampleComplete: $this->sampleComplete,
            firstRowErrorReason: $this->firstRowErrorReason,
            fileFailureReason: $this->fileFailureReason,
            fileFailureDetail: $this->fileFailureDetail,
            confirmRefusal: $this->confirmRefusal(),
        );
    }
}
