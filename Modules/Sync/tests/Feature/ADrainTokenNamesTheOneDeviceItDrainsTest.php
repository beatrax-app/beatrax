<?php

declare(strict_types=1);

use Illuminate\Database\DatabaseManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Modules\Core\Public\Contracts\Clock;
use Modules\Core\Public\Services\UserDataPathService;
use Modules\Sync\Commands\RelayServeCommand;
use Modules\Sync\Internal\Exceptions\RelayUnavailableException;
use Modules\Sync\Internal\Transport\DaemonShutdownSignal;
use Modules\Sync\Internal\Transport\Relay\RelayClient;
use Modules\Sync\Internal\Transport\Relay\RelayConfig;
use Modules\Sync\Internal\Transport\Relay\RelayDrainRegistry;
use Modules\Sync\Internal\Transport\Relay\RelayDrainToken;
use Modules\Sync\Internal\Transport\Relay\RelayMailbox;
use Modules\Sync\Internal\Transport\Relay\RelayRateLimiter;
use Modules\Sync\Internal\Transport\Relay\RelayTlsMaterial;
use Modules\Sync\Tests\Support\RelayHandlerHarness;
use Psr\Log\NullLogger;

uses(RefreshDatabase::class);

// Trust-on-first-use answers "the same caller as last time" and cannot answer
// "a credential about this device at all". Registering whatever bearer arrived
// for an unclaimed device id made every device's FIRST drain unauthenticated —
// the drain its GDK epoch wraps are waiting in. Driven through
// RelayServeCommand::route(), because the route is where the hole was.

beforeEach(function (): void {
    $this->relayConfig = new RelayConfig;
    $this->relayConfig->setEndpointUrl('https://relay.test');

    // Minted exactly as LocalRelayProvisioner minted the relay-wide bearer that
    // PairingFlowModal put in the QR. Every peer that ever paired holds a copy;
    // it names no device and must now drain nothing.
    $this->relayWideToken = bin2hex(random_bytes(32));

    $command = new RelayServeCommand(
        new NullLogger,
        new RelayMailbox(app(DatabaseManager::class), app(Clock::class)),
        new RelayDrainRegistry,
        new RelayRateLimiter(app(Clock::class)),
        new RelayTlsMaterial,
        new DaemonShutdownSignal,
    );

    $this->relayClient = new RelayClient(
        RelayHandlerHarness::httpFactory($command),
        $this->relayConfig,
        new NullLogger,
    );
});

afterEach(function (): void {
    $secrets = UserDataPathService::secretsPath();

    foreach ([
        $secrets.DIRECTORY_SEPARATOR.'sync-relay-drain-tokens.json',
        $secrets.DIRECTORY_SEPARATOR.'sync-relay-drain-secret.json',
        $secrets.DIRECTORY_SEPARATOR.'sync-relay-token.json',
        $secrets.DIRECTORY_SEPARATOR.'sync-relay-drain-registry.json',
        UserDataPathService::appPath('sync/relay.json'),
    ] as $path) {
        if (is_file($path)) {
            @unlink($path);
        }
    }
});

it('mints a token that names its own device id and no other', function (): void {
    $tokenForA = $this->relayConfig->deviceDrainToken('device-a');
    $tokenForB = $this->relayConfig->deviceDrainToken('device-b');

    expect($tokenForA)->toStartWith('bdt1.'.hash('sha256', 'device-a').'.')
        ->and($tokenForA)->not->toBe($tokenForB)
        ->and(RelayDrainToken::namesDevice($tokenForA, 'device-a'))->toBeTrue()
        ->and(RelayDrainToken::namesDevice($tokenForA, 'device-b'))->toBeFalse()
        ->and(RelayDrainToken::namesDevice($this->relayWideToken, 'device-a'))->toBeFalse();

    // Stable across calls: a re-mint would present a second token for an id the
    // relay has already bound, and every later drain would answer 401.
    expect($this->relayConfig->deviceDrainToken('device-a'))->toBe($tokenForA);
});

it('refuses a token bound to device-a against device-b, and leaves the blob for device-b', function (): void {
    $this->relayClient->deliver('device-sender', 'device-a', random_bytes(48));
    $this->relayClient->deliver('device-sender', 'device-b', random_bytes(48));

    $tokenForA = $this->relayConfig->deviceDrainToken('device-a');
    expect($this->relayClient->drain('device-a', $tokenForA))->toHaveCount(1);

    // device-b has never drained, so its slot is unclaimed — the exact case
    // whatever-bearer TOFU handed to the first caller that asked.
    expect(fn () => $this->relayClient->drain('device-b', $tokenForA))
        ->toThrow(RelayUnavailableException::class, 'HTTP 401');

    expect(DB::table('relay_mailbox')->where('recipient_did', 'device-b')->whereNull('delivered_at')->count())
        ->toBe(1);

    $drained = $this->relayClient->drain('device-b', $this->relayConfig->deviceDrainToken('device-b'));
    expect($drained)->toHaveCount(1)
        ->and($drained[0]['sender_did'])->toBe('device-sender');
});

it('refuses a token bound to device-a against device-b once device-b has claimed its slot', function (): void {
    $this->relayClient->deliver('device-sender', 'device-b', random_bytes(48));

    $tokenForB = $this->relayConfig->deviceDrainToken('device-b');
    expect($this->relayClient->drain('device-b', $tokenForB))->toHaveCount(1);

    $rowId = (int) DB::table('relay_mailbox')->where('recipient_did', 'device-b')->value('id');
    $tokenForA = $this->relayConfig->deviceDrainToken('device-a');

    expect(fn () => $this->relayClient->drain('device-b', $tokenForA))
        ->toThrow(RelayUnavailableException::class, 'HTTP 401');
    expect(fn () => $this->relayClient->confirm($rowId, $tokenForA))
        ->toThrow(RelayUnavailableException::class, 'HTTP 401');

    expect(DB::table('relay_mailbox')->where('id', $rowId)->value('delivered_at'))->toBeNull();
});

