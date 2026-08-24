<?php

declare(strict_types=1);

namespace Modules\Import\Internal\Pipeline;

use Generator;
use Illuminate\Contracts\Foundation\Application;
use InvalidArgumentException;
use Modules\Categorization\Public\Contracts\AppliesAutoCategory;
use Modules\Core\Models\User;
use Modules\Core\Public\Support\Fmt;
use Modules\Core\Public\Support\MessageNamesNoUserData;
use Modules\Core\Public\Support\SafeExceptionContext;
use Modules\Core\Public\Support\SafeTrace;
use Modules\Counterparties\Public\Pipeline\ResolvesCounterparties;
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
use Modules\Ingestion\Public\Dto\KnownAccount;
use Modules\Ingestion\Public\Dto\SourceTransactionDto;
use Modules\Ingestion\Public\Dto\UnknownAccount;
use Modules\Ingestion\Public\Enums\SourceFormat;
use Modules\Ingestion\Public\Exceptions\PdfReaderUnavailableException;
use Modules\Ingestion\Public\Services\SourceAdapterRegistry;
use Modules\Ledger\Public\Contracts\RecordsStatementSummary;
use Modules\Ledger\Public\Dto\CanonicalTransaction;
use Modules\Sync\Public\Exceptions\BlindIndexKeyUnavailableException;
use Modules\Sync\Public\Exceptions\SensitiveColumnKeyUnavailableException;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * @link ../../../../.docs/architecture/ingestion-pipeline.md
 */
final class ImportPipeline
{
    public function __construct(
        private readonly ParseStage $parse,
        private readonly NormalizeStage $normalize,
        private readonly ClassifyTransactionType $classifier,
        private readonly PaymentTypeClassifierStage $paymentTypeClassifier,
        private readonly AppliesAutoCategory $autoCategory,
        private readonly ResolvesCounterparties $resolveCounterparty,
        private readonly FingerprintStage $fingerprint,
        private readonly SourceAdapterRegistry $adapters,
        private readonly RecordsStatementSummary $statementSummaries,
        private readonly MerchantNameResolver $merchantNameResolver,
        private readonly LoggerInterface $logger,
        private readonly Application $app,
    ) {}

    /**
     * @return array{rows: list<PreviewRowDto>, canonical: list<CanonicalTransaction>, enrichments: list<PendingEnrichment>, unknownIbans: list<UnknownIban>, fileFailureReason: ?ImportFailureReason, fileFailureDetail: ?string, fileFailureRowIndex: ?int}
     */
    public function preview(string $localPath, string $sourceFormat, AccountResolver $accounts, User $user, int $importRunId, ?BankCsvFormatHint $formatHint = null): array
    {
        // The backstop at the contract boundary: CSV cannot self-disambiguate,
        // so a caller that skipped the wizard's validation is still refused.
        if ($formatHint === null && $sourceFormat === SourceFormat::AsnCsv->value) {
            throw new InvalidArgumentException('CSV imports require a format hint.');
        }

        $built = $this->buildPreviewRows(
            $this->parse->run($localPath, $sourceFormat, $accounts, $user),
            $sourceFormat,
            $accounts,
            $user,
            $importRunId,
        );

        $this->persistStatementMetadata($sourceFormat, $importRunId, $built['lastResolvedAccountId'], $user);

        return [
            'rows' => $built['rows'],
            'canonical' => $built['canonical'],
            'enrichments' => $built['enrichments'],
            'unknownIbans' => $built['unknownIbans'],
            'fileFailureReason' => $built['fileFailureReason'],
            'fileFailureDetail' => $built['fileFailureDetail'],
            'fileFailureRowIndex' => $built['fileFailureRowIndex'],
        ];
    }

