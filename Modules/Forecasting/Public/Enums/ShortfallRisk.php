<?php

declare(strict_types=1);

namespace Modules\Forecasting\Public\Enums;

// Four answers, because a shortfall count of zero carries three of them: no run
// has covered the horizon yet, a run has and found nothing, and a rerun is in
// hand over the last one's figures. A boolean reported an unforecast month as a
// safe one; three reported the superseded run's answer as this one's.
enum ShortfallRisk: string
{
    case NotYetComputed = 'not_yet_computed';

    case Computing = 'computing';

    case None = 'none';

    case Ahead = 'ahead';

    public function isKnown(): bool
    {
        return match ($this) {
            self::None, self::Ahead => true,
            self::NotYetComputed, self::Computing => false,
        };
    }
}
