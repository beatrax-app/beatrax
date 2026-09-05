<?php

declare(strict_types=1);

namespace Modules\Import\Internal\Pipeline\Stages;

use Generator;
use Illuminate\Contracts\Filesystem\FileNotFoundException;
use Illuminate\Filesystem\Filesystem;
use Modules\Core\Models\User;
use Modules\Core\Public\Support\SafeExceptionContext;
use Modules\Import\Internal\Exceptions\ReceiptFormatMismatchException;
use Modules\Import\Internal\Exceptions\ReceiptParseException;
use Modules\Ingestion\Public\Contracts\AccountResolver;
use Modules\Ingestion\Public\Dto\SourceTransactionDto;
use Modules\Ingestion\Public\Enums\SourceFormat;
use Modules\Ingestion\Public\Services\SourceAdapterRegistry;
use Modules\Receipts\Public\Actions\RecordReceipt;
use Modules\Receipts\Public\Dto\MatchOutcomeDto;
use Modules\Receipts\Public\Enums\MatchOutcomeKind;
use Modules\Receipts\Public\Pipeline\MboxIterator;
use Modules\Receipts\Public\Pipeline\ReceiptFileShape;
use Modules\Receipts\Public\Pipeline\ReceiptSourceAdapter;
use Modules\Receipts\Public\Support\ReceiptCaptureLog;
use Modules\Sync\Public\Exceptions\BlindIndexKeyUnavailableException;
use Modules\Sync\Public\Exceptions\SensitiveColumnKeyUnavailableException;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * @link ../../../../../.docs/architecture/ingestion-pipeline.md#1-parse-parsestage
 */
final readonly class ParseStage
{
    public function __construct(
        private SourceAdapterRegistry $registry,
        private RecordReceipt $recordReceipt,
        private MboxIterator $mbox,
        private ReceiptSourceAdapter $receiptAdapter,
        private Filesystem $files,
        private LoggerInterface $logger,
    ) {}

    /**
     * @return Generator<int, SourceTransactionDto>
     */
    public function run(
        string $localPath,
        string $sourceFormat,
        AccountResolver $accounts,
        ?User $user = null,
        ?ReceiptCaptureLog $captures = null,
    ): Generator {
        // Parsed here rather than through the adapter registry: a receipt file
        // carries no account, so it is read by the receipt recorder and only
        // then shaped into source rows.
        if (SourceFormat::tryFrom($sourceFormat)?->isReceiptFile() === true) {
            if ($user === null) {
                throw ReceiptParseException::missingUserContext($sourceFormat);
            }

            yield from $this->runReceiptArm($localPath, $sourceFormat, $user, $captures);

            return;
        }

        yield from $this->registry->for($sourceFormat)->parse($localPath, $accounts);
    }

    /**
     * @return Generator<int, SourceTransactionDto>
     */
    private function runReceiptArm(string $localPath, string $sourceFormat, User $user, ?ReceiptCaptureLog $captures): Generator
    {
        // The declared format is a leaf off a list whose email arm opens on the
        // single-message one, and an archive read as one message yields its
        // first message under a screen saying every message was saved. A file
        // that will not open at all is left to the readers below, which name it.
        $found = ReceiptFileShape::of($localPath);
        if (is_readable($localPath) && $found?->value !== $sourceFormat) {
            throw ReceiptFormatMismatchException::found($found);
        }

        $sourceFilename = basename($localPath);

        if ($sourceFormat === SourceFormat::Eml->value) {
            try {
                $bytes = $this->files->get($localPath);
            } catch (FileNotFoundException $e) {
                throw ReceiptParseException::unreadable($localPath, $e);
            }

            $outcome = ($this->recordReceipt)($bytes, $user, $sourceFilename, $captures);
            if ($outcome->kind === MatchOutcomeKind::Parsed && $outcome->parsed !== null) {
                yield $this->receiptAdapter->toSourceDto($outcome->parsed, sourceRowIndex: 0);
            }

            return;
        }

        $rowIndex = 0;
        foreach ($this->mbox->iterate($localPath, $captures) as $entry) {
            $outcome = $this->recordOrSkip($entry['eml'], $user, $sourceFilename, $captures, $rowIndex);
            if ($outcome?->kind === MatchOutcomeKind::Parsed && $outcome->parsed !== null) {
                yield $this->receiptAdapter->toSourceDto($outcome->parsed, sourceRowIndex: $rowIndex);
            }
            $rowIndex++;
        }
    }

    // An archive is independent documents, so message 400 failing says nothing
    // about messages 1 to 399 and is skipped rather than ending the read. A
    // statement is one continuous period and stays fatal; the app-lock pair is
    // no document's fault and would recur for every one, so it goes on up.
    private function recordOrSkip(string $eml, User $user, string $sourceFilename, ?ReceiptCaptureLog $captures, int $messageIndex): ?MatchOutcomeDto
    {
        try {
            return ($this->recordReceipt)($eml, $user, $sourceFilename, $captures);
        } catch (BlindIndexKeyUnavailableException|SensitiveColumnKeyUnavailableException $locked) {
            throw $locked;
        } catch (Throwable $e) {
            $this->logger->warning('ParseStage: a message in the archive could not be read and was skipped.', [
                'source_filename' => $sourceFilename,
                'message_index' => $messageIndex,
                ...SafeExceptionContext::describe($e),
            ]);
            $captures?->recordUnreadable($messageIndex);

            return null;
        }
    }
}
