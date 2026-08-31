<?php

declare(strict_types=1);

namespace Modules\Recurring\Public\Support;

use Modules\Core\Models\User;
use Modules\Core\Public\Contracts\Clock;

/**
 * @link ../../../../.docs/features/recurring/series-detection.md#why-the-detection-window-default-is-two-months
 */
final class RecurringDetectionWindow
{
    // SeriesDetectionGate::MIN_OCCURRENCES is 2, so two months is the narrowest
    // window that can hold two occurrences of a monthly series. That one
    // reason makes it both the floor the settings screen refuses to go below
    // and the fallback a stored zero or negative resolves to.
    public const int MINIMUM_MONTHS = 2;

    public const int MAXIMUM_MONTHS = 60;

    public static function monthsFor(User $user): int
    {
        $months = $user->recurring_detection_window_months;

        return $months > 0 ? $months : self::MINIMUM_MONTHS;
    }

    // The expense and income passes merge their output into one series set, so
    // the window is opened once here rather than computed on each side.
    // subMonthsNoOverflow keeps a run on the 31st from skipping a month, and a
    // date string is what posted_at's DATE column compares against.
    public static function opensOn(User $user, Clock $clock): string
    {
        return $clock->now()->subMonthsNoOverflow(self::monthsFor($user))->toDateString();
    }
}
