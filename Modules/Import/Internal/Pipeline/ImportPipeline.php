<?php

declare(strict_types=1);

namespace Modules\Import\Internal\Pipeline;

use Illuminate\Contracts\Foundation\Application;
use InvalidArgumentException;
use Modules\Categorization\Public\Contracts\AppliesAutoCategory;
use Modules\Core\Models\User;
use Modules\Core\Public\Support\SafeTrace;
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
use Modules\Ingestion\Public\Dto\UnknownAccount;
use Modules\Ingestion\Public\Services\SourceAdapterRegistry;
use Modules\Ledger\Public\Contracts\RecordsStatementSummary;
use Modules\Ledger\Public\Dto\CanonicalTransaction;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Orchestrates the per-row stages (parse → normalize → classify
 * transaction type → classify payment type → apply auto-category →
 * fingerprint) into one preview payload. Returns a tuple of:
 *
 *  - `rows`: per-source-row PreviewRowDto for the wizard table
 *    (NEW / DUPLICATE / ENRICHED / ERROR per row).
 *  - `canonical`: list of CanonicalTransaction rows that survived the
 *    pipeline AND are not duplicates or enrichments — this is what the
 *    ConfirmImport action eventually replays through RecordsTransactions.
 *  - `enrichments`: list of PendingEnrichment work-items the ConfirmImport
 *    action applies via the AppliesEnrichments contract; each one
 *    UPDATE-s an existing transactions row with a stronger source_ref
 *    and appends a provenance entry to `enriched_from`.
 *  - `unknownIbans`: deduplicated list of UnknownIban prompts the wizard
 *    must render before the user can confirm.
 *
 * Per-row errors are caught here and converted to ERROR-status PreviewRowDtos
 * so a single bad row never aborts the whole preview.
 */
final class ImportPipeline
{
    public function __construct(
        private readonly ParseStage $parse,
        private readonly NormalizeStage $normalize,
        private readonly ClassifyTransactionType $classifier,
        private readonly PaymentTypeClassifierStage $paymentTypeClassifier,
        private readonly AppliesAutoCategory $autoCategory,
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
        // Backstop guard at the public-contract boundary: CSV is the only
        // ambiguous bank-statement format, and the file's own header is
        // not enough to disambiguate the dialect reliably. Any caller
        // that asks for a CSV import without naming the bank explicitly
        // is refused here even if it bypassed the wizard's own
        // server-side rules() validation (other modules, future
        // programmatic callers, tests that drive the contract
        // directly).
        if ($formatHint === null && in_array($sourceFormat, ['asn-csv', 'ing-csv'], strict: true)) {
            throw new InvalidArgumentException('CSV imports require a format hint.');
        }

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
            foreach ($this->parse->run($localPath, $sourceFormat, $accounts, $user) as $source) {
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
                } catch (Throwable $e) {
                    // Log every per-row failure with the full stack
                    // trace so a developer can open /dev/logs and see
                    // which adapter / stage threw — the preview-row
                    // surfaces only the user-facing message, which is
                    // intentionally short and loses the call site.
                    // Without this log the failure was "silent" past
                    // the preview row (no entry anywhere triagable).
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
            // A fatal adapter-level error (bad header, encoding mismatch, etc.)
            // surfaces as a single ERROR row covering the whole file so the
            // wizard can still render the preview screen rather than 500ing.
            // Log the full stack trace so the failure is triagable on
            // /dev/logs — the surfaced row carries only the user-facing
            // message, which loses the call site.
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

        $this->persistStatementMetadata($sourceFormat, $importRunId, $lastResolvedAccountId, $user);

        return [
            'rows' => $preview,
            'canonical' => $canonical,
            'enrichments' => $enrichments,
            'unknownIbans' => array_values($unknownIbans),
        ];
    }

    /**
     * Asks the adapter for any statement-level metadata captured during the
     * preview parse and persists it via the injected writer. Skipped when
     * the adapter returns null (CSV path) or when no account was resolved
     * during the parse loop (all-unknown-IBAN paths can't carry a
     * statement summary because there is no account_id FK to attach it to).
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

    /**
     * Collapses a possibly-empty / whitespace-only description string into
     * a `null` so the preview view's null-coalescing fallback chain
     * (`name → iban → description → "—"`) doesn't render an empty span when
     * the source row carried a description field that was actually blank.
     */
    private static function trimToNull(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $trimmed = trim($value);

        return $trimmed === '' ? null : $trimmed;
    }
}
