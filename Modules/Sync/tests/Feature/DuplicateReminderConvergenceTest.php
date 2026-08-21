<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Database\DatabaseManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Core\Models\User;
use Modules\Notifications\Internal\Support\DeterministicKeyDeriver;
use Modules\Sync\Internal\Config\MergeRulesRegistry;
use Modules\Sync\Internal\Merge\OpLogReplayer;
use Modules\Sync\Internal\OpLog\OpLogEntry;
use Modules\Sync\Internal\OpLog\OpLogWriter;
use Modules\Sync\Internal\OpLog\OpType;
use Modules\Sync\Public\Events\NotificationMutated;

uses(RefreshDatabase::class);

// Nothing here dedups. Two devices generating the same reminder converge
// because each derives the same sha256 pk from the same inputs and the create
// path is insertOrIgnore, so the collision alone drops the second row. The
// negative control proves the key is the occurrence, not the series.

beforeEach(function (): void {
    CarbonImmutable::setTestNow('2026-07-17 09:00:00');

    $this->user = User::create([
        'username' => 'dup-reminder-'.bin2hex(random_bytes(4)),
        'password' => 'fixture',
        'period_start_day' => 1,
    ]);
});

afterEach(function (): void {
    CarbonImmutable::setTestNow();
});

function dupReminderBindDeviceWriter(int $userId, string $deviceId): string
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
function dupReminderOpsAfter(DatabaseManager $db, int $userId, string $notificationId, int $afterId): array
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

function dupReminderMaxOpLogId(DatabaseManager $db): int
{
    $max = $db->connection()->table('op_log_entries')->max('id');

    return is_numeric($max) ? (int) $max : 0;
}

/**
 * @return list<OpLogEntry>
 */
function dupReminderGenerateOnDevice(
    DatabaseManager $db,
    int $userId,
    string $deviceId,
    string $notificationId,
    string $triggerType,
): array {
    $pk = dupReminderBindDeviceWriter($userId, $deviceId);
    $watermark = dupReminderMaxOpLogId($db);

    $fields = [
        'user_id' => $userId,
        'title' => 'Payment due',
        'body' => 'Your ICS bill is due soon.',
        'trigger_type' => $triggerType,
    ];

    $db->connection()->table('notifications')->insert([
        'id' => $notificationId,
        'user_id' => $userId,
        'state' => 'open',
        'title' => $fields['title'],
        'body' => $fields['body'],
        'trigger_type' => $fields['trigger_type'],
        'created_at' => CarbonImmutable::now()->toDateTimeString(),
        'updated_at' => CarbonImmutable::now()->toDateTimeString(),
    ]);

    app(Dispatcher::class)->dispatch(new NotificationMutated(
        notificationId: $notificationId,
        userId: $userId,
        mutationType: 'create',
        dirtyFields: $fields,
    ));

    $ops = dupReminderOpsAfter($db, $userId, $notificationId, $watermark);

    // The local row goes, so the assertions can only be satisfied by what
    // replay reconstructs from the captured ops.
    $db->connection()->table('notifications')->where('id', $notificationId)->delete();

    return [$pk, $ops];
}

it('two independent devices generating the same reminder converge to exactly one notification row (Req 12)', function (): void {
    /** @var DatabaseManager $db */
    $db = app(DatabaseManager::class);

    $deriver = new DeterministicKeyDeriver;
    $seriesKey = 'ics-bill-series-'.bin2hex(random_bytes(4));
    $dueDate = '2026-08-01';
    $trigger = DeterministicKeyDeriver::TRIGGER_PAYMENT_REMINDER;

    // Both devices derive the pk from an identical tuple, and that shared
    // identity is the entire convergence mechanism.
    $idA = $deriver->derive((int) $this->user->id, $trigger, $seriesKey, $dueDate);
    $idB = $deriver->derive((int) $this->user->id, $trigger, $seriesKey, $dueDate);
    expect($idA)->toBe($idB);

    [$pkA, $aOps] = dupReminderGenerateOnDevice($db, (int) $this->user->id, 'device-a', $idA, $trigger);
    [$pkB, $bOps] = dupReminderGenerateOnDevice($db, (int) $this->user->id, 'device-b', $idB, $trigger);

    expect($aOps)->not->toBeEmpty();
    expect($bOps)->not->toBeEmpty();

    expect($db->connection()->table('notifications')->where('id', $idA)->count())->toBe(0);

    $deviceKeys = ['device-a' => $pkA, 'device-b' => $pkB];
    $replayer = new OpLogReplayer($db, $deviceKeys, new MergeRulesRegistry);
    $replayer->replay([...$aOps, ...$bOps], (int) $this->user->id);

    expect($db->connection()->table('notifications')->where('id', $idA)->count())->toBe(1);

    expect($db->connection()->table('notifications')->where('user_id', $this->user->id)->count())->toBe(1);

    expect($db->connection()->table('op_log_quarantine')->where('user_id', $this->user->id)->count())->toBe(0);
});

it('two different due dates for the same series produce two distinct notification rows (D-06)', function (): void {
    /** @var DatabaseManager $db */
    $db = app(DatabaseManager::class);

    $deriver = new DeterministicKeyDeriver;
    $seriesKey = 'ics-bill-series-'.bin2hex(random_bytes(4));
    $trigger = DeterministicKeyDeriver::TRIGGER_PAYMENT_REMINDER;

    $idAugust = $deriver->derive((int) $this->user->id, $trigger, $seriesKey, '2026-08-01');
    $idSeptember = $deriver->derive((int) $this->user->id, $trigger, $seriesKey, '2026-09-01');
    expect($idAugust)->not->toBe($idSeptember);

    [$pkA, $augustOps] = dupReminderGenerateOnDevice($db, (int) $this->user->id, 'device-a', $idAugust, $trigger);
    [$pkB, $septemberOps] = dupReminderGenerateOnDevice($db, (int) $this->user->id, 'device-b', $idSeptember, $trigger);

    $deviceKeys = ['device-a' => $pkA, 'device-b' => $pkB];
    $replayer = new OpLogReplayer($db, $deviceKeys, new MergeRulesRegistry);
    $replayer->replay([...$augustOps, ...$septemberOps], (int) $this->user->id);

    expect($db->connection()->table('notifications')->where('id', $idAugust)->count())->toBe(1);
    expect($db->connection()->table('notifications')->where('id', $idSeptember)->count())->toBe(1);

    expect($db->connection()->table('notifications')->where('user_id', $this->user->id)->count())->toBe(2);

    expect($db->connection()->table('op_log_quarantine')->where('user_id', $this->user->id)->count())->toBe(0);
});
