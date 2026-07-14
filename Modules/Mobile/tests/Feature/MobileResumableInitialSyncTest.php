<?php

declare(strict_types=1);

use Illuminate\Contracts\Session\Session;
use Illuminate\Database\DatabaseManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Modules\Auth\Internal\Lock\LockStateManager;
use Modules\Core\Models\User;
use Modules\Core\Public\Services\UserDataPathService;
use Modules\Mobile\Internal\Sync\InitialSyncPuller;
use Modules\Sync\Internal\Crypto\GdkEpoch;
use Modules\Sync\Internal\Crypto\GdkKeyringService;
use Modules\Sync\Internal\Crypto\GdkRotationService;
use Modules\Sync\Internal\Identity\DeviceIdentityService;
use Modules\Sync\Internal\Transport\Relay\RelayConfig;
use Modules\Sync\Public\Services\GdkEpochDeliveryGateway;

uses(RefreshDatabase::class);

/*
 * R5 / D-03 / D-04 / MOBILE-01/MOBILE-02 — resumable initial-sync pull
 * (15-08-PLAN.md Task 1). Turns the Wave-0 RED class_exists() gate GREEN.
 *
 * Scenario: a prior pull was killed mid-batch (40 of 100 records applied,
 * durable cursor persisted at that watermark). InitialSyncPuller is
 * re-instantiated FRESH (simulating a cold-started process after an
 * app-kill — it carries zero in-memory state) and resumes from the
 * durable `mobile_sync_progress` cursor, applies the remaining 60 records
 * exactly once (no duplication), and reaches records_applied ===
 * records_expected with phase = 'complete'.
 *
 * No live LanSyncClient WebSocket dial is exercised here — a live loopback
 * connection is Manual-Only Verification (LanDirectSessionTest's own
 * documented precedent, reaffirmed by MobileBidirectionalMergeTest,
 * 15-05-SUMMARY.md). Instead, `MobileSyncTriggerService::syncOnce()`'s
 * completion signal (`true`) is obtained via the REAL off-LAN relay leg
 * against a faked HTTP endpoint (`RelayClient` is constructor-injected
 * with `Http\Client\Factory`, which `Http::fake()` intercepts) — no test
 * double/mock of any Mobile/Sync class is used (LanSyncClient and
 * MobileSyncTriggerService are both `final`, so neither is mockable by
 * design; this test exercises the real classes throughout). The "records
 * that arrived" are seeded directly into op_log_entries — mirroring
 * MobileEncryptedCopyTest's established "write directly via
 * DatabaseManager::table()->insert()" fixture pattern — standing in for
 * whatever transport actually applied them; InitialSyncPuller's own
 * watermark-delta bookkeeping (Task 1 behavior) is what this test proves.
 */

function mobileResumeUser(string $username): User
{
    return User::query()->create([
        'username' => $username,
        'password' => bcrypt('mobile-resume-pass'),
        'period_start_day' => 1,
        'default_currency_view' => 'eur_only',
    ]);
}

