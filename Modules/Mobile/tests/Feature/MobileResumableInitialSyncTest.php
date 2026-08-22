<?php

declare(strict_types=1);

use Illuminate\Contracts\Session\Session;
use Illuminate\Database\DatabaseManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Modules\Auth\Public\Testing\AppLockTestHarness;
use Modules\Core\Models\User;
use Modules\Core\Public\Services\UserDataPathService;
use Modules\Mobile\Internal\Http\Livewire\SetupProgressScreen;
use Modules\Mobile\Internal\Sync\InitialSyncPuller;
use Modules\Mobile\Internal\Sync\SetupStep;
use Modules\Mobile\Internal\Sync\SyncBlockedReason;
use Modules\Mobile\Internal\Sync\SyncPhase;
use Modules\Sync\Internal\Crypto\GdkEpoch;
use Modules\Sync\Internal\Crypto\GdkKeyringService;
use Modules\Sync\Internal\Crypto\GdkRotationService;
use Modules\Sync\Internal\Crypto\GdkWrapRecipient;
use Modules\Sync\Internal\Identity\DeviceIdentityService;
use Modules\Sync\Internal\Transport\Relay\RelayConfig;
use Modules\Sync\Public\Services\GdkEpochDeliveryGateway;

uses(RefreshDatabase::class);

// No live WebSocket dial here: syncOnce()'s completion signal comes from the
// real off-LAN relay leg against a faked HTTP endpoint, since RelayClient is
// constructor-injected with Http\Client\Factory. LanSyncClient and
// MobileSyncTriggerService are final, so neither is mockable by design.

function mobileResumeUser(string $username): User
{
    return User::query()->create([
        'username' => $username,
        'password' => bcrypt('mobile-resume-pass'),
        'period_start_day' => 1,
        'default_currency_view' => 'eur_only',
    ]);
}

// Raw rows: no signature verification happens in the puller's read-only
// watermark-delta count — that gate belongs to OpLogReplayer::replay().
function seedResumeOpLogRows(DatabaseManager $db, int $userId, string $deviceId, int $count, int $startHlcL): void
{
    for ($i = 0; $i < $count; $i++) {
        $db->connection()->table('op_log_entries')->insert([
            'user_id' => $userId,
            'device_id' => $deviceId,
            'table_name' => 'categories',
            'pk' => (string) (5000 + $startHlcL + $i),
            'field' => 'name',
            'op_type' => 'set',
            'value' => json_encode('Fixture category '.($startHlcL + $i)),
            'hlc_l' => $startHlcL + $i,
            'hlc_c' => 0,
            'signature' => 'fixture-signature-'.($startHlcL + $i),
            'recorded_at' => '2026-07-10 00:00:00',
        ]);
    }
}

