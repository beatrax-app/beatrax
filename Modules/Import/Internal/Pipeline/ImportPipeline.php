<?php

declare(strict_types=1);

namespace Modules\Import\Internal\Pipeline;

use Generator;
use Illuminate\Contracts\Foundation\Application;
use InvalidArgumentException;
use Modules\Categorization\Public\Contracts\AppliesAutoCategory;
use Modules\Core\Models\User;
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
        // Backstop at the public-contract boundary: CSV is the only
        // ambiguous bank-statement format and cannot self-disambiguate,
        // so any caller lacking a format hint is refused here even if
        // it bypassed the wizard's own server-side validation.
        if ($formatHint === null && in_array($sourceFormat, ['asn-csv', 'ing-csv'], strict: true)) {
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

    // Shared per-row body both preview() and previewFromGenerator() feed:
    // resolve account -> Normalize -> ClassifyTransactionType ->
    // PaymentTypeClassifier -> auto-category -> ResolveCounterparties ->
    // FingerprintStage -> build PreviewRowDto -> append.
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
                        bookedAt: $source->bookedAt->format('d-m-Y'),
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
                    // Runs between auto-category and the fingerprint
                    // stage so the resolved counterparty_id rides along
                    // on the canonical row that eventually hits
                    // RecordTransactions.
                    $normalized = $this->resolveCounterparty->run($normalized, $user);
                } catch (Throwable $e) {
                    // Log the full stack trace so /dev/logs shows which
                    // adapter/stage threw — the preview row's message is
                    // intentionally short and loses the call site.
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
                        bookedAt: $source->bookedAt->format('d-m-Y'),
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
                    bookedAt: $source->bookedAt->format('d-m-Y'),
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
            // A fatal adapter-level error (bad header, encoding mismatch)
            // surfaces as a single ERROR row so the wizard still renders
            // instead of 500ing. Full trace logged to /dev/logs since the
            // surfaced row keeps only the user-facing message.
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
            // Receipt-path formats (eml, mbox) carry no statement-level
            // metadata — every receipt is its own logical record without
            // opening/closing balance or period dates. Skip silently.
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

    // Blank -> null so the preview view's null-coalescing fallback
    // chain (name -> iban -> description -> "—") never renders an
    // empty span for a source row with a whitespace-only description.
    private static function trimToNull(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $trimmed = trim($value);

        return $trimmed === '' ? null : $trimmed;
    }
}
