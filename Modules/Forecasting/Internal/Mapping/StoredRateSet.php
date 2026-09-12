<?php

declare(strict_types=1);

namespace Modules\Forecasting\Internal\Mapping;

use Carbon\CarbonImmutable;
use Modules\Core\Public\Concerns\CoercesScalars;
use Modules\Core\Public\Support\SafeDate;
use Modules\FX\Public\Dto\RateSet;
use Modules\FX\Public\Dto\RateUsed;

// The rates a projection converted at, written into and read back out of
// `forecast_runs.result_json`. Both directions live here because a curve
// rehydrated from a shape the writer no longer produces would disclose a rate
// nobody used.
/**
 * @link ../../../../.docs/features/forecasting/architecture.md#projection-orchestration-and-output-shape
 */
final readonly class StoredRateSet
{
    use CoercesScalars;

    public const string KEY = 'rates';

    /**
     * @return list<array{from: string, to: string, rate: string, source: ?string, as_of: ?string}>
     */
    public static function encode(RateSet $rates): array
    {
        $encoded = [];

        foreach ($rates->all() as $rate) {
            // The exact decimal string the column holds, never a float: a rate
            // that becomes one has already lost the digits that let the reader
            // rebuild the figure beside it.
            $encoded[] = [
                'from' => $rate->from,
                'to' => $rate->to,
                'rate' => $rate->rate,
                'source' => $rate->source,
                'as_of' => $rate->asOf?->toDateString(),
            ];
        }

        return $encoded;
    }

    // Staleness is not stored. It is a fact about the day the rate is READ, and
    // a boolean written months ago would sit under a date that is still true
    // saying the rate is fresh.
    public static function decode(mixed $raw, string $targetCurrency, CarbonImmutable $readOn): ?RateSet
    {
        if (! is_array($raw)) {
            return null;
        }

        $rates = RateSet::empty($targetCurrency);

        foreach ($raw as $entry) {
            $leg = is_array($entry) ? self::leg($entry, $targetCurrency, $readOn) : null;

            if ($leg !== null) {
                $rates = $rates->with($leg);
            }
        }

        return $rates;
    }

    /**
     * @param  array<array-key, mixed>  $entry
     */
    private static function leg(array $entry, string $targetCurrency, CarbonImmutable $readOn): ?RateUsed
    {
        $from = self::toString($entry['from'] ?? null);
        $rate = self::toString($entry['rate'] ?? null);
        $source = self::toString($entry['source'] ?? null);
        $asOf = self::toString($entry['as_of'] ?? null);

        // A leg missing either half prices nothing, and a zero-length code
        // would collide with the next one in a set keyed by it.
        if ($from === '' || $rate === '') {
            return null;
        }

        $to = self::toString($entry['to'] ?? null);

        return RateUsed::asReadOn(
            readOn: $readOn,
            from: $from,
            to: $to === '' ? $targetCurrency : $to,
            rate: $rate,
            source: $source === '' ? null : $source,
            // Through SafeDate: a bare parse of a day this build does not
            // recognise is NOW, which would date a stored rate today and
            // report a ninety-nine-day-old snapshot as fresh.
            asOf: SafeDate::dayOrNull($asOf),
        );
    }
}