it('resumes an initial sync from a durable mobile_sync_progress cursor with no duplication after a simulated app-kill', function (): void {
    $user = mobileResumeUser('mobile-resume-'.bin2hex(random_bytes(4)));

    /** @var Session $session */
    $session = app(Session::class);

    // The module TestCase does not prime an unlocked session, and the local
    // device identity cannot be generated without one.
    AppLockTestHarness::unlock($session, str_repeat("\x2a", 32));

    /** @var DeviceIdentityService $identityService */
    $identityService = app(DeviceIdentityService::class);
    $identity = $identityService->generateAndPersist((int) $user->id, $session);

    // Any device reaching the puller holds a keyring, and 'complete' is
    // gated on one, so self-mint here to model a decryptable device.
    /** @var GdkKeyringService $keyring */
    $keyring = app(GdkKeyringService::class);
    $keyring->generateAndPersist((int) $user->id, $session);

    /** @var DatabaseManager $db */
    $db = app(DatabaseManager::class);

    // resolvePeerDeviceId() takes the first confirmed non-self device.
    $peerDeviceId = 'desktop-fixture-'.bin2hex(random_bytes(4));
    $db->connection()->table('device_registry')->insert([
        'user_id' => $user->id,
        'device_id' => $peerDeviceId,
        'name' => 'Fixture Desktop',
        'ed25519_public_key_hex' => bin2hex(random_bytes(32)),
        'x25519_public_key_hex' => bin2hex(random_bytes(32)),
        'safety_number_words' => 'one two three four five six',
        'is_self' => 0,
        'paired_at' => '2026-07-01 00:00:00',
        'confirmed_at' => '2026-07-01 00:00:00',
        'created_at' => '2026-07-01 00:00:00',
        'updated_at' => '2026-07-01 00:00:00',
    ]);

    // What a pull killed mid-batch left behind: 40 of 100 applied, with the
    // durable cursor's watermark advanced to the 40th entry's HLC.
    seedResumeOpLogRows($db, (int) $user->id, $peerDeviceId, 40, 1);

    $db->connection()->table('mobile_sync_progress')->insert([
        'user_id' => $user->id,
        'peer_device_id' => $peerDeviceId,
        'records_expected' => 40,
        'records_applied' => 40,
        'last_hlc_l' => 40,
        'last_hlc_c' => 0,
        'phase' => 'pulling',
        'created_at' => '2026-07-10 00:00:00',
        'updated_at' => '2026-07-10 00:05:00',
    ]);

    // The remaining 60 entries, standing in for whatever the resumed
    // transport attempt applies.
    seedResumeOpLogRows($db, (int) $user->id, $peerDeviceId, 60, 41);

    /** @var RelayConfig $relayConfig */
    $relayConfig = app(RelayConfig::class);
    $relayConfig->setEndpointUrl('https://relay.fixture.test');
    $relayConfig->setAuthToken('fixture-relay-token');
    Http::fake(['relay.fixture.test/*' => Http::response(['blobs' => []], 200)]);

    // A FRESH puller carries no in-memory state, so a cold-started process
    // must resume entirely from the durable cursor and the local op-log.
    /** @var InitialSyncPuller $puller */
    $puller = app(InitialSyncPuller::class);

    // The rebuild is announced on its own tick before it runs, so the
    // screen can show that step instead of jumping from transfer to done.
    $announced = $puller->pull((int) $user->id, $session);
    expect($announced['phase'])->toBe(SyncPhase::Rebuilding)
        ->and($announced['blocked'])->toBe(SyncBlockedReason::Reprojecting);

    $progress = $puller->pull((int) $user->id, $session);

    expect($progress['records_applied'])->toBe(100, 'All 100 entries (40 pre-existing + 60 newly applied) must be counted exactly once.');
    expect($progress['records_expected'])->toBe(100);
    expect($progress['phase'])->toBe(SyncPhase::Complete);
    expect($progress['percent'])->toBe(100);

    // A resumed pull must never re-insert or double-count the first 40.
    expect($db->connection()->table('op_log_entries')->where('user_id', $user->id)->count())->toBe(100);

    // What persisted, not just the in-memory return value.
    $row = $db->connection()->table('mobile_sync_progress')
        ->where('user_id', $user->id)
        ->where('peer_device_id', $peerDeviceId)
        ->first();
    expect((int) $row->records_applied)->toBe(100);
    expect((int) $row->records_expected)->toBe(100);
    expect($row->phase)->toBe('complete');
    expect((int) $row->last_hlc_l)->toBe(100);

    // A screen still polling after completion must not grow anything.
    $again = $puller->pull((int) $user->id, $session);
    expect($again['records_applied'])->toBe(100);
    expect($again['phase'])->toBe(SyncPhase::Complete);
    expect($db->connection()->table('op_log_entries')->where('user_id', $user->id)->count())->toBe(100);
});

it('skips a pull entirely — no cursor mutation, data stays encrypted — when the app-lock KEK is unavailable', function (): void {
    $user = mobileResumeUser('mobile-resume-nokek-'.bin2hex(random_bytes(4)));

    /** @var Session $session */
    $session = app(Session::class);

    /** @var DatabaseManager $db */
    $db = app(DatabaseManager::class);

    // No identity file at all, so the loader returns null before any KEK check.
    /** @var InitialSyncPuller $puller */
    $puller = app(InitialSyncPuller::class);

    $progress = $puller->pull((int) $user->id, $session);

    // `blocked` names what the pull is waiting on, so a stalled setup screen
    // reads as a state instead of a frozen page.
    expect($progress)->toBe([
        'records_applied' => 0,
        'records_expected' => null,
        'percent' => 0,
        'phase' => SyncPhase::Pending,
        'blocked' => SyncBlockedReason::NoPeer,
    ]);
    expect($db->connection()->table('mobile_sync_progress')->where('user_id', $user->id)->count())->toBe(0);
    expect($db->connection()->table('op_log_entries')->where('user_id', $user->id)->count())->toBe(0);
});