it('refuses the relay-wide token against an unclaimed mailbox', function (): void {
    $this->relayClient->deliver('device-sender', 'device-fresh', random_bytes(48));

    expect(fn () => $this->relayClient->drain('device-fresh', $this->relayWideToken))
        ->toThrow(RelayUnavailableException::class, 'HTTP 401');

    // Refused, not registered: the real owner's first drain still wins its own
    // slot and still finds its blob.
    expect(DB::table('relay_mailbox')->where('recipient_did', 'device-fresh')->whereNull('delivered_at')->count())
        ->toBe(1);

    expect($this->relayClient->drain('device-fresh', $this->relayConfig->deviceDrainToken('device-fresh')))
        ->toHaveCount(1);
});

it('refuses the relay-wide token at the registry without claiming the id', function (): void {
    $registry = new RelayDrainRegistry;

    expect($registry->registerOrAuthorize('device-fresh', $this->relayWideToken))->toBeFalse()
        ->and($registry->authorizes('device-fresh', $this->relayWideToken))->toBeFalse();

    $owner = RelayDrainToken::mint('device-fresh');

    expect($registry->registerOrAuthorize('device-fresh', $owner))->toBeTrue()
        ->and($registry->authorizes('device-fresh', $owner))->toBeTrue()
        ->and($registry->authorizes('device-fresh', RelayDrainToken::mint('device-fresh')))->toBeFalse();
});

it('lets two local users of one install each drain their own mailbox', function (): void {
    // One install, one secrets directory, two device ids — device identity is
    // per user. An install-scoped secret bound the relay to whichever of them
    // drained first and answered 401 to the other one for good.
    $this->relayClient->deliver('device-peer', 'device-user-one', random_bytes(48));
    $this->relayClient->deliver('device-peer', 'device-user-two', random_bytes(48));

    $tokenOne = $this->relayConfig->deviceDrainToken('device-user-one');
    $tokenTwo = $this->relayConfig->deviceDrainToken('device-user-two');

    expect($tokenOne)->not->toBe($tokenTwo);

    $drainedOne = $this->relayClient->drain('device-user-one', $tokenOne);
    $drainedTwo = $this->relayClient->drain('device-user-two', $tokenTwo);

    expect($drainedOne)->toHaveCount(1)
        ->and($drainedTwo)->toHaveCount(1);

    $this->relayClient->confirm((int) $drainedOne[0]['id'], $tokenOne);
    $this->relayClient->confirm((int) $drainedTwo[0]['id'], $tokenTwo);

    expect(DB::table('relay_mailbox')->whereNull('delivered_at')->count())->toBe(0);

    // Same install, and still not each other's credential.
    expect(fn () => $this->relayClient->drain('device-user-one', $tokenTwo))
        ->toThrow(RelayUnavailableException::class, 'HTTP 401');
});

it('lets a device bound under the superseded scheme drain again after the upgrade', function (): void {
    // What an upgraded relay finds on disk: a bare-hash entry the install-scoped
    // scheme wrote. Honoured, it would 401 the owner out of its own mailbox for
    // good, because the per-device token hashes to something else entirely.
    $secrets = UserDataPathService::secretsPath();
    if (! is_dir($secrets)) {
        mkdir($secrets, 0700, true);
    }
    file_put_contents(
        $secrets.DIRECTORY_SEPARATOR.'sync-relay-drain-registry.json',
        json_encode(['device-upgraded' => hash('sha256', 'the-install-wide-secret')], JSON_THROW_ON_ERROR),
    );

    $this->relayClient->deliver('device-sender', 'device-upgraded', random_bytes(48));

    // The secret that wrote that entry is refused now, whatever the entry says.
    expect(fn () => $this->relayClient->drain('device-upgraded', 'the-install-wide-secret'))
        ->toThrow(RelayUnavailableException::class, 'HTTP 401');

    $token = $this->relayConfig->deviceDrainToken('device-upgraded');
    expect($this->relayClient->drain('device-upgraded', $token))->toHaveCount(1);

    // Re-registered under the new scheme, so the binding holds from here on.
    expect(fn () => $this->relayClient->drain('device-upgraded', RelayDrainToken::mint('device-upgraded')))
        ->toThrow(RelayUnavailableException::class, 'HTTP 401');
});

it('retires the superseded secret files once the replacement is written', function (): void {
    $secrets = UserDataPathService::secretsPath();
    if (! is_dir($secrets)) {
        mkdir($secrets, 0700, true);
    }

    $drainSecret = $secrets.DIRECTORY_SEPARATOR.'sync-relay-drain-secret.json';
    $relayToken = $secrets.DIRECTORY_SEPARATOR.'sync-relay-token.json';
    file_put_contents($drainSecret, json_encode(['secret' => 'the-install-wide-secret'], JSON_THROW_ON_ERROR));
    file_put_contents($relayToken, json_encode(['token' => $this->relayWideToken], JSON_THROW_ON_ERROR));

    $this->relayConfig->deviceDrainToken('device-upgraded');

    expect(is_file($drainSecret))->toBeFalse()
        ->and(is_file($relayToken))->toBeFalse()
        ->and(is_file($secrets.DIRECTORY_SEPARATOR.'sync-relay-drain-tokens.json'))->toBeTrue();
});
