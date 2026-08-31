<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Modules\Core\Models\User;
use Modules\Core\Public\Contracts\Clock;
use Modules\Core\Public\Contracts\CurrentUser;
use Modules\Ledger\Public\Services\PeriodQuery;

// Every day belongs to exactly one period and every period meets the next one
// with no gap and no overlap. Held for all 28 start days rather than the
// handful a table lists: at 1 the financial month is the calendar month and a
// month-arithmetic slip has nowhere to show.

function tilingClock(string $instant): Clock
{
    return new class($instant) implements Clock
    {
        public function __construct(private readonly string $instant) {}

        public function now(): CarbonImmutable
        {
            return CarbonImmutable::parse($this->instant);
        }
    };
}

function tilingCurrentUser(int $periodStartDay): CurrentUser
{
    return new class($periodStartDay) implements CurrentUser
    {
        public function __construct(private readonly int $periodStartDay) {}

        public function id(): int
        {
            return 1;
        }

        public function user(): User
        {
            throw new LogicException('tilingCurrentUser produces no real User');
        }

        public function periodStartDay(): int
        {
            return $this->periodStartDay;
        }

        public function isAuthenticated(): bool
        {
            return true;
        }
    };
}

it('meets the next window exactly, and comes back, for every start day', function (int $startDay): void {
    $query = new PeriodQuery(tilingClock('2026-05-12 00:00:00'), tilingCurrentUser($startDay));
    $period = $query->containing(CarbonImmutable::parse('2025-11-05 00:00:00'));
    $problems = [];

    for ($i = 0; $i < 18; $i++) {
        $next = $query->next($period);
        if (! $next->start->equalTo($period->endExclusive)) {
            $problems[] = 'next('.$period->start->toDateString().') opens '.$next->start->toDateString()
                .', the window closed '.$period->endExclusive->toDateString();
        }
        if (! $query->previous($next)->start->equalTo($period->start)) {
            $problems[] = 'previous(next('.$period->start->toDateString().')) = '
                .$query->previous($next)->start->toDateString();
        }

        foreach ([$period->start, $period->endExclusive->subDay()] as $edge) {
            if (! $query->containing($edge)->start->equalTo($period->start)) {
                $problems[] = 'containing('.$edge->toDateString().') = '
                    .$query->containing($edge)->start->toDateString().', expected '.$period->start->toDateString();
            }
            $anchored = $query->containingDate($edge->toDateString());
            if ($anchored === null || ! $anchored->start->equalTo($period->start)) {
                $problems[] = 'containingDate('.$edge->toDateString().') did not land on '.$period->start->toDateString();
            }
        }

        $period = $next;
    }

    expect($problems)->toBe([]);
})->with(range(PeriodQuery::MIN_START_DAY, PeriodQuery::MAX_START_DAY));
