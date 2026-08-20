<?php

declare(strict_types=1);

use Illuminate\Database\DatabaseManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Sync\Internal\Config\MergeRulesRegistry;
use Modules\Sync\Internal\Merge\OpLogReplayer;
use Modules\Sync\Internal\OpLog\OpLogBackfiller;
use Modules\Sync\Internal\OpLog\OpLogEntry;
use Modules\Sync\Internal\OpLog\OpLogWriter;
use Modules\Sync\Internal\OpLog\OpType;

uses(RefreshDatabase::class);

/*
 * The whole point of the op log: what one device captured must reconstruct
 * on another, byte for byte, with relationships intact. Replaying onto an
 * emptied database is that same journey minus the socket — and it is what
 * catches identity bugs, rows arriving under fresh autoincrement ids that
 * strand every reference to them. Rules are device-local and never captured,
 * so the pair here is a merchant and the memory that points at it.
 */

it('reconstructs a captured parent and its children with ids and links intact', function (): void {
    /** @var DatabaseManager $db */
    $db = app(DatabaseManager::class);
    $connection = $db->connection();

    $userId = (int) $connection->table('users')->insertGetId([
        'username' => 'roundtrip-'.bin2hex(random_bytes(4)),
        'password' => 'fixture',
        'period_start_day' => 1,
        'default_currency_view' => 'eur_only',
    ]);

    $categoryId = (int) $connection->table('categories')->insertGetId([
        'user_id' => $userId,
        'name' => 'Abonnementen',
        'slug' => 'roundtrip-'.bin2hex(random_bytes(3)),
        'kind' => 'expense',
        'display_order' => 1,
        'created_at' => '2026-06-14 00:00:00',
        'updated_at' => '2026-06-14 00:00:00',
    ]);

    $merchantId = (int) $connection->table('merchants')->insertGetId([
        'user_id' => $userId,
        'name' => 'Netflix',
        'normalized_name' => 'netflix',
        'created_at' => '2026-06-14 00:00:00',
        'updated_at' => '2026-06-14 00:00:00',
    ]);

    $connection->table('merchant_memories')->insert([
        'user_id' => $userId,
        'merchant_id' => $merchantId,
        'category_id' => $categoryId,
        'occurrence_count' => 7,
        'last_seen_at' => '2026-06-14 00:00:00',
        'created_at' => '2026-06-14 00:00:00',
        'updated_at' => '2026-06-14 00:00:00',
    ]);

    $memoryId = (int) $connection->table('merchant_memories')->where('merchant_id', $merchantId)->value('id');

    $keypair = sodium_crypto_sign_keypair();
    $publicKey = sodium_crypto_sign_publickey($keypair);

    /** @var OpLogWriter $writer */
    $writer = app(OpLogWriter::class, [
        'deviceId' => 'device-source',
        'userId' => $userId,
        'secretKey' => sodium_crypto_sign_secretkey($keypair),
        'publicKey' => $publicKey,
    ]);

    /** @var OpLogBackfiller $backfiller */
    $backfiller = app(OpLogBackfiller::class);
    expect($backfiller->backfill($userId, $writer))->toBeGreaterThan(0);

    // Stand in for the receiving device: the same signed history, applied to a
    // database that no longer holds the rows.
    $connection->table('merchant_memories')->where('user_id', $userId)->delete();
    $connection->table('merchants')->where('user_id', $userId)->delete();

    $replayer = new OpLogReplayer(
        db: $db,
        deviceKeys: ['device-source' => bin2hex($publicKey)],
        rules: new MergeRulesRegistry,
    );

    $rows = $connection->table('op_log_entries')->where('user_id', $userId)->orderBy('hlc_l')->orderBy('hlc_c')->get();

    $replayed = [];
    foreach ($rows as $row) {
        $replayed[] = new OpLogEntry(
            table: (string) $row->table_name,
            pk: is_numeric($row->pk) ? (int) $row->pk : (string) $row->pk,
            field: (string) $row->field,
            value: $row->value === null ? null : (string) $row->value,
            hlcL: (int) $row->hlc_l,
            hlcC: (int) $row->hlc_c,
            deviceId: (string) $row->device_id,
            opType: OpType::from((string) $row->op_type),
            signature: (string) $row->signature,
            userId: $userId,
            gdkEpoch: $row->gdk_epoch === null ? null : (int) $row->gdk_epoch,
        );
    }

    $replayer->replay($replayed, $userId);

    $rebuiltMerchant = $connection->table('merchants')->where('id', $merchantId)->first();
    $rebuiltMemory = $connection->table('merchant_memories')->where('id', $memoryId)->first();

    expect($rebuiltMerchant)->not->toBeNull()
        ->and($rebuiltMerchant->name)->toBe('Netflix')
        ->and($rebuiltMemory)->not->toBeNull()
        ->and((int) $rebuiltMemory->merchant_id)->toBe($merchantId)
        ->and((int) $rebuiltMemory->category_id)->toBe($categoryId);
});

