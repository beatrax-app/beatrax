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

/*
 * D-06: Delete vs edit (tombstones) conflict scenario.
 *
 * Tests delete-wins (tombstone HLC 1001 > edit HLC 1000) and the reverse
 * (edit HLC 1000 > tombstone HLC 999, so edit-wins / add-wins).
 *
 * Both devices share the same throwaway Ed25519 keypair for signing;
 * the replayer's $deviceKeys map points both device-ids to the same
 * public key.
 *
 * RED: The OpLogReplayer skeleton (Wave 1) does not yet apply tombstone
 * logic, so both assertions fail until Wave 2 implements the merge.
 */

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
 * Seeds category + categorization_rule and returns [ruleId, categoryId].
 *
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

    // Redesigned (2026_07_06) parent-rule shape: the flat field/match/value/
    // category_id columns moved to rule_conditions/rule_actions; combinator is
    // enum-guarded by a SQLite trigger. These tests only exercise `notes` SET
    // ops + tombstones against the row id, so only the row shape matters here.
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
    // Device A edits notes at HLC [1000, 0]; device B tombstones at HLC [1001, 0].
    // delete-wins: tombstone HLC 1001 > edit HLC 1000.

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

    // RED: delete-wins — row must not exist after replay.
    $exists = app(DatabaseManager::class)
        ->connection()
        ->table('categorization_rules')
        ->where('id', $this->ruleIdA)
        ->exists();

    expect($exists)->toBeFalse();

    // Assert: production path persists ops to op_log_entries (SYNC-01).
    // RED: fails until Plan 11-02 wires op_log_entries writes.
    $logCount = app(DatabaseManager::class)
        ->connection()
        ->table('op_log_entries')
        ->where('user_id', $this->userA->id)
        ->count();
    expect($logCount)->toBeGreaterThanOrEqual(2);
});

it('edit (HLC 1000) wins over tombstone (HLC 999) — row survives with edited notes', function (): void {
    // Device A edits notes at HLC [1000, 0]; device B tombstones at HLC [999, 0].
    // edit-wins: edit HLC 1000 > tombstone HLC 999.

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

    // RED: add-wins in reverse — row must still exist with notes='edited'.
    $row = app(DatabaseManager::class)
        ->connection()
        ->table('categorization_rules')
        ->where('id', $this->ruleIdB)
        ->first();

    expect($row)->not->toBeNull();
    expect($row->notes)->toBe('edited');

    // Assert: production path persists ops to op_log_entries (SYNC-01).
    // RED: fails until Plan 11-02 wires op_log_entries writes.
    $logCount = app(DatabaseManager::class)
        ->connection()
        ->table('op_log_entries')
        ->where('user_id', $this->userB->id)
        ->count();
    expect($logCount)->toBeGreaterThanOrEqual(2);
});
