<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Database\DatabaseManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Core\Models\User;
use Modules\Sync\Internal\Config\MergeRulesRegistry;
use Modules\Sync\Internal\Merge\OpLogReplayer;
use Modules\Sync\Internal\OpLog\OpLogEntry;
use Modules\Sync\Internal\OpLog\OpLogWriter;
use Modules\Sync\Internal\OpLog\OpType;
use Modules\Sync\Public\Events\NotificationMutated;

uses(RefreshDatabase::class);

/*
 * Req 11 (18-04-PLAN.md), mirroring EnvelopeConcurrentEditConvergenceTest's
 * / StatusConcurrentEditConvergenceTest's two-device merge-simulation
 * harness. No domain "mark read"/"dismiss" action exists yet in this phase
 * (later plans wire it) — the test dispatches the real NotificationMutated
 * event directly, exercising the exact same production capture pipeline
 * (SyncCaptureListener::handleNotificationMutated -> OpLogWriter) a future
 * caller will use.
 *
 * Scenario setup: one notifications row is seeded once via a direct insert,
 * so both "device A" and "device B" hold an IDENTICAL row (same deterministic
 * string PK, D-05).
 *
 * Cases:
 *   1. Device A marks read -> ops replay onto device B -> B's row has the
 *      same non-null read_at (Req 11's stated acceptance criterion).
 *   2. Both devices mark the SAME notification read concurrently with
 *      DIFFERENT timestamps -> after merge both converge to the SAME single
 *      value regardless of replay order, no quarantine, exactly ONE row
 *      (D-09: either timestamp is correct).
 *   3. Device A dismisses, device B undoes the dismiss LATER -> LWW
 *      converges on the later write (D-10: dismiss is reversible).
 */

beforeEach(function (): void {
    CarbonImmutable::setTestNow('2026-07-17 09:00:00');

    $this->user = User::create([
        'username' => 'notif-sync-'.bin2hex(random_bytes(4)),
        'password' => 'fixture',
        'period_start_day' => 1,
    ]);
});

afterEach(function (): void {
    CarbonImmutable::setTestNow();
});

/**
 * Constructs a fresh OpLogWriter for the given device, binds it into the
 * container (so SyncCaptureListener's lazy resolution picks it up), and
 * returns its hex-encoded public key for the replayer's device-key map.
 */
function notifSyncBindDeviceWriter(int $userId, string $deviceId): string
{
    $keypair = sodium_crypto_sign_keypair();
    $sk = sodium_crypto_sign_secretkey($keypair);
    $pk = sodium_crypto_sign_publickey($keypair);

    /** @var OpLogWriter $writer */
    $writer = app(OpLogWriter::class, [
        'deviceId' => $deviceId,
        'userId' => $userId,
        'secretKey' => $sk,
        'publicKey' => $pk,
    ]);
    app()->instance(OpLogWriter::class, $writer);

    return bin2hex($pk);
}

/**
 * @return list<OpLogEntry>
 */
function notifSyncOpsAfter(DatabaseManager $db, int $userId, string $notificationId, int $afterId): array
{
    return $db->connection()->table('op_log_entries')
        ->where('user_id', $userId)
        ->where('table_name', 'notifications')
        ->where('pk', $notificationId)
        ->where('id', '>', $afterId)
        ->orderBy('id')
        ->get()
        ->map(static function (object $row): OpLogEntry {
            $pk = is_numeric($row->pk) ? (int) $row->pk : (string) $row->pk;

            return new OpLogEntry(
                table: (string) $row->table_name,
                pk: $pk,
                field: (string) $row->field,
                value: $row->value !== null ? (string) $row->value : null,
                hlcL: (int) $row->hlc_l,
                hlcC: (int) $row->hlc_c,
                deviceId: (string) $row->device_id,
                opType: OpType::from((string) $row->op_type),
                signature: (string) $row->signature,
                userId: (int) $row->user_id,
            );
        })
        ->all();
}

function notifSyncMaxOpLogId(DatabaseManager $db): int
{
    $max = $db->connection()->table('op_log_entries')->max('id');

    return is_numeric($max) ? (int) $max : 0;
}

