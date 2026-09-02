<?php

declare(strict_types=1);

use Illuminate\Contracts\Session\Session;
use Illuminate\Database\DatabaseManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Auth\Public\Testing\AppLockTestHarness;
use Modules\Core\Models\User;
use Modules\Sync\Internal\Crypto\GdkKeyringService;
use Modules\Sync\Internal\Crypto\GdkRotationService;
use Modules\Sync\Internal\Identity\DeviceIdentityService;

uses(RefreshDatabase::class);

// The fan-out lives in PairingFlowModal::enterSuccessStep(), reachable only
// from that component's own confirm and its 3-second poll. When the SECOND
// confirm of a ceremony lands while the modal is gone, the token still reaches
// `confirmed` and the peer is still admitted — and no epoch is ever sent. A
// fresh iPhone paired this way held 4,223 op-log entries, quarantined 460 of
// them as `gdk_decrypt_failed`, and waited on "Waiting for the encryption keys"
// for good, with no in-app recovery.
/**
 * @link ../../../../.docs/features/sync/gdk-epoch-wrap-delivery.md
 */
function pcoUser(): User
{
    return User::query()->create([
        'username' => 'pco-'.bin2hex(random_bytes(4)),
        'password' => bcrypt('fixture'),
        'period_start_day' => 1,
        'default_currency_view' => 'eur_only',
    ]);
}

// A peer admitted to the registry exactly as the ceremony leaves it: confirmed,
// and never fanned out to.
function pcoConfirmedPeer(User $user): int
{
    $boxKp = sodium_crypto_box_keypair();
    $sigKp = sodium_crypto_sign_keypair();

    return (int) app(DatabaseManager::class)->connection()->table('device_registry')->insertGetId([
        'user_id' => $user->id,
        'device_id' => 'pco-peer-'.bin2hex(random_bytes(4)),
        'name' => 'iPhone',
        'ed25519_public_key_hex' => sodium_bin2hex(sodium_crypto_sign_publickey($sigKp)),
        'x25519_public_key_hex' => sodium_bin2hex(sodium_crypto_box_publickey($boxKp)),
        'safety_number_words' => 'abandon ability able about above absent',
        'is_self' => 0,
        'paired_at' => '2026-09-01T23:34:20Z',
        'confirmed_at' => '2026-09-01T23:34:30Z',
        'last_seen_at' => null,
        'created_at' => '2026-09-01T23:34:20Z',
        'updated_at' => '2026-09-01T23:34:20Z',
    ]);
}

function pcoWrapCount(): int
{
    return app(DatabaseManager::class)->connection()
        ->table('relay_mailbox')
        ->where('blob', 'like', '%GDK_EPOCH_WRAP%')
        ->count();
}

it('names a confirmed peer that was never fanned out to', function (): void {
    $user = pcoUser();
    $registryId = pcoConfirmedPeer($user);

    expect(app(GdkRotationService::class)->peersOwedEpochs((int) $user->id))->toBe([$registryId]);
});

it('owes nothing once the fan-out has run', function (): void {
    $user = pcoUser();
    /** @var Session $session */
    $session = app(Session::class);
    AppLockTestHarness::unlock($session, str_repeat("\x2a", 32));

    app(DeviceIdentityService::class)->generateAndPersist((int) $user->id, $session);
    app(GdkKeyringService::class)->generateAndPersist((int) $user->id, $session);

    $registryId = pcoConfirmedPeer($user);
    expect(pcoWrapCount())->toBe(0);

    app(GdkRotationService::class)->fanOutAllEpochsToDevice((int) $user->id, $registryId, $session);

    // The wrap the stranded phone never got, and the debt cleared with it.
    expect(pcoWrapCount())->toBeGreaterThan(0);
    expect(app(GdkRotationService::class)->peersOwedEpochs((int) $user->id))->toBe([]);
});

// The device this bug already stranded: confirmed long ago, never sent an
// epoch, and nothing in the app retried. It must be repaid, not written off.
it('still owes a peer that was admitted before this column existed', function (): void {
    $user = pcoUser();
    $registryId = pcoConfirmedPeer($user);

    app(DatabaseManager::class)->connection()->table('device_registry')
        ->where('id', $registryId)
        ->update(['epochs_delivered_at' => null]);

    expect(app(GdkRotationService::class)->peersOwedEpochs((int) $user->id))->toContain($registryId);
});
