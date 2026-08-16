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

it('ignores a CreateRow op\'s supplied user_id and scopes the row to the replaying user — nothing is planted in the other user\'s namespace', function (): void {
    $db = $this->db;
    $userAId = (int) $this->userA->id;
    $userBId = (int) $this->userB->id;

    // Malicious device of user A: the entry is replayed under A's scope, but
    // the dirtyFields['user_id'] payload claims user B.
    $entries = [
        cruoCreateEntry($this, 'user_id', (string) $userBId, 7401, $userAId),
        cruoCreateEntry($this, 'category_id', (string) $this->categoryA, 7401, $userAId),
        cruoCreateEntry($this, 'overspend_mode', json_encode('reduce_to_budget', JSON_THROW_ON_ERROR), 7401, $userAId),
    ];

    $replayer = new OpLogReplayer($db, $this->deviceKeys);
    $replayer->replay($entries, $userAId);

    // THE invariant: insertOrIgnore carries no WHERE clause, so the forced
    // scope overwrite is the only thing standing between a wire-supplied
    // user_id and a row planted in someone else's namespace.
    $plantedInB = $db->connection()
        ->table('envelope_settings')
        ->where('user_id', $userBId)
        ->count();
    expect($plantedInB)->toBe(0);

    // The row is not rejected, it is re-scoped. A confirmed device of user A
    // sending A's own data is the ordinary case — user_id on the wire is the
    // sender's local autoincrement and carries no authority here, so it is
    // overwritten rather than compared.
    $row = $db->connection()
        ->table('envelope_settings')
        ->where('user_id', $userAId)
        ->first();

    expect($row)->not->toBeNull()
        ->and((int) $row->user_id)->toBe($userAId);
});

it('creates the row correctly under the replay scope when the user_id field matches (regression — legitimate creates still work)', function (): void {
    $db = $this->db;
    $userAId = (int) $this->userA->id;

    $entries = [
        cruoCreateEntry($this, 'user_id', (string) $userAId, 910001, $userAId),
        cruoCreateEntry($this, 'category_id', (string) $this->categoryA, 910001, $userAId),
        cruoCreateEntry($this, 'overspend_mode', json_encode('reduce_to_budget', JSON_THROW_ON_ERROR), 910001, $userAId),
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
