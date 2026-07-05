<?php

declare(strict_types=1);

namespace Modules\Ledger\Public\Dto;

use Carbon\CarbonImmutable;
use Modules\Import\Public\Enums\PaymentType;
use Spatie\LaravelData\Data;

/**
 * The canonical, persistence-ready shape of one transaction row. Ingestion +
 * Import build CanonicalTransaction instances from any source adapter; the
 * Ledger `RecordTransactions` action is the only thing that persists them.
 *
 * `counterparty_normalized` is NEVER NULL: NormalizeStage substitutes a
 * sentinel when the counterparty name is empty so the composite UNIQUE on
 * transactions catches duplicates even when `source_ref` is absent.
 *
 * `autoCategoryProvenance` is nullable JSON shape `{source: 'rule'|'memory',
 * rule_id?: int, memory_id?: int, category_id: int}` stamped by
 * ApplyAutoCategoryStage and persisted by RecordTransactions onto the
 * `transactions.auto_category_provenance` column. Read by the correction-
 * divergence flow (plan 05) so the drawer panel knows whether the
 * suggestion came from an explicit rule (which the user may want to
 * update) or from learned merchant memory.
 *
 * `paymentType` is the resolved `PaymentType` enum value the import
 * pipeline's classifier stage stamps onto every row before persistence.
 * The DB column `transactions.payment_type` carries the same closed
 * enumeration; a paired BEFORE INSERT / BEFORE UPDATE trigger on that
 * column rejects any value outside the enum's eight cases. Defaults to
 * null so legacy call-sites (raw row reconstruction in
 * FingerprintRederiveService, test fixtures, NormalizeStage's first
 * pass before classification) compile and run unchanged.
 *
 * `counterpartyId` is the FK onto `counterparties.id` the
 * ResolveCounterpartyStage stamps onto every row after the resolver
 * upserts the matching Counterparty. Stays null for the self_account
 * branch (the resolver short-circuits without writing a row) and for
 * the pathological branch where the source row carries no name, no
 * IBAN, and no description. Persisted to the `transactions.counterparty_id`
 * column via `toAttributes()`; intentionally NOT part of the
 * fingerprint tuple so re-resolving a row against an updated
 * counterparty model never invalidates a historical fingerprint.
 *
 * `note` mirrors `transactions.note` (nullable free-text). Stamped by
 * `RuleApplier::applyAtImport()` (Plan 05) when a firing rule carries a
 * `note` action — there is no prior stored note at import time, so
 * both `set` and `append` note modes resolve to the payload text
 * outright. Null for every other ingestion path.
 */
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
        public readonly ?array $autoCategoryProvenance = null,
        public readonly ?PaymentType $paymentType = null,
        public readonly ?int $counterpartyId = null,
        public readonly ?string $note = null,
    ) {}

    /**
     * Immutable clone-with-override for `type`. The
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
            autoCategoryProvenance: $this->autoCategoryProvenance,
            paymentType: $this->paymentType,
            counterpartyId: $this->counterpartyId,
            note: $this->note,
        );
    }

    /**
     * Immutable clone-with-override for `categoryId`. The
     * ApplyAutoCategoryStage uses this to stamp the chosen rule /
     * memory category onto the canonical row before fingerprinting
     * + persistence. Pass `null` to explicitly clear the categoryId.
     */
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
            fxRateUsed: $this->fxRateUsed,
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

    /**
     * Immutable clone-with-override for `autoCategoryProvenance`.
     * ApplyAutoCategoryStage builds the provenance map alongside the
     * categoryId so RecordTransactions can persist both atomically.
     *
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
            autoCategoryProvenance: $provenance,
            paymentType: $this->paymentType,
            counterpartyId: $this->counterpartyId,
            note: $this->note,
        );
    }

    /**
     * Immutable clone-with-override for `paymentType`. The
     * `PaymentTypeClassifierStage` uses this to stamp the resolved
     * payment-type chip onto every canonical row after the per-source
     * hinters and the description-keyword fallback have run.
     */
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
            autoCategoryProvenance: $this->autoCategoryProvenance,
            paymentType: $paymentType,
            counterpartyId: $this->counterpartyId,
        );
    }

    /**
     * Immutable clone-with-override for `counterpartyId`. The
     * `ResolveCounterpartyStage` uses this to stamp the FK that points
     * at the upserted `counterparties` row onto every canonical
     * transaction. Pass `null` to leave the FK unset — the
     * `self_account` branch (the user's own account legs) does exactly
     * that, since the resolver short-circuits without writing a
     * counterparty row.
     */
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
            autoCategoryProvenance: $this->autoCategoryProvenance,
            paymentType: $this->paymentType,
            counterpartyId: $counterpartyId,
            note: $this->note,
        );
    }

    /**
     * Immutable clone-with-override for `note`. `RuleApplier::applyAtImport()`
     * (Plan 05) uses this to fold a firing rule's `note` action onto the
     * canonical row before persistence — the sole import-time writer of
     * this field. Pass `null` to explicitly clear the note.
     */
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
            autoCategoryProvenance: $this->autoCategoryProvenance,
            paymentType: $this->paymentType,
            counterpartyId: $this->counterpartyId,
            note: $note,
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
            'status' => $this->sourceFormat === 'manual' ? 'uncleared' : 'cleared',
        ];
    }
}
