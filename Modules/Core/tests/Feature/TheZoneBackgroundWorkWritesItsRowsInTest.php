<?php

declare(strict_types=1);

use Illuminate\Console\Events\CommandStarting;
use Illuminate\Contracts\Queue\Job;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Queue\Events\JobProcessing;
use Modules\Core\Public\Support\HostTimezone;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;

uses(RefreshDatabase::class);

// `SetInstallTimezone` binds the zone on the `web` group, and neither a
// scheduled command nor a queued job passes through it. Both then wrote their
// DATETIME columns in whatever the configuration bootstrapped to, which on
// every shipped bundle is UTC because none of them pins APP_TIMEZONE.

// Measured with the pin removed: a console run reported the zone as
// Europe/Amsterdam and now() as 21:51 while the wall clock said 23:51 — one
// installation writing its requests in one frame and its background work in
// another.
beforeEach(function (): void {
    config()->set('app.timezone_pinned', null);
    config()->set('app.timezone', 'UTC');
    date_default_timezone_set('UTC');
    HostTimezone::fake('Europe/Amsterdam');
});

afterEach(function (): void {
    HostTimezone::fake(null);
    date_default_timezone_set('UTC');
});

it('binds the zone before a console command writes anything', function (): void {
    event(new CommandStarting('fx:refresh-rates', new ArrayInput([]), new BufferedOutput));

    expect(config('app.timezone'))->toBe('Europe/Amsterdam')
        ->and(date_default_timezone_get())->toBe('Europe/Amsterdam');
});

it('binds the zone before a queued job writes anything', function (): void {
    // shouldIgnoreMissing plus an explicit payload: the framework's own
    // JobProcessing listener reads the payload to rehydrate log context, and a
    // bare double makes that the failure rather than the zone.
    $job = Mockery::mock(Job::class)->shouldIgnoreMissing();
    $job->allows('payload')->andReturn([]);

    event(new JobProcessing('database', $job));

    expect(config('app.timezone'))->toBe('Europe/Amsterdam')
        ->and(date_default_timezone_get())->toBe('Europe/Amsterdam');
});

// Applying the zone mutates config('app.timezone'), and these two serialise
// the configuration to disk. Letting them observe a resolved value would bake
// the packager's zone into a cached config — the pin no shipped bundle
// carries, arriving by the back door.
it('leaves the configuration alone for the commands that write it to disk', function (string $command): void {
    event(new CommandStarting($command, new ArrayInput([]), new BufferedOutput));

    expect(config('app.timezone'))->toBe('UTC');
})->with(['config:cache', 'optimize']);

// now() is what a scheduled writer stamps a row with, so the resolved zone
// has to reach the clock and not only the config value.
it('moves the clock a background writer actually reads', function (): void {
    $utc = now()->format('H');

    event(new CommandStarting('recurring:detect', new ArrayInput([]), new BufferedOutput));

    $resolved = now()->format('H');

    expect($resolved)->not->toBe($utc, 'the clock stayed in the frame the bundle bootstrapped to');
});