/** Insert a notifications row directly (bypassing any writer — simulates a row already known to both devices). */
function notifSyncSeedRow(DatabaseManager $db, int $userId, string $id): void
{
    $db->connection()->table('notifications')->insert([
        'id' => $id,
        'user_id' => $userId,
        'state' => 'open',
        'read_at' => null,
        'dismissed_at' => null,
        'title' => 'Payment due',
        'body' => 'Your ICS bill is due soon.',
        'params' => null,
        'trigger_type' => 'payment_reminder',
        'created_at' => CarbonImmutable::now()->toDateTimeString(),
        'updated_at' => CarbonImmutable::now()->toDateTimeString(),
    ]);
}

it('device A marks a notification read and device B replays to the same read_at (Req 11)', function (): void {
    /** @var DatabaseManager $db */
    $db = app(DatabaseManager::class);

    $notificationId = hash('sha256', 'notif-read-case-1-'.bin2hex(random_bytes(4)));
    notifSyncSeedRow($db, (int) $this->user->id, $notificationId);

    $pkA = notifSyncBindDeviceWriter((int) $this->user->id, 'device-a');
    $watermark = notifSyncMaxOpLogId($db);

    $readAt = CarbonImmutable::now()->toDateTimeString();
    $db->connection()->table('notifications')->where('id', $notificationId)->update(['read_at' => $readAt]);
    app(Dispatcher::class)->dispatch(new NotificationMutated(
        notificationId: $notificationId,
        userId: (int) $this->user->id,
        mutationType: 'edit',
        dirtyFields: ['read_at' => $readAt],
    ));

    $aOps = notifSyncOpsAfter($db, (int) $this->user->id, $notificationId, $watermark);
    expect($aOps)->not->toBeEmpty();

    // Simulate device B: it has NOT applied A's edit yet.
    $db->connection()->table('notifications')->where('id', $notificationId)->update(['read_at' => null]);
    expect($db->connection()->table('notifications')->where('id', $notificationId)->value('read_at'))->toBeNull();

    $replayer = new OpLogReplayer($db, ['device-a' => $pkA], new MergeRulesRegistry);
    $replayer->replay($aOps, (int) $this->user->id);

    $row = $db->connection()->table('notifications')->where('id', $notificationId)->first();
    expect($row)->not->toBeNull();
    expect($row->read_at)->not->toBeNull();
    expect((string) $row->read_at)->toBe($readAt);

    expect($db->connection()->table('notifications')->where('id', $notificationId)->count())->toBe(1);
    expect($db->connection()->table('op_log_quarantine')->where('user_id', $this->user->id)->count())->toBe(0);
});

it('two devices concurrently marking the SAME notification read converge deterministically regardless of replay order (Req 11)', function (): void {
    /** @var DatabaseManager $db */
    $db = app(DatabaseManager::class);

    $notificationId = hash('sha256', 'notif-read-case-2-'.bin2hex(random_bytes(4)));
    notifSyncSeedRow($db, (int) $this->user->id, $notificationId);

    // --- Device A (offline): marks read at t_a. ---
    $pkA = notifSyncBindDeviceWriter((int) $this->user->id, 'device-a');
    $watermarkA = notifSyncMaxOpLogId($db);
    $readAtA = '2026-07-17 09:05:00';
    $db->connection()->table('notifications')->where('id', $notificationId)->update(['read_at' => $readAtA]);
    app(Dispatcher::class)->dispatch(new NotificationMutated(
        notificationId: $notificationId,
        userId: (int) $this->user->id,
        mutationType: 'edit',
        dirtyFields: ['read_at' => $readAtA],
    ));
    $aOps = notifSyncOpsAfter($db, (int) $this->user->id, $notificationId, $watermarkA);
    expect($aOps)->not->toBeEmpty();

    // Restore to unread — device B is offline and has NOT seen A's edit.
    $db->connection()->table('notifications')->where('id', $notificationId)->update(['read_at' => null]);

    // --- Device B (offline, independently): marks read at a DIFFERENT t_b. ---
    $pkB = notifSyncBindDeviceWriter((int) $this->user->id, 'device-b');
    $watermarkB = notifSyncMaxOpLogId($db);
    $readAtB = '2026-07-17 09:07:30';
    $db->connection()->table('notifications')->where('id', $notificationId)->update(['read_at' => $readAtB]);
    app(Dispatcher::class)->dispatch(new NotificationMutated(
        notificationId: $notificationId,
        userId: (int) $this->user->id,
        mutationType: 'edit',
        dirtyFields: ['read_at' => $readAtB],
    ));
    $bOps = notifSyncOpsAfter($db, (int) $this->user->id, $notificationId, $watermarkB);
    expect($bOps)->not->toBeEmpty();

    $deviceKeys = ['device-a' => $pkA, 'device-b' => $pkB];

    // Replay in one order.
    $replayerForward = new OpLogReplayer($db, $deviceKeys, new MergeRulesRegistry);
    $replayerForward->replay([...$aOps, ...$bOps], (int) $this->user->id);
    $forwardResult = (string) $db->connection()->table('notifications')->where('id', $notificationId)->value('read_at');

    // Reset and replay in the REVERSE order — LWW must converge to the SAME value.
    $db->connection()->table('notifications')->where('id', $notificationId)->update(['read_at' => null]);
    $replayerReverse = new OpLogReplayer($db, $deviceKeys, new MergeRulesRegistry);
    $replayerReverse->replay([...$bOps, ...$aOps], (int) $this->user->id);
    $reverseResult = (string) $db->connection()->table('notifications')->where('id', $notificationId)->value('read_at');

    expect($reverseResult)->toBe($forwardResult);
    expect([$readAtA, $readAtB])->toContain($forwardResult);

    // Exactly one row — no duplicate/lost notification.
    expect($db->connection()->table('notifications')->where('id', $notificationId)->count())->toBe(1);

    // No quarantine — both devices' keys were known, signatures verified.
    expect($db->connection()->table('op_log_quarantine')->where('user_id', $this->user->id)->count())->toBe(0);
});

