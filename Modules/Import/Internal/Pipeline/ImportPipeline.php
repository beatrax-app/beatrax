<?php

declare(strict_types=1);

namespace Modules\Import\Internal\Pipeline;

use Generator;
use Illuminate\Contracts\Foundation\Application;
use InvalidArgumentException;
use Modules\Categorization\Public\Contracts\AppliesAutoCategory;
use Modules\Core\Models\User;
use Modules\Core\Public\Support\Lang;
use Modules\Core\Public\Support\MessageNamesNoUserData;
use Modules\Core\Public\Support\SafeExceptionContext;
use Modules\Core\Public\Support\SafeTrace;
use Modules\Counterparties\Public\Pipeline\ResolvesCounterparties;
use Modules\Import\Internal\Dto\PreviewHead;
use Modules\Import\Internal\Dto\PreviewRun;
use Modules\Import\Internal\Pipeline\Stages\ClassifyTransactionType;
use Modules\Import\Internal\Pipeline\Stages\FingerprintStage;
use Modules\Import\Internal\Pipeline\Stages\ParseStage;
use Modules\Import\Internal\Pipeline\Stages\PaymentTypeClassifierStage;
use Modules\Import\Public\Dto\EnrichedDisposition;
use Modules\Import\Public\Dto\FingerprintDisposition;
use Modules\Import\Public\Dto\PendingEnrichment;
use Modules\Import\Public\Dto\PreviewRowDto;
use Modules\Import\Public\Dto\UnknownIban;
use Modules\Import\Public\Enums\BankCsvFormatHint;
use Modules\Import\Public\Enums\ImportFailureReason;
use Modules\Import\Public\Enums\PreviewRowStatus;
use Modules\Import\Public\Pipeline\NormalizeStage;
use Modules\Import\Public\Services\MerchantNameResolver;
use Modules\Ingestion\Public\Contracts\AccountResolver;
use Modules\Ingestion\Public\Contracts\NamesAFormatMismatch;
use Modules\Ingestion\Public\Contracts\NamesRowsItCouldNotRead;
use Modules\Ingestion\Public\Dto\KnownAccount;
use Modules\Ingestion\Public\Dto\SourceTransactionDto;
use Modules\Ingestion\Public\Dto\UnknownAccount;
use Modules\Ingestion\Public\Exceptions\OrphanedPaypalChildRowException;
use Modules\Ingestion\Public\Exceptions\PdfHasNoTextLayerException;
use Modules\Ingestion\Public\Exceptions\PdfPasswordProtectedException;
use Modules\Ingestion\Public\Exceptions\PdfReaderUnavailableException;
use Modules\Ingestion\Public\Services\CsvPresetRegistry;
use Modules\Ingestion\Public\Services\SourceAdapterRegistry;
use Modules\Ledger\Public\Contracts\RecordsStatementSummary;
use Modules\Ledger\Public\Dto\CanonicalTransaction;
use Modules\Ledger\Public\Support\LedgerDay;
use Modules\Receipts\Public\Support\ReceiptCaptureLog;
use Modules\Sync\Public\Exceptions\BlindIndexKeyUnavailableException;
use Modules\Sync\Public\Exceptions\SensitiveColumnKeyUnavailableException;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * @link ../../../../.docs/architecture/ingestion-pipeline.md
 */
