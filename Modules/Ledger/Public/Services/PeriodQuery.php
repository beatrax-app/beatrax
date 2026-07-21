<?php

declare(strict_types=1);

namespace Modules\Ledger\Public\Services;

use Carbon\CarbonImmutable;
use Modules\Core\Public\Contracts\Clock;
use Modules\Core\Public\Contracts\CurrentUser;
use Modules\Ledger\Public\Dto\Period;

// start = the most recent occurrence of period_start_day on or before
// the instant in question; endExclusive = start + 1 month via the
// NoOverflow variants so Feb + start_day=28 never rolls into March.
// period_start_day is clamped into 1..28 (not every month has day 29-31).
final class PeriodQuery
{
    public function __construct(
        private readonly Clock $clock,
        private readonly CurrentUser $currentUser,
    ) {}

    public function current(): Period
    {
        return $this->containing($this->clock->now());
    }

    public function containing(CarbonImmutable $instant): Period
    {
        $startDay = max(1, min(28, $this->currentUser->periodStartDay()));

        $candidate = $instant->setDay($startDay)->startOfDay();
        $start = $instant->day >= $startDay
            ? $candidate
            : $candidate->subMonthNoOverflow();
        $endExclusive = $start->addMonthNoOverflow();

        $label = $startDay === 1
            ? $start->format('F Y')
            : $start->format('j M').' → '.$endExclusive->subDay()->format('j M Y');

        return new Period(start: $start, endExclusive: $endExclusive, label: $label);
    }

    public function previous(Period $p): Period
    {
        return $this->containing($p->start->subDay());
    }

    public function next(Period $p): Period
    {
        return $this->containing($p->endExclusive);
    }
}
