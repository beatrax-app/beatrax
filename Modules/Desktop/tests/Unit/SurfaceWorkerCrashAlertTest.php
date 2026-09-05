<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Contracts\Routing\UrlGenerator;
use Illuminate\Database\DatabaseManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Core\Public\Contracts\Clock;
use Modules\Core\Public\Services\SystemAlertWriter;
use Modules\Desktop\Internal\Listeners\SurfaceWorkerCrashAlert;
use Modules\Desktop\Internal\Native\ShellState;
use Modules\Desktop\Internal\Native\WindowFocusState;
use Native\Desktop\Events\ChildProcess\ProcessExited;

uses(RefreshDatabase::class);

// A new listener for every call, because that is what the shell gets: each
// ProcessExited arrives in a PHP process of its own. Anything these cases prove
// about a counter is therefore proven about a counter that outlives its reader.
function crashListener(Clock $clock): SurfaceWorkerCrashAlert
{
    return new SurfaceWorkerCrashAlert(
        $clock,
        app(DatabaseManager::class),
        app(WindowFocusState::class),
        app(UrlGenerator::class),
        app(SystemAlertWriter::class),
        app(ShellState::class),
    );
}

it('exposes the windowed crash-loop threshold as public constants', function (): void {
    expect(SurfaceWorkerCrashAlert::CRASH_LOOP_THRESHOLD)->toBeGreaterThanOrEqual(2);
    expect(SurfaceWorkerCrashAlert::CRASH_LOOP_WINDOW_SECONDS)->toBeGreaterThan(0);
});

it('returns false on the first ProcessExited for the worker alias', function (): void {
    $clock = new class implements Clock
    {
        public function now(): CarbonImmutable
        {
            return CarbonImmutable::parse('2026-05-23T12:00:00Z');
        }
    };

    $listener = crashListener($clock);

    expect($listener->isCrashLoop(SurfaceWorkerCrashAlert::WORKER_ALIAS))->toBeFalse();

    $listener->recordExit(new ProcessExited(alias: SurfaceWorkerCrashAlert::WORKER_ALIAS, code: 1));

    expect($listener->isCrashLoop(SurfaceWorkerCrashAlert::WORKER_ALIAS))->toBeFalse();
});

it('returns true after threshold ProcessExited events within the rolling window', function (): void {
    $now = CarbonImmutable::parse('2026-05-23T12:00:00Z');
    $clock = new class($now) implements Clock
    {
        public function __construct(public CarbonImmutable $time) {}

        public function now(): CarbonImmutable
        {
            return $this->time;
        }
    };

    $listener = crashListener($clock);

    for ($i = 0; $i < SurfaceWorkerCrashAlert::CRASH_LOOP_THRESHOLD; $i++) {
        $clock->time = $now->addSeconds($i * 10);
        $listener->recordExit(new ProcessExited(alias: SurfaceWorkerCrashAlert::WORKER_ALIAS, code: 1));
    }

    expect($listener->isCrashLoop(SurfaceWorkerCrashAlert::WORKER_ALIAS))->toBeTrue();
});

it('does not flag a crash-loop when exits are spaced beyond the window', function (): void {
    $now = CarbonImmutable::parse('2026-05-23T12:00:00Z');
    $clock = new class($now) implements Clock
    {
        public function __construct(public CarbonImmutable $time) {}

        public function now(): CarbonImmutable
        {
            return $this->time;
        }
    };

    $listener = crashListener($clock);

    // Spacing the exits a full window apart leaves only the most recent one
    // inside the window, so the threshold is never reached.
    $windowSeconds = SurfaceWorkerCrashAlert::CRASH_LOOP_WINDOW_SECONDS;
    for ($i = 0; $i < SurfaceWorkerCrashAlert::CRASH_LOOP_THRESHOLD + 2; $i++) {
        $clock->time = $now->addSeconds($i * ($windowSeconds + 10));
        $listener->recordExit(new ProcessExited(alias: SurfaceWorkerCrashAlert::WORKER_ALIAS, code: 1));
    }

    expect($listener->isCrashLoop(SurfaceWorkerCrashAlert::WORKER_ALIAS))->toBeFalse();
});

it('ignores ProcessExited events for non-worker aliases', function (): void {
    $clock = new class implements Clock
    {
        public function now(): CarbonImmutable
        {
            return CarbonImmutable::parse('2026-05-23T12:00:00Z');
        }
    };

    $listener = crashListener($clock);

    // Enough events to trip the threshold, but under a foreign alias, which the
    // worker's counter must not count.
    for ($i = 0; $i < SurfaceWorkerCrashAlert::CRASH_LOOP_THRESHOLD + 2; $i++) {
        $listener->recordExit(new ProcessExited(alias: 'something-else', code: 1));
    }

    expect($listener->isCrashLoop(SurfaceWorkerCrashAlert::WORKER_ALIAS))->toBeFalse();
});

it('counts an exit recorded by a listener that has already been thrown away', function (): void {
    $clock = new class implements Clock
    {
        public function now(): CarbonImmutable
        {
            return CarbonImmutable::parse('2026-05-23T12:00:00Z');
        }
    };

    for ($i = 0; $i < SurfaceWorkerCrashAlert::CRASH_LOOP_THRESHOLD; $i++) {
        crashListener($clock)->recordExit(new ProcessExited(alias: SurfaceWorkerCrashAlert::WORKER_ALIAS, code: 1));
    }

    expect(crashListener($clock)->isCrashLoop(SurfaceWorkerCrashAlert::WORKER_ALIAS))->toBeTrue(
        'Three exits, three listeners, one crash-loop. Held on the object the '.
        'count reset with every event and the threshold could not be reached.',
    );
});

it('uses the verbatim UI-SPEC body for the worker-crashed alert', function (): void {
    // The copy lives on a class constant so an edit lands in one place.
    expect(SurfaceWorkerCrashAlert::ALERT_BODY)
        ->toContain("Beatrax's background processing stopped unexpectedly")
        ->toContain('Imports and email scans are paused')
        ->toContain('Reopen the app to restart it');

    expect(SurfaceWorkerCrashAlert::OS_NOTIFICATION_TITLE)->toBe('Background work stopped');
    expect(SurfaceWorkerCrashAlert::ALERT_KIND)->toBe('worker.crashed');
});
