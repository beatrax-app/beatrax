<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Modules\Core\Models\User;
use Modules\Core\Public\Contracts\Clock;
use Modules\Core\Public\Contracts\CurrentUser;
use Modules\Ledger\Public\Services\PeriodQuery;

/**
 * Builds an anonymous Clock that always returns a fixed instant.
 */
function fakeClock(string $instant): Clock
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

/**
 * Builds an anonymous CurrentUser that returns a fixed periodStartDay.
 */
function periodQueryCurrentUser(int $periodStartDay): CurrentUser
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
            throw new LogicException('periodQueryCurrentUser does not produce a real User');
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

it('builds a calendar-month period when period_start_day=1', function (): void {
    $query = new PeriodQuery(fakeClock('2026-05-12T10:00:00Z'), periodQueryCurrentUser(1));
    $period = $query->current();

    expect($period->start->toDateString())->toBe('2026-05-01');
    expect($period->endExclusive->toDateString())->toBe('2026-06-01');
    expect($period->label)->toBe('May 2026');
});

it('builds a salary-cycle period (start_day=25) when instant is past day 25', function (): void {
    $query = new PeriodQuery(fakeClock('2026-05-26T00:00:00Z'), periodQueryCurrentUser(25));
    $period = $query->containing(CarbonImmutable::parse('2026-05-26T00:00:00Z'));

    expect($period->start->toDateString())->toBe('2026-05-25');
    expect($period->endExclusive->toDateString())->toBe('2026-06-25');
});

it('builds the prior salary-cycle period (start_day=25) when instant is before day 25', function (): void {
    $query = new PeriodQuery(fakeClock('2026-05-12T00:00:00Z'), periodQueryCurrentUser(25));
    $period = $query->containing(CarbonImmutable::parse('2026-05-12T00:00:00Z'));

    expect($period->start->toDateString())->toBe('2026-04-25');
    expect($period->endExclusive->toDateString())->toBe('2026-05-25');
});

it('previous() rewinds one window', function (): void {
    $query = new PeriodQuery(fakeClock('2026-05-12T00:00:00Z'), periodQueryCurrentUser(1));
    $current = $query->current();
    $previous = $query->previous($current);

    expect($previous->start->toDateString())->toBe('2026-04-01');
    expect($previous->endExclusive->toDateString())->toBe('2026-05-01');
});

it('next() advances one window', function (): void {
    $query = new PeriodQuery(fakeClock('2026-05-12T00:00:00Z'), periodQueryCurrentUser(1));
    $current = $query->current();
    $next = $query->next($current);

    expect($next->start->toDateString())->toBe('2026-06-01');
    expect($next->endExclusive->toDateString())->toBe('2026-07-01');
});

it('clamps period_start_day to the 1..28 window', function (): void {
    $query = new PeriodQuery(fakeClock('2026-05-12T00:00:00Z'), periodQueryCurrentUser(99));
    $period = $query->current();

    // Clamped to day 28; instant 12 is before 28 → previous month.
    expect($period->start->toDateString())->toBe('2026-04-28');
});

dataset('period_sweep', function () {
    $startDays = [1, 7, 15, 25, 28];
    $instants = [
        '2026-01-01T00:00:00Z',
        '2026-02-15T12:00:00Z',
        '2026-02-28T23:59:59Z',
        '2026-03-01T00:00:00Z',
        '2026-12-31T23:59:59Z',
    ];

    foreach ($startDays as $sd) {
        foreach ($instants as $i) {
            yield "sd={$sd}, instant={$i}" => [$sd, $i];
        }
    }
});

it('period contains the instant for every (start_day, instant) pair', function (int $startDay, string $instant): void {
    $query = new PeriodQuery(fakeClock($instant), periodQueryCurrentUser($startDay));
    $period = $query->containing(CarbonImmutable::parse($instant));

    expect($period->start->lessThanOrEqualTo(CarbonImmutable::parse($instant)))->toBeTrue();
    expect($period->endExclusive->greaterThan(CarbonImmutable::parse($instant)))->toBeTrue();
})->with('period_sweep');
