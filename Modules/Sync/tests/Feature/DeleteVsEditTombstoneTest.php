<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Database\DatabaseManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Core\Models\User;
use Modules\Sync\Internal\Merge\OpLogReplayer;
use Modules\Sync\Internal\OpLog\OpLogEntry;
use Modules\Sync\Internal\OpLog\OpType;
use Modules\Sync\Internal\Signing\DeviceKeySigner;

uses(RefreshDatabase::class);

// A tombstone does not win because it is a delete; it wins when its HLC is
// higher. Both directions are covered, because a delete-always-wins rule would
// pass the first case and silently lose an edit that genuinely came after.

function tombUser(string $username): User
{
    return User::query()->create([
        'username' => $username,
        'password' => 'fixture',
        'period_start_day' => 1,
        'default_currency_view' => 'eur_only',
    ]);
}

/**
 * @return array{0: int, 1: int}
 */
function tombRule(DatabaseManager $db, int $userId, string $suffix): array
{
    $categoryId = $db->connection()->table('categories')->insertGetId([
        'user_id' => $userId,
        'name' => 'Tomb category '.$suffix,
        'slug' => 'tomb-cat-'.$suffix,
        'kind' => 'expense',
        'created_at' => '2026-06-01 00:00:00',
        'updated_at' => '2026-06-01 00:00:00',
    ]);

    // Only the parent row's shape matters here: these tests drive notes SETs
    // and tombstones against its id, never the conditions or actions that the
    // flat columns moved out into.
    $ruleId = $db->connection()->table('categorization_rules')->insertGetId([
        'user_id' => $userId,
        'priority' => 0,
        'combinator' => 'all',
        'hits_count' => 0,
        'active' => true,
        'notes' => 'original',
        'created_at' => '2026-06-01 00:00:00',
        'updated_at' => '2026-06-01 00:00:00',
    ]);

    return [$ruleId, $categoryId];
}

beforeEach(function (): void {
    CarbonImmutable::setTestNow('2026-06-14 10:00:00');
    $this->userA = tombUser('sync-tomb-a');
    $this->userB = tombUser('sync-tomb-b');

    /** @var DatabaseManager $db */
    $db = app(DatabaseManager::class);

    [$this->ruleIdA, $this->catIdA] = tombRule($db, (int) $this->userA->id, 'a');
    [$this->ruleIdB, $this->catIdB] = tombRule($db, (int) $this->userB->id, 'b');

    $keypair = sodium_crypto_sign_keypair();
    $this->sk = sodium_crypto_sign_secretkey($keypair);
    $this->pk = sodium_crypto_sign_publickey($keypair);
    $this->signer = new DeviceKeySigner;
    $this->pkHex = bin2hex($this->pk);
});

afterEach(function (): void {
    CarbonImmutable::setTestNow();
});

