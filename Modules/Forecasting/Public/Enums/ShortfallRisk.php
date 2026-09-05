<?php

declare(strict_types=1);

namespace Modules\Forecasting\Public\Enums;

// Three answers, because a shortfall count of zero carries two of them: no run
// has covered the horizon yet, and a run has and found nothing. Collapsing them
// to a boolean reported an unforecast month as a safe one.
enum ShortfallRisk: string
{
    case NotYetComputed = 'not_yet_computed';

    case None = 'none';

    case Ahead = 'ahead';

    public function isKnown(): bool
    {
        return $this !== self::NotYetComputed;
    }
}
