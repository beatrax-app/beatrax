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
     * Generator-driven counterpart to `preview()` for callers with no local
     * file to parse (e.g. Modules\OpenBanking's remote fetch, via the
     * Public `RunsImports::runFromRemoteFetch()` contract). Feeds the
     * caller-supplied `Generator<SourceTransactionDto>` into the SAME
     * shared per-row body `preview()` uses, so cross-source fingerprint
     * dedup and the consolidated preview are inherited unchanged.
     *
     * Deliberately does NOT call `persistStatementMetadata()` — a fetched
     * balance is a point-in-time reading, not an opening/closing
     * statement pair, so it does not map onto `StatementSummaryData`'s
     * CAMT-shaped fields. Balance/last-sync surfacing belongs on the
     * OpenBanking module's own connection metadata, not this seam.
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
     * The shared per-row body both `preview()` and `previewFromGenerator()`
     * feed: resolve account → NormalizeStage → ClassifyTransactionType →
     * PaymentTypeClassifier → applyAutoCategory → ResolveCounterparties →
     * FingerprintStage-classify → build PreviewRowDto → append. Both the
     * inner (per-row) and outer (whole-loop) error tiers are preserved
     * verbatim from the original `preview()` implementation.
     *
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
