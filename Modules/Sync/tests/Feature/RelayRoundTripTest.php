<?php

declare(strict_types=1);

use Illuminate\Database\DatabaseManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Modules\Core\Public\Contracts\Clock;
use Modules\Core\Public\Services\UserDataPathService;
use Modules\Sync\Commands\RelayServeCommand;
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

// RelayHandlerHarness routes the real RelayClient's outbound HTTP straight into
// the real RelayServeCommand::route(), so both halves of the wire contract are
// exercised without booting a socket server. The blob stays opaque random bytes
// throughout; nothing here decrypts it.

beforeEach(function (): void {
    // HTTPS because RelayClient refuses anything else. Draining authenticates
    // with a token minted for the draining device id and refused against any
    // other, so nothing relay-wide is a drain credential.
    $this->relayConfig = new RelayConfig;
    $this->relayConfig->setEndpointUrl('https://relay.test');

    $mailbox = new RelayMailbox(
        app(DatabaseManager::class),
        app(Clock::class),
    );

    $command = new RelayServeCommand(new NullLogger, $mailbox, new RelayDrainRegistry, new RelayRateLimiter(app(Clock::class)), new RelayTlsMaterial, new DaemonShutdownSignal);

    $this->relayClient = new RelayClient(
        RelayHandlerHarness::httpFactory($command),
        $this->relayConfig,
        new NullLogger,
    );
});

afterEach(function (): void {
    // Delete the files outright rather than clearing the values, so nothing is
    // left behind in storage/app/secrets for a later test to find.
    $secretsDir = UserDataPathService::secretsPath();
    $drainTokensPath = $secretsDir.DIRECTORY_SEPARATOR.'sync-relay-drain-tokens.json';
    $drainRegistryPath = $secretsDir.DIRECTORY_SEPARATOR.'sync-relay-drain-registry.json';
    $relayPath = UserDataPathService::appPath('sync/relay.json');

    foreach ([$drainTokensPath, $drainRegistryPath, $relayPath] as $path) {
        if (is_file($path)) {
            @unlink($path);
        }
    }
});

it('round-trips deliver -> drain -> confirm against the real relay handler', function (): void {
    $senderDid = 'device-sender';
    $recipientDid = 'device-recipient';
    $blob = random_bytes(96);

    $this->relayClient->deliver($senderDid, $recipientDid, $blob);

    $row = DB::table('relay_mailbox')
        ->where('recipient_did', $recipientDid)
        ->first();
    expect($row)->not->toBeNull('deliver() must persist the blob (CR-01 contract)');
    expect($row->sender_did)->toBe($senderDid);
    expect($row->blob)->toBe($blob, 'blob must be stored verbatim (ZK invariant)');
    expect($row->delivered_at)->toBeNull('freshly delivered blob is pending');

    // The owning device presents the token minted for THIS device id; the relay
    // TOFU-registers it on this first drain.
    $deviceToken = $this->relayConfig->deviceDrainToken($recipientDid);

    $rows = $this->relayClient->drain($recipientDid, $deviceToken);

    expect($rows)->toBeArray()->toHaveCount(1, 'drain() must return the single pending row, unwrapped');
    $drained = $rows[0];
    expect($drained)->toBeArray()->toHaveKeys(['id', 'sender_did', 'blob']);
    expect($drained['sender_did'])->toBe($senderDid);
    expect(base64_decode((string) $drained['blob'], true))->toBe($blob, 'drained blob must round-trip the ciphertext');

    $this->relayClient->confirm((int) $drained['id'], $deviceToken);

    $confirmed = DB::table('relay_mailbox')
        ->where('id', $drained['id'])
        ->first();
    expect($confirmed->delivered_at)->not->toBeNull('confirm() must mark the row delivered');
});

it('rejects a drain from a different device once the owner has TOFU-registered its secret', function (): void {
    $recipientDid = 'device-victim';
    $this->relayClient->deliver('device-sender', $recipientDid, random_bytes(48));

    // The owning device drains first with its OWN drain token — the relay
    // TOFU-binds this did to that token's hash.
    $ownerSecret = $this->relayConfig->deviceDrainToken($recipientDid);
    expect($this->relayClient->drain($recipientDid, $ownerSecret))->toHaveCount(1);

    // Another device's token names another device, so it is refused here before
    // the stored hash is even consulted.
    expect(fn () => $this->relayClient->drain($recipientDid, RelayDrainToken::mint('device-somebody-else')))
        ->toThrow(RuntimeException::class);

    // And a bearer that names no device at all — which is what a relay-wide
    // token is — drains nothing.
    expect(fn () => $this->relayClient->drain($recipientDid, 'relay-shared-secret'))
        ->toThrow(RuntimeException::class);
});

it('confirm honours the same per-device auth — a different device is rejected, the owner is not', function (): void {
    $recipientDid = 'device-victim-2';
    $this->relayClient->deliver('device-sender', $recipientDid, random_bytes(48));

    // Owner drains first, TOFU-registering its own drain token for this did.
    $ownerSecret = $this->relayConfig->deviceDrainToken($recipientDid);
    $rows = $this->relayClient->drain($recipientDid, $ownerSecret);
    $id = (int) $rows[0]['id'];

    expect(fn () => $this->relayClient->confirm($id, RelayDrainToken::mint('device-somebody-else')))
        ->toThrow(RuntimeException::class);
    $row = DB::table('relay_mailbox')->where('id', $id)->first();
    expect($row->delivered_at)->toBeNull('unauthorized confirm must not mark the row delivered');

    $this->relayClient->confirm($id, $ownerSecret);
    $confirmed = DB::table('relay_mailbox')->where('id', $id)->first();
    expect($confirmed->delivered_at)->not->toBeNull('the owning device confirm marks the row delivered');
});
