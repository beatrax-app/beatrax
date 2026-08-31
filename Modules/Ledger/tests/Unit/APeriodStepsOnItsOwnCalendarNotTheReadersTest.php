<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Modules\Core\Models\User;
use Modules\Core\Public\Contracts\Clock;
use Modules\Core\Public\Contracts\CurrentUser;
use Modules\Core\Public\Exceptions\NotAuthenticatedException;
use Modules\Ledger\Public\Dto\Period;
use Modules\Ledger\Public\Services\PeriodQuery;

// Named for this file rather than reused from PeriodQueryTest: Pest declares a
// test file's functions globally, so a shared name collides when both files
// land in one parallel process and is undefined when this one lands alone.
function periodStepClock(string $instant): Clock
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

function periodStepReader(int $periodStartDay): CurrentUser
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
            throw new NotAuthenticatedException('periodStepReader does not produce a real User');
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

function nobodySignedIn(): CurrentUser
{
    return new class implements CurrentUser
    {
        public function id(): int
        {
            throw new NotAuthenticatedException('No authenticated user is bound to the current guard.');
        }

        public function user(): User
        {
            throw new NotAuthenticatedException('No authenticated user is bound to the current guard.');
        }

        public function periodStartDay(): int
        {
            throw new NotAuthenticatedException('No authenticated user is bound to the current guard.');
        }

        public function isAuthenticated(): bool
        {
            return false;
        }
    };
}

function periodStartingOn(string $start, string $endExclusive): Period
{
    return new Period(CarbonImmutable::parse($start), CarbonImmutable::parse($endExclusive), '');
}

// previous() and next() are handed the period to step from, so the day the
// window turns on is already in their argument. Reading it off the guard
// instead made a pure date operation need somebody to be signed in.
it('steps back from a period handed to it with nobody signed in', function (): void {
    $query = new PeriodQuery(periodStepClock('2026-08-29T00:00:00Z'), nobodySignedIn());

    $previous = $query->previous(periodStartingOn('2026-08-01', '2026-09-01'));

    expect($previous->start->toDateString())->toBe('2026-07-01');
    expect($previous->endExclusive->toDateString())->toBe('2026-08-01');
});

it('steps forward from a period handed to it with nobody signed in', function (): void {
    $query = new PeriodQuery(periodStepClock('2026-08-29T00:00:00Z'), nobodySignedIn());

    $next = $query->next(periodStartingOn('2026-08-01', '2026-09-01'));

    expect($next->start->toDateString())->toBe('2026-09-01');
});

// A reader browsing on the first of the month must not re-window another
// owner's salary cycle onto their own calendar.
it('keeps a salary-cycle period on day 25 while the reader keeps day 1', function (): void {
    $query = new PeriodQuery(periodStepClock('2026-08-29T00:00:00Z'), periodStepReader(1));

    $previous = $query->previous(periodStartingOn('2026-08-25', '2026-09-25'));

    expect($previous->start->toDateString())->toBe('2026-07-25');
    expect($previous->endExclusive->toDateString())->toBe('2026-08-25');
});
