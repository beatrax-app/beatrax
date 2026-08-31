<?php

declare(strict_types=1);

namespace Modules\Import\Public\Pipeline;

use Modules\Core\Models\User;
use Modules\Ingestion\Public\Dto\SourceTransactionDto;
use Modules\Ledger\Public\Dto\CanonicalTransaction;
use Modules\Ledger\Public\Enums\TransactionType;
use Modules\Ledger\Public\Services\CounterpartyKey;
use Modules\Ledger\Public\Services\FingerprintComposer;
use Modules\Ledger\Public\ValueObjects\TransactionAmount;

/**
 * @link ../../../../.docs/architecture/ingestion-pipeline.md#3-normalize-normalizestage
 */
final readonly class NormalizeStage
{
    public function __construct(
        private FingerprintComposer $fingerprints,
        private CounterpartyKey $counterpartyKey,
    ) {}

    public function run(SourceTransactionDto $source, int $accountId, User $user, int $importRunId, string $sourceFormat): CanonicalTransaction
    {
        $normalized = $this->counterpartyKey->forName($source->counterpartyName, $user->id);

        $type = match (true) {
            $source->amountMinor > 0 => TransactionType::Income->value,
            $source->amountMinor < 0 => TransactionType::Expense->value,
            default => TransactionType::Adjustment->value,
        };

        $settledMinor = $source->settledAmountMinor;
        $settledCurrency = $source->settledCurrency;

        // A source names both halves of its settled leg or neither, so a row
        // without a conversion inherits the native pair. TransactionAmount owns
        // the sign the two legs share and the rate between them, which is why no
        // adapter's own idea of either reaches a column from here.
        $amount = $settledMinor === null || $settledCurrency === null
            ? TransactionAmount::relate($source->amountMinor, $source->currency, $source->amountMinor, $source->currency)
            : TransactionAmount::relate($source->amountMinor, $source->currency, $settledMinor, $settledCurrency);

        return new CanonicalTransaction(
            userId: $user->id,
            accountId: $accountId,
            type: $type,
            postedAt: $source->postedAt,
            bookedAt: $source->bookedAt,
            valueDate: $source->valueDate,
            amountMinor: $amount->amountMinor,
            currency: $amount->currency,
            settledAmountMinor: $amount->settledAmountMinor,
            settledCurrency: $amount->settledCurrency,
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
