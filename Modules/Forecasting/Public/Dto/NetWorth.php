<?php

declare(strict_types=1);

namespace Modules\Forecasting\Public\Dto;

use Spatie\LaravelData\Data;

/**
 * Net-worth roll-up: one EUR figure across all of a user's accounts (assets
 * minus liabilities), with the per-account breakdown. `totalMinor` sums only
 * EUR-denominated accounts; `hasExcludedAccounts` is true when a non-EUR
 * account was left out of the single figure (still shown in `accounts`), since
 * the app has no FX-conversion service for balances yet.
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
    ) {}

    public function hasAccounts(): bool
    {
        return $this->accounts !== [];
    }
}
