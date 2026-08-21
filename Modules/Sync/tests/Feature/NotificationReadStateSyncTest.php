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

// Read state and dismissal both converge on the later write, and either
// timestamp is a correct answer for "when was this read". Dismissal is
// reversible, so the last write has to win there too rather than a dismiss
// being treated as terminal.

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

// Bound into the container so the capture listener's lazy resolution picks it
// up, and returns the public key the replayer needs to verify what it signs.
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

// Seeded directly, so both devices start from an identical row.
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

it('device A marks a notification read and device B replays to the same read_at', function (): void {
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

    // Device B has not applied A's edit yet.
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

it('two devices concurrently marking the SAME notification read converge deterministically regardless of replay order', function (): void {
    /** @var DatabaseManager $db */
    $db = app(DatabaseManager::class);

    $notificationId = hash('sha256', 'notif-read-case-2-'.bin2hex(random_bytes(4)));
    notifSyncSeedRow($db, (int) $this->user->id, $notificationId);

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

    // Device B never saw A's edit, so the live row goes back to unread.
    $db->connection()->table('notifications')->where('id', $notificationId)->update(['read_at' => null]);

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

    $replayerForward = new OpLogReplayer($db, $deviceKeys, new MergeRulesRegistry);
    $replayerForward->replay([...$aOps, ...$bOps], (int) $this->user->id);
    $forwardResult = (string) $db->connection()->table('notifications')->where('id', $notificationId)->value('read_at');

    // The reverse order must land on the same value.
    $db->connection()->table('notifications')->where('id', $notificationId)->update(['read_at' => null]);
    $replayerReverse = new OpLogReplayer($db, $deviceKeys, new MergeRulesRegistry);
    $replayerReverse->replay([...$bOps, ...$aOps], (int) $this->user->id);
    $reverseResult = (string) $db->connection()->table('notifications')->where('id', $notificationId)->value('read_at');

    expect($reverseResult)->toBe($forwardResult);
    expect([$readAtA, $readAtB])->toContain($forwardResult);

    expect($db->connection()->table('notifications')->where('id', $notificationId)->count())->toBe(1);

    expect($db->connection()->table('op_log_quarantine')->where('user_id', $this->user->id)->count())->toBe(0);
});

it('device B undoing a dismiss LATER converges to the reopened state under LWW', function (): void {
    /** @var DatabaseManager $db */
    $db = app(DatabaseManager::class);

    $notificationId = hash('sha256', 'notif-dismiss-case-3-'.bin2hex(random_bytes(4)));
    notifSyncSeedRow($db, (int) $this->user->id, $notificationId);

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

    // The clock reads raw microtime rather than the frozen test clock, so the
    // millisecond bucket has to actually advance for B's tick to sort later.
    usleep(5000);

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

    // Replayed together in either submission order: the assertion is on the
    // later HLC winning, not on the order of the array.
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
