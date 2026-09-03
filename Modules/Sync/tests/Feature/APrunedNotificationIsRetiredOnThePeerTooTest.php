<?php

declare(strict_types=1);

use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Database\DatabaseManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Modules\Core\Models\User;
use Modules\Core\Public\Contracts\Clock;
use Modules\Notifications\Internal\Jobs\PruneNotificationsJob;
use Modules\Sync\Internal\Config\MergeRulesRegistry;
use Modules\Sync\Internal\Merge\OpLogReplayer;
use Modules\Sync\Internal\OpLog\OpLogEntry;
use Modules\Sync\Internal\OpLog\OpLogWriter;
use Modules\Sync\Internal\OpLog\OpType;

uses(RefreshDatabase::class);

// The retention sweep runs on both devices, so it looks self-correcting. It is
// not: the registry gives notifications `_delete_wins`, which only settles a
// tombstone against a create, and without one the peer's own history of the row
// is the last word on whether a retired notification comes back.

function retiredNotificationUser(): User
{
    return User::query()->create([
        'username' => 'retired-notification-'.bin2hex(random_bytes(4)),
        'password' => 'fixture-password-12chars',
        'period_start_day' => 1,
    ]);
}

function bindRetiredNotificationWriter(int $userId): string
{
    $keypair = sodium_crypto_sign_keypair();
    $publicKey = sodium_crypto_sign_publickey($keypair);

    app()->instance(OpLogWriter::class, app(OpLogWriter::class, [
        'deviceId' => 'retired-notification-device',
        'userId' => $userId,
        'secretKey' => sodium_crypto_sign_secretkey($keypair),
        'publicKey' => $publicKey,
    ]));

    return bin2hex($publicKey);
}

function retiredNotificationRow(int $userId, int $daysAgo): string
{
    $id = hash('sha256', $userId.'-'.$daysAgo.'-'.bin2hex(random_bytes(8)));
    $createdAt = now()->subDays($daysAgo)->toDateTimeString();

    app(DatabaseManager::class)->connection()->table('notifications')->insert([
        'id' => $id,
        'user_id' => $userId,
        'state' => 'open',
        'read_at' => null,
        'dismissed_at' => null,
        'title' => 'A payment is due',
        'body' => 'Your rent leaves the account tomorrow.',
        'params' => null,
        'trigger_type' => 'payment_reminder',
        'created_at' => $createdAt,
        'updated_at' => $createdAt,
    ]);

    return $id;
}

function runRetentionSweep(int $userId): void
{
    (new PruneNotificationsJob($userId))->handle(
        app(DatabaseManager::class),
        app(Clock::class),
        events: app(Dispatcher::class),
    );
}

/** @return Collection<int, stdClass> */
function retiredNotificationOps(int $userId)
{
    return app(DatabaseManager::class)->connection()->table('op_log_entries')
        ->where('user_id', $userId)
        ->where('table_name', 'notifications')
        ->get();
}

it('announces the retirement of a notification the retention sweep deleted', function (): void {
    $user = retiredNotificationUser();
    bindRetiredNotificationWriter((int) $user->id);
    $id = retiredNotificationRow((int) $user->id, 400);

    runRetentionSweep((int) $user->id);

    $ops = retiredNotificationOps((int) $user->id);

    expect($ops->pluck('op_type')->all())->toBe([OpType::DeleteTombstone->value])
        ->and($ops->pluck('pk')->all())->toBe([$id]);
});

it('announces nothing for a notification still inside the retention window', function (): void {
    $user = retiredNotificationUser();
    bindRetiredNotificationWriter((int) $user->id);
    retiredNotificationRow((int) $user->id, 364);

    runRetentionSweep((int) $user->id);

    expect(retiredNotificationOps((int) $user->id))->toBeEmpty(
        'a row the sweep left alone has nothing to tell a peer',
    );
});

it('retires the row on a peer that has not run its own sweep yet', function (): void {
    $user = retiredNotificationUser();
    $publicKeyHex = bindRetiredNotificationWriter((int) $user->id);
    $id = retiredNotificationRow((int) $user->id, 400);

    runRetentionSweep((int) $user->id);

    $db = app(DatabaseManager::class);
    $connection = $db->connection();

    // Stand in for the receiving device: the same signed history against a
    // database that still holds the notification, which is the phone whose own
    // schedule has not fired since the desktop retired it.
    retiredNotificationRow((int) $user->id, 400);
    $connection->table('notifications')->where('user_id', $user->id)->update(['id' => $id]);

    $entries = [];
    foreach ($connection->table('op_log_entries')->where('user_id', $user->id)->orderBy('hlc_l')->orderBy('hlc_c')->get() as $row) {
        $entries[] = new OpLogEntry(
            table: (string) $row->table_name,
            pk: (string) $row->pk,
            field: (string) $row->field,
            value: $row->value === null ? null : (string) $row->value,
            hlcL: (int) $row->hlc_l,
            hlcC: (int) $row->hlc_c,
            deviceId: (string) $row->device_id,
            opType: OpType::from((string) $row->op_type),
            signature: (string) $row->signature,
            userId: (int) $user->id,
            gdkEpoch: $row->gdk_epoch === null ? null : (int) $row->gdk_epoch,
        );
    }

    (new OpLogReplayer(
        db: $db,
        deviceKeys: ['retired-notification-device' => $publicKeyHex],
        rules: new MergeRulesRegistry,
    ))->replay($entries, (int) $user->id);

    expect($connection->table('notifications')->where('id', $id)->exists())->toBeFalse(
        'a notification one device retired for retention must not go on living on the other',
    );
});
