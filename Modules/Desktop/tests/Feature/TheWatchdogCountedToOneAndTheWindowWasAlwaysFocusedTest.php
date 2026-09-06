<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Support\Facades\Http;
use Modules\Core\Models\SystemAlert;
use Modules\Core\Models\User;
use Modules\Core\Public\Contracts\Clock;
use Modules\Core\Public\Support\Lang;
use Modules\Desktop\Internal\Listeners\DispatchOsNotification;
use Modules\Desktop\Internal\Listeners\SurfaceWorkerCrashAlert;
use Modules\Desktop\Internal\Listeners\TrackWindowFocus;
use Modules\Desktop\Internal\Native\WindowFocusState;
use Modules\Notifications\Public\Enums\NotificationTrigger;
use Modules\Notifications\Public\Events\NotificationDeliverable;
use Native\Desktop\Events\App\ApplicationBooted;
use Native\Desktop\Events\ChildProcess\ProcessExited;
use Native\Desktop\Events\Windows\WindowBlurred;

// The sibling suites resolve a listener once and call it repeatedly, which is
// the one thing the shell never does. Each _native/api/events POST is its own
// PHP process -- the bundle serves the app through `php -S` -- so every handler
// starts from a container that has just been built. Read that way, the crash
// counter counted to one for ever and the focus flag was the constructed `true`
// on every request that ever asked.

// Restores the boundary one in-process container hides. Forgetting the resolved
// instances is what a new process does to them, and it is the whole difference
// between a watchdog that escalates and one that never has.
function aFreshShellRequest(): void
{
    foreach ([SurfaceWorkerCrashAlert::class, WindowFocusState::class, TrackWindowFocus::class, DispatchOsNotification::class] as $perRequest) {
        app()->forgetInstance($perRequest);
    }
}

function watchdogClockAt(string $iso): Clock
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

// The provider binds this one below the bundle gate, because the listener ends
// at an Electron notification. The suite declares the same binding so the real
// POST reaches it off-bundle.
function watchdogListensAsTheBundleDoes(): void
{
    app(Dispatcher::class)->listen(ProcessExited::class, [SurfaceWorkerCrashAlert::class, 'handle']);
}

function theWorkerExited(): void
{
    test()->post('_native/api/events', [
        'event' => ProcessExited::class,
        'payload' => [SurfaceWorkerCrashAlert::WORKER_ALIAS_PREFIX.'default', 1],
    ])->assertOk();
}

function watchdogAlertCount(): int
{
    return SystemAlert::query()->where('kind', SurfaceWorkerCrashAlert::ALERT_KIND)->count();
}

function watchdogUser(string $username): User
{
    return User::query()->create([
        'username' => $username,
        'password' => 'fixture-password-12chars',
        'period_start_day' => 1,
    ]);
}

function aDeliverableFor(int $userId): NotificationDeliverable
{
    return new NotificationDeliverable(
        notificationId: hash('sha256', 'watchdog-forecast-'.$userId),
        userId: $userId,
        triggerType: NotificationTrigger::ForecastShortfall,
        title: Lang::get('notifications::copy.title.forecast'),
        body: 'Your projected balance dips below zero within the next 30 days.',
        deepLinkRoute: '/forecast',
    );
}

it('raises the alert when the exits arrive in separate shell requests, which is the only way they arrive', function (): void {
    Http::fake();
    $clock = watchdogClockAt('2026-05-23T12:00:00Z');
    $this->app->instance(Clock::class, $clock);
    watchdogListensAsTheBundleDoes();

    for ($i = 0; $i < SurfaceWorkerCrashAlert::CRASH_LOOP_THRESHOLD; $i++) {
        $clock->time = $clock->time->addSeconds(10);
        aFreshShellRequest();
        theWorkerExited();
    }

    expect(watchdogAlertCount())->toBe(1, implode("\n", [
        'The watchdog counted nothing. Its rolling window lived on the listener,',
        'a per-request singleton, so three ProcessExited events in five minutes',
        'read as one exit three times and the threshold was unreachable.',
    ]));
});

it('still says nothing about the single exit that is the supervisor working', function (): void {
    Http::fake();
    $clock = watchdogClockAt('2026-05-23T12:00:00Z');
    $this->app->instance(Clock::class, $clock);
    watchdogListensAsTheBundleDoes();

    aFreshShellRequest();
    theWorkerExited();

    expect(watchdogAlertCount())->toBe(0);
});

// Real time, not the frozen Clock, decides when the cache row expires, so this
// case is decided by the prune the payload carries rather than by the TTL. That
// is deliberate: the TTL only sweeps up after a device that stopped crashing.
it('forgets an exit the window has left, so a crash a year apart never accumulates', function (): void {
    Http::fake();
    $clock = watchdogClockAt('2026-05-23T12:00:00Z');
    $this->app->instance(Clock::class, $clock);
    watchdogListensAsTheBundleDoes();

    for ($i = 0; $i < SurfaceWorkerCrashAlert::CRASH_LOOP_THRESHOLD + 2; $i++) {
        $clock->time = $clock->time->addSeconds(SurfaceWorkerCrashAlert::CRASH_LOOP_WINDOW_SECONDS + 1);
        aFreshShellRequest();
        theWorkerExited();
    }

    expect(watchdogAlertCount())->toBe(0);
});

it('lets a notification through to the OS after the request that recorded the blur has ended', function (): void {
    Http::fake();
    $user = watchdogUser('watchdog-blurred');

    $this->post('_native/api/events', ['event' => WindowBlurred::class, 'payload' => ['main']])->assertOk();
    aFreshShellRequest();

    app(DispatchOsNotification::class)->handleNotificationDeliverable(aDeliverableFor($user->id));

    Http::assertSent(fn ($request) => str_ends_with((string) $request->url(), '/notification'), implode("\n", [
        'Nothing was pushed. The blur the shell reported died with the request',
        'that reported it, so every delivery decision this adapter ever made was',
        'taken against the constructed default -- window focused, stay quiet.',
    ]));
});

it('keeps quiet for a window nothing has reported a blur for', function (): void {
    Http::fake();
    $user = watchdogUser('watchdog-focused');

    aFreshShellRequest();

    app(DispatchOsNotification::class)->handleNotificationDeliverable(aDeliverableFor($user->id));

    Http::assertNothingSent();
});

it('starts a launch from the default, so a shell killed while blurred does not toast over its own banner', function (): void {
    Http::fake();
    $user = watchdogUser('watchdog-relaunched');

    $this->post('_native/api/events', ['event' => WindowBlurred::class, 'payload' => ['main']])->assertOk();
    aFreshShellRequest();

    // Raised rather than posted: the vendor controller behind the booted route
    // boots the whole NativePHP provider, opening a window. The event is the
    // part of that request this listener answers to.
    app(Dispatcher::class)->dispatch(new ApplicationBooted);
    aFreshShellRequest();

    app(DispatchOsNotification::class)->handleNotificationDeliverable(aDeliverableFor($user->id));

    Http::assertNothingSent();
});
