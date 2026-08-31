<?php

declare(strict_types=1);

namespace Modules\Transfers\Public\Support;

use Carbon\CarbonImmutable;
use Modules\Transfers\Public\Enums\CounterLegOrder;

// When the far leg may sit, and which of several the caller wants. The order
// belongs here rather than beside the match because NearestToCentre is nearest
// to THIS centre: the date below is both the middle of the window and the point
// the ranking measures from.
final readonly class CounterLegWindow
{
    // How far apart the two legs of one movement may sit. The bank pairer
    // called it WINDOW_DAYS and the PayPal funding resolver DATE_WINDOW_DAYS,
    // both three, both rebuilding the bounds from their own copy. The real
    // PayPal export puts both legs on one timestamp, so three days is slack.
    public const int DEFAULT_DAYS = 3;

    public function __construct(
        public CarbonImmutable $bookedAt,
        public int $windowDays,
        public CounterLegOrder $order,
    ) {}
}