/**
 * Seed $count raw op_log_entries rows directly (no signature verification
 * occurs anywhere in InitialSyncPuller's read-only watermark-delta count —
 * that gate belongs to OpLogReplayer::replay(), exercised elsewhere).
 */
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

    // Modules\Mobile\Tests\TestCase does not prime an unlocked session —
    // unlock it (mirrors MobileBidirectionalMergeTest's own precedent) so
    // the local device identity can be generated.
    (new LockStateManager)->unlock($session, str_repeat("\x2a", 32));

    /** @var DeviceIdentityService $identityService */
    $identityService = app(DeviceIdentityService::class);
    $identity = $identityService->generateAndPersist((int) $user->id, $session);

    // A device that reaches InitialSyncPuller holds a GDK keyring — it has
    // either self-minted (create-account) or received the peer's epochs
    // (import). Self-mint one so this durable-cursor resume test reflects a
    // decryptable device: LOW-01 (15-import-join-REVIEW.md) now gates
    // 'complete' on an installed keyring so an import can't finish onto a
    // dashboard of gdk_decrypt_failed rows.
    /** @var GdkKeyringService $keyring */
    $keyring = app(GdkKeyringService::class);
    $keyring->generateAndPersist((int) $user->id, $session);

    /** @var DatabaseManager $db */
    $db = app(DatabaseManager::class);

    // A confirmed desktop peer — required for InitialSyncPuller's
    // resolvePeerDeviceId() (mirrors LanSyncClient's own confirmed-only,
    // first-non-self resolution).
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

    // 40 already-applied entries (hlc 1..40) — the state a prior,
    // interrupted pull left behind before the app was killed mid-batch.
    seedResumeOpLogRows($db, (int) $user->id, $peerDeviceId, 40, 1);

    // The durable cursor exactly as a killed-mid-pull screen would have
    // left it: 40 applied, watermark advanced to the 40th entry's HLC.
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

    // The remaining 60 entries (hlc 41..100) now exist locally — standing
    // in for whatever the resumed transport attempt applies.
    seedResumeOpLogRows($db, (int) $user->id, $peerDeviceId, 60, 41);

    // A configured, faked relay endpoint makes
    // MobileSyncTriggerService::syncOnce() report a genuine `true` (the
    // FULL bidirectional catch-up exchange completed) without a live
    // socket. RelayClient is constructor-injected with Http\Client\Factory
    // (never the Http facade internally), which Http::fake() intercepts —
    // this is the real relay leg, not a mock of any Mobile/Sync class.
    /** @var RelayConfig $relayConfig */
    $relayConfig = app(RelayConfig::class);
    $relayConfig->setEndpointUrl('https://relay.fixture.test');
    $relayConfig->setAuthToken('fixture-relay-token');
    Http::fake(['relay.fixture.test/*' => Http::response(['blobs' => []], 200)]);

    // Re-instantiate InitialSyncPuller FRESH — simulates a cold-started
    // process after the app-kill; it carries no in-memory state, so
    // resumption must come entirely from the durable cursor + local op-log.
    /** @var InitialSyncPuller $puller */
    $puller = app(InitialSyncPuller::class);

    $progress = $puller->pull((int) $user->id, $session);

    expect($progress['records_applied'])->toBe(100, 'All 100 entries (40 pre-existing + 60 newly applied) must be counted exactly once.');
    expect($progress['records_expected'])->toBe(100);
    expect($progress['phase'])->toBe('complete');
    expect($progress['percent'])->toBe(100);

    // No duplication: exactly 100 rows exist — a resumed pull must never
    // re-insert or double-count the 40 already-applied rows.
    expect($db->connection()->table('op_log_entries')->where('user_id', $user->id)->count())->toBe(100);

    // The durable cursor is what actually persisted (not just the
    // in-memory return value).
    $row = $db->connection()->table('mobile_sync_progress')
        ->where('user_id', $user->id)
        ->where('peer_device_id', $peerDeviceId)
        ->first();
    expect((int) $row->records_applied)->toBe(100);
    expect((int) $row->records_expected)->toBe(100);
    expect($row->phase)->toBe('complete');
    expect((int) $row->last_hlc_l)->toBe(100);

    // Idempotency: calling pull() again (e.g. a screen polling after
    // completion) must not grow or duplicate anything — the puller
    // short-circuits once phase === 'complete'.
    $again = $puller->pull((int) $user->id, $session);
    expect($again['records_applied'])->toBe(100);
    expect($again['phase'])->toBe('complete');
    expect($db->connection()->table('op_log_entries')->where('user_id', $user->id)->count())->toBe(100);
});

it('skips a pull entirely — no cursor mutation, data stays encrypted — when the app-lock KEK is unavailable', function (): void {
    $user = mobileResumeUser('mobile-resume-nokek-'.bin2hex(random_bytes(4)));

    /** @var Session $session */
    $session = app(Session::class);

    /** @var DatabaseManager $db */
    $db = app(DatabaseManager::class);

    // No identity file exists for this user at all (sync never enabled) —
    // DeviceIdentityLoader::load() returns null before any KEK check.
    /** @var InitialSyncPuller $puller */
    $puller = app(InitialSyncPuller::class);

    $progress = $puller->pull((int) $user->id, $session);

    expect($progress)->toBe(['records_applied' => 0, 'records_expected' => null, 'percent' => 0, 'phase' => 'pending']);
    expect($db->connection()->table('mobile_sync_progress')->where('user_id', $user->id)->count())->toBe(0);
    expect($db->connection()->table('op_log_entries')->where('user_id', $user->id)->count())->toBe(0);
});

/*
 * Task 4 + threat item 6 (Phase 15 import-join) — resumability/idempotency
 * of the epoch-install -> rebuild-once step. 6h per
 * 15-import-join-PLAN.md's test-plan.
 */

