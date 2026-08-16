<?php

declare(strict_types=1);

use Illuminate\Database\DatabaseManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Sync\Internal\Config\MergeRulesRegistry;
use Modules\Sync\Internal\Merge\OpLogReplayer;
use Modules\Sync\Internal\OpLog\OpLogEntry;
use Modules\Sync\Internal\OpLog\OpType;
use Modules\Sync\Internal\Signing\DeviceKeySigner;

uses(RefreshDatabase::class);

/*
 * Covered tables are not all user-scoped: rule_conditions and rule_actions
 * hang off categorization_rules and have no user_id column of their own. The
 * create path seeded one regardless, so every catch-up carrying one died with
 * "table rule_conditions has no column named user_id" and the whole exchange
 * aborted — taking the rest of the peer's history with it.
 */

function unscopedSignedEntry(
    DeviceKeySigner $signer,
    string $secretKey,
    int $userId,
    string $table,
    int|string $pk,
    string $field,
    ?string $value,
    int $hlcL,
): OpLogEntry {
    $make = static fn (string $signature): OpLogEntry => new OpLogEntry(
        table: $table,
        pk: $pk,
        field: $field,
        value: $value,
        hlcL: $hlcL,
        hlcC: 0,
        deviceId: 'device-unscoped',
        opType: OpType::CreateRow,
        signature: $signature,
        userId: $userId,
    );

    return $make($signer->sign($make('')->signingPayload(), $secretKey));
}

it('applies a create for a table that has no user_id column', function (): void {
    /** @var DatabaseManager $db */
    $db = app(DatabaseManager::class);

    $userId = (int) $db->connection()->table('users')->insertGetId([
        'username' => 'unscoped-'.bin2hex(random_bytes(4)),
        'password' => 'fixture',
        'period_start_day' => 1,
        'default_currency_view' => 'eur_only',
    ]);

    $ruleId = (int) $db->connection()->table('categorization_rules')->insertGetId([
        'user_id' => $userId,
        'priority' => 1,
        'active' => true,
        'combinator' => 'all',
        'created_at' => '2026-06-14 00:00:00',
        'updated_at' => '2026-06-14 00:00:00',
    ]);

    /** @var DeviceKeySigner $signer */
    $signer = app(DeviceKeySigner::class);
    $keypair = sodium_crypto_sign_keypair();
    $secretKey = sodium_crypto_sign_secretkey($keypair);
    $publicKey = sodium_crypto_sign_publickey($keypair);

    $replayer = new OpLogReplayer(
        db: $db,
        deviceKeys: ['device-unscoped' => bin2hex($publicKey)],
        rules: new MergeRulesRegistry,
    );

    $fields = [
        'rule_id' => (string) $ruleId,
        'field' => json_encode('counterparty', JSON_THROW_ON_ERROR),
        'op' => json_encode('contains', JSON_THROW_ON_ERROR),
        'value_type' => json_encode('string', JSON_THROW_ON_ERROR),
        'value' => json_encode('Netflix', JSON_THROW_ON_ERROR),
    ];

    $entries = [];
    $hlc = 2000;

    foreach ($fields as $field => $value) {
        $entries[] = unscopedSignedEntry($signer, $secretKey, $userId, 'rule_conditions', 4242, $field, $value, $hlc++);
    }

    $replayer->replay($entries, $userId);

    $quarantined = $db->connection()->table('op_log_quarantine')->get()->pluck('reason')->all();
    $persisted = $db->connection()->table('op_log_entries')->count();
    $quarantined[] = 'persisted='.$persisted;

    // Created under the OP'S pk, not a fresh autoincrement: a child row on
    // another device references the parent by that id, and inventing a new one
    // breaks the relationship the moment it is replayed.
    $row = $db->connection()->table('rule_conditions')->where('id', 4242)->first();

    expect($row)->not->toBeNull(json_encode($quarantined))
        ->and((int) $row->rule_id)->toBe($ruleId)
        ->and($row->field)->toBe('counterparty');
});

it('refuses a child row whose parent belongs to another user', function (): void {
    /** @var DatabaseManager $db */
    $db = app(DatabaseManager::class);

    $mine = (int) $db->connection()->table('users')->insertGetId([
        'username' => 'mine-'.bin2hex(random_bytes(4)),
        'password' => 'fixture',
        'period_start_day' => 1,
        'default_currency_view' => 'eur_only',
    ]);

    $theirs = (int) $db->connection()->table('users')->insertGetId([
        'username' => 'theirs-'.bin2hex(random_bytes(4)),
        'password' => 'fixture',
        'period_start_day' => 1,
        'default_currency_view' => 'eur_only',
    ]);

    $theirRuleId = (int) $db->connection()->table('categorization_rules')->insertGetId([
        'user_id' => $theirs,
        'priority' => 1,
        'active' => true,
        'combinator' => 'all',
        'created_at' => '2026-06-14 00:00:00',
        'updated_at' => '2026-06-14 00:00:00',
    ]);

    /** @var DeviceKeySigner $signer */
    $signer = app(DeviceKeySigner::class);
    $keypair = sodium_crypto_sign_keypair();
    $secretKey = sodium_crypto_sign_secretkey($keypair);
    $publicKey = sodium_crypto_sign_publickey($keypair);

    $replayer = new OpLogReplayer(
        db: $db,
        deviceKeys: ['device-unscoped' => bin2hex($publicKey)],
        rules: new MergeRulesRegistry,
    );

    $fields = [
        'rule_id' => (string) $theirRuleId,
        'field' => json_encode('counterparty', JSON_THROW_ON_ERROR),
        'op' => json_encode('contains', JSON_THROW_ON_ERROR),
        'value_type' => json_encode('string', JSON_THROW_ON_ERROR),
        'value' => json_encode('Netflix', JSON_THROW_ON_ERROR),
    ];

    $entries = [];
    $hlc = 5000;

    foreach ($fields as $field => $value) {
        $entries[] = unscopedSignedEntry($signer, $secretKey, $mine, 'rule_conditions', 7777, $field, $value, $hlc++);
    }

    $replayer->replay($entries, $mine);

    expect($db->connection()->table('rule_conditions')->where('id', 7777)->exists())->toBeFalse();
});