it('runs the history re-projection AT MOST ONCE per cursor once the keyring becomes non-empty; percent never regresses', function (): void {
    $user = mobileResumeUser('mobile-resume-reproject-'.bin2hex(random_bytes(4)));
    $userId = (int) $user->id;

    /** @var Session $session */
    $session = app(Session::class);
    AppLockTestHarness::unlock($session, str_repeat("\x2a", 32));

    @unlink(UserDataPathService::appPath('sync/gdk/'.$userId.'.enc'));

    /** @var DeviceIdentityService $identityService */
    $identityService = app(DeviceIdentityService::class);
    $identityService->generateAndPersist($userId, $session);

    /** @var DatabaseManager $db */
    $db = app(DatabaseManager::class);

    $peerDeviceId = 'desktop-fixture-reproject-'.bin2hex(random_bytes(4));
    $db->connection()->table('device_registry')->insert([
        'user_id' => $userId,
        'device_id' => $peerDeviceId,
        'name' => 'Fixture Desktop',
        'ed25519_public_key_hex' => bin2hex(random_bytes(32)),
        'x25519_public_key_hex' => bin2hex(random_bytes(32)),
        'safety_number_words' => 'one two three four five six',
        'is_self' => 0,
        'paired_at' => '2026-07-01 00:00:00',
        'confirmed_at' => '2026-07-01 00:00:00',
        'created_at' => '2026-07-01 00:00:00',
        'updated_at' => '2026-07-01 00:00:00',
    ]);

    seedResumeOpLogRows($db, $userId, $peerDeviceId, 10, 1);

    // Populated BEFORE the first pull(), standing in for the post-catch-up
    // receive leg installing the desktop's epoch during this attempt.
    // current_epoch going null -> non-null once is the only signal read.
    /** @var GdkKeyringService $keyring */
    $keyring = app(GdkKeyringService::class);
    $keyring->generateAndPersist($userId, $session);

    /** @var RelayConfig $relayConfig */
    $relayConfig = app(RelayConfig::class);
    $relayConfig->setEndpointUrl('https://relay.fixture.test');
    $relayConfig->setAuthToken('fixture-relay-token');
    Http::fake(['relay.fixture.test/*' => Http::response(['blobs' => []], 200)]);

    /** @var InitialSyncPuller $puller */
    $puller = app(InitialSyncPuller::class);

    // Tick one announces the rebuild; tick two performs it and completes.
    expect($puller->pull($userId, $session)['phase'])->toBe(SyncPhase::Rebuilding);

    $first = $puller->pull($userId, $session);
    expect($first['phase'])->toBe(SyncPhase::Complete);
    expect($first['percent'])->toBe(100);

    $cursorAfterFirst = $db->connection()->table('mobile_sync_progress')
        ->where('user_id', $userId)
        ->where('peer_device_id', $peerDeviceId)
        ->first();
    expect($cursorAfterFirst->reprojected_at)->not->toBeNull('the FIRST pull() step to see a non-empty keyring must run the re-projection');

    $reprojectedAtAfterFirst = $cursorAfterFirst->reprojected_at;

    // A second tick must leave the guard flag unchanged.
    $second = $puller->pull($userId, $session);
    expect($second['phase'])->toBe(SyncPhase::Complete);
    expect($second['percent'])->toBe(100, 'percent must never regress');
    expect($second['records_applied'])->toBe($first['records_applied'], 'a completed pull is a cheap idempotent no-op');

    $cursorAfterSecond = $db->connection()->table('mobile_sync_progress')
        ->where('user_id', $userId)
        ->where('peer_device_id', $peerDeviceId)
        ->first();
    expect($cursorAfterSecond->reprojected_at)->toBe($reprojectedAtAfterFirst, 'the re-projection must run AT MOST ONCE per cursor');
});

