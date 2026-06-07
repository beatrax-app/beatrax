<?php

declare(strict_types=1);

namespace Modules\Forecasting\Public\Dto;

use Carbon\CarbonImmutable;
use Spatie\LaravelData\Data;

/**
 * One account's current balance for the net-worth roll-up. `balanceMinor` is
 * already sign-correct: assets (bank, PayPal) are positive, a credit card is
 * negative (the amount owed), as encoded by the balance-anchor resolver.
 *
 * FX fields (`baseEquivalentMinor`, `fxRate`, `fxSource`, `fxAsOf`,
 * `fxIsStale`) carry the per-account conversion detail so the breakdown row
 * can render the real base-currency equivalent and a rate-disclosure popover
 * (UI-SPEC §5.2/§5.4). They are additive-nullable so existing construction
 * sites keep compiling:
 *   - account already in base currency (passthrough): all FX fields null/false.
 *   - account converted with an available rate: `baseEquivalentMinor` is the
 *     converted minor amount, `fxRate` is the DECIMAL(18,8) rate string used.
 *   - account with NO rate at all (D-07): `currency !== base` yet
 *     `baseEquivalentMinor` stays null — the row shows the no-rate fallback.
 *
 * `fxRate` is `?string` (never float) to preserve DECIMAL(18,8) precision.
 */
final class AccountBalanceLine extends Data
{
    public function __construct(
        public readonly int $accountId,
        public readonly string $name,
        public readonly string $kind,
        public readonly int $balanceMinor,
        public readonly string $currency,
        public readonly bool $isLiability,
        public readonly ?int $baseEquivalentMinor = null,
        public readonly ?string $fxRate = null,
        public readonly ?string $fxSource = null,
        public readonly ?CarbonImmutable $fxAsOf = null,
        public readonly bool $fxIsStale = false,
    ) {}

    /**
     * True when this account was converted to the base currency at an
     * available rate (so the row can show the base equivalent + popover).
     */
    public function isConverted(): bool
    {
        return $this->baseEquivalentMinor !== null;
    }

    /**
     * True when this account is in a non-base currency but no rate was
     * available to convert it (D-07 no-rate fallback row).
     */
    public function hasNoRate(string $baseCurrency): bool
    {
        return $this->currency !== $baseCurrency && $this->baseEquivalentMinor === null;
    }
}
