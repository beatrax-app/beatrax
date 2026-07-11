<?php

declare(strict_types=1);

use Illuminate\Contracts\Session\Session;
use Illuminate\Database\DatabaseManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Auth\Internal\Lock\LockStateManager;
use Modules\Auth\Public\Services\AppLockKeyService;
use Modules\Core\Models\User;
use Modules\Mobile\Internal\Sync\MobileSyncTriggerService;
use Modules\Sync\Internal\Identity\DeviceIdentityService;
use Modules\Sync\Internal\Merge\OpLogReplayer;
use Modules\Sync\Internal\OpLog\OpLogEntry;
use Modules\Sync\Internal\OpLog\OpType;
use Modules\Sync\Internal\Signing\DeviceKeySigner;

uses(RefreshDatabase::class);

/*
 * MOBILE-01 — phone<->desktop bidirectional convergence + the KEK-gated
 * skip behavior (15-05-PLAN.md Task 3).
 *
 * Turns the Wave-0 RED class_exists() gate GREEN by fleshing it out into:
 *
 *   1. The real convergence scenario: a phone-originated ("device-mobile")
 *      and a desktop-originated ("device-desktop") op on the SAME
 *      (table,pk,field) converge to an identical value on both peers,
 *      order-independent — mirroring the established
 *      EnvelopeConcurrentEditConvergenceTest / ConcurrentSameFieldEditTest
 *      pattern (STATE precedent: real op-log rows replayed forward vs
 *      reverse through the UNCHANGED OpLogReplayer/MergeRulesRegistry).
 *      No live LanSyncClient dial is exercised here — a live loopback
 *      WebSocket connection is Manual-Only Verification, mirroring
 *      LanDirectSessionTest's own documented precedent; this test proves
 *      the MERGE OUTCOME the mobile peer's receive path (SyncSession::
 *      receiveOps() -> OpLogReplayer::replay(), reused byte-for-byte by
 *      LanSyncClient) is responsible for producing.
 *   2. MobileSyncTriggerService::syncOnce() skips cleanly — no data
 *      write, no key cached — when the app-lock KEK is unavailable
 *      (RESEARCH Anti-Pattern #3 / T-15-12), using the same
 *      release()-returns-null substitution DeviceIdentityLoaderTest
 *      already established for this exact scenario.
 */

function mobileMergeUser(string $username): User
{
    return User::query()->create([
        'username' => $username,
        'password' => bcrypt('mobile-merge-pass'),
        'period_start_day' => 1,
        'default_currency_view' => 'eur_only',
    ]);
}

it('converges a phone-originated and a desktop-originated op on the same (table,pk,field) identically on both peers', function (): void {
    $user = mobileMergeUser('mobile-merge-'.bin2hex(random_bytes(4)));

    $triggerServiceClass = MobileSyncTriggerService::class;
    expect(class_exists($triggerServiceClass))->toBeTrue(
        'MobileSyncTriggerService must exist (Plan 05) before the convergence scenario below is meaningful.'
    );

    /** @var DatabaseManager $db */
    $db = app(DatabaseManager::class);

    // A category both peers already hold (same stable PK — mirrors a prior
    // CREATE_ROW sync both devices already converged on).
    $categoryId = $db->connection()->table('categories')->insertGetId([
        'user_id' => $user->id,
        'name' => 'Groceries',
        'slug' => 'mobile-merge-groceries-'.bin2hex(random_bytes(3)),
        'kind' => 'expense',
        'display_order' => 1,
        'created_at' => '2026-07-01 00:00:00',
        'updated_at' => '2026-07-01 00:00:00',
    ]);

    $signer = new DeviceKeySigner;
    $keypair = sodium_crypto_sign_keypair();
    $sk = sodium_crypto_sign_secretkey($keypair);
    $pk = sodium_crypto_sign_publickey($keypair);
    $pkHex = bin2hex($pk);

    // Phone (offline) renames the category.
    $mobileEntryUnsigned = new OpLogEntry(
        table: 'categories',
        pk: $categoryId,
        field: 'name',
        value: '"Groceries (phone edit)"',
        hlcL: 2000,
        hlcC: 0,
        deviceId: 'device-mobile',
        opType: OpType::Set,
        signature: '',
        userId: (int) $user->id,
    );
    $mobileEntry = new OpLogEntry(
        table: $mobileEntryUnsigned->table,
        pk: $mobileEntryUnsigned->pk,
        field: $mobileEntryUnsigned->field,
        value: $mobileEntryUnsigned->value,
        hlcL: $mobileEntryUnsigned->hlcL,
        hlcC: $mobileEntryUnsigned->hlcC,
        deviceId: $mobileEntryUnsigned->deviceId,
        opType: $mobileEntryUnsigned->opType,
        signature: $signer->sign($mobileEntryUnsigned->signingPayload(), $sk),
        userId: $mobileEntryUnsigned->userId,
    );

    // Desktop (independently, offline) renames the SAME category to a
    // different value, with a LATER HLC — desktop's edit is the true LWW
    // winner regardless of replay order.
    $desktopEntryUnsigned = new OpLogEntry(
        table: 'categories',
        pk: $categoryId,
        field: 'name',
        value: '"Groceries (desktop edit)"',
        hlcL: 3000,
        hlcC: 0,
        deviceId: 'device-desktop',
        opType: OpType::Set,
        signature: '',
        userId: (int) $user->id,
    );
    $desktopEntry = new OpLogEntry(
        table: $desktopEntryUnsigned->table,
        pk: $desktopEntryUnsigned->pk,
        field: $desktopEntryUnsigned->field,
        value: $desktopEntryUnsigned->value,
        hlcL: $desktopEntryUnsigned->hlcL,
        hlcC: $desktopEntryUnsigned->hlcC,
        deviceId: $desktopEntryUnsigned->deviceId,
        opType: $desktopEntryUnsigned->opType,
        signature: $signer->sign($desktopEntryUnsigned->signingPayload(), $sk),
        userId: $desktopEntryUnsigned->userId,
    );

    $deviceKeys = ['device-mobile' => $pkHex, 'device-desktop' => $pkHex];

    // Replay in one order (mobile first, then desktop) — this is exactly
    // the merge SyncSession::receiveOps() drives on whichever peer
    // receives the mobile op first.
    $forwardReplayer = new OpLogReplayer($db, $deviceKeys);
    $forwardReplayer->replay([$mobileEntry, $desktopEntry], (int) $user->id);
    $forwardResult = $db->connection()->table('categories')->where('id', $categoryId)->value('name');

    // Reset and replay in the REVERSE order — order-independence: the
    // OTHER peer receives the desktop op first, yet must converge to the
    // IDENTICAL final state.
    $db->connection()->table('categories')->where('id', $categoryId)->update(['name' => 'Groceries']);
    $reverseReplayer = new OpLogReplayer($db, $deviceKeys);
    $reverseReplayer->replay([$desktopEntry, $mobileEntry], (int) $user->id);
    $reverseResult = $db->connection()->table('categories')->where('id', $categoryId)->value('name');

    expect($reverseResult)->toBe($forwardResult, 'Replay order must never change the converged state (order-independence).');
    expect($forwardResult)->toBe('Groceries (desktop edit)', 'The later-HLC (desktop) edit must win LWW regardless of replay order.');

    // Exactly one row — no duplicate/lost category.
    expect($db->connection()->table('categories')->where('id', $categoryId)->count())->toBe(1);

    // No quarantine — both devices' keys were known and signatures verified.
    expect($db->connection()->table('op_log_quarantine')->where('user_id', $user->id)->count())->toBe(0);
});