it('re-delivering an already-installed (stale) epoch wrap through the receive gateway is a no-op — no duplication, no current_epoch downgrade', function (): void {
    $user = mobileResumeUser('mobile-resume-stale-wrap-'.bin2hex(random_bytes(4)));
    $userId = (int) $user->id;

    /** @var Session $session */
    $session = app(Session::class);
    AppLockTestHarness::unlock($session, str_repeat("\x2a", 32));

    @unlink(UserDataPathService::appPath('sync/gdk/'.$userId.'.enc'));

    /** @var DeviceIdentityService $identityService */
    $identityService = app(DeviceIdentityService::class);
    $phone = $identityService->generateAndPersist($userId, $session);

    /** @var GdkKeyringService $keyring */
    $keyring = app(GdkKeyringService::class);

    // The receive gateway rejects any wrap not signed by a confirmed device.
    /** @var DatabaseManager $db */
    $db = app(DatabaseManager::class);
    $senderSigKp = sodium_crypto_sign_keypair();
    $senderSecretHex = sodium_bin2hex(sodium_crypto_sign_secretkey($senderSigKp));
    $db->connection()->table('device_registry')->insert([
        'user_id' => $userId,
        'device_id' => 'desktop-sender',
        'name' => 'Desktop',
        'ed25519_public_key_hex' => sodium_bin2hex(sodium_crypto_sign_publickey($senderSigKp)),
        'x25519_public_key_hex' => bin2hex(sodium_crypto_box_publickey(sodium_crypto_box_keypair())),
        'safety_number_words' => 'one two three four five six',
        'is_self' => 0,
        'paired_at' => '2026-07-01 00:00:00',
        'confirmed_at' => '2026-07-01 00:00:00',
        'created_at' => '2026-07-01 00:00:00',
        'updated_at' => '2026-07-01 00:00:00',
    ]);

    $rawEpochKey = random_bytes(SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_KEYBYTES);

    /** @var GdkRotationService $rotation */
    $rotation = app(GdkRotationService::class);
    $recipientPub = sodium_hex2bin($phone->x25519PublicKeyHex);
    $wrap = $rotation->buildGdkEpochWrap(1, $rawEpochKey, new GdkWrapRecipient($phone->deviceId, $recipientPub), 'desktop-sender', $senderSecretHex);
    $wrapJson = json_encode($wrap, JSON_THROW_ON_ERROR);

    /** @var GdkEpochDeliveryGateway $delivery */
    $delivery = app(GdkEpochDeliveryGateway::class);
    $delivery->receiveEpochWrap($wrapJson, $userId, 'desktop-sender', $phone->deviceId, $session);

    $afterFirst = $keyring->currentEpoch($userId, $session);
    expect($afterFirst->epochId)->toBe(1);

    // Advance further, then re-deliver the SAME stale epoch-1 wrap.
    $keyring->appendEpoch(
        $userId,
        new GdkEpoch(2, sodium_bin2hex(random_bytes(SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_KEYBYTES))),
        $session,
    );

    $delivery->receiveEpochWrap($wrapJson, $userId, 'desktop-sender', $phone->deviceId, $session);

    $final = $keyring->currentEpoch($userId, $session);
    expect($final->epochId)->toBe(2, 'a redelivered stale wrap must never downgrade current_epoch');

    $loaded = $keyring->loadKeyring($userId, $session);
    $countOfEpochOne = 0;
    foreach ($loaded->epochs() as $epoch) {
        if ($epoch->epochId === 1) {
            $countOfEpochOne++;
        }
    }
    expect($countOfEpochOne)->toBe(1, 'epoch 1 must not be duplicated in the keyring');
});