    /**
     * @link ../../../../.docs/features/import/architecture.md#runimport-preview-idempotency--race-recovery
     *
     * @param  Generator<int, SourceTransactionDto>  $sourceRows
     * @return array{rows: list<PreviewRowDto>, canonical: list<CanonicalTransaction>, enrichments: list<PendingEnrichment>, unknownIbans: list<UnknownIban>, fileFailureReason: ?ImportFailureReason, fileFailureDetail: ?string, fileFailureRowIndex: ?int}
     */
    public function previewFromGenerator(Generator $sourceRows, string $sourceFormat, AccountResolver $accounts, User $user, int $importRunId): array
    {
        $built = $this->buildPreviewRows($sourceRows, $sourceFormat, $accounts, $user, $importRunId);

        return [
            'rows' => $built['rows'],
            'canonical' => $built['canonical'],
            'enrichments' => $built['enrichments'],
            'unknownIbans' => $built['unknownIbans'],
            'fileFailureReason' => $built['fileFailureReason'],
            'fileFailureDetail' => $built['fileFailureDetail'],
            'fileFailureRowIndex' => $built['fileFailureRowIndex'],
        ];
    }

    /**
     * @param  iterable<int, SourceTransactionDto>  $sourceRows
     * @return array{rows: list<PreviewRowDto>, canonical: list<CanonicalTransaction>, enrichments: list<PendingEnrichment>, unknownIbans: list<UnknownIban>, fileFailureReason: ?ImportFailureReason, fileFailureDetail: ?string, fileFailureRowIndex: ?int, lastResolvedAccountId: ?int}
     */
    private function buildPreviewRows(iterable $sourceRows, string $sourceFormat, AccountResolver $accounts, User $user, int $importRunId): array
    {
        /** @var list<PreviewRowDto> $preview */
        $preview = [];
        /** @var list<CanonicalTransaction> $canonical */
        $canonical = [];
        /** @var list<PendingEnrichment> $enrichments */
        $enrichments = [];
        /** @var array<string, UnknownIban> $unknownIbans */
        $unknownIbans = [];
        $lastResolvedAccountId = null;
        $fileFailureReason = null;
        $fileFailureDetail = null;
        $fileFailureRowIndex = null;

        try {
            foreach ($sourceRows as $source) {
                $resolution = $accounts->resolve($source->ownIban);

                if ($resolution instanceof UnknownAccount) {
                    $unknownIbans[$source->ownIban] = new UnknownIban(
                        iban: $source->ownIban,
                        seenCounterpartyName: $source->counterpartyName,
                    );
                    $preview[] = self::failedRow($source, null, ImportFailureReason::UnknownAccount);

                    continue;
                }

                /** @var KnownAccount $resolution */
                $accountId = $resolution->accountId;
                $lastResolvedAccountId = $accountId;

                $enriched = $this->enrichRow($source, $accountId, $user, $importRunId, $sourceFormat);
                if ($enriched instanceof PreviewRowDto) {
                    $preview[] = $enriched;

                    continue;
                }

                $disposition = $this->fingerprint->classify($enriched, $user);
                $preview[] = $this->acceptedRow($source, $accountId, $enriched, $disposition, $user);

                if ($disposition->isNew()) {
                    $canonical[] = $enriched;
                } elseif ($disposition instanceof EnrichedDisposition) {
                    $enrichments[] = new PendingEnrichment(
                        existingTransactionId: $disposition->existingTransactionId,
                        newSourceRef: $disposition->toSourceRef,
                        importRunId: $importRunId,
                        sourceFormat: $sourceFormat,
                        conflictingFields: $disposition->conflictingFields,
                    );
                }
            }
        } catch (Throwable $e) {
            // A fatal adapter error is the file failing, not a row of it, and
            // it stops the read where it was raised. Reported as a row it read
            // as a transaction with no values; reported here it can say that
            // nothing past this point was read. The trace goes to the log.
            $this->logger->warning('ImportPipeline: parse failed.', [
                'source_format' => $sourceFormat,
                'import_run_id' => $importRunId,
                ...SafeExceptionContext::describe($e),
                'exception_message' => $e instanceof MessageNamesNoUserData ? $e->getMessage() : null,
                'exception_trace' => $e->getTraceAsString(),
            ]);
            $fileFailureReason = self::reasonFor($e, ImportFailureReason::FileUnreadable);
            $fileFailureDetail = self::safeDetail($e);
            // One preview row per source row, so the count is the index of the
            // one being read when it stopped. Counted rather than read out of
            // the message, which for most of these adapters quotes a cell and
            // so cannot be shown or stored.
            $fileFailureRowIndex = $preview === [] ? null : count($preview);
        }

        return [
            'rows' => $preview,
            'canonical' => $canonical,
            'enrichments' => $enrichments,
            'unknownIbans' => array_values($unknownIbans),
            'fileFailureReason' => $fileFailureReason,
            'fileFailureDetail' => $fileFailureDetail,
            'fileFailureRowIndex' => $fileFailureRowIndex,
            'lastResolvedAccountId' => $lastResolvedAccountId,
        ];
    }

