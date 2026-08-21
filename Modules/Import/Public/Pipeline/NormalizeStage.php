<?php

declare(strict_types=1);

namespace Modules\Import\Public\Pipeline;

use Modules\Core\Models\User;
use Modules\Ingestion\Public\Dto\SourceTransactionDto;
use Modules\Ledger\Public\Dto\CanonicalTransaction;
use Modules\Ledger\Public\Services\CounterpartyKey;
use Modules\Ledger\Public\Services\FingerprintComposer;
use Modules\Ledger\Public\ValueObjects\Money;
use Modules\Ledger\Public\ValueObjects\Rate;

/**
 * @link ../../../../.docs/architecture/ingestion-pipeline.md#3-normalize-normalizestage
 */
final class NormalizeStage
{
    public function __construct(
        private readonly FingerprintComposer $fingerprints,
        private readonly CounterpartyKey $counterpartyKey,
    ) {}

    public function run(SourceTransactionDto $source, int $accountId, User $user, int $importRunId, string $sourceFormat): CanonicalTransaction
    {
        $normalized = $this->counterpartyKey->forName($source->counterpartyName, $user->id);

        $type = match (true) {
            $source->amountMinor > 0 => 'income',
            $source->amountMinor < 0 => 'expense',
            default => 'adjustment',
        };

        // A EUR-native row leaves the settled fields null and inherits the
        // native pair; a foreign-currency row carries both verbatim.
        $settledMinor = $source->settledAmountMinor ?? $source->amountMinor;
        $settledCurrency = $source->settledCurrency ?? $source->currency;

        // Rate, because the column is decimal(18,8) and float division is
        // forbidden on the money path.
        $fxRateUsed = null;
        if (
            $source->settledAmountMinor !== null
            && $source->settledCurrency !== null
            && $source->amountMinor !== 0
            && $source->settledCurrency !== $source->currency
        ) {
            $fxRateUsed = (string) Rate::between(
                Money::ofMinor($source->settledAmountMinor, $source->settledCurrency),
                Money::ofMinor($source->amountMinor, $source->currency),
            );
        }

        return new CanonicalTransaction(
            userId: $user->id,
            accountId: $accountId,
            type: $type,
            postedAt: $source->postedAt,
            bookedAt: $source->bookedAt,
            valueDate: $source->valueDate,
            amountMinor: $source->amountMinor,
            currency: $source->currency,
            settledAmountMinor: $settledMinor,
            settledCurrency: $settledCurrency,
            fxRateUsed: $fxRateUsed,
            counterpartyName: $source->counterpartyName,
            counterpartyIban: $source->counterpartyIban,
            counterpartyNormalized: $normalized,
            normalizationVersion: $this->fingerprints->version(),
            description: $source->description,
            categoryId: null,
            sourceFormat: $sourceFormat,
            importRunId: $importRunId,
            sourceRowIndex: $source->sourceRowIndex,
            sourceRef: $source->sourceRef,
            rawPayload: $source->rawPayload === [] ? null : $source->rawPayload,
        );
    }
}
