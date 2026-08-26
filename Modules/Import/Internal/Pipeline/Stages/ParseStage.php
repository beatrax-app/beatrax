<?php

declare(strict_types=1);

namespace Modules\Import\Internal\Pipeline\Stages;

use Generator;
use Illuminate\Contracts\Filesystem\FileNotFoundException;
use Illuminate\Filesystem\Filesystem;
use Modules\Core\Models\User;
use Modules\Import\Internal\Exceptions\ReceiptParseException;
use Modules\Ingestion\Public\Contracts\AccountResolver;
use Modules\Ingestion\Public\Dto\SourceTransactionDto;
use Modules\Ingestion\Public\Enums\SourceFormat;
use Modules\Ingestion\Public\Services\SourceAdapterRegistry;
use Modules\Receipts\Public\Actions\RecordReceipt;
use Modules\Receipts\Public\Enums\MatchOutcomeKind;
use Modules\Receipts\Public\Pipeline\MboxIterator;
use Modules\Receipts\Public\Pipeline\ReceiptSourceAdapter;

/**
 * @link ../../../../../.docs/architecture/ingestion-pipeline.md#1-parse-parsestage
 */
final class ParseStage
{
    // Parsed here rather than through the adapter registry: a receipt file
    // carries no account, so it is read by the receipt recorder and only then
    // shaped into source rows.
    /** @var list<string> */
    private const array RECEIPT_FORMATS = [SourceFormat::Eml->value, SourceFormat::Mbox->value];

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

        if ($sourceFormat === SourceFormat::Eml->value) {
            try {
                $bytes = $this->files->get($localPath);
            } catch (FileNotFoundException $e) {
                throw ReceiptParseException::unreadable($localPath, $e);
            }

            $outcome = ($this->recordReceipt)($bytes, $user, $sourceFilename);
            if ($outcome->kind === MatchOutcomeKind::Parsed && $outcome->parsed !== null) {
                yield $this->receiptAdapter->toSourceDto($outcome->parsed, sourceRowIndex: 0);
            }

            return;
        }

        $rowIndex = 0;
        foreach ($this->mbox->iterate($localPath) as $entry) {
            $outcome = ($this->recordReceipt)($entry['eml'], $user, $sourceFilename);
            if ($outcome->kind === MatchOutcomeKind::Parsed && $outcome->parsed !== null) {
                yield $this->receiptAdapter->toSourceDto($outcome->parsed, sourceRowIndex: $rowIndex);
            }
            $rowIndex++;
        }
    }
}
