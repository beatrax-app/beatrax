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
 * catches identity bugs (rows arriving under fresh autoincrement ids) and
 * scope bugs (user_id seeded into tables that have no such column).
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

    $ruleId = (int) $connection->table('categorization_rules')->insertGetId([
        'user_id' => $userId,
        'priority' => 7,
        'active' => true,
        'combinator' => 'all',
        'created_at' => '2026-06-14 00:00:00',
        'updated_at' => '2026-06-14 00:00:00',
    ]);

    $connection->table('rule_conditions')->insert([
        'rule_id' => $ruleId,
        'field' => 'counterparty',
        'op' => 'contains',
        'value_type' => 'string',
        'value' => 'Netflix',
        'created_at' => '2026-06-14 00:00:00',
        'updated_at' => '2026-06-14 00:00:00',
    ]);

    $conditionId = (int) $connection->table('rule_conditions')->where('rule_id', $ruleId)->value('id');

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
    $connection->table('rule_conditions')->delete();
    $connection->table('categorization_rules')->where('user_id', $userId)->delete();

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

    $rebuiltRule = $connection->table('categorization_rules')->where('id', $ruleId)->first();
    $rebuiltCondition = $connection->table('rule_conditions')->where('id', $conditionId)->first();

    expect($rebuiltRule)->not->toBeNull()
        ->and((int) $rebuiltRule->priority)->toBe(7)
        ->and($rebuiltCondition)->not->toBeNull()
        ->and((int) $rebuiltCondition->rule_id)->toBe($ruleId)
        ->and($rebuiltCondition->value)->toBe('Netflix');
});
