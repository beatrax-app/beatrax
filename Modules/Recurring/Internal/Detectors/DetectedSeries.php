<?php

declare(strict_types=1);

namespace Modules\Recurring\Internal\Detectors;

use Carbon\CarbonImmutable;
use Modules\Recurring\Public\Enums\SeriesCadence;
use stdClass;

// What a cluster of transactions turned out to be, once the variance
// filter and the cadence inferrer have both had their say. Both detectors
// compute these eight values together and then hand them on together, so
// they travel as one thing rather than as eight positional arguments.
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
    ) {}

    /**
     * @param  array{cadence: SeriesCadence, median_interval_days: float, next_expected_at: ?CarbonImmutable, confidence_low: bool, missed_count: int}  $cadence
     * @param  list<stdClass>  $rows
     */
    public static function fromCadence(string $clusterKey, array $cadence, int $latestAmountMinor, string $currency, array $rows): self
    {
        return new self(
            clusterKey: $clusterKey,
            cadence: $cadence['cadence'],
            latestAmountMinor: $latestAmountMinor,
            currency: $currency,
            monthlyEquivalentMinor: self::monthlyEquivalent($latestAmountMinor, $cadence['cadence']),
            nextExpectedAt: $cadence['next_expected_at'],
            confidenceLow: $cadence['confidence_low'],
            rows: $rows,
        );
    }

    private static function monthlyEquivalent(int $latestAmountMinor, SeriesCadence $cadence): ?int
    {
        return match ($cadence) {
            // 52/12 is the exact weeks-per-month conversion; the rounded
            // literal 4.33 drifted by about 0.07% on every weekly row, so a
            // weekly ten projects to 43.33 a month rather than 43.30.
            SeriesCadence::Weekly => (int) round($latestAmountMinor * 52 / 12),
            SeriesCadence::Monthly => $latestAmountMinor,
            SeriesCadence::Quarterly => (int) round($latestAmountMinor / 3),
            SeriesCadence::Yearly => (int) round($latestAmountMinor / 12),
            SeriesCadence::Irregular => null,
        };
    }
}
