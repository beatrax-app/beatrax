<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Http;
use Modules\Core\Models\SystemAlert;
use Modules\Core\Public\Contracts\Clock;
use Modules\Desktop\Internal\Listeners\SurfaceWorkerCrashAlert;
use Modules\Desktop\Internal\Native\WindowFocusState;
use Native\Desktop\Events\ChildProcess\ProcessExited;

/*
 * Feature tests for the D-07 worker crash-loop alert listener: NativePHP
 * fires ProcessExited every time the supervisor restarts a worker. A
 * SINGLE exit is NOT the failure signal — NativePHP auto-restarts; a
 * sustained crash-loop is. The listener accumulates exits in a rolling
 * window and only writes a system_alerts row once the threshold is
 * crossed.
 *
 * The system_alerts write uses kind='worker.crashed', severity='critical',
 * user_id=null (system-wide — a crashed worker is not user-scoped).
 *
 * The Notification facade has no v2 fake (NATIVEPHP-FAKES.md), so the
 * OS-notification half is asserted via Http::fake() — a fired notification
 * surfaces as an outbound POST to /api/notification on the NativePHP HTTP
 * client. The focus-gate (D-13) decides whether to fire: unfocused fires,
 * focused stays quiet (the in-app SystemAlertsBanner shows the same row).
 */

/**
 * Builds a Clock whose `time` property the test mutates between exits.
 * The returned anonymous class implements Clock and exposes the mutable
 * `$time` public property; PHP cannot express that as a return type
 * without naming the anonymous class, so we return `Clock` and rely on
 * dynamic property access at the call sites.
 */
function freezableClockAt(string $iso): Clock
{
    return new class(CarbonImmutable::parse($iso)) implements Clock
    {
        public function __construct(public CarbonImmutable $time) {}

        public function now(): CarbonImmutable
        {
            return $this->time;
        }
    };
}

it('does NOT write a system_alerts row for a single ProcessExited (auto-restart is the steady state)', function (): void {
    Http::fake();

    $clock = freezableClockAt('2026-05-23T12:00:00Z');
    $this->app->instance(Clock::class, $clock);

    /** @var SurfaceWorkerCrashAlert $listener */
    $listener = app(SurfaceWorkerCrashAlert::class);
    $listener->handle(new ProcessExited(alias: SurfaceWorkerCrashAlert::WORKER_ALIAS, code: 1));

    expect(SystemAlert::query()->where('kind', 'worker.crashed')->count())->toBe(0);
});

it('writes ONE critical system_alerts row when the worker crash-loops within the rolling window', function (): void {
    Http::fake();

    $clock = freezableClockAt('2026-05-23T12:00:00Z');
    $this->app->instance(Clock::class, $clock);

    /** @var SurfaceWorkerCrashAlert $listener */
    $listener = app(SurfaceWorkerCrashAlert::class);

    // Fire THRESHOLD exits within the window. Each handle() call goes
    // through the production code path (recordExit + isCrashLoop +
    // de-dup + write).
    for ($i = 0; $i < SurfaceWorkerCrashAlert::CRASH_LOOP_THRESHOLD; $i++) {
        $clock->time = $clock->time->addSeconds(10);
        $listener->handle(new ProcessExited(alias: SurfaceWorkerCrashAlert::WORKER_ALIAS, code: 1));
    }

    $alerts = SystemAlert::query()->where('kind', 'worker.crashed')->get();
    expect($alerts)->toHaveCount(1);

    /** @var SystemAlert $alert */
    $alert = $alerts->first();
    expect($alert->severity)->toBe('critical');
    expect($alert->user_id)->toBeNull(); // system-wide
    expect($alert->message)->toBe(SurfaceWorkerCrashAlert::ALERT_BODY);
});

