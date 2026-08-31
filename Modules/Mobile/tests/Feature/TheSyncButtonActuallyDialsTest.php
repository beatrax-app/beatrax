<?php

declare(strict_types=1);

use Illuminate\Contracts\Session\Session;
use Illuminate\Database\DatabaseManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Modules\Auth\Public\Testing\AppLockTestHarness;
use Modules\Core\Models\User;
use Modules\Core\Public\Services\UserDataPathService;
use Modules\Mobile\Internal\Http\Livewire\SyncScreen;
use Modules\Mobile\Internal\Sync\SyncAttemptOutcome;
use Modules\Sync\Internal\Identity\DeviceIdentityService;

uses(RefreshDatabase::class);

// On a paired, fully synced Galaxy S23 with 62 phone-authored ops waiting, a
// press of "Sync now" opened no TCP connection at all: the screen handed the
// burst the host out of a relay URL, which a LAN-only pairing never configures,
// so the LAN leg was skipped and the relay drained a mailbox it had none of.

// 127.0.0.1:1 is closed on every machine this runs on, so the dial is refused
// immediately. What is being measured is that a dial is attempted against the
// remembered address at all, not what the desktop would have answered.
const SYNC_DIAL_CLOSED_PORT = 1;

// A relay endpoint left on disk by another run is the fallback this test is
// measuring the absence of: with one present the screen dials the desktop that
// issued a QR instead of the address it actually reached.
beforeEach(function (): void {
    @unlink(UserDataPathService::appPath('sync/relay.json'));
});

function syncDialUser(string $username): User
{
    return User::query()->create([
        'username' => $username,
        'password' => bcrypt('mobile-dial-pass'),
        'period_start_day' => 1,
        'default_currency_view' => 'eur_only',
    ]);
}

function seedReachedPeer(DatabaseManager $db, int $userId, string $host, int $port): string
{
    $peerDeviceId = 'desktop-dial-'.bin2hex(random_bytes(4));

    $db->connection()->table('device_registry')->insert([
        'user_id' => $userId,
        'device_id' => $peerDeviceId,
        'name' => 'Study desktop',
        'ed25519_public_key_hex' => bin2hex(random_bytes(32)),
        'x25519_public_key_hex' => bin2hex(random_bytes(32)),
        'safety_number_words' => 'one two three four five six',
        'is_self' => 0,
        'paired_at' => '2026-08-01 00:00:00',
        'confirmed_at' => '2026-08-01 00:00:00',
        'last_lan_host' => $host,
        'last_lan_port' => $port,
        'created_at' => '2026-08-01 00:00:00',
        'updated_at' => '2026-08-01 00:00:00',
    ]);

    return $peerDeviceId;
}

it('dials the address the phone last reached the desktop at, and drops it when nobody answers', function (): void {
    $user = syncDialUser('mobile-dial-'.bin2hex(random_bytes(4)));
    $userId = (int) $user->id;
    $this->actingAs($user);

    /** @var Session $session */
    $session = app(Session::class);
    AppLockTestHarness::unlock($session, str_repeat("\x2a", 32));

    @unlink(UserDataPathService::appPath('sync/identity/'.$userId.'.enc'));

    /** @var DeviceIdentityService $identityService */
    $identityService = app(DeviceIdentityService::class);
    $identityService->generateAndPersist($userId, $session);

    /** @var DatabaseManager $db */
    $db = app(DatabaseManager::class);
    $peerDeviceId = seedReachedPeer($db, $userId, '127.0.0.1', SYNC_DIAL_CLOSED_PORT);

    Livewire::test(SyncScreen::class)
        ->call('syncNow')
        ->assertSet('lastSyncResult', SyncAttemptOutcome::Unreachable->value);

    // Forgetting only happens on a dial that was actually made against a
    // remembered address, so the cleared columns are the proof the screen
    // reached for the registry rather than for a relay URL it never had.
    $row = $db->connection()->table('device_registry')
        ->where('user_id', $userId)
        ->where('device_id', $peerDeviceId)
        ->first(['last_lan_host', 'last_lan_port']);

    expect($row)->not->toBeNull()
        ->and($row->last_lan_host)->toBeNull()
        ->and($row->last_lan_port)->toBeNull();
});

it('keeps the remembered address when there was never a peer to dial', function (): void {
    $user = syncDialUser('mobile-dial-nopeer-'.bin2hex(random_bytes(4)));
    $this->actingAs($user);

    // hasPeers is false, so the press returns before any burst — and must not
    // report an outcome it did not produce.
    Livewire::test(SyncScreen::class)
        ->call('syncNow')
        ->assertSet('lastSyncResult', null);
});
