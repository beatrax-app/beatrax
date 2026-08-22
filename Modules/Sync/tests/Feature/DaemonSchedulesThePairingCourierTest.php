<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Sync\Commands\SyncServeCommand;
use Modules\Sync\Internal\Transport\DaemonTicker;
use Modules\Sync\Internal\Transport\DaemonTimer;

uses(RefreshDatabase::class);

// The courier's whole claim is that redelivery no longer waits on something
// being open. That claim is only true if a driver actually runs it, so the
// wiring is asserted here rather than inferred — and asserted with no event
// loop under it, which is what DaemonTimer exists to make possible.

// Captures what the daemon asked for instead of scheduling it.
function daemonFakeTimer(): DaemonTimer
{
    return new class implements DaemonTimer
    {
        public ?float $seconds = null;

        public ?Closure $tick = null;

        public bool $stopped = false;

        public function every(float $seconds, Closure $tick): void
        {
            $this->seconds = $seconds;
            $this->tick = $tick;
        }

        public function stop(): void
        {
            $this->stopped = true;
        }
    };
}

function daemonCommandWith(DaemonTimer $timer): SyncServeCommand
{
    app()->instance(DaemonTimer::class, $timer);
    // Artisan resolves every registered command at console bootstrap, so the
    // singleton already exists by now and would hand back the real ticker.
    app()->forgetInstance(SyncServeCommand::class);

    return app(SyncServeCommand::class);
}

function daemonStartCourier(SyncServeCommand $command, int $userId): void
{
    (new ReflectionMethod($command, 'startPendingPairingCourier'))->invoke($command, $userId);
}

it('schedules the pending-pairing courier on its own timer, at the poll interval it replaces', function (): void {
    $timer = daemonFakeTimer();

    daemonStartCourier(daemonCommandWith($timer), 4242);

    expect($timer->seconds)->toBe(3.0);
    expect($timer->tick)->toBeInstanceOf(Closure::class);
});

it('schedules nothing on a daemon spawned with no identity to carry a ceremony for', function (): void {
    $timer = daemonFakeTimer();

    // Zero is what SyncWebSocketHandler reports when the daemon booted while
    // the app was locked. A timer here would query every three seconds for the
    // life of the process and could only ever answer "no such user".
    daemonStartCourier(daemonCommandWith($timer), 0);

    expect($timer->seconds)->toBeNull();
    expect($timer->tick)->toBeNull();
});

it('runs a tick that survives having nothing to carry, and never throws into the loop', function (): void {
    $timer = daemonFakeTimer();

    daemonStartCourier(daemonCommandWith($timer), 4242);

    $tick = $timer->tick;
    expect($tick)->not->toBeNull();

    // No device, no ceremony, no relay: the shape the daemon is in for almost
    // all of its life. A throw out of here reaches the event loop's error
    // handler, which is the last place a daemon wants to find a bug.
    $tick();
    $tick();

    expect(true)->toBeTrue();
});

// The real timer is the seam's only third-party consumer, so its own two rules
// are pinned here rather than left to the daemon to demonstrate.
it('refuses to schedule a second tick over a live one, and forgets it after a stop', function (): void {
    $ticker = new DaemonTicker;
    $ran = 0;

    $ticker->every(0.01, function () use (&$ran): void {
        $ran++;
    });

    $first = new ReflectionProperty(DaemonTicker::class, 'callbackId');
    $idAfterFirst = $first->getValue($ticker);

    $ticker->every(0.01, function () use (&$ran): void {
        $ran++;
    });

    expect($first->getValue($ticker))->toBe($idAfterFirst);

    $ticker->stop();

    expect($first->getValue($ticker))->toBeNull();
    expect($ran)->toBe(0);
});
