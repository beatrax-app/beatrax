<?php

declare(strict_types=1);

namespace Modules\Import\Internal\Pipeline\Stages;

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
 * 4. Mirrors the native amount + currency into the settled pair. Multi-
 *    currency adapters override these fields with their own settled
 *    amount + FX rate.
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

        return new CanonicalTransaction(
            userId: $user->id,
            accountId: $accountId,
            type: $type,
            postedAt: $source->postedAt,
            bookedAt: $source->bookedAt,
            valueDate: $source->valueDate,
            amountMinor: $source->amountMinor,
            currency: $source->currency,
            settledAmountMinor: $source->amountMinor,
            settledCurrency: $source->currency,
            fxRateUsed: null,
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
        );
    }
}
