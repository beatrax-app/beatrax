<?php

declare(strict_types=1);

use Illuminate\Contracts\Session\Session;
use Illuminate\Database\DatabaseManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Auth\Public\Testing\AppLockTestHarness;
use Modules\Core\Models\User;
use Modules\Core\Public\Support\Lang;
use Modules\Mobile\Internal\Sync\MobileSyncTriggerService;
use Modules\Mobile\Internal\Sync\SyncAttemptOutcome;
use Modules\Sync\Internal\Identity\DeviceIdentityService;
use Revolt\EventLoop;

uses(RefreshDatabase::class);

// A desktop that spawned its listener before the app was unlocked holds the LAN
// port with an empty transport key: it accepts the WebSocket upgrade and then
// fails every Noise handshake. Measured on a paired Galaxy A51 and the desktop
// install on 2026-09-11 — the TCP connection succeeded (101 Switching Protocols)
// and the phone still told the reader to go and check their network.

/** @return array{0: int, 1: Session} */
function answeringPeerPhone(): array
{
    $user = User::query()->create([
        'username' => 'answered-'.bin2hex(random_bytes(4)),
        'password' => bcrypt('answered-pass'),
        'period_start_day' => 1,
        'default_currency_view' => 'eur_only',
    ]);

    /** @var Session $session */
    $session = app(Session::class);
    AppLockTestHarness::unlock($session, str_repeat("\x2a", 32));

    app(DeviceIdentityService::class)->generateAndPersist((int) $user->id, $session);

    app(DatabaseManager::class)->connection()->table('device_registry')->insert([
        'user_id' => (int) $user->id,
        'device_id' => 'desktop-that-answers',
        'name' => 'Study desktop',
        'ed25519_public_key_hex' => str_repeat('ab', 32),
        'x25519_public_key_hex' => str_repeat('cd', 32),
        'safety_number_words' => 'alpha bravo charlie delta echo foxtrot',
        'is_self' => 0,
        'paired_at' => '2026-09-01T10:00:00Z',
        'confirmed_at' => '2026-09-01T10:01:00Z',
        'created_at' => '2026-09-01T10:00:00Z',
        'updated_at' => '2026-09-01T10:01:00Z',
    ]);

    return [(int) $user->id, $session];
}

// Answers the RFC6455 upgrade, reads the Noise msg1 the initiator sends, then
// hangs up — exactly what SyncWebSocketHandler does when its static key is the
// empty string it was spawned with. Driven from the Revolt loop the amphp
// client awaits on, so one process serves both ends.
/**
 * @param  resource  $client
 * @return bool whether the connection is still open and worth watching
 */
function refuseAfterUpgrading($client, string &$buffer, bool &$upgraded): bool
{
    $chunk = @fread($client, 8192);

    if ($upgraded || $chunk === false || ($chunk === '' && feof($client))) {
        @fclose($client);

        return false;
    }

    $buffer .= $chunk;

    if (! str_contains($buffer, "\r\n\r\n")) {
        return true;
    }

    $key = preg_match('/Sec-WebSocket-Key:\s*(\S+)/i', $buffer, $found) === 1 ? $found[1] : '';
    $accept = base64_encode(sha1($key.'258EAFA5-E914-47DA-95CA-C5AB0DC85B11', true));

    @fwrite($client, "HTTP/1.1 101 Switching Protocols\r\nUpgrade: websocket\r\nConnection: Upgrade\r\nSec-WebSocket-Accept: {$accept}\r\n\r\n");

    $upgraded = true;
    $buffer = '';

    return true;
}

/** @param  Closure(int): void  $work */
function withAPeerThatUpgradesThenHangsUp(Closure $work): void
{
    $server = @stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);

    if ($server === false) {
        test()->markTestSkipped('no loopback port available on this host: '.$errstr);
    }

    stream_set_blocking($server, false);
    $port = (int) explode(':', (string) stream_socket_get_name($server, false))[1];

    $connections = [];

    $watcher = EventLoop::onReadable($server, function () use ($server, &$connections): void {
        $client = @stream_socket_accept($server, 0);

        if ($client === false) {
            return;
        }

        stream_set_blocking($client, false);
        $buffer = '';
        $upgraded = false;

        $connections[] = EventLoop::onReadable(
            $client,
            function (string $callbackId) use ($client, &$buffer, &$upgraded): void {
                if (! refuseAfterUpgrading($client, $buffer, $upgraded)) {
                    EventLoop::cancel($callbackId);
                }
            },
        );
    });

    try {
        $work($port);
    } finally {
        foreach ($connections as $id) {
            EventLoop::cancel($id);
        }

        EventLoop::cancel($watcher);
        @fclose($server);
    }
}

function aPortNothingIsListeningOn(): int
{
    $probe = @stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);

    if ($probe === false) {
        test()->markTestSkipped('no loopback port available on this host: '.$errstr);
    }

    $port = (int) explode(':', (string) stream_socket_get_name($probe, false))[1];
    fclose($probe);

    return $port;
}

// THE DEFECT. Both legs of this comparison came back "Could not reach your
// other device — check both are on the same network", so the one sentence the
// reader got named a cause that had already been ruled out by the connection
// succeeding.
it('does not read a peer that answered and then refused the handshake as one it never reached', function (): void {
    [$userId, $session] = answeringPeerPhone();

    $answered = null;

    withAPeerThatUpgradesThenHangsUp(function (int $port) use ($userId, $session, &$answered): void {
        $answered = app(MobileSyncTriggerService::class)->attempt($userId, $session, '127.0.0.1', $port);
    });

    $neverReached = app(MobileSyncTriggerService::class)
        ->attempt($userId, $session, '127.0.0.1', aPortNothingIsListeningOn());

    expect($answered)->toBe(SyncAttemptOutcome::NotSecured);
    expect($neverReached)->toBe(SyncAttemptOutcome::Unreachable);
    expect(Lang::get('mobile::sync.result.'.$answered->value))
        ->not->toBe(Lang::get('mobile::sync.result.'.$neverReached->value));
});

// POSITIVE CONTROL — passes before and after the fix. A dial that reached
// nothing at all is still reported as the unreachable one, so the assertion
// above is discriminating and not just renaming every failure.
it('still reads a peer nothing answered for as unreachable', function (): void {
    [$userId, $session] = answeringPeerPhone();

    $outcome = app(MobileSyncTriggerService::class)
        ->attempt($userId, $session, '127.0.0.1', aPortNothingIsListeningOn());

    expect(Lang::get('mobile::sync.result.'.$outcome->value))
        ->toBe(Lang::get('mobile::sync.result.unreachable'));
});