it('runs the history re-projection AT MOST ONCE per cursor once the keyring becomes non-empty; percent never regresses', function (): void {
    $user = mobileResumeUser('mobile-resume-reproject-'.bin2hex(random_bytes(4)));
    $userId = (int) $user->id;

    /** @var Session $session */
    $session = app(Session::class);
    (new LockStateManager)->unlock($session, str_repeat("\x2a", 32));

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

    // The keyring is populated BEFORE the first pull() step — standing in
    // for "LanSyncClient's post-catch-up receive leg just installed the
    // desktop's epoch during THIS connection attempt" (Task 4's own
    // sequence: sync step -> install epochs -> if newly decryptable,
    // rebuild). current_epoch transitions null -> non-null exactly once,
    // which is the ONLY signal InitialSyncPuller::pull() reads.
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

    $first = $puller->pull($userId, $session);
    expect($first['phase'])->toBe('complete');
    expect($first['percent'])->toBe(100);

    $cursorAfterFirst = $db->connection()->table('mobile_sync_progress')
        ->where('user_id', $userId)
        ->where('peer_device_id', $peerDeviceId)
        ->first();
    expect($cursorAfterFirst->reprojected_at)->not->toBeNull('the FIRST pull() step to see a non-empty keyring must run the re-projection');

    $reprojectedAtAfterFirst = $cursorAfterFirst->reprojected_at;

    // A second pull() tick (e.g. a wire:poll after phase === 'complete')
    // must NOT re-run the re-projection — the guard flag stays UNCHANGED.
    $second = $puller->pull($userId, $session);
    expect($second['phase'])->toBe('complete');
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
    (new LockStateManager)->unlock($session, str_repeat("\x2a", 32));

    @unlink(UserDataPathService::appPath('sync/gdk/'.$userId.'.enc'));

    /** @var DeviceIdentityService $identityService */
    $identityService = app(DeviceIdentityService::class);
    $phone = $identityService->generateAndPersist($userId, $session);

    /** @var GdkKeyringService $keyring */
    $keyring = app(GdkKeyringService::class);

    $rawEpochKey = random_bytes(SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_KEYBYTES);

    /** @var GdkRotationService $rotation */
    $rotation = app(GdkRotationService::class);
    $recipientPub = sodium_hex2bin($phone->x25519PublicKeyHex);
    $wrap = $rotation->buildGdkEpochWrap(1, $rawEpochKey, $recipientPub, $phone->deviceId);
    $wrapJson = json_encode($wrap, JSON_THROW_ON_ERROR);

    /** @var GdkEpochDeliveryGateway $delivery */
    $delivery = app(GdkEpochDeliveryGateway::class);
    $delivery->receiveEpochWrap($wrapJson, $userId, $session);

    $afterFirst = $keyring->currentEpoch($userId, $session);
    expect($afterFirst->epochId)->toBe(1);

    // Advance further (mirrors GdkEpochDeliveryTest's idempotency
    // assertion at :346), then re-deliver the SAME stale epoch-1 wrap.
    $keyring->appendEpoch(
        $userId,
        new GdkEpoch(2, sodium_bin2hex(random_bytes(SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_KEYBYTES))),
        $session,
    );

    $delivery->receiveEpochWrap($wrapJson, $userId, $session);

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

it('does not report complete while the GDK keyring is empty — an import awaiting the desktop epochs stays blocking (LOW-01)', function (): void {
    $user = mobileResumeUser('mobile-low01-'.bin2hex(random_bytes(4)));

    /** @var Session $session */
    $session = app(Session::class);
    (new LockStateManager)->unlock($session, str_repeat("\x2a", 32));

    /** @var DeviceIdentityService $identityService */
    $identityService = app(DeviceIdentityService::class);
    $identityService->generateAndPersist((int) $user->id, $session);

    // Deliberately NO keyring self-mint — this device is importing and has
    // not yet received the desktop's epochs (empty keyring).

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

    // Faked relay so syncOnce() can report a genuine catch-up — the ONLY
    // thing that must prevent 'complete' here is the empty keyring.
    /** @var RelayConfig $relayConfig */
    $relayConfig = app(RelayConfig::class);
    $relayConfig->setEndpointUrl('https://relay.fixture.test');
    $relayConfig->setAuthToken('fixture-relay-token');
    Http::fake(['relay.fixture.test/*' => Http::response(['blobs' => []], 200)]);

    /** @var InitialSyncPuller $puller */
    $puller = app(InitialSyncPuller::class);
    $progress = $puller->pull((int) $user->id, $session);

    expect($progress['phase'])->not->toBe('complete', 'an import with an empty keyring must not report complete — it would land on a gdk_decrypt_failed dashboard');
});
