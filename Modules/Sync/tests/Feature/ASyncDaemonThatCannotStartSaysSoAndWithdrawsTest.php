<?php

declare(strict_types=1);

use Amp\Socket;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Sync\Commands\SyncServeCommand;
use Modules\Sync\Internal\Pairing\PairingFrameApplier;
use Modules\Sync\Internal\Pairing\PairingOfferRateLimiter;
use Modules\Sync\Internal\Pairing\PairingOfferService;
use Modules\Sync\Internal\Pairing\PairingPeerOutbox;
use Modules\Sync\Internal\Pairing\PairingPullAuthorizer;
use Modules\Sync\Internal\Pairing\PendingPairingCourier;
use Modules\Sync\Internal\Transport\DaemonShutdownSignal;
use Modules\Sync\Internal\Transport\DaemonTimer;
use Modules\Sync\Internal\Transport\Discovery\MdnsAdvertiser;
use Modules\Sync\Internal\Transport\SyncWebSocketHandler;
use Modules\Sync\Tests\Support\RecordingLogger;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;

uses(RefreshDatabase::class);

// A supervisor restarts this daemon whenever it exits, so the difference
// between "could not start" and "started and then stopped" is the difference
// between a fixable report and a restart loop. Nothing exercised the start-up
// path, and it is the one that runs before anything is listening.
/**
 * @link ../../../../.docs/features/sync/lan-discovery-trust-model.md
 */
function syncDaemonTimerDouble(): DaemonTimer
{
    return new class implements DaemonTimer
    {
        public ?Closure $tick = null;

        public bool $stopped = false;

        public function every(float $seconds, Closure $tick): void
        {
            $this->tick = $tick;
        }

        public function stop(): void
        {
            $this->stopped = true;
        }
    };
}

function syncDaemonCommand(RecordingLogger $logger, DaemonTimer $timer, ?Closure $courier = null): SyncServeCommand
{
    $command = new SyncServeCommand(
        logger: $logger,
        handler: fn (): SyncWebSocketHandler => app(SyncWebSocketHandler::class),
        advertiser: new MdnsAdvertiser($logger),
        shutdown: new DaemonShutdownSignal,
        offers: app(PairingOfferService::class),
        offerRateLimiter: app(PairingOfferRateLimiter::class),
        frameApplier: app(PairingFrameApplier::class),
        peerOutbox: app(PairingPeerOutbox::class),
        pullAuthorizer: app(PairingPullAuthorizer::class),
        pendingCourier: $courier ?? fn (): PendingPairingCourier => app(PendingPairingCourier::class),
        ticker: $timer,
    );

    $command->setLaravel(app());

    return $command;
}

it('reports a failure instead of parking when the sync port is already held', function (): void {
    $held = Socket\listen('0.0.0.0:0');
    $port = $held->getAddress()->getPort();

    $logger = new RecordingLogger;
    $timer = syncDaemonTimerDouble();
    $output = new BufferedOutput;

    try {
        $code = syncDaemonCommand($logger, $timer)->run(new ArrayInput(['--port' => (string) $port]), $output);
    } finally {
        $held->close();
    }

    expect($code)->toBe(SyncServeCommand::FAILURE)
        ->and($output->fetch())->toContain('sync:serve: fatal')
        ->and($logger->said('sync:serve: fatal error.'))->toBeTrue()
        ->and($timer->tick)->toBeNull('nothing may be scheduled by a daemon that never started listening');
});

it('says out loud that a daemon spawned without an identity will not be found', function (): void {
    // No SYNC_* environment, which is how the daemon starts while the app is
    // still locked: no device id to advertise and no user to carry a ceremony
    // for. Silence here cost four rounds of a desktop simply invisible on the LAN.
    $held = Socket\listen('0.0.0.0:0');
    $port = $held->getAddress()->getPort();

    $logger = new RecordingLogger;

    try {
        syncDaemonCommand($logger, syncDaemonTimerDouble())->run(new ArrayInput(['--port' => (string) $port]), new BufferedOutput);
    } finally {
        $held->close();
    }

    expect($logger->said('refusing a device id that is not a UUID'))
        ->toBeTrue('a keyless daemon that advertises nothing must say so, not bind quietly and answer nothing');
});

it('refuses a port a socket could never carry', function (): void {
    $output = new BufferedOutput;

    $code = syncDaemonCommand(new RecordingLogger, syncDaemonTimerDouble())->run(new ArrayInput(['--port' => '70000']), $output);

    expect($code)->toBe(SyncServeCommand::FAILURE)
        ->and($output->fetch())->toContain('invalid port 70000');
});

it('keeps a courier tick that throws out of the event loop', function (): void {
    $logger = new RecordingLogger;
    $timer = syncDaemonTimerDouble();

    $command = syncDaemonCommand($logger, $timer, courier: function (): PendingPairingCourier {
        throw new RuntimeException('the relay is unreachable');
    });

    (new ReflectionMethod($command, 'startPendingPairingCourier'))->invoke($command, 4242);

    $tick = $timer->tick;
    expect($tick)->not->toBeNull();

    // Anything escaping here reaches the loop's error handler, which on this
    // daemon means the listener dies and the supervisor restarts it.
    $tick();
    $tick();

    expect($logger->said('pending pairing courier tick failed'))->toBeTrue()
        ->and($logger->lines)->toHaveCount(2, 'each failed tick is reported, and neither is allowed to end the daemon');
});

it('only promises signal handling on a runtime that has both halves of it', function (): void {
    $command = syncDaemonCommand(new RecordingLogger, syncDaemonTimerDouble());

    expect((new ReflectionMethod($command, 'canTrapSignals'))->invoke($command))
        ->toBe(\function_exists('pcntl_signal') && \defined('SIGTERM') && \defined('SIGINT'));
});
