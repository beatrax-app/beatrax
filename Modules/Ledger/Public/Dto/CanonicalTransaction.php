<?php

declare(strict_types=1);

namespace Modules\Ledger\Public\Dto;

use Carbon\CarbonImmutable;
use Modules\Import\Public\Enums\PaymentType;
use Modules\Import\Public\Enums\SyntheticSourceFormat;
use Modules\Ledger\Public\Enums\ClearedStatus;
use Modules\Ledger\Public\ValueObjects\TransactionAmount;
use Spatie\LaravelData\Data;

final class CanonicalTransaction extends Data
{
    /**
     * @param  array<int|string, mixed>|null  $rawPayload
     * @param  array<string, mixed>|null  $autoCategoryProvenance
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
        public readonly ?array $autoCategoryProvenance = null,
        public readonly ?PaymentType $paymentType = null,
        public readonly ?int $counterpartyId = null,
        public readonly ?string $note = null,
    ) {}

    // ClassifyTransactionType flips the NormalizeStage-derived default
    // (expense/income) to the transfer/refund/fee variants the
    // pair-detection listener requires.
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
            autoCategoryProvenance: $this->autoCategoryProvenance,
            paymentType: $this->paymentType,
            counterpartyId: $this->counterpartyId,
            note: $this->note,
        );
    }

    // ApplyAutoCategoryStage stamps the chosen rule/memory category
    // before fingerprinting + persistence; null explicitly clears it.
    public function withCategoryId(?int $categoryId): self
    {
        return new self(
            userId: $this->userId,
            accountId: $this->accountId,
            type: $this->type,
            postedAt: $this->postedAt,
            bookedAt: $this->bookedAt,
            valueDate: $this->valueDate,
            amountMinor: $this->amountMinor,
            currency: $this->currency,
            settledAmountMinor: $this->settledAmountMinor,
            settledCurrency: $this->settledCurrency,
            counterpartyName: $this->counterpartyName,
            counterpartyIban: $this->counterpartyIban,
            counterpartyNormalized: $this->counterpartyNormalized,
            normalizationVersion: $this->normalizationVersion,
            description: $this->description,
            categoryId: $categoryId,
            sourceFormat: $this->sourceFormat,
            importRunId: $this->importRunId,
            sourceRowIndex: $this->sourceRowIndex,
            sourceRef: $this->sourceRef,
            rawPayload: $this->rawPayload,
            autoCategoryProvenance: $this->autoCategoryProvenance,
            paymentType: $this->paymentType,
            counterpartyId: $this->counterpartyId,
            note: $this->note,
        );
    }

    // ApplyAutoCategoryStage builds this alongside categoryId so
    // RecordTransactions can persist both atomically.
    /**
     * @param  array<string, mixed>|null  $provenance
     */
    public function withAutoCategoryProvenance(?array $provenance): self
    {
        return new self(
            userId: $this->userId,
            accountId: $this->accountId,
            type: $this->type,
            postedAt: $this->postedAt,
            bookedAt: $this->bookedAt,
            valueDate: $this->valueDate,
            amountMinor: $this->amountMinor,
            currency: $this->currency,
            settledAmountMinor: $this->settledAmountMinor,
            settledCurrency: $this->settledCurrency,
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
            autoCategoryProvenance: $provenance,
            paymentType: $this->paymentType,
            counterpartyId: $this->counterpartyId,
            note: $this->note,
        );
    }

    // PaymentTypeClassifierStage stamps the resolved chip after the
    // per-source hinters and the description-keyword fallback have run.
    public function withPaymentType(PaymentType $paymentType): self
    {
        return new self(
            userId: $this->userId,
            accountId: $this->accountId,
            type: $this->type,
            postedAt: $this->postedAt,
            bookedAt: $this->bookedAt,
            valueDate: $this->valueDate,
            amountMinor: $this->amountMinor,
            currency: $this->currency,
            settledAmountMinor: $this->settledAmountMinor,
            settledCurrency: $this->settledCurrency,
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
            autoCategoryProvenance: $this->autoCategoryProvenance,
            paymentType: $paymentType,
            counterpartyId: $this->counterpartyId,
        );
    }

