<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Database\QueryException;
use Illuminate\Queue\Events\Looping;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Modules\Core\Models\SystemAlert;
use Modules\Core\Public\Contracts\Clock;
use Modules\Desktop\Internal\Listeners\SurfaceWorkerCrashAlert;
use Modules\Desktop\Internal\Native\ShellState;
use Native\Desktop\Events\ChildProcess\ProcessExited;

// Advanced rather than frozen: every case here turns on how long ago the last
// exit was, which is the one thing a fixed clock cannot say.
function workerBackClock(string $iso): Clock
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

function workerBackCrashLoop(Clock $clock): void
{
    /** @var SurfaceWorkerCrashAlert $listener */
    $listener = app(SurfaceWorkerCrashAlert::class);

    for ($i = 0; $i < SurfaceWorkerCrashAlert::CRASH_LOOP_THRESHOLD; $i++) {
        $clock->time = $clock->time->addSeconds(10);
        $listener->handle(new ProcessExited(alias: SurfaceWorkerCrashAlert::WORKER_ALIAS_PREFIX.'default', code: 1));
    }
}

// Through the dispatcher, not the method: the signal only reaches the reader if
// DesktopServiceProvider actually binds it, and a direct call would pass on a
// branch where nothing is listening.
function workerBackTick(): void
{
    /** @var Dispatcher $events */
    $events = app(Dispatcher::class);
    $events->dispatch(new Looping('database', 'default'));
}

// Standing in for the minute the throttle holds. Real time does not move inside
// a test, so the slot has to be retired by hand.
function workerBackMinutePasses(): void
{
    app(ShellState::class)->forget(SurfaceWorkerCrashAlert::RECOVERY_PROBE_SLOT);
}

function workerBackOpen(string $kind): int
{
    return SystemAlert::query()->where('kind', $kind)->whereNull('acknowledged_at')->count();
}

// A corrupt backup is the event itself, not a state anything re-reads. Raised
// beside the crash alert so a withdrawal that swept the table rather than the
// one kind it healed is caught by the run that proves the sweep happens at all.
function workerBackLatchingAlert(): int
{
    /** @var SystemAlert $alert */
    $alert = SystemAlert::query()->create([
        'user_id' => null,
        'dedup_key' => null,
        'kind' => 'backup_corrupt',
        'severity' => 'critical',
        'message' => 'The backup written at 2026-09-11 09:00 failed integrity check.',
        'metadata' => null,
    ]);

    return $alert->id;
}

it('takes the worker banner down once the worker has run a full window without exiting', function (): void {
    Http::fake();

    $clock = workerBackClock('2026-05-23T12:00:00Z');
    $this->app->instance(Clock::class, $clock);

    workerBackCrashLoop($clock);
    expect(workerBackOpen('worker.crashed'))->toBe(1, 'The crash-loop should have raised one open row to withdraw.');

    $latchingId = workerBackLatchingAlert();

    // The reader does what the banner told them to. The worker comes back and
    // keeps running, and a whole crash-loop window passes with no exit in it.
    $clock->time = $clock->time->addSeconds(SurfaceWorkerCrashAlert::CRASH_LOOP_WINDOW_SECONDS + 10);
    workerBackTick();

    expect(workerBackOpen('worker.crashed'))
        ->toBe(0, 'The worker is running again and the banner still says imports are paused.');

    expect(SystemAlert::query()->where('kind', 'worker.crashed')->whereNotNull('acknowledged_at')->count())
        ->toBe(1, 'The row is resolved, not deleted — system_alerts syncs, and a delete emits no tombstone.');

    expect(SystemAlert::query()->whereKey($latchingId)->whereNull('acknowledged_at')->exists())
        ->toBeTrue('A corrupt-backup alert records an event and must survive an unrelated kind healing.');
});

it('leaves the banner standing while the worker is still crash-looping', function (): void {
    Http::fake();

    $clock = workerBackClock('2026-05-23T12:00:00Z');
    $this->app->instance(Clock::class, $clock);

    workerBackCrashLoop($clock);

    // A crash-looping worker does run — between two crashes it spawns, loops and
    // ticks. Withdrawing there is worse than the stale banner: imports really
    // are stopped, and the banner would be false rather than out of date.
    $clock->time = $clock->time->addSeconds(5);
    workerBackTick();

    expect(workerBackOpen('worker.crashed'))->toBe(1, 'A tick inside the crash window must not withdraw.');

    /** @var SurfaceWorkerCrashAlert $listener */
    $listener = app(SurfaceWorkerCrashAlert::class);
    $clock->time = $clock->time->addSeconds(10);
    $listener->handle(new ProcessExited(alias: SurfaceWorkerCrashAlert::WORKER_ALIAS_PREFIX.'default', code: 1));
    workerBackMinutePasses();
    workerBackTick();

    expect(SystemAlert::query()->where('kind', 'worker.crashed')->count())
        ->toBe(1, 'Raising and withdrawing on every turn of the loop would fill the table.');
});

it('raises the kind again when the worker crash-loops after a withdrawal', function (): void {
    Http::fake();

    $clock = workerBackClock('2026-05-23T12:00:00Z');
    $this->app->instance(Clock::class, $clock);

    workerBackCrashLoop($clock);

    $clock->time = $clock->time->addSeconds(SurfaceWorkerCrashAlert::CRASH_LOOP_WINDOW_SECONDS + 10);
    workerBackTick();
    expect(workerBackOpen('worker.crashed'))->toBe(0);

    // Withdrawal goes through acknowledgeForUser(), so the dedup-key trigger
    // fires and the kind can be claimed again.
    workerBackCrashLoop($clock);

    expect(workerBackOpen('worker.crashed'))->toBe(1, 'A crash-loop that came back has to be raised again.');
    expect(SystemAlert::query()->where('kind', 'worker.crashed')->count())->toBe(2);
});

it('asks the database at most once a minute', function (): void {
    Http::fake();

    $clock = workerBackClock('2026-05-23T12:00:00Z');
    $this->app->instance(Clock::class, $clock);

    workerBackTick();
    expect(app(ShellState::class)->read(SurfaceWorkerCrashAlert::RECOVERY_PROBE_SLOT))
        ->not->toBeNull('The first tick has to leave the throttle behind it.');

    workerBackCrashLoop($clock);
    $clock->time = $clock->time->addSeconds(SurfaceWorkerCrashAlert::CRASH_LOOP_WINDOW_SECONDS + 10);

    workerBackTick();
    expect(workerBackOpen('worker.crashed'))
        ->toBe(1, 'A tick inside the throttle must not reach the table at all.');

    workerBackMinutePasses();
    workerBackTick();
    expect(workerBackOpen('worker.crashed'))->toBe(0);
});

it('keeps the worker looping when the withdrawal cannot reach the table', function (): void {
    Http::fake();

    $clock = workerBackClock('2026-05-23T12:00:00Z');
    $this->app->instance(Clock::class, $clock);

    Schema::drop('system_alerts');
    Log::spy();

    // An exception escaping here stops the daemon, which is the crash the alert
    // exists to report — so the failure has to be swallowed and logged.
    workerBackTick();

    // Named, not merely counted: a swallowed failure nobody can find in the log
    // is the same silence as no handler at all.
    Log::shouldHaveReceived('warning')->withArgs(
        static fn (string $message, array $context): bool => str_contains($message, 'failed to withdraw')
            && $context['reason'] === QueryException::class,
    );
});