it('device B undoing a dismiss LATER converges to the reopened state under LWW (D-10)', function (): void {
    /** @var DatabaseManager $db */
    $db = app(DatabaseManager::class);

    $notificationId = hash('sha256', 'notif-dismiss-case-3-'.bin2hex(random_bytes(4)));
    notifSyncSeedRow($db, (int) $this->user->id, $notificationId);

    // --- Device A: dismisses at t1. ---
    $pkA = notifSyncBindDeviceWriter((int) $this->user->id, 'device-a');
    $watermarkA = notifSyncMaxOpLogId($db);
    $dismissedAt = CarbonImmutable::now()->toDateTimeString();
    $db->connection()->table('notifications')->where('id', $notificationId)->update(['dismissed_at' => $dismissedAt]);
    app(Dispatcher::class)->dispatch(new NotificationMutated(
        notificationId: $notificationId,
        userId: (int) $this->user->id,
        mutationType: 'edit',
        dirtyFields: ['dismissed_at' => $dismissedAt],
    ));
    $aOps = notifSyncOpsAfter($db, (int) $this->user->id, $notificationId, $watermarkA);
    expect($aOps)->not->toBeEmpty();

    // Force the wall-clock ms bucket to advance so device B's HLC tick is
    // deterministically LATER than device A's (HybridLogicalClock::tick()
    // reads raw microtime(), not the frozen CarbonImmutable test clock).
    usleep(5000);

    // --- Device B (later): undoes the dismiss. ---
    $pkB = notifSyncBindDeviceWriter((int) $this->user->id, 'device-b');
    $watermarkB = notifSyncMaxOpLogId($db);
    $db->connection()->table('notifications')->where('id', $notificationId)->update(['dismissed_at' => null]);
    app(Dispatcher::class)->dispatch(new NotificationMutated(
        notificationId: $notificationId,
        userId: (int) $this->user->id,
        mutationType: 'edit',
        dirtyFields: ['dismissed_at' => null],
    ));
    $bOps = notifSyncOpsAfter($db, (int) $this->user->id, $notificationId, $watermarkB);
    expect($bOps)->not->toBeEmpty();

    // Restore to the dismissed state, then replay BOTH devices' ops together
    // (in either submission order — the assertion is on the LATER HLC winning,
    // not on array order).
    $db->connection()->table('notifications')->where('id', $notificationId)->update(['dismissed_at' => $dismissedAt]);
    $deviceKeys = ['device-a' => $pkA, 'device-b' => $pkB];
    $replayer = new OpLogReplayer($db, $deviceKeys, new MergeRulesRegistry);
    $replayer->replay([...$bOps, ...$aOps], (int) $this->user->id);

    $row = $db->connection()->table('notifications')->where('id', $notificationId)->first();
    expect($row)->not->toBeNull();
    expect($row->dismissed_at)->toBeNull();

    expect($db->connection()->table('notifications')->where('id', $notificationId)->count())->toBe(1);
    expect($db->connection()->table('op_log_quarantine')->where('user_id', $this->user->id)->count())->toBe(0);
});
