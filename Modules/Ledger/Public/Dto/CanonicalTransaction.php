<?php

declare(strict_types=1);

namespace Modules\Ledger\Public\Dto;

use Carbon\CarbonImmutable;
use Spatie\LaravelData\Data;

/**
 * The canonical, persistence-ready shape of one transaction row. Ingestion +
 * Import build CanonicalTransaction instances from any source adapter; the
 * Ledger `RecordTransactions` action is the only thing that persists them.
 *
 * `counterparty_normalized` is NEVER NULL (Pitfall 5 mitigation): the
 * NormalizeStage substitutes a sentinel when both the counterparty name and
 * description are empty, so the composite UNIQUE on transactions catches
 * duplicates even when source_ref is absent.
 */
final class CanonicalTransaction extends Data
{
    public function __construct(
        public readonly ?int $userId,
        public readonly int $accountId,
        public readonly string $type,
        public readonly CarbonImmutable $postedAt,
        public readonly CarbonImmutable $bookedAt,
        public readonly CarbonImmutable $valueDate,
        public readonly int $amountMinor,
        public readonly string $currency,
        public readonly int $settledAmountMinor,
        public readonly string $settledCurrency,
        public readonly ?string $fxRateUsed,
        public readonly ?string $counterpartyName,
        public readonly ?string $counterpartyIban,
        public readonly string $counterpartyNormalized,
        public readonly int $normalizationVersion,
        public readonly ?string $description,
        public readonly ?int $categoryId,
        public readonly string $sourceFormat,
        public readonly int $importRunId,
        public readonly int $sourceRowIndex,
        public readonly ?string $sourceRef,
    ) {}

    /**
     * Returns the column-name → value map ready for direct DB insert via
     * `Transaction::query()->insertOrIgnore($attrs)`. Does NOT include the
     * fingerprint columns — the action adds those after composing them.
     *
     * @return array<string, mixed>
     */
    public function toAttributes(): array
    {
        $now = CarbonImmutable::now();

        return [
            'user_id' => $this->userId,
            'account_id' => $this->accountId,
            'type' => $this->type,
            'posted_at' => $this->postedAt->toDateString(),
            'booked_at' => $this->bookedAt->toDateTimeString(),
            'value_date' => $this->valueDate->toDateString(),
            'amount_minor' => $this->amountMinor,
            'currency' => $this->currency,
            'settled_amount_minor' => $this->settledAmountMinor,
            'settled_currency' => $this->settledCurrency,
            'fx_rate_used' => $this->fxRateUsed,
            'counterparty_name' => $this->counterpartyName,
            'counterparty_iban' => $this->counterpartyIban,
            'counterparty_normalized' => $this->counterpartyNormalized,
            'normalization_version' => $this->normalizationVersion,
            'description' => $this->description,
            'category_id' => $this->categoryId,
            'source_format' => $this->sourceFormat,
            'import_run_id' => $this->importRunId,
            'source_row_index' => $this->sourceRowIndex,
            'source_ref' => $this->sourceRef,
            'status' => 'cleared',
            'created_at' => $now->toDateTimeString(),
            'updated_at' => $now->toDateTimeString(),
        ];
    }
}
