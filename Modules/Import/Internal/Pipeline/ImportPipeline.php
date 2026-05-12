<?php

declare(strict_types=1);

namespace Modules\Import\Internal\Pipeline;

use Modules\Core\Models\User;
use Modules\Import\Internal\Pipeline\Stages\FingerprintStage;
use Modules\Import\Internal\Pipeline\Stages\NormalizeStage;
use Modules\Import\Internal\Pipeline\Stages\ParseStage;
use Modules\Import\Public\Dto\PreviewRowDto;
use Modules\Import\Public\Dto\UnknownIban;
use Modules\Ingestion\Public\Contracts\AccountResolver;
use Modules\Ingestion\Public\Dto\KnownAccount;
use Modules\Ingestion\Public\Dto\UnknownAccount;
use Modules\Ledger\Public\Dto\CanonicalTransaction;
use Throwable;

/**
 * Orchestrates the three stages (parse → normalize → fingerprint) into one
 * preview payload. Returns a tuple of:
 *
 *  - `rows`: per-source-row PreviewRowDto for the wizard table (NEW /
 *    DUPLICATE / ERROR per row).
 *  - `canonical`: list of CanonicalTransaction rows that survived the
 *    pipeline AND are not duplicates — this is what the ConfirmImport
 *    action eventually replays through RecordsTransactions.
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
        private readonly FingerprintStage $fingerprint,
    ) {}

    /**
     * @return array{rows: list<PreviewRowDto>, canonical: list<CanonicalTransaction>, unknownIbans: list<UnknownIban>}
     */
    public function preview(string $localPath, string $sourceFormat, AccountResolver $accounts, User $user, int $importRunId): array
    {
        /** @var list<PreviewRowDto> $preview */
        $preview = [];
        /** @var list<CanonicalTransaction> $canonical */
        $canonical = [];
        /** @var array<string, UnknownIban> $unknownIbans */
        $unknownIbans = [];

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

                try {
                    $normalized = $this->normalize->run($source, $accountId, $user, $importRunId, $sourceFormat);
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

                $isDuplicate = $this->fingerprint->isExistingFingerprint($normalized);
                $preview[] = new PreviewRowDto(
                    rowIndex: $source->sourceRowIndex,
                    status: $isDuplicate ? 'duplicate' : 'new',
                    accountId: $accountId,
                    bookedAt: $source->bookedAt->format('d-m-Y'),
                    counterpartyName: $source->counterpartyName,
                    categoryName: null,
                    amountMinor: $source->amountMinor,
                    currency: $source->currency,
                    error: null,
                );

                if (! $isDuplicate) {
                    $canonical[] = $normalized;
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

        return [
            'rows' => $preview,
            'canonical' => $canonical,
            'unknownIbans' => array_values($unknownIbans),
        ];
    }
}
