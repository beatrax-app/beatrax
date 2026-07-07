<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Modules\Core\Models\User;
use Modules\Core\Public\Contracts\Clock;
use Modules\Core\Public\Contracts\CurrentUser;
use Modules\Ledger\Public\Services\PeriodQuery;
use Modules\Reports\Internal\Aggregation\PeriodPresetResolver;

/*
 * Covers 999.6-04 Task 1 / Req 6: PeriodPresetResolver::resolve() for the
 * six presets + custom range. Mirrors PeriodQueryTest's fakeClock/
 * fakeCurrentUser pattern (pure unit test, no DB) — helper functions are
 * prefixed pprt_ to avoid cross-file global-function collisions with
 * Modules/Ledger/tests/Unit/PeriodQueryTest.php's identically-named
 * fakeClock()/fakeCurrentUser() helpers.
 */

function pprtFakeClock(string $instant): Clock
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

function pprtFakeCurrentUser(int $periodStartDay): CurrentUser
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
            throw new LogicException('pprtFakeCurrentUser does not produce a real User');
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

function pprtResolver(string $instant, int $periodStartDay = 1): PeriodPresetResolver
{
    $clock = pprtFakeClock($instant);
    $periodQuery = new PeriodQuery($clock, pprtFakeCurrentUser($periodStartDay));

    return new PeriodPresetResolver($periodQuery, $clock);
}

it('resolves this_month to the same window PeriodQuery::current() produces', function (): void {
    $clock = pprtFakeClock('2026-05-15T10:00:00Z');
    $periodQuery = new PeriodQuery($clock, pprtFakeCurrentUser(1));
    $resolver = new PeriodPresetResolver($periodQuery, $clock);

    $expected = $periodQuery->current();
    $resolved = $resolver->resolve('this_month');

    expect($resolved->start->toDateString())->toBe($expected->start->toDateString());
    expect($resolved->endExclusive->toDateString())->toBe($expected->endExclusive->toDateString());
    expect($resolved->label)->toBe($expected->label);
});

it('resolves last_3_months to a 3-calendar-month half-open window', function (): void {
    $resolver = pprtResolver('2026-05-15T10:00:00Z');
    $period = $resolver->resolve('last_3_months');

    expect($period->start->toDateString())->toBe('2026-03-01');
    expect($period->endExclusive->toDateString())->toBe('2026-06-01');
});

it('resolves last_6_months to a 6-calendar-month half-open window', function (): void {
    $resolver = pprtResolver('2026-05-15T10:00:00Z');
    $period = $resolver->resolve('last_6_months');

    expect($period->start->toDateString())->toBe('2025-12-01');
    expect($period->endExclusive->toDateString())->toBe('2026-06-01');
});

it('resolves last_12_months to a 12-calendar-month half-open window', function (): void {
    $resolver = pprtResolver('2026-05-15T10:00:00Z');
    $period = $resolver->resolve('last_12_months');

    expect($period->start->toDateString())->toBe('2025-06-01');
    expect($period->endExclusive->toDateString())->toBe('2026-06-01');
});

it('resolves ytd to startOfYear through tomorrow (today inclusive)', function (): void {
    $resolver = pprtResolver('2026-05-15T10:00:00Z');
    $period = $resolver->resolve('ytd');

    expect($period->start->toDateString())->toBe('2026-01-01');
    expect($period->endExclusive->toDateString())->toBe('2026-05-16');
    expect($period->label)->toBe('Year to date');
});

it('resolves this_year to the same window as ytd with a different label', function (): void {
    $resolver = pprtResolver('2026-05-15T10:00:00Z');
    $ytd = $resolver->resolve('ytd');
    $thisYear = $resolver->resolve('this_year');

    expect($thisYear->start->toDateString())->toBe($ytd->start->toDateString());
    expect($thisYear->endExclusive->toDateString())->toBe($ytd->endExclusive->toDateString());
    expect($thisYear->label)->toBe('2026');
});

it('resolves custom with an inclusive user-picked end date converted to endExclusive = end+1day', function (): void {
    $resolver = pprtResolver('2026-05-15T10:00:00Z');
    $period = $resolver->resolve('custom', '2026-03-01', '2026-03-15');

    expect($period->start->toDateString())->toBe('2026-03-01');
    // Pitfall 2(a): the 15th itself must still be included, so
    // endExclusive is the 16th, not the 15th.
    expect($period->endExclusive->toDateString())->toBe('2026-03-16');
});

it('throws for a custom preset missing customFrom/customTo', function (): void {
    $resolver = pprtResolver('2026-05-15T10:00:00Z');

    expect(fn () => $resolver->resolve('custom'))->toThrow(InvalidArgumentException::class);
});

it('throws for an unknown preset', function (): void {
    $resolver = pprtResolver('2026-05-15T10:00:00Z');

    expect(fn () => $resolver->resolve('decade'))->toThrow(InvalidArgumentException::class);
});
