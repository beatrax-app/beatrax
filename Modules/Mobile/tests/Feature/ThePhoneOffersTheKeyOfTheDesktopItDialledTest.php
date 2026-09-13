<?php

declare(strict_types=1);

use Illuminate\Contracts\Session\Session;
use Illuminate\Database\DatabaseManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Modules\Auth\Public\Testing\AppLockTestHarness;
use Modules\Core\Models\User;
use Modules\Mobile\Internal\Http\Livewire\SyncScreen;
use Modules\Mobile\Internal\Sync\MobileSyncTriggerService;
use Modules\Mobile\Internal\Sync\PeerDial;
use Modules\Sync\Internal\Identity\DeviceIdentityService;
use Modules\Sync\Public\Services\DeviceRegistryService;
use Modules\Sync\Tests\Support\AnsweringNoisePeer;

uses(RefreshDatabase::class);

// THE DEFECT. A Noise IK initiator names the responder before msg1: the first
// message is written to the responder's static key, so a phone that offers the
// wrong one has not started a slower handshake, it has started one that cannot
// complete. The phone resolved the ADDRESS from an ordered read of the registry
// and the KEY from a second, unordered one, and with two confirmed desktops the
// two named different machines.

/** @return array{0: int, 1: Session} */
function twoDeskPhone(): array
{
    $user = User::query()->create([
        'username' => 'twodesk-'.bin2hex(random_bytes(4)),
        'password' => bcrypt('twodesk-pass'),
        'period_start_day' => 1,
        'default_currency_view' => 'eur_only',
    ]);

    /** @var Session $session */
    $session = app(Session::class);
    AppLockTestHarness::unlock($session, str_repeat("\x2a", 32));
    app(DeviceIdentityService::class)->generateAndPersist((int) $user->id, $session);

    return [(int) $user->id, $session];
}

// Confirmed in the SAME SECOND, which is what two desktops paired in one
// sitting look like: confirmed_at is a second-resolution zulu stamp, so the
// ordered read breaks the tie by device_id and an unordered one breaks it by
// whichever row the scan reached first.
function twoDeskConfirm(int $userId, string $deviceId, string $x25519Hex, ?int $port = null): void
{
    app(DatabaseManager::class)->connection()->table('device_registry')->insert([
        'user_id' => $userId,
        'device_id' => $deviceId,
        'name' => $deviceId,
        'ed25519_public_key_hex' => bin2hex(sodium_crypto_sign_publickey(sodium_crypto_sign_keypair())),
        'x25519_public_key_hex' => $x25519Hex,
        'safety_number_words' => 'alpha bravo charlie delta echo foxtrot',
        'is_self' => 0,
        'paired_at' => '2026-09-01T10:00:00Z',
        'confirmed_at' => '2026-09-01T10:01:00Z',
        'last_lan_host' => $port === null ? null : '127.0.0.1',
        'last_lan_port' => $port,
        'created_at' => '2026-09-01T10:00:00Z',
        'updated_at' => '2026-09-01T10:01:00Z',
    ]);
}

it('offers the key of the desktop whose address it dialled, not the first one on file', function (): void {
    [$userId, $session] = twoDeskPhone();

    $studio = new AnsweringNoisePeer;

    try {
        // Two confirmed desktops. `aa-…` sorts first under the stated order and
        // is not the one being dialled; the studio is, and holds the only key
        // that can open a handshake addressed to it.
        twoDeskConfirm($userId, 'aa-kitchen-desktop', str_repeat('cd', 32));
        twoDeskConfirm($userId, 'zz-studio-desktop', $studio->publicKeyHex, $studio->port);

        app(MobileSyncTriggerService::class)->attempt(
            $userId,
            $session,
            new PeerDial('zz-studio-desktop', '127.0.0.1', $studio->port),
        );

        expect($studio->wasDialled)->toBeTrue('the dial must reach the desktop whose address was resolved')
            ->and($studio->openedTheHandshake)->toBeTrue(
                'the desktop that was dialled must be able to open msg1 — it cannot when the phone wrote it to the other desktop\'s static key',
            );
    } finally {
        $studio->stop();
    }
});

