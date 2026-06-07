<?php

declare(strict_types=1);

namespace Modules\Forecasting\Public\Dto;

use Carbon\CarbonImmutable;
use Spatie\LaravelData\Data;

/**
 * Net-worth roll-up across all of a user's accounts (assets minus liabilities),
 * with a per-account breakdown and FX conversion metadata.
 *
 * `totalMinor` sums all accounts converted to the user's base currency via
 * ExchangeRateService. `hasExcludedAccounts` is true only when at least one
 * account had no available rate at all (D-07 no-rate fallback). Stale/bundled
 * conversions are still included in the total but flagged via `hasStaleRates`.
 *
 * FX metadata fields (`ratesSource`, `ratesAsOf`, `hasStaleRates`,
 * `accountsWithoutRate`) are nullable with safe defaults so every existing
 * construction site keeps compiling without modification (additive-nullable
 * pattern per RESEARCH Pitfall 7).
 */
final class NetWorth extends Data
{
    /**
     * @param  list<AccountBalanceLine>  $accounts
     */
    public function __construct(
        public readonly int $totalMinor,
        public readonly string $currency,
        public readonly array $accounts,
        public readonly bool $hasExcludedAccounts,
        public readonly ?string $ratesSource = null,
        public readonly ?CarbonImmutable $ratesAsOf = null,
        public readonly bool $hasStaleRates = false,
        public readonly int $accountsWithoutRate = 0,
    ) {}

    public function hasAccounts(): bool
    {
        return $this->accounts !== [];
    }
}
