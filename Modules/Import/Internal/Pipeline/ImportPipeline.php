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
use Modules\Import\Internal\Enums\ImportFailureReason;
use Modules\Import\Internal\Enums\PreviewRowStatus;
use Modules\Import\Internal\Pipeline\Stages\ClassifyTransactionType;
use Modules\Import\Internal\Pipeline\Stages\FingerprintStage;
use Modules\Import\Internal\Pipeline\Stages\ParseStage;
use Modules\Import\Internal\Pipeline\Stages\PaymentTypeClassifierStage;
use Modules\Import\Public\Dto\EnrichedDisposition;
use Modules\Import\Public\Dto\PendingEnrichment;
use Modules\Import\Public\Dto\PreviewRowDto;
use Modules\Import\Public\Dto\UnknownIban;
use Modules\Import\Public\Enums\BankCsvFormatHint;
use Modules\Import\Public\Pipeline\NormalizeStage;
use Modules\Import\Public\Services\MerchantNameResolver;
use Modules\Ingestion\Public\Contracts\AccountResolver;
use Modules\Ingestion\Public\Dto\KnownAccount;
use Modules\Ingestion\Public\Dto\SourceTransactionDto;
use Modules\Ingestion\Public\Dto\UnknownAccount;
use Modules\Ingestion\Public\Enums\SourceFormat;
use Modules\Ingestion\Public\Services\SourceAdapterRegistry;
use Modules\Ledger\Public\Contracts\RecordsStatementSummary;
use Modules\Ledger\Public\Dto\CanonicalTransaction;
use Modules\Sync\Public\Exceptions\BlindIndexKeyUnavailableException;
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
     * @return array{rows: list<PreviewRowDto>, canonical: list<CanonicalTransaction>, enrichments: list<PendingEnrichment>, unknownIbans: list<UnknownIban>, fileFailureReason: ?string, fileFailureDetail: ?string}
     */
    public function preview(string $localPath, string $sourceFormat, AccountResolver $accounts, User $user, int $importRunId, ?BankCsvFormatHint $formatHint = null): array
    {
        // The backstop at the contract boundary: CSV cannot self-disambiguate,
        // so a caller that skipped the wizard's validation is still refused.
        if ($formatHint === null && in_array($sourceFormat, [SourceFormat::AsnCsv->value, SourceFormat::IngCsv->value], strict: true)) {
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
        ];
    }

    /**
     * @link ../../../../.docs/features/import/architecture.md#runimport-preview-idempotency--race-recovery
     *
     * @param  Generator<int, SourceTransactionDto>  $sourceRows
     * @return array{rows: list<PreviewRowDto>, canonical: list<CanonicalTransaction>, enrichments: list<PendingEnrichment>, unknownIbans: list<UnknownIban>, fileFailureReason: ?string, fileFailureDetail: ?string}
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
        ];
    }

    /**
     * @param  iterable<int, SourceTransactionDto>  $sourceRows
     * @return array{rows: list<PreviewRowDto>, canonical: list<CanonicalTransaction>, enrichments: list<PendingEnrichment>, unknownIbans: list<UnknownIban>, fileFailureReason: ?string, fileFailureDetail: ?string, lastResolvedAccountId: ?int}
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

        try {
            foreach ($sourceRows as $source) {
                $resolution = $accounts->resolve($source->ownIban);
                $rowDescription = self::trimToNull($source->description);

                if ($resolution instanceof UnknownAccount) {
                    $unknownIbans[$source->ownIban] = new UnknownIban(
                        iban: $source->ownIban,
                        seenCounterpartyName: $source->counterpartyName,
                    );
                    $preview[] = new PreviewRowDto(
                        rowIndex: $source->sourceRowIndex,
                        status: PreviewRowStatus::Error->value,
                        accountId: null,
                        bookedAt: Fmt::shortDate($source->bookedAt),
                        counterpartyName: $source->counterpartyName,
                        counterpartyIban: $source->counterpartyIban,
                        description: $rowDescription,
                        categoryName: null,
                        amountMinor: $source->amountMinor,
                        currency: $source->currency,
                        error: ImportFailureReason::UnknownAccount->label(),
                        errorReason: ImportFailureReason::UnknownAccount->value,
                    );

                    continue;
                }

                /** @var KnownAccount $resolution */
                $accountId = $resolution->accountId;
                $lastResolvedAccountId = $accountId;

                try {
                    $normalized = $this->normalize->run($source, $accountId, $user, $importRunId, $sourceFormat);
                    $normalized = $this->classifier->run($normalized, $user);
                    $normalized = $this->paymentTypeClassifier->run($normalized, $user, $sourceFormat);
                    $autoOutcome = $this->autoCategory->apply($normalized, $user);
                    $normalized = $autoOutcome->canonical;
                    // Before the fingerprint stage, so counterparty_id rides
                    // the canonical row into RecordTransactions.
                    $normalized = $this->resolveCounterparty->run($normalized, $user);
                } catch (BlindIndexKeyUnavailableException $e) {
                    // Its message names a class and the user's own id. Correct
                    // for a log, wrong for a preview row, and it would repeat
                    // once per row of the statement.
                    $this->logger->warning('ImportPipeline: row refused — the app-lock key is not held.', [
                        'source_format' => $sourceFormat,
                        'import_run_id' => $importRunId,
                        ...SafeExceptionContext::describe($e),
                    ]);
                    $preview[] = new PreviewRowDto(
                        rowIndex: $source->sourceRowIndex,
                        status: PreviewRowStatus::Error->value,
                        accountId: $accountId,
                        bookedAt: Fmt::shortDate($source->bookedAt),
                        counterpartyName: $source->counterpartyName,
                        counterpartyIban: $source->counterpartyIban,
                        description: $rowDescription,
                        categoryName: null,
                        amountMinor: $source->amountMinor,
                        currency: $source->currency,
                        error: ImportFailureReason::AppLocked->label(),
                        errorReason: ImportFailureReason::AppLocked->value,
                    );

                    continue;
                } catch (Throwable $e) {
                    // The preview row's message is short and loses the call
                    // site, so the trace goes to the log instead.
                    $this->logger->warning('ImportPipeline: row failed.', [
                        'source_format' => $sourceFormat,
                        'import_run_id' => $importRunId,
                        'row_index' => $source->sourceRowIndex,
                        ...SafeExceptionContext::describe($e),
                        'exception_trace' => SafeTrace::cap($e, $this->app->basePath()),
                    ]);
                    $rowReason = self::reasonFor($e, ImportFailureReason::RowUnreadable);
                    $preview[] = new PreviewRowDto(
                        rowIndex: $source->sourceRowIndex,
                        status: PreviewRowStatus::Error->value,
                        accountId: $accountId,
                        bookedAt: Fmt::shortDate($source->bookedAt),
                        counterpartyName: $source->counterpartyName,
                        counterpartyIban: $source->counterpartyIban,
                        description: $rowDescription,
                        categoryName: null,
                        amountMinor: $source->amountMinor,
                        currency: $source->currency,
                        error: $rowReason->label(),
                        errorReason: $rowReason->value,
                    );

                    continue;
                }

                $disposition = $this->fingerprint->classify($normalized, $user);
                $diff = null;
                if ($disposition instanceof EnrichedDisposition) {
                    $diff = [
                        'source_ref' => [
                            'from' => $disposition->fromSourceRef,
                            'to' => $disposition->toSourceRef,
                        ],
                    ];
                }

                $aliasFriendlyName = $rowDescription === null
                    ? null
                    : $this->merchantNameResolver->resolve($rowDescription, $user->id);

                $preview[] = new PreviewRowDto(
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
                    aliasFriendlyName: $aliasFriendlyName,
                );

                if ($disposition->isNew()) {
                    $canonical[] = $normalized;
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
            $fileFailureReason = self::reasonFor($e, ImportFailureReason::FileUnreadable)->value;
            $fileFailureDetail = self::safeDetail($e);
        }

        return [
            'rows' => $preview,
            'canonical' => $canonical,
            'enrichments' => $enrichments,
            'unknownIbans' => array_values($unknownIbans),
            'fileFailureReason' => $fileFailureReason,
            'fileFailureDetail' => $fileFailureDetail,
            'lastResolvedAccountId' => $lastResolvedAccountId,
        ];
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
    // whose own message names an internal class and their user id. Everything
    // else falls back to the caller's default rather than being guessed at.
    private static function reasonFor(Throwable $e, ImportFailureReason $default): ImportFailureReason
    {
        return $e instanceof BlindIndexKeyUnavailableException
            ? ImportFailureReason::AppLocked
            : $default;
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
}