final readonly class ImportPipeline
{
    public function __construct(
        private ParseStage $parse,
        private NormalizeStage $normalize,
        private ClassifyTransactionType $classifier,
        private PaymentTypeClassifierStage $paymentTypeClassifier,
        private AppliesAutoCategory $autoCategory,
        private ResolvesCounterparties $resolveCounterparty,
        private FingerprintStage $fingerprint,
        private SourceAdapterRegistry $adapters,
        private RecordsStatementSummary $statementSummaries,
        private MerchantNameResolver $merchantNameResolver,
        private LoggerInterface $logger,
        private Application $app,
    ) {}

    public function preview(string $localPath, string $sourceFormat, AccountResolver $accounts, User $user, int $importRunId, PreviewWriter $writer, ?BankCsvFormatHint $formatHint = null): PreviewHead
    {
        // Vestigial since every CSV dialect became a preset that names itself in
        // its format id. Kept because callers outside this module still pass the
        // hint and a silent relaxation would leave them asserting nothing.
        if ($formatHint === null && $sourceFormat === CsvPresetRegistry::ASN) {
            throw new InvalidArgumentException('CSV imports require a format hint.');
        }

        // Created here rather than inside the stage: ParseStage is resolved as
        // a dependency of this singleton, so state held on it would outlive the
        // run and, on the phone's single-process runtime, the request too.
        $captures = new ReceiptCaptureLog;
        $run = new PreviewRun($sourceFormat, $accounts, $user, $importRunId);

        $built = $this->buildPreviewRows(
            $this->parse->run($localPath, $sourceFormat, $accounts, $user, $captures),
            $run,
            $writer,
            $localPath,
            $captures,
        );

        $this->persistStatementMetadata($sourceFormat, $importRunId, $built['lastResolvedAccountId'], $user);

        return $built['head'];
    }

    /**
     * @link ../../../../.docs/features/import/architecture.md#runimport-preview-idempotency--race-recovery
     *
     * @param  Generator<int, SourceTransactionDto>  $sourceRows
     */
    public function previewFromGenerator(Generator $sourceRows, string $sourceFormat, AccountResolver $accounts, User $user, int $importRunId, PreviewWriter $writer): PreviewHead
    {
        $run = new PreviewRun($sourceFormat, $accounts, $user, $importRunId);

        return $this->buildPreviewRows($sourceRows, $run, $writer)['head'];
    }

    /**
     * @param  iterable<int, SourceTransactionDto>  $sourceRows
     * @return array{head: PreviewHead, lastResolvedAccountId: ?int}
     */
    private function buildPreviewRows(iterable $sourceRows, PreviewRun $run, PreviewWriter $writer, ?string $localPath = null, ?ReceiptCaptureLog $captures = null): array
    {
        /** @var array<string, UnknownIban> $unknownIbans */
        $unknownIbans = [];
        $rowsWritten = 0;
        $lastResolvedAccountId = null;
        $fileFailureReason = null;
        $fileFailureDetail = null;
        $fileFailureRowIndex = null;

        try {
            foreach ($sourceRows as $source) {
                $resolution = $run->accounts->resolve($source->ownIban);

                if ($resolution instanceof UnknownAccount) {
                    $unknownIbans[$source->ownIban] = self::seenAgain(
                        $unknownIbans[$source->ownIban] ?? null,
                        $source,
                    );
                    $writer->addRow(self::failedRow($source, null, ImportFailureReason::UnknownAccount));
                    $rowsWritten++;

                    continue;
                }

                /** @var KnownAccount $resolution */
                $accountId = $resolution->accountId;
                $lastResolvedAccountId = $accountId;

                $enriched = $this->enrichRow($source, $accountId, $run);
                if ($enriched instanceof PreviewRowDto) {
                    $writer->addRow($enriched);
                    $rowsWritten++;

                    continue;
                }

                $disposition = $this->fingerprint->classify($enriched, $run->user);
                $writer->addRow($this->acceptedRow($source, $accountId, $enriched, $disposition, $run->user));
                $rowsWritten++;

                if ($disposition->isNew()) {
                    $writer->addCanonical($enriched);
                } elseif ($disposition instanceof EnrichedDisposition) {
                    $writer->addEnrichment(new PendingEnrichment(
                        existingTransactionId: $disposition->existingTransactionId,
                        newSourceRef: $disposition->toSourceRef,
                        importRunId: $run->importRunId,
                        sourceFormat: $run->sourceFormat,
                        conflictingFields: $disposition->conflictingFields,
                    ));
                }
            }
        } catch (Throwable $e) {
            // A fatal adapter error is the file failing, not a row of it, and
            // it stops the read where it was raised. The size and the row count
            // go with it: a file that arrived empty and a file that died on its
            // content are one line apart in a log, and identical on the screen.
            $this->logger->warning('ImportPipeline: parse failed.', [
                'source_format' => $run->sourceFormat,
                'import_run_id' => $run->importRunId,
                'source_bytes' => self::sourceBytes($localPath),
                'rows_read' => $rowsWritten,
                ...SafeExceptionContext::describe($e),
                'exception_message' => $e instanceof MessageNamesNoUserData ? $e->getMessage() : null,
                'exception_trace' => SafeTrace::cap($e, $this->app->basePath()),
            ]);
            $fileFailureReason = self::fileReasonFor($e);
            $fileFailureDetail = self::fileDetail($e);
            // One preview row per source row, so the count is the index of the
            // one being read when it stopped. Counted rather than read out of
            // the message, which for most of these adapters quotes a cell and
            // so cannot be shown or stored.
            $fileFailureRowIndex = $rowsWritten === 0 ? null : $rowsWritten;
        }

        // Added after the read rather than during it, because the adapters that
        // report these dropped the rows before they yielded their first. The
        // file-failure index above is the row the read stopped on, so these are
        // counted past it and never mistaken for where it stopped.
        foreach ($this->unreadableRowIndexes($run->sourceFormat) as $unreadableIndex) {
            $writer->addRow(self::unreadableRow($unreadableIndex, ImportFailureReason::RowUnreadable));
        }

        // A message of an archive that would not read is one document of many,
        // so it is a failed row rather than a failed file -- counted, listed
        // and skipped by the same machinery a bad CSV line goes through, which
        // is what lets the rest of the archive still confirm.
        foreach ($captures?->unreadableIndexes() ?? [] as $unreadableMessage) {
            $writer->addRow(self::unreadableRow($unreadableMessage, ImportFailureReason::MessageUnreadable));
        }

        return [
            'head' => $writer->finish(
                array_values($unknownIbans),
                $fileFailureReason,
                $fileFailureDetail,
                $fileFailureRowIndex,
                $captures?->kept() ?? [],
                $captures?->total() ?? 0,
            ),
            'lastResolvedAccountId' => $lastResolvedAccountId,
        ];
    }

    // Every stage a row has to survive to become a transaction. One that fails
    // any of them is not the file failing: it comes back as the preview row
    // that says so, and the read carries on to the next row.
    private function enrichRow(SourceTransactionDto $source, int $accountId, PreviewRun $run): CanonicalTransaction|PreviewRowDto
    {
        $user = $run->user;

        try {
            $normalized = $this->normalize->run($source, $accountId, $user, $run->importRunId, $run->sourceFormat);
            $normalized = $this->classifier->run($normalized, $user);
            $normalized = $this->paymentTypeClassifier->run($normalized, $user, $run->sourceFormat);
            $normalized = $this->autoCategory->apply($normalized, $user)->canonical;

            // Before the fingerprint stage, so counterparty_id rides the
            // canonical row into RecordTransactions.
            return $this->resolveCounterparty->run($normalized, $user);
        } catch (BlindIndexKeyUnavailableException|SensitiveColumnKeyUnavailableException $e) {
            // Both messages name a class and the user's own id. Correct for a
            // log, wrong for a preview row, and it would repeat once per row of
            // the statement.
            $this->logger->warning('ImportPipeline: row refused — the app-lock key is not held.', [
                'source_format' => $run->sourceFormat,
                'import_run_id' => $run->importRunId,
                ...SafeExceptionContext::describe($e),
            ]);

            return self::failedRow($source, $accountId, ImportFailureReason::AppLocked);
        } catch (Throwable $e) {
            // The preview row's message is short and loses the call site, so
            // the trace goes to the log instead.
            $this->logger->warning('ImportPipeline: row failed.', [
                'source_format' => $run->sourceFormat,
                'import_run_id' => $run->importRunId,
                'row_index' => $source->sourceRowIndex,
                ...SafeExceptionContext::describe($e),
                'exception_trace' => SafeTrace::cap($e, $this->app->basePath()),
            ]);

            return self::failedRow(
                $source,
                $accountId,
                self::reasonFor($e, ImportFailureReason::RowUnreadable),
                self::safeDetail($e),
            );
        }
    }

    // The settled leg, because that is what the account itself moved by and
    // what every balance in the app sums; a row's native leg is the merchant's
    // money, not the account's. One row disagreeing leaves the denomination
    // unanswered rather than picking a winner, so null here is absorbing.
    /**
     * @link ../../../../.docs/features/import/an-account-is-denominated-by-its-statement.md#one-file-many-currencies
     */
    private static function seenAgain(?UnknownIban $seen, SourceTransactionDto $source): UnknownIban
    {
        $settled = $source->settledCurrency ?? $source->currency;
        $unanimous = $seen === null || $seen->statementCurrency === $settled;

        return new UnknownIban(
            iban: $source->ownIban,
            seenCounterpartyName: $source->counterpartyName,
            statementCurrency: $unanimous && $settled !== '' ? $settled : null,
        );
    }

    // A source row that never became a SourceTransactionDto at all: there is no
    // day, no amount and no counterparty to put beside it, and its own file is
    // where the reader goes to find it. Without a row of its own the screen
    // counts a shorter file than the one that was uploaded.
    private static function unreadableRow(int $rowIndex, ImportFailureReason $reason): PreviewRowDto
    {
        return new PreviewRowDto(
            rowIndex: $rowIndex,
            status: PreviewRowStatus::Error,
            accountId: null,
            postedAt: null,
            counterpartyName: null,
            counterpartyIban: null,
            description: null,
            amountMinor: null,
            currency: null,
            error: $reason->label(),
            errorReason: $reason,
        );
    }

    private static function failedRow(SourceTransactionDto $source, ?int $accountId, ImportFailureReason $reason, ?string $detail = null): PreviewRowDto
    {
        return new PreviewRowDto(
            rowIndex: $source->sourceRowIndex,
            status: PreviewRowStatus::Error,
            accountId: $accountId,
            postedAt: LedgerDay::shown($source->postedAt),
            counterpartyName: $source->counterpartyName,
            counterpartyIban: $source->counterpartyIban,
            description: self::trimToNull($source->description),
            amountMinor: $source->amountMinor,
            currency: $source->currency,
            error: $reason->label(),
            errorReason: $reason,
            errorDetail: $detail,
        );
    }

    private function acceptedRow(SourceTransactionDto $source, int $accountId, CanonicalTransaction $normalized, FingerprintDisposition $disposition, User $user): PreviewRowDto
    {
        $rowDescription = self::trimToNull($source->description);

        $diff = $disposition instanceof EnrichedDisposition
            ? ['source_ref' => ['from' => $disposition->fromSourceRef, 'to' => $disposition->toSourceRef]]
            : null;

        return new PreviewRowDto(
            rowIndex: $source->sourceRowIndex,
            status: $disposition->status(),
            accountId: $accountId,
            // Off the canonical row, not off the source: this is the day the
            // commit is about to write, and on a card statement the source's
            // other day runs ahead of it — a month ahead on the row that
            // straddles the turn.
            postedAt: LedgerDay::shown($normalized->postedAt),
            counterpartyName: $source->counterpartyName,
            counterpartyIban: $source->counterpartyIban,
            description: $rowDescription,
            amountMinor: $source->amountMinor,
            currency: $source->currency,
            error: null,
            diff: $diff,
            paymentType: $normalized->paymentType,
            // Resolved from the description, and the three blades rendering the
            // counterparty column read it first — so on a row whose file also
            // names the counterparty it hid that name and previewed something
            // the commit never writes. It stands in for one; it never overrules.
            aliasFriendlyName: $rowDescription === null || self::hasText($source->counterpartyName)
                ? null
                : $this->merchantNameResolver->resolve($rowDescription, $user->id),
        );
    }

    /**
     * @return list<int>
     */
    private function unreadableRowIndexes(string $sourceFormat): array
    {
        if (! in_array($sourceFormat, $this->adapters->supportedFormats(), strict: true)) {
            return [];
        }

        $adapter = $this->adapters->for($sourceFormat);

        return $adapter instanceof NamesRowsItCouldNotRead ? $adapter->unreadableRowIndexes() : [];
    }

    /**
     * @link ../../../../.docs/architecture/ingestion-pipeline.md#statement-metadata-side-channel
     */
    private function persistStatementMetadata(string $sourceFormat, int $importRunId, ?int $accountId, User $user): void
    {
        if ($accountId === null) {
            return;
        }

        if (! in_array($sourceFormat, $this->adapters->supportedFormats(), strict: true)) {
            return;
        }

        $metadata = $this->adapters->for($sourceFormat)->statementMetadata();
        if ($metadata === null) {
            return;
        }

        ($this->statementSummaries)(
            $user,
            $metadata->withImportRunId($importRunId)->withAccountId($accountId),
        );
    }

    // The format check runs before the first row is read, so anything that
    // throws after it has already agreed the header matches. Blaming the header
    // there sent the reader to their bank for a file their bank sent correctly.
    private static function fileReasonFor(Throwable $e): ImportFailureReason
    {
        return $e instanceof NamesAFormatMismatch
            ? ImportFailureReason::FileUnreadable
            : self::reasonFor($e, ImportFailureReason::FileStoppedShort);
    }

    // The app-lock case is the one a reader can act on, and the only failure
    // whose own message names an internal class and their user id. It arrives
    // by two doors — the matching key cannot be derived, or the AEAD column
    // cannot be sealed — and both mean "unlock the app and try again".
    private static function reasonFor(Throwable $e, ImportFailureReason $default): ImportFailureReason
    {
        return match (true) {
            $e instanceof BlindIndexKeyUnavailableException,
            $e instanceof SensitiveColumnKeyUnavailableException => ImportFailureReason::AppLocked,
            // Nothing about the file is wrong, so the header-row advice the
            // default carries would send the reader to re-download a statement
            // that would fail again the same way.
            $e instanceof PdfReaderUnavailableException => ImportFailureReason::PdfReaderUnavailable,
            // Three PDF refusals with three different answers. Collapsed onto
            // one, a scan and an encrypted file both told the reader to go find
            // a program, which fixes neither and is not even true of either.
            $e instanceof PdfHasNoTextLayerException => ImportFailureReason::PdfHasNoTextLayer,
            $e instanceof PdfPasswordProtectedException => ImportFailureReason::PdfPasswordProtected,
            // A statement split at a month boundary strands the far half of a
            // paired row. Naming the row unreadable told the reader nothing
            // they could act on; the other file is the whole answer.
            $e instanceof OrphanedPaypalChildRowException => ImportFailureReason::RowBelongsToAnotherStatement,
            default => $default,
        };
    }

    // The same seam the log path uses, applied one step earlier: a message that
    // has not declared itself free of user data never enters the preview at all.
    // The sniffer's own "this CSV is missing column X" is the one worth keeping,
    // and it is declared safe.
    private static function sourceBytes(?string $localPath): ?int
    {
        if ($localPath === null || ! is_file($localPath)) {
            return null;
        }

        $bytes = @filesize($localPath);

        return $bytes === false ? null : $bytes;
    }

    // An unmarked message is machine text and stays in the log, but a row that
    // failed with nothing under it told the reader only that it failed. The
    // class name carries neither the message nor the reader's id, and it is the
    // one thing worth quoting in an issue.
    private static function safeDetail(Throwable $e): string
    {
        return self::detailOr(
            $e,
            Lang::get('import::preview.errors.row_unreadable_detail', ['code' => SafeExceptionContext::shortName($e)]),
        );
    }

    // The file-level twin. Sharing the row wording made a refused PDF report
    // that one row could not be read, under a heading saying the whole file
    // could not be read — of a file whose rows were never reached at all.
    private static function fileDetail(Throwable $e): string
    {
        return self::detailOr(
            $e,
            Lang::get('import::preview.errors.file_unreadable_detail', ['code' => SafeExceptionContext::shortName($e)]),
        );
    }

    private static function detailOr(Throwable $e, string $fallback): string
    {
        $message = $e instanceof MessageNamesNoUserData ? trim($e->getMessage()) : '';

        return $message === '' ? $fallback : $message;
    }

    // The preview's name → iban → description → "—" chain is null-coalescing,
    // so a whitespace-only description must arrive as null or it renders blank.
    private static function trimToNull(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $trimmed = trim($value);

        return $trimmed === '' ? null : $trimmed;
    }

    private static function hasText(?string $value): bool
    {
        return self::trimToNull($value) !== null;
    }
}
