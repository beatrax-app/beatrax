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
 * CreateRowUserIdOverwriteTest — SECURITY finding proof.
 *
 * OpLogReplayer's CREATE_ROW path seeds $payload = ['user_id' => $userId]
 * (the scope argument passed to replay()) but then lets the per-field
 * assembly loop overwrite it with whatever value the op-log carries for a
 * 'user_id' field. Several tables (envelope_assignments, envelope_settings)
 * legitimately list 'user_id' in their _create_required set, so CREATE_ROW
 * ops for those tables DO carry a 'user_id' field entry.
 *
 * The attack: a malicious device belonging to user A crafts a CREATE_ROW
 * op set with entry->userId = A (satisfies the I1 cross_user gate, since
 * replay() is invoked as replay($entries, A->id)) but
 * dirtyFields['user_id'] = B. insertOrIgnore has no WHERE clause, so I2's
 * "WHERE user_id = $userId on every write" invariant does not protect
 * CREATE — the assembled row is inserted with user_id = B, planting data
 * inside user B's namespace from a replay call scoped to user A.
 *
 * These tests prove: (1) a mismatched user_id field never results in a row
 * being created under the other user's id, and (2) a matching (or absent)
 * user_id field still creates the row correctly under $userId.
 */

function cruoUser(string $username): User
{
    return User::query()->create([
        'username' => $username,
        'password' => 'fixture',
        'period_start_day' => 1,
        'default_currency_view' => 'eur_only',
    ]);
}

beforeEach(function (): void {
    CarbonImmutable::setTestNow('2026-07-05 10:00:00');

    $this->userA = cruoUser('cruo-user-a');
    $this->userB = cruoUser('cruo-user-b');

    /** @var DatabaseManager $db */
    $db = app(DatabaseManager::class);
    $this->db = $db;

    $this->categoryA = $db->connection()->table('categories')->insertGetId([
        'user_id' => $this->userA->id,
        'name' => 'Cruo category A',
        'slug' => 'cruo-category-a',
        'kind' => 'expense',
        'created_at' => '2026-07-01 00:00:00',
        'updated_at' => '2026-07-01 00:00:00',
    ]);

    $keypair = sodium_crypto_sign_keypair();
    $this->sk = sodium_crypto_sign_secretkey($keypair);
    $this->pkHex = bin2hex(sodium_crypto_sign_publickey($keypair));
    $this->signer = new DeviceKeySigner;
    $this->deviceKeys = ['cruo-device' => $this->pkHex];
});

afterEach(function (): void {
    CarbonImmutable::setTestNow();
});

/**
 * Builds a signed CREATE_ROW field entry for envelope_settings.
 */
function cruoCreateEntry(object $test, string $field, string $value, int|string $pk, int $entryUserId): OpLogEntry
{
    $stub = new OpLogEntry(
        table: 'envelope_settings',
        pk: $pk,
        field: $field,
        value: $value,
        hlcL: 1000,
        hlcC: 0,
        deviceId: 'cruo-device',
        opType: OpType::CreateRow,
        signature: '',
        userId: $entryUserId,
    );
    $sig = $test->signer->sign($stub->signingPayload(), $test->sk);

    return new OpLogEntry(
        table: 'envelope_settings',
        pk: $pk,
        field: $field,
        value: $value,
        hlcL: 1000,
        hlcC: 0,
        deviceId: 'cruo-device',
        opType: OpType::CreateRow,
        signature: $sig,
        userId: $entryUserId,
    );
}

it('rejects a CreateRow op whose supplied user_id field disagrees with the replay scope — no row is planted in the other user\'s namespace', function (): void {
    $db = $this->db;
    $userAId = (int) $this->userA->id;
    $userBId = (int) $this->userB->id;

    // Malicious device of user A: entry->userId = A (passes I1), but the
    // dirtyFields['user_id'] payload claims user B.
    $entries = [
        cruoCreateEntry($this, 'user_id', (string) $userBId, 'hostile-envelope-1', $userAId),
        cruoCreateEntry($this, 'category_id', (string) $this->categoryA, 'hostile-envelope-1', $userAId),
        cruoCreateEntry($this, 'overspend_mode', json_encode('reduce_to_budget', JSON_THROW_ON_ERROR), 'hostile-envelope-1', $userAId),
    ];

    $replayer = new OpLogReplayer($db, $this->deviceKeys);
    $replayer->replay($entries, $userAId);

    // ASSERTION: no envelope_settings row exists under user B's id.
    $plantedInB = $db->connection()
        ->table('envelope_settings')
        ->where('user_id', $userBId)
        ->count();
    expect($plantedInB)->toBe(0);

    // ASSERTION: no envelope_settings row was created at all — the whole
    // CREATE_ROW set is rejected rather than silently reassigned, since the
    // supplied identity cannot be trusted once it disagrees with scope.
    $totalRows = $db->connection()->table('envelope_settings')->count();
    expect($totalRows)->toBe(0);

    // ASSERTION: quarantined using the same reason as the I1 gate.
    $quarantined = $db->connection()
        ->table('op_log_quarantine')
        ->where('user_id', $userAId)
        ->where('table_name', 'envelope_settings')
        ->where('reason', 'cross_user')
        ->count();
    expect($quarantined)->toBeGreaterThanOrEqual(1);
});

it('creates the row correctly under the replay scope when the user_id field matches (regression — legitimate creates still work)', function (): void {
    $db = $this->db;
    $userAId = (int) $this->userA->id;

    $entries = [
        cruoCreateEntry($this, 'user_id', (string) $userAId, 'legit-envelope-1', $userAId),
        cruoCreateEntry($this, 'category_id', (string) $this->categoryA, 'legit-envelope-1', $userAId),
        cruoCreateEntry($this, 'overspend_mode', json_encode('reduce_to_budget', JSON_THROW_ON_ERROR), 'legit-envelope-1', $userAId),
    ];

    $replayer = new OpLogReplayer($db, $this->deviceKeys);
    $replayer->replay($entries, $userAId);

    $row = $db->connection()
        ->table('envelope_settings')
        ->where('category_id', $this->categoryA)
        ->first();

    expect($row)->not->toBeNull();
    expect((int) $row->user_id)->toBe($userAId);
    expect($row->overspend_mode)->toBe('reduce_to_budget');
});
