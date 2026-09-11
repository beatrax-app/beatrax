<?php

declare(strict_types=1);

namespace Modules\Recurring\Internal\Detectors;

use Carbon\CarbonImmutable;
use Modules\Recurring\Internal\InferredCadence;
use Modules\Recurring\Internal\Support\MonthlyEquivalent;
use Modules\Recurring\Public\Enums\SeriesCadence;
use stdClass;

final readonly class DetectedSeries
{
    /**
     * @param  list<stdClass>  $rows
     */
    public function __construct(
        public string $clusterKey,
        public SeriesCadence $cadence,
        public int $latestAmountMinor,
        public string $currency,
        public ?int $monthlyEquivalentMinor,
        public ?CarbonImmutable $nextExpectedAt,
        public bool $confidenceLow,
        public array $rows,
        public ?int $billingDay = null,
    ) {}

    /**
     * @param  list<stdClass>  $rows
     */
    public static function fromCadence(string $clusterKey, InferredCadence $cadence, int $latestAmountMinor, string $currency, array $rows): self
    {
        return new self(
            clusterKey: $clusterKey,
            cadence: $cadence->cadence,
            latestAmountMinor: $latestAmountMinor,
            currency: $currency,
            monthlyEquivalentMinor: self::monthlyEquivalent($latestAmountMinor, $cadence->cadence),
            nextExpectedAt: $cadence->nextExpectedAt,
            confidenceLow: $cadence->confidenceLow,
            rows: $rows,
            billingDay: $cadence->billingDay,
        );
    }

    private static function monthlyEquivalent(int $latestAmountMinor, SeriesCadence $cadence): ?int
    {
        return MonthlyEquivalent::forCadence($latestAmountMinor, $cadence);
    }
}