it('MobileSyncTriggerService::syncOnce() skips cleanly — no data write, no key cached — when the app-lock KEK is unavailable', function (): void {
    $user = mobileMergeUser('mobile-merge-nokek-'.bin2hex(random_bytes(4)));

    /** @var Session $session */
    $session = app(Session::class);

    // Modules\Mobile\Tests\TestCase (unlike Modules\Sync\Tests\TestCase)
    // does not prime the session with an unlocked dummy data key, so a
    // fresh feature-test session starts genuinely locked (release() ===
    // null). Unlock it first — mirroring Modules\Sync\Tests\TestCase's own
    // priming — so generateAndPersist() below succeeds; only THEN do we
    // rebind AppLockKeyService to a locked double to exercise the gate
    // this test actually cares about.
    (new LockStateManager)->unlock($session, str_repeat("\x2a", 32));

    // Generate a real device identity while unlocked (mirrors
    // DeviceIdentityLoaderTest's own KEK-null precedent) so the ONLY thing
    // distinguishing this call from a normal sync attempt is the KEK.
    /** @var DeviceIdentityService $identityService */
    $identityService = app(DeviceIdentityService::class);
    $identityService->generateAndPersist((int) $user->id, $session);

    /** @var DatabaseManager $db */
    $db = app(DatabaseManager::class);
    $opLogCountBefore = $db->connection()->table('op_log_entries')->where('user_id', $user->id)->count();

    // A fake AppLockKeyService whose release() returns null (locked / no
    // app-lock) — the exact substitution AppLockKeyService's own docblock
    // documents as the established Phase 12 test pattern for this gate.
    app()->bind(AppLockKeyService::class, fn () => new class extends AppLockKeyService
    {
        public function __construct() {}

        public function release(Session $session): ?string
        {
            return null;
        }
    });

    /** @var MobileSyncTriggerService $trigger */
    $trigger = app(MobileSyncTriggerService::class);

    $result = $trigger->syncOnce((int) $user->id, $session, lanHost: '127.0.0.1', lanPort: 51337);

    expect($result)->toBeNull('syncOnce() must report a SKIPPED tick (null), never true/false, when no KEK is available.');

    $opLogCountAfter = $db->connection()->table('op_log_entries')->where('user_id', $user->id)->count();
    expect($opLogCountAfter)->toBe($opLogCountBefore, 'A locked tick must never write to op_log_entries.');

    // No transport was ever attempted — the identity-null guard returns
    // BEFORE LanSyncClient/RelayClient are reached, so no sync_sessions
    // row is created for this tick.
    expect($db->connection()->table('sync_sessions')->where('user_id', $user->id)->count())->toBe(0);
});
