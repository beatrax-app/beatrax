<?php

declare(strict_types=1);

namespace Modules\Forecasting\Public\Dto;

use Carbon\CarbonImmutable;
use Modules\Forecasting\Public\Services\NetWorthQuery;
use Modules\FX\Public\Dto\ConversionDisclosure;
use Modules\FX\Public\Dto\RateSet;
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
        public readonly int $balancesWithoutRate = 0,
        public readonly ?ConversionDisclosure $conversion = null,
    ) {}

    public function hasAccounts(): bool
    {
        return $this->accounts !== [];
    }

    // One account holding two currencies contributes two breakdown lines, so
    // the count the card prints has to be of accounts, not of lines.
    public function accountCount(): int
    {
        return count(array_unique(array_column($this->accounts, 'accountId')));
    }

    // The exclusion said through the same component as the rates, with no rate
    // set of its own: an account is out because no rate reached its currency,
    // so there is none to name beside it.
    public function accountExclusion(): ?ConversionDisclosure
    {
        $names = $this->excludedAccountNames();

        return $names === [] ? null : ConversionDisclosure::of(RateSet::empty($this->currency), $names);
    }

    // The accounts the roll-up left out, by name: an account holding two
    // unconvertible currencies is two lines and one name, and two accounts a
    // reader gave the same name are one name to read.
    /**
     * @return list<string>
     */
    public function excludedAccountNames(): array
    {
        $names = [];
        foreach ($this->accounts as $line) {
            if ($line->hasNoRate($this->currency)) {
                $names[$line->name] = true;
            }
        }

        $named = array_keys($names);
        sort($named);

        return $named;
    }
}
