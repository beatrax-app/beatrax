<?php

declare(strict_types=1);

namespace Modules\Import\Internal\Pipeline;

use Modules\Core\Models\User;
use Modules\Import\Internal\Pipeline\Stages\ClassifyTransactionType;
use Modules\Import\Internal\Pipeline\Stages\FingerprintStage;
use Modules\Import\Internal\Pipeline\Stages\NormalizeStage;
use Modules\Import\Internal\Pipeline\Stages\ParseStage;
use Modules\Import\Public\Dto\EnrichedDisposition;
use Modules\Import\Public\Dto\PendingEnrichment;
use Modules\Import\Public\Dto\PreviewRowDto;
use Modules\Import\Public\Dto\UnknownIban;
use Modules\Ingestion\Public\Contracts\AccountResolver;
use Modules\Ingestion\Public\Dto\KnownAccount;
use Modules\Ingestion\Public\Dto\UnknownAccount;
use Modules\Ingestion\Public\Services\SourceAdapterRegistry;
use Modules\Ledger\Public\Contracts\RecordsStatementSummary;
use Modules\Ledger\Public\Dto\CanonicalTransaction;
use Throwable;

/**
 * Orchestrates the three stages (parse → normalize → fingerprint) into one
 * preview payload. Returns a tuple of:
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
        private readonly FingerprintStage $fingerprint,
        private readonly SourceAdapterRegistry $adapters,
        private readonly RecordsStatementSummary $statementSummaries,
    ) {}

    /**
     * @return array{rows: list<PreviewRowDto>, canonical: list<CanonicalTransaction>, enrichments: list<PendingEnrichment>, unknownIbans: list<UnknownIban>}
     */
    public function preview(string $localPath, string $sourceFormat, AccountResolver $accounts, User $user, int $importRunId): array
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
            foreach ($this->parse->run($localPath, $sourceFormat, $accounts) as $source) {
                $resolution = $accounts->resolve($source->ownIban);

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
                } catch (Throwable $e) {
                    $preview[] = new PreviewRowDto(
                        rowIndex: $source->sourceRowIndex,
                        status: 'error',
                        accountId: $accountId,
                        bookedAt: $source->bookedAt->format('d-m-Y'),
                        counterpartyName: $source->counterpartyName,
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

                $preview[] = new PreviewRowDto(
                    rowIndex: $source->sourceRowIndex,
                    status: $disposition->status(),
                    accountId: $accountId,
                    bookedAt: $source->bookedAt->format('d-m-Y'),
                    counterpartyName: $source->counterpartyName,
                    categoryName: null,
                    amountMinor: $source->amountMinor,
                    currency: $source->currency,
                    error: null,
                    diff: $diff,
                );

                if ($disposition->isNew()) {
                    $canonical[] = $normalized;
                } elseif ($disposition instanceof EnrichedDisposition) {
                    $enrichments[] = new PendingEnrichment(
                        existingTransactionId: $disposition->existingTransactionId,
                        newSourceRef: $disposition->toSourceRef,
                        importRunId: $importRunId,
                        sourceFormat: $sourceFormat,
                    );
                }
            }
        } catch (Throwable $e) {
            // A fatal adapter-level error (bad header, encoding mismatch, etc.)
            // surfaces as a single ERROR row covering the whole file so the
            // wizard can still render the preview screen rather than 500ing.
            $preview[] = new PreviewRowDto(
                rowIndex: 0,
                status: 'error',
                accountId: null,
                bookedAt: null,
                counterpartyName: null,
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

        $metadata = $this->adapters->for($sourceFormat)->statementMetadata();
        if ($metadata === null) {
            return;
        }

        ($this->statementSummaries)(
            $user,
            $metadata->withImportRunId($importRunId)->withAccountId($accountId),
        );
    }
}