it('uses the UI-SPEC verbatim body for the worker-crashed alert', function (): void {
    Http::fake();

    $clock = freezableClockAt('2026-05-23T12:00:00Z');
    $this->app->instance(Clock::class, $clock);

    /** @var SurfaceWorkerCrashAlert $listener */
    $listener = app(SurfaceWorkerCrashAlert::class);

    for ($i = 0; $i < SurfaceWorkerCrashAlert::CRASH_LOOP_THRESHOLD; $i++) {
        $clock->time = $clock->time->addSeconds(10);
        $listener->handle(new ProcessExited(alias: SurfaceWorkerCrashAlert::WORKER_ALIAS, code: 1));
    }

    /** @var SystemAlert $alert */
    $alert = SystemAlert::query()->where('kind', 'worker.crashed')->first();
    expect($alert->message)
        ->toContain("Beatrax's background processing stopped unexpectedly")
        ->toContain('Imports and email scans are paused')
        ->toContain('Reopen the app to restart it');
});

it('does NOT insert a duplicate row when an un-acknowledged worker.crashed alert already exists (de-dup)', function (): void {
    Http::fake();

    $clock = freezableClockAt('2026-05-23T12:00:00Z');
    $this->app->instance(Clock::class, $clock);

    /** @var SurfaceWorkerCrashAlert $listener */
    $listener = app(SurfaceWorkerCrashAlert::class);

    // First crash-loop produces one row.
    for ($i = 0; $i < SurfaceWorkerCrashAlert::CRASH_LOOP_THRESHOLD; $i++) {
        $clock->time = $clock->time->addSeconds(10);
        $listener->handle(new ProcessExited(alias: SurfaceWorkerCrashAlert::WORKER_ALIAS, code: 1));
    }
    expect(SystemAlert::query()->where('kind', 'worker.crashed')->count())->toBe(1);

    // A new crash-loop while the prior alert remains un-acknowledged
    // does NOT insert a second row.
    for ($i = 0; $i < SurfaceWorkerCrashAlert::CRASH_LOOP_THRESHOLD; $i++) {
        $clock->time = $clock->time->addSeconds(10);
        $listener->handle(new ProcessExited(alias: SurfaceWorkerCrashAlert::WORKER_ALIAS, code: 1));
    }

    expect(SystemAlert::query()->where('kind', 'worker.crashed')->count())->toBe(1);
});

it('fires the OS notification when the window is UNFOCUSED at crash-loop time (D-13 unfocused branch)', function (): void {
    Http::fake();

    $clock = freezableClockAt('2026-05-23T12:00:00Z');
    $this->app->instance(Clock::class, $clock);

    // Unfocused — the focus-gate lets the notification through.
    app(WindowFocusState::class)->markBlurred();

    /** @var SurfaceWorkerCrashAlert $listener */
    $listener = app(SurfaceWorkerCrashAlert::class);
    for ($i = 0; $i < SurfaceWorkerCrashAlert::CRASH_LOOP_THRESHOLD; $i++) {
        $clock->time = $clock->time->addSeconds(10);
        $listener->handle(new ProcessExited(alias: SurfaceWorkerCrashAlert::WORKER_ALIAS, code: 1));
    }

    // The Notification facade has no v2 fake — a fired notification
    // surfaces as an outbound POST to /api/notification on the
    // NativePHP HTTP client.
    Http::assertSent(fn ($request) => str_ends_with((string) $request->url(), '/notification'));
});

it('suppresses the OS notification when the window is FOCUSED at crash-loop time (D-13 focused branch)', function (): void {
    Http::fake();

    $clock = freezableClockAt('2026-05-23T12:00:00Z');
    $this->app->instance(Clock::class, $clock);

    // Focused — the focus-gate suppresses the OS notification. The
    // system_alerts row still writes; the in-app SystemAlertsBanner
    // surfaces the same row to the focused user.
    app(WindowFocusState::class)->markFocused();

    /** @var SurfaceWorkerCrashAlert $listener */
    $listener = app(SurfaceWorkerCrashAlert::class);
    for ($i = 0; $i < SurfaceWorkerCrashAlert::CRASH_LOOP_THRESHOLD; $i++) {
        $clock->time = $clock->time->addSeconds(10);
        $listener->handle(new ProcessExited(alias: SurfaceWorkerCrashAlert::WORKER_ALIAS, code: 1));
    }

    expect(SystemAlert::query()->where('kind', 'worker.crashed')->count())->toBe(1);

    // No outbound POST — the Notification facade was not invoked.
    Http::assertNothingSent();
});

