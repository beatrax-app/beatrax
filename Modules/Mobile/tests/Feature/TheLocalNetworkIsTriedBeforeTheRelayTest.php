<?php

declare(strict_types=1);

use Illuminate\Contracts\Session\Session;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Modules\Auth\Public\Testing\AppLockTestHarness;
use Modules\Core\Models\User;
use Modules\Core\Public\Services\UserDataPathService;
use Modules\Mobile\Internal\Sync\MobileSyncTriggerService;
use Modules\Sync\Internal\Identity\DeviceIdentityService;
use Modules\Sync\Internal\Transport\Relay\RelayConfig;

uses(RefreshDatabase::class);

// A tick used to open a round-trip to a remote host before it ever looked at
// the network the peer is standing on. The relay carries no operations — only
// epoch wraps — so a phone beside its desktop paid a relay dial to learn
// nothing, on every tick, and told the reader nothing about which leg served it.

function lanFirstPhone(): array
{
    $user = User::query()->create([
        'username' => 'lanfirst-'.bin2hex(random_bytes(4)),
        'password' => bcrypt('lanfirst-pass'),
        'period_start_day' => 1,
        'default_currency_view' => 'eur_only',
    ]);

    /** @var Session $session */
    $session = app(Session::class);
    AppLockTestHarness::unlock($session, str_repeat("\x2a", 32));

    app(DeviceIdentityService::class)->generateAndPersist((int) $user->id, $session);

    /** @var RelayConfig $relayConfig */
    $relayConfig = app(RelayConfig::class);
    $relayConfig->setEndpointUrl('https://relay.fixture.test');

    return [(int) $user->id, $session];
}

afterEach(function (): void {
    $secretsDir = UserDataPathService::secretsPath();

    foreach (['sync-relay-drain-tokens.json', 'sync-relay-drain-registry.json'] as $name) {
        $path = $secretsDir.DIRECTORY_SEPARATOR.$name;

        if (is_file($path)) {
            @unlink($path);
        }
    }
});

it('dials the local network before it opens the relay', function (): void {
    [$userId, $session] = lanFirstPhone();

    $order = [];

    // No confirmed LAN peer key is on file, so the LAN leg refuses at its first
    // question and says so. That line is the leg's own record of being reached.
    Event::listen(function (MessageLogged $logged) use (&$order): void {
        if (str_contains($logged->message, 'LanSyncClient:') && ! in_array('lan', $order, true)) {
            $order[] = 'lan';
        }
    });

    Http::fake(function (Request $request) use (&$order) {
        if (! in_array('relay', $order, true)) {
            $order[] = 'relay';
        }

        return Http::response(['blobs' => []], 200);
    });

    app(MobileSyncTriggerService::class)->attempt($userId, $session, '127.0.0.1', 8765);

    expect($order)->toBe(
        ['lan', 'relay'],
        'the sync attempt must try the local network before it falls back to the relay',
    );
});

it('still drains the relay on the same tick, because the relay is a fallback in order and not in whether it runs', function (): void {
    [$userId, $session] = lanFirstPhone();

    $drained = false;

    Http::fake(function (Request $request) use (&$drained) {
        $drained = true;

        return Http::response(['blobs' => []], 200);
    });

    app(MobileSyncTriggerService::class)->attempt($userId, $session, '127.0.0.1', 8765);

    expect($drained)->toBeTrue(
        'a tick that reached the LAN leg must still read the mailbox, or a wrap from a device that is not the LAN peer is never read',
    );
});