it('rebuilds a notification, whose id travels as the op pk rather than a field', function (): void {
    /** @var DatabaseManager $db */
    $db = app(DatabaseManager::class);
    $connection = $db->connection();

    $userId = (int) $connection->table('users')->insertGetId([
        'username' => 'notify-'.bin2hex(random_bytes(4)),
        'password' => 'fixture',
        'period_start_day' => 1,
        'default_currency_view' => 'eur_only',
    ]);

    // A sha256 string PK, not an autoincrement, which is why the rules name
    // `id` as required. The backfill never emits `id` as a field — the row's
    // identity travels as the op's pk — so demanding it discarded every
    // notification on arrival as incomplete_create_row: 19 of 19, on a real
    // phone, with nothing surfacing the loss.
    $notificationId = hash('sha256', 'notify-'.bin2hex(random_bytes(8)));

    $connection->table('notifications')->insert([
        'id' => $notificationId,
        'user_id' => $userId,
        'state' => 'open',
        'title' => 'Budget nearly spent',
        'body' => 'Groceries is at 90% with nine days to go.',
        'trigger_type' => 'budget_threshold',
        'created_at' => '2026-06-14 00:00:00',
        'updated_at' => '2026-06-14 00:00:00',
    ]);

    $keypair = sodium_crypto_sign_keypair();
    $publicKey = sodium_crypto_sign_publickey($keypair);

    /** @var OpLogWriter $writer */
    $writer = app(OpLogWriter::class, [
        'deviceId' => 'device-source',
        'userId' => $userId,
        'secretKey' => sodium_crypto_sign_secretkey($keypair),
        'publicKey' => $publicKey,
    ]);

    /** @var OpLogBackfiller $backfiller */
    $backfiller = app(OpLogBackfiller::class);
    $backfiller->backfill($userId, $writer);

    $connection->table('notifications')->where('user_id', $userId)->delete();

    $replayer = new OpLogReplayer(
        db: $db,
        deviceKeys: ['device-source' => bin2hex($publicKey)],
        rules: new MergeRulesRegistry,
    );

    $rows = $connection->table('op_log_entries')
        ->where('user_id', $userId)
        ->where('table_name', 'notifications')
        ->orderBy('hlc_l')
        ->orderBy('hlc_c')
        ->get();

    expect($rows)->not->toBeEmpty('the backfill captured no notification at all');

    $replayed = [];
    foreach ($rows as $row) {
        $replayed[] = new OpLogEntry(
            table: (string) $row->table_name,
            pk: is_numeric($row->pk) ? (int) $row->pk : (string) $row->pk,
            field: (string) $row->field,
            value: $row->value === null ? null : (string) $row->value,
            hlcL: (int) $row->hlc_l,
            hlcC: (int) $row->hlc_c,
            deviceId: (string) $row->device_id,
            opType: OpType::from((string) $row->op_type),
            signature: (string) $row->signature,
            userId: $userId,
            gdkEpoch: $row->gdk_epoch === null ? null : (int) $row->gdk_epoch,
        );
    }

    $replayer->replay($replayed, $userId);

    $rebuilt = $connection->table('notifications')->where('id', $notificationId)->first();

    expect($rebuilt)->not->toBeNull('the notification was discarded on arrival');
    expect($rebuilt->title)->toBe('Budget nearly spent');
    expect((int) $rebuilt->user_id)->toBe($userId);

    $quarantined = $connection->table('op_log_quarantine')
        ->where('user_id', $userId)
        ->where('table_name', 'notifications')
        ->count();

    expect($quarantined)->toBe(0, 'the notification was quarantined rather than applied');
});
