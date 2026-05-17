<?php

declare(strict_types=1);

namespace Modules\Import\Internal\Pipeline\Stages;

use Generator;
use Illuminate\Contracts\Filesystem\FileNotFoundException;
use Illuminate\Filesystem\Filesystem;
use Modules\Core\Models\User;
use Modules\Ingestion\Public\Contracts\AccountResolver;
use Modules\Ingestion\Public\Dto\SourceTransactionDto;
use Modules\Ingestion\Public\Services\SourceAdapterRegistry;
use Modules\Receipts\Public\Actions\RecordReceipt;
use Modules\Receipts\Public\Pipeline\MboxIterator;
use Modules\Receipts\Public\Pipeline\ReceiptSourceAdapter;
use RuntimeException;

/**
 * Thin orchestration wrapper around source-format parsing.
 *
 * For the CSV / CAMT / MT940 / PDF formats this stage hands the
 * declared format string to the SourceAdapterRegistry and yields the
 * matching adapter's lazy stream of SourceTransactionDto rows.
 *
 * For the `eml` / `mbox` formats this stage drives the receipt path:
 * read the file bytes, hand them to `RecordReceipt` which persists a
 * `file_imports` row, stores the .eml on disk, runs the matcher
 * dispatch, and transitions the row status. On a `parsed` outcome
 * the stage bridges the `ParsedReceiptDto` to a SourceTransactionDto
 * via `ReceiptSourceAdapter`; skipped / unmatched outcomes yield
 * nothing. The mbox arm iterates the archive through `MboxIterator`
 * and runs the same per-message flow for each contained message.
 *
 * The User is required for the receipt arms so the file_imports row
 * can be scoped per-user; CSV / CAMT / MT940 / PDF arms ignore it.
 */
final class ParseStage
{
    /** Wire-format keys routed through the receipt path. */
    private const RECEIPT_FORMATS = ['eml', 'mbox'];

    public function __construct(
        private readonly SourceAdapterRegistry $registry,
        private readonly RecordReceipt $recordReceipt,
        private readonly MboxIterator $mbox,
        private readonly ReceiptSourceAdapter $receiptAdapter,
        private readonly Filesystem $files,
    ) {}

    /**
     * @return Generator<int, SourceTransactionDto>
     */
    public function run(
        string $localPath,
        string $sourceFormat,
        AccountResolver $accounts,
        ?User $user = null,
    ): Generator {
        if (in_array($sourceFormat, self::RECEIPT_FORMATS, strict: true)) {
            if ($user === null) {
                throw new RuntimeException(sprintf(
                    "ParseStage: sourceFormat '%s' requires a User context.",
                    $sourceFormat,
                ));
            }

            yield from $this->runReceiptArm($localPath, $sourceFormat, $user);

            return;
        }

        yield from $this->registry->for($sourceFormat)->parse($localPath, $accounts);
    }

    /**
     * @return Generator<int, SourceTransactionDto>
     */
    private function runReceiptArm(string $localPath, string $sourceFormat, User $user): Generator
    {
        $sourceFilename = basename($localPath);

        if ($sourceFormat === 'eml') {
            try {
                $bytes = $this->files->get($localPath);
            } catch (FileNotFoundException $e) {
                throw new RuntimeException(
                    "ParseStage: cannot read .eml at {$localPath}.",
                    previous: $e,
                );
            }

            $outcome = ($this->recordReceipt)($bytes, $user, $sourceFilename);
            if ($outcome->kind === 'parsed' && $outcome->parsed !== null) {
                yield $this->receiptAdapter->toSourceDto($outcome->parsed, sourceRowIndex: 0);
            }

            return;
        }

        $rowIndex = 0;
        foreach ($this->mbox->iterate($localPath) as $entry) {
            $outcome = ($this->recordReceipt)($entry['eml'], $user, $sourceFilename);
            if ($outcome->kind === 'parsed' && $outcome->parsed !== null) {
                yield $this->receiptAdapter->toSourceDto($outcome->parsed, sourceRowIndex: $rowIndex);
            }
            $rowIndex++;
        }
    }
}