    // ResolveCounterpartyStage stamps the FK onto the upserted
    // counterparties row; null leaves it unset (the self_account
    // branch short-circuits without writing a counterparty row).
    public function withCounterpartyId(?int $counterpartyId): self
    {
        return new self(
            userId: $this->userId,
            accountId: $this->accountId,
            type: $this->type,
            postedAt: $this->postedAt,
            bookedAt: $this->bookedAt,
            valueDate: $this->valueDate,
            amountMinor: $this->amountMinor,
            currency: $this->currency,
            settledAmountMinor: $this->settledAmountMinor,
            settledCurrency: $this->settledCurrency,
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
            autoCategoryProvenance: $this->autoCategoryProvenance,
            paymentType: $this->paymentType,
            counterpartyId: $counterpartyId,
            note: $this->note,
        );
    }

    // RuleApplier::applyAtImport() folds a firing rule's note action
    // before persistence — the sole import-time writer of this field.
    public function withNote(?string $note): self
    {
        return new self(
            userId: $this->userId,
            accountId: $this->accountId,
            type: $this->type,
            postedAt: $this->postedAt,
            bookedAt: $this->bookedAt,
            valueDate: $this->valueDate,
            amountMinor: $this->amountMinor,
            currency: $this->currency,
            settledAmountMinor: $this->settledAmountMinor,
            settledCurrency: $this->settledCurrency,
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
            autoCategoryProvenance: $this->autoCategoryProvenance,
            paymentType: $this->paymentType,
            counterpartyId: $this->counterpartyId,
            note: $note,
        );
    }

    // Every row in the ledger is born here, so the four money columns leave
    // through the seam that keeps them in step rather than however the caller
    // held them: a migrated converted pair arrived under no rate, and its
    // settled leg kept a credit sign its native leg did not have.
    /**
     * @link ../../../../.docs/conventions/invariants-from-shipped-failures.md#money-that-left-its-seam
     */
    public function amount(): TransactionAmount
    {
        return TransactionAmount::relate(
            $this->amountMinor,
            $this->currency,
            $this->settledAmountMinor,
            $this->settledCurrency,
        );
    }

    // Does not include the fingerprint columns or created_at/updated_at
    // — the recorder action adds those via the injected Clock so tests
    // can pin the value.
    /**
     * @return array<string, mixed>
     */
    public function toAttributes(): array
    {
        return $this->amount()->toColumns() + [
            'user_id' => $this->userId,
            'account_id' => $this->accountId,
            'type' => $this->type,
            'posted_at' => $this->postedAt->toDateString(),
            'booked_at' => $this->bookedAt->toDateTimeString(),
            'value_date' => $this->valueDate->toDateString(),
            'counterparty_name' => $this->counterpartyName,
            'counterparty_iban' => $this->counterpartyIban,
            'counterparty_normalized' => $this->counterpartyNormalized,
            'normalization_version' => $this->normalizationVersion,
            'description' => $this->description,
            'category_id' => $this->categoryId,
            'counterparty_id' => $this->counterpartyId,
            'note' => $this->note,
            'auto_category_provenance' => $this->autoCategoryProvenance === null
                ? null
                : json_encode($this->autoCategoryProvenance, JSON_THROW_ON_ERROR),
            'source_format' => $this->sourceFormat,
            'import_run_id' => $this->importRunId,
            'source_row_index' => $this->sourceRowIndex,
            'source_ref' => $this->sourceRef,
            'raw_payload' => $this->rawPayload === null ? null : json_encode($this->rawPayload),
            'payment_type' => ($this->paymentType ?? PaymentType::Unknown)->value,
            'status' => $this->sourceFormat === SyntheticSourceFormat::Manual->value
                ? ClearedStatus::Uncleared->value
                : ClearedStatus::Cleared->value,
        ];
    }
}