it('does not report complete while the GDK keyring is empty — an import awaiting the desktop epochs stays blocking', function (): void {
    $user = mobileResumeUser('mobile-low01-'.bin2hex(random_bytes(4)));

    /** @var Session $session */
    $session = app(Session::class);
    AppLockTestHarness::unlock($session, str_repeat("\x2a", 32));

    /** @var DeviceIdentityService $identityService */
    $identityService = app(DeviceIdentityService::class);
    $identityService->generateAndPersist((int) $user->id, $session);

    // Deliberately no keyring: an importing device awaiting the epochs.

    /** @var DatabaseManager $db */
    $db = app(DatabaseManager::class);
    $peerDeviceId = 'desktop-fixture-'.bin2hex(random_bytes(4));
    $db->connection()->table('device_registry')->insert([
        'user_id' => $user->id,
        'device_id' => $peerDeviceId,
        'name' => 'Fixture Desktop',
        'ed25519_public_key_hex' => bin2hex(random_bytes(32)),
        'x25519_public_key_hex' => bin2hex(random_bytes(32)),
        'safety_number_words' => 'one two three four five six',
        'is_self' => 0,
        'paired_at' => '2026-07-01 00:00:00',
        'confirmed_at' => '2026-07-01 00:00:00',
        'created_at' => '2026-07-01 00:00:00',
        'updated_at' => '2026-07-01 00:00:00',
    ]);
    seedResumeOpLogRows($db, (int) $user->id, $peerDeviceId, 10, 1);

    // Faked relay so syncOnce() reports a genuine catch-up: the empty
    // keyring must be the only thing preventing 'complete'.
    /** @var RelayConfig $relayConfig */
    $relayConfig = app(RelayConfig::class);
    $relayConfig->setEndpointUrl('https://relay.fixture.test');
    $relayConfig->setAuthToken('fixture-relay-token');
    Http::fake(['relay.fixture.test/*' => Http::response(['blobs' => []], 200)]);

    /** @var InitialSyncPuller $puller */
    $puller = app(InitialSyncPuller::class);
    $progress = $puller->pull((int) $user->id, $session);

    expect($progress['phase'])->not->toBe(SyncPhase::Complete, 'an import with an empty keyring must not report complete — it would land on a gdk_decrypt_failed dashboard');
});

// The four phase strings are a storage format, not an internal spelling: a row
// written by an earlier build has to hydrate, and a value no case can represent
// has to resume the gate rather than throw on the read.
it('hydrates every stored phase string, and falls back to pending on one no case can represent', function (): void {
    $user = mobileResumeUser('mobile-resume-hydrate-'.bin2hex(random_bytes(4)));
    $userId = (int) $user->id;

    /** @var DatabaseManager $db */
    $db = app(DatabaseManager::class);

    /** @var InitialSyncPuller $puller */
    $puller = app(InitialSyncPuller::class);

    $stored = [
        'pending' => SyncPhase::Pending,
        'pulling' => SyncPhase::Pulling,
        'rebuilding' => SyncPhase::Rebuilding,
        'complete' => SyncPhase::Complete,
        'a-phase-no-case-can-represent' => SyncPhase::Pending,
    ];

    foreach ($stored as $column => $expected) {
        $db->connection()->table('mobile_sync_progress')->where('user_id', $userId)->delete();
        $db->connection()->table('mobile_sync_progress')->insert([
            'user_id' => $userId,
            'peer_device_id' => 'desktop-peer-dev',
            'records_expected' => 100,
            'records_applied' => 100,
            'last_hlc_l' => 100,
            'last_hlc_c' => 0,
            'phase' => $column,
            'created_at' => '2026-07-10 00:00:00',
            'updated_at' => '2026-07-10 00:05:00',
        ]);

        expect($puller->progress($userId)['phase'])->toBe($expected, "a stored '{$column}' must hydrate to its own case");
    }
});

it('drives the setup screen from a stored phase string alone', function (): void {
    $user = mobileResumeUser('mobile-resume-screen-'.bin2hex(random_bytes(4)));

    /** @var DatabaseManager $db */
    $db = app(DatabaseManager::class);
    $db->connection()->table('mobile_sync_progress')->insert([
        'user_id' => $user->id,
        'peer_device_id' => 'desktop-peer-dev',
        'records_expected' => 100,
        'records_applied' => 100,
        'last_hlc_l' => 100,
        'last_hlc_c' => 0,
        'phase' => 'complete',
        'created_at' => '2026-07-10 00:00:00',
        'updated_at' => '2026-07-10 00:05:00',
    ]);

    $screen = Livewire::actingAs($user)->test(SetupProgressScreen::class);

    expect($screen->instance()->phase)->toBe(SyncPhase::Complete)
        ->and($screen->instance()->step)->toBe(SetupStep::Rebuild);

    $screen->assertSet('percent', 100)->assertSet('isResuming', true);
});
