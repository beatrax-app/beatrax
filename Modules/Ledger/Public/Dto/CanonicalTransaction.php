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
 * `counterparty_normalized` is NEVER NULL: NormalizeStage substitutes a
 * sentinel when the counterparty name is empty so the composite UNIQUE on
 * transactions catches duplicates even when `source_ref` is absent.
 */
final class CanonicalTransaction extends Data
{
    /**
     * @param  array<int|string, mixed>|null  $rawPayload
     */
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
        public readonly ?array $rawPayload = null,
    ) {}

    /**
     * Immutable clone-with-override for `type`. The Wave 2
     * ClassifyTransactionType pipeline stage uses this to flip the
     * NormalizeStage-derived default (`expense` / `income`) to the
     * transfer / refund / fee variants required by the pair-detection
     * listener.
     */
    public function withType(string $type): self
    {
        return new self(
            userId: $this->userId,
            accountId: $this->accountId,
            type: $type,
            postedAt: $this->postedAt,
            bookedAt: $this->bookedAt,
            valueDate: $this->valueDate,
            amountMinor: $this->amountMinor,
            currency: $this->currency,
            settledAmountMinor: $this->settledAmountMinor,
            settledCurrency: $this->settledCurrency,
            fxRateUsed: $this->fxRateUsed,
            counterpartyName: $this->counterpartyName,
            counterpartyIban: $this->counterpartyIban,
            counterpartyNormalized: $this->counterpartyNormalized,
            normalizationVersion: $this->normalizationVersion,
            description: $this->description,
            categoryId: $this->categoryId,
            sourceFormat: $this->sourceFormat,
            importRunId: $this->importRunId,
            sourceRowIndex: $this->sourceRowIndex,
            sourceRef: $this->sourceRef,
            rawPayload: $this->rawPayload,
        );
    }

    /**
     * Returns the column-name → value map ready for direct DB insert via
     * `Transaction::query()->insertOrIgnore($attrs)`. Does NOT include the
     * fingerprint columns or the created_at/updated_at timestamps — the
     * recorder action adds those (timestamps via the injected Clock, so
     * tests can pin the value through `CarbonImmutable::setTestNow`).
     *
     * @return array<string, mixed>
     */
    public function toAttributes(): array
    {
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
            'raw_payload' => $this->rawPayload === null ? null : json_encode($this->rawPayload),
            'status' => 'cleared',
        ];
    }
}
