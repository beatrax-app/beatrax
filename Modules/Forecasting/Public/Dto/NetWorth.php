<?php

declare(strict_types=1);

namespace Modules\Forecasting\Public\Dto;

use Carbon\CarbonImmutable;
use Modules\Forecasting\Public\Services\NetWorthQuery;
use Spatie\LaravelData\Data;

/**
 * @see NetWorthQuery
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