it('tombstone (HLC 1001) wins over concurrent edit (HLC 1000) — row is absent after replay', function (): void {
    // The tombstone carries the higher HLC, so it wins.

    $editEntry = new OpLogEntry(
        table: 'categorization_rules',
        pk: $this->ruleIdA,
        field: 'notes',
        value: json_encode('edited', JSON_THROW_ON_ERROR),
        hlcL: 1000,
        hlcC: 0,
        deviceId: 'device-a',
        opType: OpType::Set,
        signature: '',
        userId: (int) $this->userA->id,
    );

    $tombEntry = new OpLogEntry(
        table: 'categorization_rules',
        pk: $this->ruleIdA,
        field: '__tombstone__',
        value: null,
        hlcL: 1001,
        hlcC: 0,
        deviceId: 'device-b',
        opType: OpType::DeleteTombstone,
        signature: '',
        userId: (int) $this->userA->id,
    );

    $sigEdit = $this->signer->sign($editEntry->signingPayload(), $this->sk);
    $sigTomb = $this->signer->sign($tombEntry->signingPayload(), $this->sk);

    $editEntry = new OpLogEntry(
        table: 'categorization_rules',
        pk: $this->ruleIdA,
        field: 'notes',
        value: json_encode('edited', JSON_THROW_ON_ERROR),
        hlcL: 1000,
        hlcC: 0,
        deviceId: 'device-a',
        opType: OpType::Set,
        signature: $sigEdit,
        userId: (int) $this->userA->id,
    );

    $tombEntry = new OpLogEntry(
        table: 'categorization_rules',
        pk: $this->ruleIdA,
        field: '__tombstone__',
        value: null,
        hlcL: 1001,
        hlcC: 0,
        deviceId: 'device-b',
        opType: OpType::DeleteTombstone,
        signature: $sigTomb,
        userId: (int) $this->userA->id,
    );

    $replayer = new OpLogReplayer(
        app(DatabaseManager::class),
        ['device-a' => $this->pkHex, 'device-b' => $this->pkHex],
    );

    $replayer->replay([$editEntry, $tombEntry], (int) $this->userA->id);

    $exists = app(DatabaseManager::class)
        ->connection()
        ->table('categorization_rules')
        ->where('id', $this->ruleIdA)
        ->exists();

    expect($exists)->toBeFalse();

    $logCount = app(DatabaseManager::class)
        ->connection()
        ->table('op_log_entries')
        ->where('user_id', $this->userA->id)
        ->count();
    expect($logCount)->toBeGreaterThanOrEqual(2);
});

it('edit (HLC 1000) wins over tombstone (HLC 999) — row survives with edited notes', function (): void {
    // Reversed: the edit carries the higher HLC, so the row survives.

    $editEntry = new OpLogEntry(
        table: 'categorization_rules',
        pk: $this->ruleIdB,
        field: 'notes',
        value: json_encode('edited', JSON_THROW_ON_ERROR),
        hlcL: 1000,
        hlcC: 0,
        deviceId: 'device-a',
        opType: OpType::Set,
        signature: '',
        userId: (int) $this->userB->id,
    );

    $tombEntry = new OpLogEntry(
        table: 'categorization_rules',
        pk: $this->ruleIdB,
        field: '__tombstone__',
        value: null,
        hlcL: 999,
        hlcC: 0,
        deviceId: 'device-b',
        opType: OpType::DeleteTombstone,
        signature: '',
        userId: (int) $this->userB->id,
    );

    $sigEdit = $this->signer->sign($editEntry->signingPayload(), $this->sk);
    $sigTomb = $this->signer->sign($tombEntry->signingPayload(), $this->sk);

    $editEntry = new OpLogEntry(
        table: 'categorization_rules',
        pk: $this->ruleIdB,
        field: 'notes',
        value: json_encode('edited', JSON_THROW_ON_ERROR),
        hlcL: 1000,
        hlcC: 0,
        deviceId: 'device-a',
        opType: OpType::Set,
        signature: $sigEdit,
        userId: (int) $this->userB->id,
    );

    $tombEntry = new OpLogEntry(
        table: 'categorization_rules',
        pk: $this->ruleIdB,
        field: '__tombstone__',
        value: null,
        hlcL: 999,
        hlcC: 0,
        deviceId: 'device-b',
        opType: OpType::DeleteTombstone,
        signature: $sigTomb,
        userId: (int) $this->userB->id,
    );

    $replayer = new OpLogReplayer(
        app(DatabaseManager::class),
        ['device-a' => $this->pkHex, 'device-b' => $this->pkHex],
    );

    $replayer->replay([$editEntry, $tombEntry], (int) $this->userB->id);

    $row = app(DatabaseManager::class)
        ->connection()
        ->table('categorization_rules')
        ->where('id', $this->ruleIdB)
        ->first();

    expect($row)->not->toBeNull();
    expect($row->notes)->toBe('edited');

    $logCount = app(DatabaseManager::class)
        ->connection()
        ->table('op_log_entries')
        ->where('user_id', $this->userB->id)
        ->count();
    expect($logCount)->toBeGreaterThanOrEqual(2);
});
