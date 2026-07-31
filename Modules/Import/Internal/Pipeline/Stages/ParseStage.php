<?php

declare(strict_types=1);

namespace Modules\Import\Internal\Pipeline\Stages;

use Generator;
use Illuminate\Contracts\Filesystem\FileNotFoundException;
use Illuminate\Filesystem\Filesystem;
use Modules\Core\Models\User;
use Modules\Import\Public\Exceptions\ReceiptParseException;
use Modules\Ingestion\Public\Contracts\AccountResolver;
use Modules\Ingestion\Public\Dto\SourceTransactionDto;
use Modules\Ingestion\Public\Services\SourceAdapterRegistry;
use Modules\Receipts\Public\Actions\RecordReceipt;
use Modules\Receipts\Public\Pipeline\MboxIterator;
use Modules\Receipts\Public\Pipeline\ReceiptSourceAdapter;

/**
 * @link ../../../../../.docs/architecture/ingestion-pipeline.md#1-parse-parsestage
 */
final class ParseStage
{
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
                throw ReceiptParseException::missingUserContext($sourceFormat);
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
                throw ReceiptParseException::unreadable($localPath, $e);
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
