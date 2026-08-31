<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Cache\ArrayStore;
use Illuminate\Cache\Repository;
use Modules\Core\Public\Contracts\Clock;
use Modules\Core\Public\Scheduling\DailyLocalWindow;

// The phone's background runner takes a repeat period and nothing else, so a
// task that must not fire before 09:15 ticks on an interval and asks this.
// A digest at midnight is a different product, and SuppressionEvaluator's
// quiet hours would swallow it, so the row would be written and never sent.

function dailyLocalWindowAt(string $moment, Repository $cache): DailyLocalWindow
{
    $clock = new class($moment) implements Clock
    {
        public function __construct(private readonly string $moment) {}

        public function now(): CarbonImmutable
        {
            return CarbonImmutable::parse($this->moment);
        }
    };

    return new DailyLocalWindow($cache, $clock);
}

it('stays shut before the local time the task is due at', function (): void {
    $cache = new Repository(new ArrayStore);

    expect(dailyLocalWindowAt('2026-08-29 09:14:59', $cache)->isDue('k', '09:15'))->toBeFalse();
    expect(dailyLocalWindowAt('2026-08-29 09:14:59', $cache)->claim('k', '09:15'))->toBeFalse();
    expect(dailyLocalWindowAt('2026-08-29 00:00:00', $cache)->claim('k', '09:15'))->toBeFalse();
});

it('opens on the first tick at or after that time, and closes for the rest of the day', function (): void {
    $cache = new Repository(new ArrayStore);

    expect(dailyLocalWindowAt('2026-08-29 09:15:00', $cache)->isDue('k', '09:15'))->toBeTrue();
    expect(dailyLocalWindowAt('2026-08-29 09:15:00', $cache)->claim('k', '09:15'))->toBeTrue();

    expect(dailyLocalWindowAt('2026-08-29 09:30:00', $cache)->claim('k', '09:15'))->toBeFalse();
    expect(dailyLocalWindowAt('2026-08-29 23:45:00', $cache)->claim('k', '09:15'))->toBeFalse();
    expect(dailyLocalWindowAt('2026-08-29 23:45:00', $cache)->isDue('k', '09:15'))->toBeFalse();
});

it('opens again the next local day', function (): void {
    $cache = new Repository(new ArrayStore);

    expect(dailyLocalWindowAt('2026-08-29 09:15:00', $cache)->claim('k', '09:15'))->toBeTrue();
    expect(dailyLocalWindowAt('2026-08-30 09:15:00', $cache)->claim('k', '09:15'))->toBeTrue();
});

// isDue() is what the desktop scheduler asks as a ->when() filter, ninety-six
// times a day for a fifteen-minute entry. Consuming the day there would leave
// the command it gates with nothing to do when it re-asks.
it('does not consume the day when only asked whether it is due', function (): void {
    $cache = new Repository(new ArrayStore);

    expect(dailyLocalWindowAt('2026-08-29 09:15:00', $cache)->isDue('k', '09:15'))->toBeTrue();
    expect(dailyLocalWindowAt('2026-08-29 09:15:00', $cache)->isDue('k', '09:15'))->toBeTrue();
    expect(dailyLocalWindowAt('2026-08-29 09:15:00', $cache)->claim('k', '09:15'))->toBeTrue();
});

it('keeps one key\'s day apart from another\'s', function (): void {
    $cache = new Repository(new ArrayStore);

    expect(dailyLocalWindowAt('2026-08-29 09:15:00', $cache)->claim('one', '09:15'))->toBeTrue();
    expect(dailyLocalWindowAt('2026-08-29 09:15:00', $cache)->claim('two', '09:15'))->toBeTrue();
});
