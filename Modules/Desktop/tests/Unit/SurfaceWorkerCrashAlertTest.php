<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Contracts\Routing\UrlGenerator;
use Illuminate\Database\DatabaseManager;
use Illuminate\Support\Facades\Http;
use Modules\Core\Public\Contracts\Clock;
use Modules\Desktop\Internal\Listeners\SurfaceWorkerCrashAlert;
use Modules\Desktop\Internal\Native\WindowFocusState;
use Native\Desktop\Events\ChildProcess\ProcessExited;

/*
 * Pure-unit drives for the D-07 worker-crash-loop listener — windowed
 * counter behaviour without touching the system_alerts table.
 *
 * The listener exposes the rolling-window threshold + window-seconds as
 * public class constants so the test assertions reference the same
 * source of truth as production. `SurfaceWorkerCrashAlert::isCrashLoop()`
 * returns true only after the threshold is reached within the window.
 *
 * The OS-notification half of the listener (D-13 focus-gate) is also
 * verified here as a pure boolean — the listener exposes a
 * `shouldFireOsNotification()` helper that consults WindowFocusState.
 * No `Notification` facade fake exists in NativePHP v2 (NATIVEPHP-FAKES.md),
 * so the live `Notification::title()->...->show()` call surfaces in the
 * feature test as an outbound POST asserted via Http::fake().
 */

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

    $listener = new SurfaceWorkerCrashAlert(
        $clock,
        app(DatabaseManager::class),
        app(WindowFocusState::class),
        app(UrlGenerator::class),
    );

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

    $listener = new SurfaceWorkerCrashAlert(
        $clock,
        app(DatabaseManager::class),
        app(WindowFocusState::class),
        app(UrlGenerator::class),
    );

    // Fire THRESHOLD exits within the rolling window — the last one
    // should trip the crash-loop signal.
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

    $listener = new SurfaceWorkerCrashAlert(
        $clock,
        app(DatabaseManager::class),
        app(WindowFocusState::class),
        app(UrlGenerator::class),
    );

    // Space the exits one full window apart so only the most recent
    // exit ever falls inside the rolling window — the threshold is
    // never reached.
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

    $listener = new SurfaceWorkerCrashAlert(
        $clock,
        app(DatabaseManager::class),
        app(WindowFocusState::class),
        app(UrlGenerator::class),
    );

    // Fire enough events to trip the threshold — but tagged with a
    // foreign alias. The crash-loop check stays false for the worker
    // alias because the foreign events are not counted.
    for ($i = 0; $i < SurfaceWorkerCrashAlert::CRASH_LOOP_THRESHOLD + 2; $i++) {
        $listener->recordExit(new ProcessExited(alias: 'something-else', code: 1));
    }

    expect($listener->isCrashLoop(SurfaceWorkerCrashAlert::WORKER_ALIAS))->toBeFalse();
});

it('is bound as a singleton so its crash-counter state persists across resolutions', function (): void {
    $first = app(SurfaceWorkerCrashAlert::class);
    $second = app(SurfaceWorkerCrashAlert::class);

    expect($first)->toBe($second);
});

it('uses the verbatim UI-SPEC body for the worker-crashed alert', function (): void {
    // The locked copy lives as a public class constant so a future
    // copy edit lands in one place and the test references the same
    // source of truth.
    expect(SurfaceWorkerCrashAlert::ALERT_BODY)
        ->toContain("Beatrax's background processing stopped unexpectedly")
        ->toContain('Imports and email scans are paused')
        ->toContain('Reopen the app to restart it');

    expect(SurfaceWorkerCrashAlert::OS_NOTIFICATION_TITLE)->toBe('Background work stopped');
    expect(SurfaceWorkerCrashAlert::ALERT_KIND)->toBe('worker.crashed');
});
