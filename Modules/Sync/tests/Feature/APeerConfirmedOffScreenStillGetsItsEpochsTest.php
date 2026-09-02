<?php

declare(strict_types=1);

use Illuminate\Contracts\Session\Session;
use Illuminate\Database\DatabaseManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Modules\Auth\Public\Testing\AppLockTestHarness;
use Modules\Core\Models\User;
use Modules\Sync\Internal\Crypto\GdkKeyringService;
use Modules\Sync\Internal\Crypto\GdkRotationService;
use Modules\Sync\Internal\Http\Middleware\DeliversOwedEpochs;
use Modules\Sync\Internal\Identity\DeviceIdentityService;
use Symfony\Component\HttpFoundation\Response;

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

// The tail has two halves and the screen skipped both. A key with no history
// behind it hands the peer a readable log of only what was captured live: this
// desktop sent 103 transactions from the one import that post-dated sync and
// none of the 35 that predated it. ResumesPreSyncCapture cannot start one —
// it only ever resumes a capture that is already open.
it('opens the pre-sync capture the same skipped tail owed', function (): void {
    /** @var DatabaseManager $db */
    $db = app(DatabaseManager::class);
    $user = pcoUser();
    $userId = (int) $user->id;
    test()->actingAs($user);

    /** @var Session $session */
    $session = app(Session::class);
    AppLockTestHarness::unlock($session, str_repeat("\x2a", 32));

    app(DeviceIdentityService::class)->generateAndPersist($userId, $session);
    app(GdkKeyringService::class)->generateAndPersist($userId, $session);
    pcoConfirmedPeer($user);

    // A row that predates sync, so only the history capture can carry it.
    $db->connection()->table('accounts')->insert([
        'user_id' => $userId,
        'name' => 'Predates sync',
        'slug' => 'pco-pre-'.bin2hex(random_bytes(4)),
        'kind' => 'bank',
        'iban' => 'NL00PCO'.strtoupper(bin2hex(random_bytes(5))),
        'default_currency' => 'EUR',
        'created_at' => '2026-09-01 23:00:00',
        'updated_at' => '2026-09-01 23:00:00',
    ]);

    expect($db->connection()->table('op_log_entries')->where('user_id', $userId)->count())->toBe(0);

    app(DeliversOwedEpochs::class)->terminate(Request::create('/'), new Response);

    expect($db->connection()->table('op_log_entries')->where('user_id', $userId)->count())
        ->toBeGreaterThan(0, 'the peer was handed a key with no history behind it');
});

// The two debts are independent. Gating the capture on the fan-out meant one
// peer whose key material can never be sealed held this device's own history
// hostage for good — and the history is what ResumesPreSyncCapture needs
// opened before it can finish anything.
it('captures the history even when a peer cannot be sealed to', function (): void {
    /** @var DatabaseManager $db */
    $db = app(DatabaseManager::class);
    $user = pcoUser();
    $userId = (int) $user->id;
    test()->actingAs($user);

    /** @var Session $session */
    $session = app(Session::class);
    AppLockTestHarness::unlock($session, str_repeat("\x2a", 32));

    app(DeviceIdentityService::class)->generateAndPersist($userId, $session);
    app(GdkKeyringService::class)->generateAndPersist($userId, $session);

    // Confirmed, owed, and holding a public key no sealed box can be built
    // against — the fan-out throws for this peer however often it is retried.
    $registryId = pcoConfirmedPeer($user);
    $db->connection()->table('device_registry')
        ->where('id', $registryId)
        ->update(['x25519_public_key_hex' => 'not-hex-at-all']);

    $db->connection()->table('accounts')->insert([
        'user_id' => $userId,
        'name' => 'Predates sync',
        'slug' => 'pco-unsealable-'.bin2hex(random_bytes(4)),
        'kind' => 'bank',
        'iban' => 'NL00PCU'.strtoupper(bin2hex(random_bytes(5))),
        'default_currency' => 'EUR',
        'created_at' => '2026-09-01 23:00:00',
        'updated_at' => '2026-09-01 23:00:00',
    ]);

    app(DeliversOwedEpochs::class)->terminate(Request::create('/'), new Response);

    expect($db->connection()->table('op_log_entries')->where('user_id', $userId)->count())
        ->toBeGreaterThan(0, 'an unsealable peer blocked this device capturing its own history');
});
