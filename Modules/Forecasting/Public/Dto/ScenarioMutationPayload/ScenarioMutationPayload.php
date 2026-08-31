<?php

declare(strict_types=1);

namespace Modules\Forecasting\Public\Dto\ScenarioMutationPayload;

use InvalidArgumentException;
use Modules\Core\Public\Support\SafeDate;
use Modules\Forecasting\Internal\Casts\ScenarioMutationPayloadCast;
use Modules\Ledger\Public\ValueObjects\Money;
use Spatie\LaravelData\Data;

/**
 * @see ScenarioMutationPayloadCast
 */
abstract class ScenarioMutationPayload extends Data
{
    abstract public function kind(): string;

    // 'usd' and 'ZZZ' both used to persist unchecked, and the first stage that
    // could not denominate them was DailyFold — which refuses by raising, and
    // takes the whole projection with it. Money owns the ISO-4217 registry the
    // rest of the app is measured against, so it is the one asked here.
    protected static function normalisedCurrency(string $raw): string
    {
        $code = strtoupper(trim($raw));
        if (Money::tryOfMinor(0, $code) === null) {
            throw new InvalidArgumentException(
                static::class."::\$currency must be an ISO-4217 code; got '{$raw}'."
            );
        }

        return $code;
    }

    // The constructor is the only point the sidebar form and a row arriving
    // from a peer both pass through — Data::from() runs it too — so this is
    // where a day the calendar does not have is refused. Left to the applier,
    // '2027-02-29' stepped the curve on 1 March under a line reading the 29th.
    protected static function assertCalendarDay(string $raw, string $field): string
    {
        if (SafeDate::dayOrNull($raw) === null) {
            throw new InvalidArgumentException(
                static::class."::\${$field} must be a real calendar date in ".SafeDate::DAY_FORMAT." form; got '{$raw}'."
            );
        }

        return $raw;
    }
}
