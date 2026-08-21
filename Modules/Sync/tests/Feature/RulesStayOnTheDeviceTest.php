<?php

declare(strict_types=1);

use Illuminate\Database\DatabaseManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Core\Models\User;
use Modules\Sync\Internal\OpLog\OpLogBackfiller;
use Modules\Sync\Internal\OpLog\OpLogWriter;

uses(RefreshDatabase::class);

// Rules are authored per device and stay there. Merge rules kept from an earlier
// forward-prepared state put them in the pairing snapshot, so a freshly paired
// phone arrived holding the desktop's rules and then diverged from them silently,
// because nothing ever dispatched an update. Either they sync, or they stay put.

function rulesLocalUser(): User
{
    return User::query()->create([
        'username' => 'rules-local-'.bin2hex(random_bytes(4)),
        'password' => 'fixture',
        'period_start_day' => 1,
        'default_currency_view' => 'eur_only',
    ]);
}

function rulesLocalWriter(int $userId): OpLogWriter
{
    $keypair = sodium_crypto_sign_keypair();

    return app(OpLogWriter::class, [
        'deviceId' => 'rules-local-device',
        'userId' => $userId,
        'secretKey' => sodium_crypto_sign_secretkey($keypair),
        'publicKey' => sodium_crypto_sign_publickey($keypair),
    ]);
}

function seedRule(DatabaseManager $db, int $userId): int
{
    $ruleId = (int) $db->connection()->table('categorization_rules')->insertGetId([
        'user_id' => $userId,
        'priority' => 10,
        'combinator' => 'all',
        'active' => 1,
        'hits_count' => 0,
        'created_at' => '2026-08-19 12:00:00',
        'updated_at' => '2026-08-19 12:00:00',
    ]);

    $db->connection()->table('rule_conditions')->insert([
        'rule_id' => $ruleId,
        'field' => 'description',
        'op' => 'contains',
        'value_type' => 'string',
        'value' => 'Albert Heijn',
        'created_at' => '2026-08-19 12:00:00',
        'updated_at' => '2026-08-19 12:00:00',
    ]);

    $db->connection()->table('rule_actions')->insert([
        'rule_id' => $ruleId,
        'position' => 1,
        'type' => 'category',
        'payload' => json_encode(['category_id' => 1]),
        'created_at' => '2026-08-19 12:00:00',
        'updated_at' => '2026-08-19 12:00:00',
    ]);

    return $ruleId;
}

it('never puts a rule into the pairing snapshot', function (): void {
    /** @var DatabaseManager $db */
    $db = app(DatabaseManager::class);
    $user = rulesLocalUser();
    seedRule($db, (int) $user->id);

    app(OpLogBackfiller::class)->backfill((int) $user->id, rulesLocalWriter((int) $user->id));

    $ruleOps = $db->connection()->table('op_log_entries')
        ->where('user_id', $user->id)
        ->whereIn('table_name', ['categorization_rules', 'rule_conditions', 'rule_actions'])
        ->count();

    expect($ruleOps)->toBe(0, 'a rule reached the wire — the rules screen tells the user it will not');
});

it('still backfills everything else, so the exclusion is not a blanket skip', function (): void {
    /** @var DatabaseManager $db */
    $db = app(DatabaseManager::class);
    $user = rulesLocalUser();
    seedRule($db, (int) $user->id);

    $db->connection()->table('categories')->insert([
        'user_id' => $user->id,
        'name' => 'Boodschappen',
        'slug' => 'rules-local-'.bin2hex(random_bytes(3)),
        'kind' => 'expense',
        'display_order' => 1,
        'created_at' => '2026-08-19 12:00:00',
        'updated_at' => '2026-08-19 12:00:00',
    ]);

    $captured = app(OpLogBackfiller::class)->backfill((int) $user->id, rulesLocalWriter((int) $user->id));

    expect($captured)->toBeGreaterThan(0)
        ->and($db->connection()->table('op_log_entries')->where('table_name', 'categories')->count())
        ->toBeGreaterThan(0);
});