it('suppresses the OS notification on a SECOND unfocused crash-loop while the prior alert is still un-acknowledged (WR-06)', function (): void {
    // The original bug: the de-dup guard skipped the system_alerts
    // insert correctly, but the OS-notification path fired
    // unconditionally on every crash-loop escalation. A repeat
    // crash-loop while the prior alert was still un-acknowledged
    // would spam duplicate OS toasts to a partner who already saw
    // the first one. Now the OS-notification is gated on
    // `! $alreadyAlerted` AS WELL AS the focus state, so the second
    // crash-loop stays quiet until either (a) the row is acknowledged
    // or (b) the user re-focuses + re-blurs, at which point a fresh
    // crash will fire a fresh notification.
    Http::fake();

    $clock = freezableClockAt('2026-05-23T12:00:00Z');
    $this->app->instance(Clock::class, $clock);
    app(WindowFocusState::class)->markBlurred();

    /** @var SurfaceWorkerCrashAlert $listener */
    $listener = app(SurfaceWorkerCrashAlert::class);

    // First crash-loop — one OS notification fires (the unfocused
    // happy path).
    for ($i = 0; $i < SurfaceWorkerCrashAlert::CRASH_LOOP_THRESHOLD; $i++) {
        $clock->time = $clock->time->addSeconds(10);
        $listener->handle(new ProcessExited(alias: SurfaceWorkerCrashAlert::WORKER_ALIAS, code: 1));
    }
    Http::assertSent(fn ($request) => str_ends_with((string) $request->url(), '/notification'));

    // Second crash-loop while the prior alert is still un-acknowledged.
    // The de-dup branch skips the system_alerts insert AND the OS
    // notification — the partner is already aware.
    $sentBefore = count(Http::recorded());
    for ($i = 0; $i < SurfaceWorkerCrashAlert::CRASH_LOOP_THRESHOLD; $i++) {
        $clock->time = $clock->time->addSeconds(10);
        $listener->handle(new ProcessExited(alias: SurfaceWorkerCrashAlert::WORKER_ALIAS, code: 1));
    }

    expect(count(Http::recorded()))->toBe($sentBefore);
    expect(SystemAlert::query()->where('kind', 'worker.crashed')->count())->toBe(1);
});

it('re-fires the OS notification on a fresh crash-loop after the prior alert is acknowledged (regression of WR-06 over-correction)', function (): void {
    // The flip-side check: once the user acknowledges the prior row,
    // a NEW crash-loop must re-insert AND re-notify. Without this
    // assertion the WR-06 fix could over-correct and silence
    // legitimate post-acknowledgement crash-loops.
    Http::fake();

    $clock = freezableClockAt('2026-05-23T12:00:00Z');
    $this->app->instance(Clock::class, $clock);
    app(WindowFocusState::class)->markBlurred();

    /** @var SurfaceWorkerCrashAlert $listener */
    $listener = app(SurfaceWorkerCrashAlert::class);

    for ($i = 0; $i < SurfaceWorkerCrashAlert::CRASH_LOOP_THRESHOLD; $i++) {
        $clock->time = $clock->time->addSeconds(10);
        $listener->handle(new ProcessExited(alias: SurfaceWorkerCrashAlert::WORKER_ALIAS, code: 1));
    }
    expect(SystemAlert::query()->where('kind', 'worker.crashed')->count())->toBe(1);

    // Acknowledge the row — simulate the user clicking "dismiss" on
    // the SystemAlertsBanner.
    SystemAlert::query()
        ->where('kind', 'worker.crashed')
        ->update(['acknowledged_at' => $clock->now()->toDateTimeString()]);

    $sentBefore = count(Http::recorded());

    // A fresh crash-loop now must produce a new row AND a new
    // notification.
    for ($i = 0; $i < SurfaceWorkerCrashAlert::CRASH_LOOP_THRESHOLD; $i++) {
        $clock->time = $clock->time->addSeconds(10);
        $listener->handle(new ProcessExited(alias: SurfaceWorkerCrashAlert::WORKER_ALIAS, code: 1));
    }

    expect(SystemAlert::query()->where('kind', 'worker.crashed')->whereNull('acknowledged_at')->count())->toBe(1);
    expect(count(Http::recorded()))->toBeGreaterThan($sentBefore);
});
