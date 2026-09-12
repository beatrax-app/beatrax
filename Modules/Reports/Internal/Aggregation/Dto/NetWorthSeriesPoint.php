<?php

declare(strict_types=1);

namespace Modules\Reports\Internal\Aggregation\Dto;

use Carbon\CarbonImmutable;
use Modules\FX\Public\Dto\ConversionDisclosure;
use Modules\FX\Public\Dto\RateSet;

final readonly class NetWorthSeriesPoint
{
    /**
     * @param  array<int, string>  $excludedAccounts  account id => name, for accounts holding a balance line no rate could convert — keyed so a series can union them instead of adding the same account up once per bucket, and carrying the name because the exclusion names the accounts rather than counting them
     * @param  RateSet  $rates  the rates THIS bucket converted at: the series prices each bucket at the rate in effect on its own day, so sixty buckets hold sixty sets and each of them answers for its own figure and no other
     */
    public function __construct(
        public CarbonImmutable $date,
        public string $label,
        public int $totalMinor,
        public string $currency,
        // Required, never defaulted: a default makes a bucket that converted
        // and will not say at what constructible, which is the state every
        // converting surface was in.
        public RateSet $rates,
        public array $excludedAccounts = [],
    ) {}

    public function excludedCount(): int
    {
        return count($this->excludedAccounts);
    }

    // Null where the bucket converted nothing. Its own exclusions are not
    // named here either: the sentence beside the headline names the accounts
    // over the whole series, and sixty rows would say it sixty times.
    public function conversion(): ?ConversionDisclosure
    {
        return $this->rates->isEmpty() ? null : ConversionDisclosure::of($this->rates);
    }
}