// POSITIVE CONTROL. The same assertion against a desktop the phone holds NO
// confirmed key for must fail to open, so the check above is discriminating
// rather than true of any responder that answers.
it('cannot open a handshake at a desktop this phone holds no confirmed key for', function (): void {
    [$userId, $session] = twoDeskPhone();

    $stranger = new AnsweringNoisePeer;

    try {
        // Confirmed under a key that is not the one the listener holds, which
        // is exactly the state the defect put every non-first peer into.
        twoDeskConfirm($userId, 'zz-studio-desktop', str_repeat('cd', 32), $stranger->port);

        app(MobileSyncTriggerService::class)->attempt(
            $userId,
            $session,
            new PeerDial('zz-studio-desktop', '127.0.0.1', $stranger->port),
        );

        expect($stranger->wasDialled)->toBeTrue()
            ->and($stranger->openedTheHandshake)->toBeFalse();
    } finally {
        $stranger->stop();
    }
});

// A peer this phone has no confirmed key-agreement key for is skipped before
// the socket, so a dial aimed at an unknown device never reaches the network at
// all — where the old first-entry read would have dialled it with somebody
// else's key.
it('does not dial at all for a peer it holds no confirmed key for', function (): void {
    [$userId, $session] = twoDeskPhone();

    $desktop = new AnsweringNoisePeer;

    try {
        twoDeskConfirm($userId, 'aa-kitchen-desktop', str_repeat('cd', 32));

        app(MobileSyncTriggerService::class)->attempt(
            $userId,
            $session,
            new PeerDial('zz-never-paired-desktop', '127.0.0.1', $desktop->port),
        );

        expect($desktop->wasDialled)->toBeFalse();
    } finally {
        $desktop->stop();
    }
});

// The other half: naming the peer fixes WHICH desktop is reached, and this
// fixes how many. The phone runs no listener, so a desktop it never dials is
// one it never syncs with — and the second desktop of a household was
// unreachable for as long as the first one existed.
it('walks past a desktop that answered and refused to reach the next confirmed one', function (): void {
    [$userId] = twoDeskPhone();
    test()->actingAs(User::query()->findOrFail($userId));

    $kitchen = new AnsweringNoisePeer;
    $studio = new AnsweringNoisePeer;

    try {
        twoDeskConfirm($userId, 'aa-kitchen-desktop', str_repeat('cd', 32), $kitchen->port);
        twoDeskConfirm($userId, 'zz-studio-desktop', $studio->publicKeyHex, $studio->port);

        Livewire::test(SyncScreen::class)->call('syncNow');

        expect($kitchen->wasDialled)->toBeTrue('the first confirmed peer is still dialled first')
            ->and($studio->wasDialled)->toBeTrue('the second confirmed peer must be dialled too')
            ->and($studio->openedTheHandshake)->toBeTrue('and dialled with its own key, not the first peer\'s');
    } finally {
        $kitchen->stop();
        $studio->stop();
    }
});

// The order the walk takes is the registry's stated one, so a peer that came
// and went cannot reshuffle which desktop a press reaches first.
it('walks the confirmed peers in the registry\'s stated order', function (): void {
    [$userId] = twoDeskPhone();

    twoDeskConfirm($userId, 'zz-studio-desktop', str_repeat('ab', 32));
    twoDeskConfirm($userId, 'aa-kitchen-desktop', str_repeat('cd', 32));

    $registry = app(DeviceRegistryService::class);

    // Minus this phone's own row, exactly as every caller of the key map drops
    // it before reading anything else out.
    $keys = $registry->deviceX25519Keys($userId);
    unset($keys[(string) $registry->localDeviceId($userId)]);

    expect(array_keys($registry->otherDeviceNames($userId)))
        ->toBe(['aa-kitchen-desktop', 'zz-studio-desktop'])
        ->and(array_keys($keys))
        ->toBe(
            ['aa-kitchen-desktop', 'zz-studio-desktop'],
            'the key map has to come back in the same stated order as the name map, or the two disagree about who "the peer" is the moment a tie exists',
        );
});
