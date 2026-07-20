<?php

declare(strict_types=1);

namespace Modules\Import\Public\Pipeline;

use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Modules\Core\Models\User;
use Modules\Ingestion\Public\Dto\SourceTransactionDto;
use Modules\Ledger\Public\Dto\CanonicalTransaction;
use Modules\Ledger\Public\Services\FingerprintComposer;

/**
 * Converts a SourceTransactionDto into a CanonicalTransaction:
 *
 * 1. Normalises the counterparty name through FingerprintComposer (lowercase,
 *    diacritic strip, punctuation collapse, 80-char truncate).
 * 2. Substitutes the literal `_no_counterparty` sentinel when the
 *    counterparty name is null / empty / punctuation-only — the composite
 *    UNIQUE on transactions requires NOT NULL to catch duplicates that
 *    lack a usable name.
 * 3. Maps the amount sign to Transaction.type: positive → income,
 *    negative → expense, zero → adjustment. Future transfer-pair detection
 *    overrides this mapping for matched cross-account flows.
 * 4. Substitutes the native amount + currency into the settled pair when
 *    the source DTO omits `settledAmountMinor` / `settledCurrency` (every
 *    EUR-native row). When the source supplies a different settled
 *    currency (foreign-currency rows), the canonical row carries both
 *    legs verbatim AND derives `fxRateUsed = settled / native` via
 *    `Brick\Math\BigDecimal` at scale 8 with HALF_UP rounding. Float
 *    arithmetic is forbidden on the money path — the decimal(18,8)
 *    column requires exact precision.
 *
 * The `sourceFormat` is supplied by the orchestrating pipeline so each
 * adapter's rows persist with its own format string for audit, rather than
 * inheriting a single hard-coded literal.
 */
final class NormalizeStage
{
    public const NO_COUNTERPARTY = '_no_counterparty';

    public function __construct(private readonly FingerprintComposer $fingerprints) {}

    public function run(SourceTransactionDto $source, int $accountId, User $user, int $importRunId, string $sourceFormat): CanonicalTransaction
    {
        $name = $source->counterpartyName;
        if ($name === null || trim($name) === '') {
            $normalized = self::NO_COUNTERPARTY;
        } else {
            $normalized = $this->fingerprints->normalize($name);
            if ($normalized === '') {
                $normalized = self::NO_COUNTERPARTY;
            }
        }

        $type = match (true) {
            $source->amountMinor > 0 => 'income',
            $source->amountMinor < 0 => 'expense',
            default => 'adjustment',
        };

        // Substitute settled = native when the source omitted a settled
        // pair: EUR-native rows leave the settled fields null and inherit
        // the native pair; foreign-currency rows carry both pairs
        // verbatim (settled-EUR leg alongside the native amount).
        $settledMinor = $source->settledAmountMinor ?? $source->amountMinor;
        $settledCurrency = $source->settledCurrency ?? $source->currency;

        // Derive the effective post-markup rate when (and only when) both
        // legs are present, currencies differ, and the native amount is
        // non-zero. BigDecimal preserves the decimal(18,8) column's exact
        // precision; float `/` is forbidden on the money path.
        $fxRateUsed = null;
        if (
            $source->settledAmountMinor !== null
            && $source->settledCurrency !== null
            && $source->amountMinor !== 0
            && $source->settledCurrency !== $source->currency
        ) {
            $fxRateUsed = (string) BigDecimal::of((string) $source->settledAmountMinor)
                ->dividedBy(
                    BigDecimal::of((string) $source->amountMinor),
                    8,
                    RoundingMode::HALF_UP,
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
