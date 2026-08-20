<?php

declare(strict_types=1);

namespace Modules\Import\Internal\Pipeline;

use Generator;
use Illuminate\Contracts\Foundation\Application;
use InvalidArgumentException;
use Modules\Categorization\Public\Contracts\AppliesAutoCategory;
use Modules\Core\Models\User;
use Modules\Core\Public\Support\Fmt;
use Modules\Core\Public\Support\SafeTrace;
use Modules\Counterparties\Public\Pipeline\ResolvesCounterparties;
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
     * @return array{rows: list<PreviewRowDto>, canonical: list<CanonicalTransaction>, enrichments: list<PendingEnrichment>, unknownIbans: list<UnknownIban>}
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
        ];
    }

    /**
     * @link ../../../../.docs/features/import/architecture.md#runimport-preview-idempotency--race-recovery
     *
     * @param  Generator<int, SourceTransactionDto>  $sourceRows
     * @return array{rows: list<PreviewRowDto>, canonical: list<CanonicalTransaction>, enrichments: list<PendingEnrichment>, unknownIbans: list<UnknownIban>}
     */
    public function previewFromGenerator(Generator $sourceRows, string $sourceFormat, AccountResolver $accounts, User $user, int $importRunId): array
    {
        $built = $this->buildPreviewRows($sourceRows, $sourceFormat, $accounts, $user, $importRunId);

        return [
            'rows' => $built['rows'],
            'canonical' => $built['canonical'],
            'enrichments' => $built['enrichments'],
            'unknownIbans' => $built['unknownIbans'],
        ];
    }

    /**
     * @param  iterable<int, SourceTransactionDto>  $sourceRows
     * @return array{rows: list<PreviewRowDto>, canonical: list<CanonicalTransaction>, enrichments: list<PendingEnrichment>, unknownIbans: list<UnknownIban>, lastResolvedAccountId: ?int}
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
                        status: 'error',
                        accountId: null,
                        bookedAt: Fmt::shortDate($source->bookedAt),
                        counterpartyName: $source->counterpartyName,
                        counterpartyIban: $source->counterpartyIban,
                        description: $rowDescription,
                        categoryName: null,
                        amountMinor: $source->amountMinor,
                        currency: $source->currency,
                        error: sprintf(
                            'Unknown account for IBAN %s. Name it to continue.',
                            $source->ownIban,
                        ),
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
                    // Before the fingerprint stage, so the resolved
                    // counterparty_id rides the canonical row into
                    // RecordTransactions.
                    $normalized = $this->resolveCounterparty->run($normalized, $user);
                } catch (Throwable $e) {
                    // The preview row's message is short and loses the call
                    // site, so the trace goes to the log instead.
                    $this->logger->warning('ImportPipeline: row failed.', [
                        'source_format' => $sourceFormat,
                        'import_run_id' => $importRunId,
                        'row_index' => $source->sourceRowIndex,
                        'exception_class' => $e::class,
                        'exception_message' => $e->getMessage(),
                        'exception_trace' => SafeTrace::cap($e, $this->app->basePath()),
                    ]);
                    $preview[] = new PreviewRowDto(
                        rowIndex: $source->sourceRowIndex,
                        status: 'error',
                        accountId: $accountId,
                        bookedAt: Fmt::shortDate($source->bookedAt),
                        counterpartyName: $source->counterpartyName,
                        counterpartyIban: $source->counterpartyIban,
                        description: $rowDescription,
                        categoryName: null,
                        amountMinor: $source->amountMinor,
                        currency: $source->currency,
                        error: $e->getMessage(),
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
            // A fatal adapter error surfaces as one ERROR row so the wizard
            // still renders; only the user-facing message survives onto it,
            // so the trace goes to the log.
            $this->logger->warning('ImportPipeline: parse failed.', [
                'source_format' => $sourceFormat,
                'import_run_id' => $importRunId,
                'exception_class' => $e::class,
                'exception_message' => $e->getMessage(),
                'exception_trace' => $e->getTraceAsString(),
            ]);
            $preview[] = new PreviewRowDto(
                rowIndex: 0,
                status: 'error',
                accountId: null,
                bookedAt: null,
                counterpartyName: null,
                counterpartyIban: null,
                description: null,
                categoryName: null,
                amountMinor: null,
                currency: null,
                error: $e->getMessage(),
            );
        }

        return [
            'rows' => $preview,
            'canonical' => $canonical,
            'enrichments' => $enrichments,
            'unknownIbans' => array_values($unknownIbans),
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
            // A receipt is its own record: no opening balance, no period.
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

    // The preview's name → iban → description → "—" fallback chain is
    // null-coalescing, so a whitespace-only description must arrive as null
    // or it renders as an empty span.
    private static function trimToNull(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $trimmed = trim($value);

        return $trimmed === '' ? null : $trimmed;
    }
}
