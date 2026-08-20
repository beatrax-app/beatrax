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

function mobileMergeUser(string $username): User
{
    return User::query()->create([
        'username' => $username,
        'password' => bcrypt('mobile-merge-pass'),
        'period_start_day' => 1,
        'default_currency_view' => 'eur_only',
    ]);
}

// No live LanSyncClient dial happens here: a loopback WebSocket connection is
// manual-only verification. What is proved instead is the merge outcome the
// mobile peer's receive path produces, since receiveOps() reuses OpLogReplayer
// byte for byte.

it('converges a phone-originated and a desktop-originated op on the same (table,pk,field) identically on both peers', function (): void {
    $user = mobileMergeUser('mobile-merge-'.bin2hex(random_bytes(4)));

    $triggerServiceClass = MobileSyncTriggerService::class;
    expect(class_exists($triggerServiceClass))->toBeTrue(
        'MobileSyncTriggerService must exist (Plan 05) before the convergence scenario below is meaningful.'
    );

    /** @var DatabaseManager $db */
    $db = app(DatabaseManager::class);

    // A category both peers already hold, as a prior CREATE_ROW sync would leave it.
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

    // The phone renames the category while offline.
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

    // The desktop renames it differently, also offline, and with the later HLC.
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

    // Exactly the merge SyncSession::receiveOps() drives on whichever peer
    // receives the mobile op first.
    $forwardReplayer = new OpLogReplayer($db, $deviceKeys);
    $forwardReplayer->replay([$mobileEntry, $desktopEntry], (int) $user->id);
    $forwardResult = $db->connection()->table('categories')->where('id', $categoryId)->value('name');

    // The other peer receives the desktop op first and must still land there.
    $db->connection()->table('categories')->where('id', $categoryId)->update(['name' => 'Groceries']);
    $reverseReplayer = new OpLogReplayer($db, $deviceKeys);
    $reverseReplayer->replay([$desktopEntry, $mobileEntry], (int) $user->id);
    $reverseResult = $db->connection()->table('categories')->where('id', $categoryId)->value('name');

    expect($reverseResult)->toBe($forwardResult, 'Replay order must never change the converged state (order-independence).');
    expect($forwardResult)->toBe('Groceries (desktop edit)', 'The later-HLC (desktop) edit must win LWW regardless of replay order.');

    // No duplicate or lost category.
    expect($db->connection()->table('categories')->where('id', $categoryId)->count())->toBe(1);

    // Both devices' keys were known, so nothing was quarantined.
    expect($db->connection()->table('op_log_quarantine')->where('user_id', $user->id)->count())->toBe(0);
});

it('MobileSyncTriggerService::syncOnce() skips cleanly — no data write, no key cached — when the app-lock KEK is unavailable', function (): void {
    $user = mobileMergeUser('mobile-merge-nokek-'.bin2hex(random_bytes(4)));

    /** @var Session $session */
    $session = app(Session::class);

    // The Mobile TestCase does not prime the session with an unlocked dummy data
    // key the way the Sync one does, so a fresh session starts genuinely locked.
    // Unlock it so generateAndPersist() succeeds, and only then rebind
    // AppLockKeyService, leaving the KEK as the one thing that differs.
    (new LockStateManager)->unlock($session, str_repeat("\x2a", 32));

    /** @var DeviceIdentityService $identityService */
    $identityService = app(DeviceIdentityService::class);
    $identityService->generateAndPersist((int) $user->id, $session);

    /** @var DatabaseManager $db */
    $db = app(DatabaseManager::class);
    $opLogCountBefore = $db->connection()->table('op_log_entries')->where('user_id', $user->id)->count();

    // release() returning null is a locked app-lock with no key to hand out.
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

    // The identity-null guard returns before LanSyncClient or RelayClient is
    // reached, so no sync_sessions row is created for this tick.
    expect($db->connection()->table('sync_sessions')->where('user_id', $user->id)->count())->toBe(0);
});