    // Every stage a row has to survive to become a transaction. One that fails
    // any of them is not the file failing: it comes back as the preview row
    // that says so, and the read carries on to the next row.
    private function enrichRow(SourceTransactionDto $source, int $accountId, User $user, int $importRunId, string $sourceFormat): CanonicalTransaction|PreviewRowDto
    {
        try {
            $normalized = $this->normalize->run($source, $accountId, $user, $importRunId, $sourceFormat);
            $normalized = $this->classifier->run($normalized, $user);
            $normalized = $this->paymentTypeClassifier->run($normalized, $user, $sourceFormat);
            $normalized = $this->autoCategory->apply($normalized, $user)->canonical;

            // Before the fingerprint stage, so counterparty_id rides the
            // canonical row into RecordTransactions.
            return $this->resolveCounterparty->run($normalized, $user);
        } catch (BlindIndexKeyUnavailableException|SensitiveColumnKeyUnavailableException $e) {
            // Both messages name a class and the user's own id. Correct for a
            // log, wrong for a preview row, and it would repeat once per row of
            // the statement.
            $this->logger->warning('ImportPipeline: row refused — the app-lock key is not held.', [
                'source_format' => $sourceFormat,
                'import_run_id' => $importRunId,
                ...SafeExceptionContext::describe($e),
            ]);

            return self::failedRow($source, $accountId, ImportFailureReason::AppLocked);
        } catch (Throwable $e) {
            // The preview row's message is short and loses the call site, so
            // the trace goes to the log instead.
            $this->logger->warning('ImportPipeline: row failed.', [
                'source_format' => $sourceFormat,
                'import_run_id' => $importRunId,
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

    private static function failedRow(SourceTransactionDto $source, ?int $accountId, ImportFailureReason $reason, ?string $detail = null): PreviewRowDto
    {
        return new PreviewRowDto(
            rowIndex: $source->sourceRowIndex,
            status: PreviewRowStatus::Error,
            accountId: $accountId,
            bookedAt: Fmt::shortDate($source->bookedAt),
            counterpartyName: $source->counterpartyName,
            counterpartyIban: $source->counterpartyIban,
            description: self::trimToNull($source->description),
            categoryName: null,
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
            bookedAt: Fmt::shortDate($source->bookedAt),
            counterpartyName: $source->counterpartyName,
            counterpartyIban: $source->counterpartyIban,
            description: $rowDescription,
            categoryName: null,
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
            default => $default,
        };
    }

    // The same seam the log path uses, applied one step earlier: a message that
    // has not declared itself free of user data never enters the preview at all.
    // The sniffer's own "this CSV is missing column X" is the one worth keeping,
    // and it is declared safe.
    private static function safeDetail(Throwable $e): ?string
    {
        if (! $e instanceof MessageNamesNoUserData) {
            return null;
        }

        $message = trim($e->getMessage());

        return $message === '' ? null : $message;
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
