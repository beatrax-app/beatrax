<?php

declare(strict_types=1);

namespace Modules\FX\Internal\Build;

use Carbon\CarbonImmutable;
use Modules\Core\Public\Support\SafeDate;
use Modules\FX\Internal\Providers\BundledSnapshotProvider;
use Modules\FX\Public\Exceptions\RateFetchException;

/**
 * @link ../../../../.docs/features/fx/bundled-rates.md#how-old-the-file-may-be-when-a-build-is-cut
 */
final readonly class BundledSnapshot
{
    // The ECB's own short-history feed covers ninety days, which is upstream's
    // reading of how far back a rate is still recent, and the measured cost of
    // the bound is written down beside it in the page linked above.
    public const int SHIP_WITHIN_DAYS = 90;

    public function __construct(
        private BundledSnapshotProvider $provider,
        private string $path,
    ) {}

    public function staleness(CarbonImmutable $now): ?StaleBundledRates
    {
        try {
            $date = $this->provider->fetch()['date'];
        } catch (RateFetchException $e) {
            return StaleBundledRates::unreadable($this->path, $e->getMessage());
        }

        return $this->ageOf($date, $now);
    }

    // Whole days between two midnights, so a build cut at nine in the morning
    // is not a day older than one cut the evening before.
    private function ageOf(string $date, CarbonImmutable $now): ?StaleBundledRates
    {
        $asOf = SafeDate::dayOrNull($date);

        if ($asOf === null) {
            return StaleBundledRates::undated($this->path, $date);
        }

        $age = (int) $asOf->startOfDay()->diffInDays($now->startOfDay());

        return $age > self::SHIP_WITHIN_DAYS
            ? StaleBundledRates::tooOld($this->path, $date, $age, self::SHIP_WITHIN_DAYS)
            : null;
    }
}
