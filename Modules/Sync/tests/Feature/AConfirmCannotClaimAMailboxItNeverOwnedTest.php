<?php

declare(strict_types=1);

use Illuminate\Database\DatabaseManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Core\Public\Contracts\Clock;
use Modules\Core\Public\Services\UserDataPathService;
use Modules\Sync\Commands\RelayServeCommand;
use Modules\Sync\Internal\Exceptions\RelayUnavailableException;
use Modules\Sync\Internal\Transport\DaemonShutdownSignal;
use Modules\Sync\Internal\Transport\Relay\RelayClient;
use Modules\Sync\Internal\Transport\Relay\RelayConfig;
use Modules\Sync\Internal\Transport\Relay\RelayDrainRegistry;
use Modules\Sync\Internal\Transport\Relay\RelayMailbox;
use Modules\Sync\Internal\Transport\Relay\RelayRateLimiter;
use Modules\Sync\Internal\Transport\Relay\RelayTlsMaterial;
use Modules\Sync\Tests\Support\RelayHandlerHarness;
use Psr\Log\NullLogger;

uses(RefreshDatabase::class);

// relay_mailbox.id is an autoincrement integer, so DELETE /relay/drain/{1..N}
// is a sweep any caller on the LAN can run. Confirm resolves the recipient did
// from that id; when that lookup was allowed to TOFU-register, the sweep
// claimed every device's drain slot without ever knowing a device id, marked
// the queued blobs delivered, and locked the real device out for good.

beforeEach(function (): void {
    $this->relayConfig = new RelayConfig;
    $this->relayConfig->setEndpointUrl('https://relay.test');
    $this->relayConfig->setAuthToken('relay-shared-secret');

    $this->mailbox = new RelayMailbox(app(DatabaseManager::class), app(Clock::class));

    $command = new RelayServeCommand(
        new NullLogger,
        $this->mailbox,
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
        $secrets.DIRECTORY_SEPARATOR.'sync-relay-token.json',
        $secrets.DIRECTORY_SEPARATOR.'sync-relay-drain-secret.json',
        $secrets.DIRECTORY_SEPARATOR.'sync-relay-drain-registry.json',
        UserDataPathService::appPath('sync/relay.json'),
    ] as $path) {
        if (is_file($path)) {
            @unlink($path);
        }
    }
});

it('refuses a confirm from a caller that never drained, and leaves the blob for its owner', function (): void {
    $this->relayClient->deliver('device-sender', 'device-recipient', random_bytes(48));

    $row = app(DatabaseManager::class)->connection()->table('relay_mailbox')->first();
    expect($row)->not->toBeNull();

    expect(fn () => $this->relayClient->confirm((int) $row->id, 'an-attacker-token-32-bytes-long!'))
        ->toThrow(RelayUnavailableException::class);

    expect(app(DatabaseManager::class)->connection()->table('relay_mailbox')->where('id', $row->id)->value('delivered_at'))
        ->toBeNull();

    // The owner's real secret still works, so the slot was never claimed.
    $drained = $this->relayClient->drain('device-recipient', $this->relayConfig->deviceDrainSecret());
    expect($drained)->toHaveCount(1);
});

it('lets the device that drained confirm its own blob', function (): void {
    $this->relayClient->deliver('device-sender', 'device-recipient', random_bytes(48));

    $secret = $this->relayConfig->deviceDrainSecret();
    $drained = $this->relayClient->drain('device-recipient', $secret);
    expect($drained)->toHaveCount(1);

    $this->relayClient->confirm((int) $drained[0]['id'], $secret);

    expect(app(DatabaseManager::class)->connection()->table('relay_mailbox')->value('delivered_at'))->not->toBeNull();
});

it('refuses a drain-slot verification for a device id that never registered', function (): void {
    $registry = new RelayDrainRegistry;

    expect($registry->authorizes('device-never-seen', 'some-token'))->toBeFalse();
    expect($registry->registerOrAuthorize('device-never-seen', 'some-token'))->toBeTrue();
    expect($registry->authorizes('device-never-seen', 'some-token'))->toBeTrue();
    expect($registry->authorizes('device-never-seen', 'a-different-token'))->toBeFalse();
});
