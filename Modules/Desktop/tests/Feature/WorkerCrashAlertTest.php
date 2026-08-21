<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Http;
use Modules\Core\Models\SystemAlert;
use Modules\Core\Public\Contracts\Clock;
use Modules\Desktop\Internal\Listeners\SurfaceWorkerCrashAlert;
use Modules\Desktop\Internal\Native\WindowFocusState;
use Native\Desktop\Events\ChildProcess\ProcessExited;

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

// NativePHP fires ProcessExited on every supervisor restart and auto-restarts
// the worker, so a single exit is the steady state, not a failure. Only a
// sustained crash-loop inside the rolling window is worth an alert.
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

    for ($i = 0; $i < SurfaceWorkerCrashAlert::CRASH_LOOP_THRESHOLD; $i++) {
        $clock->time = $clock->time->addSeconds(10);
        $listener->handle(new ProcessExited(alias: SurfaceWorkerCrashAlert::WORKER_ALIAS, code: 1));
    }
    expect(SystemAlert::query()->where('kind', 'worker.crashed')->count())->toBe(1);

    for ($i = 0; $i < SurfaceWorkerCrashAlert::CRASH_LOOP_THRESHOLD; $i++) {
        $clock->time = $clock->time->addSeconds(10);
        $listener->handle(new ProcessExited(alias: SurfaceWorkerCrashAlert::WORKER_ALIAS, code: 1));
    }

    expect(SystemAlert::query()->where('kind', 'worker.crashed')->count())->toBe(1);
});

it('fires the OS notification when the window is UNFOCUSED at crash-loop time', function (): void {
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

    // The Notification facade has no v2 fake, so a fired notification surfaces
    // as an outbound POST on the NativePHP HTTP client.
    Http::assertSent(fn ($request) => str_ends_with((string) $request->url(), '/notification'));
});

it('suppresses the OS notification when the window is FOCUSED at crash-loop time', function (): void {
    Http::fake();

    $clock = freezableClockAt('2026-05-23T12:00:00Z');
    $this->app->instance(Clock::class, $clock);

    // The row still writes when focused; the in-app SystemAlertsBanner surfaces
    // it, which is why the OS toast can be suppressed without losing the signal.
    app(WindowFocusState::class)->markFocused();

    /** @var SurfaceWorkerCrashAlert $listener */
    $listener = app(SurfaceWorkerCrashAlert::class);
    for ($i = 0; $i < SurfaceWorkerCrashAlert::CRASH_LOOP_THRESHOLD; $i++) {
        $clock->time = $clock->time->addSeconds(10);
        $listener->handle(new ProcessExited(alias: SurfaceWorkerCrashAlert::WORKER_ALIAS, code: 1));
    }

    expect(SystemAlert::query()->where('kind', 'worker.crashed')->count())->toBe(1);

    Http::assertNothingSent();
});

it('suppresses the OS notification on a SECOND unfocused crash-loop while the prior alert is still un-acknowledged', function (): void {
    // The de-dup guard used to skip only the system_alerts insert, leaving the
    // OS-notification path to fire on every escalation and spam duplicate toasts
    // at a partner who had already seen the first. It is now gated on
    // `! $alreadyAlerted` as well as the focus state.
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
    Http::assertSent(fn ($request) => str_ends_with((string) $request->url(), '/notification'));

    $sentBefore = count(Http::recorded());
    for ($i = 0; $i < SurfaceWorkerCrashAlert::CRASH_LOOP_THRESHOLD; $i++) {
        $clock->time = $clock->time->addSeconds(10);
        $listener->handle(new ProcessExited(alias: SurfaceWorkerCrashAlert::WORKER_ALIAS, code: 1));
    }

    expect(count(Http::recorded()))->toBe($sentBefore);
    expect(SystemAlert::query()->where('kind', 'worker.crashed')->count())->toBe(1);
});

it('re-fires the OS notification on a fresh crash-loop after the prior alert is acknowledged', function (): void {
    // The de-dup fix could over-correct and silence legitimate crash-loops after
    // the user acknowledged the previous one, so this pins the re-fire.
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

    // Stand in for the user dismissing the row on the SystemAlertsBanner.
    SystemAlert::query()
        ->where('kind', 'worker.crashed')
        ->update(['acknowledged_at' => $clock->now()->toDateTimeString()]);

    $sentBefore = count(Http::recorded());

    for ($i = 0; $i < SurfaceWorkerCrashAlert::CRASH_LOOP_THRESHOLD; $i++) {
        $clock->time = $clock->time->addSeconds(10);
        $listener->handle(new ProcessExited(alias: SurfaceWorkerCrashAlert::WORKER_ALIAS, code: 1));
    }

    expect(SystemAlert::query()->where('kind', 'worker.crashed')->whereNull('acknowledged_at')->count())->toBe(1);
    expect(count(Http::recorded()))->toBeGreaterThan($sentBefore);
});
