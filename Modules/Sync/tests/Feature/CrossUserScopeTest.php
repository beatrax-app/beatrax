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
 * CrossUserScopeTest — STRIDE T-10-02 access-control proof.
 *
 * Proves that OpLogReplayer::replay() NEVER applies an op-log entry to a
 * row belonging to a different user, even when a hostile entry is explicitly
 * constructed with another user's userId.
 *
 * Two security invariants are exercised:
 *
 * I1 — User-id filter before apply: entries whose userId !== $userId argument
 *      are filtered out before signature verification. u2's data is never
 *      touched by a replay($entries, $u1->id) call.
 *
 * I2 — WHERE user_id = ? on every DB write: even if the filter were bypassed,
 *      every UPDATE includes WHERE user_id = $userId, so a cross-user write
 *      would touch 0 rows (not u2's row).
 *
 * The hostile case: an OpLogEntry with userId=u2->id is fed into
 * replay($entries, $u1->id). Neither invariant allows it to mutate u2's row.
 *
 * This is the executable proof for STRIDE T-10-02 (Elevation of Privilege).
 */

function scopeUser(string $username): User
{
    return User::query()->create([
        'username' => $username,
        'password' => 'fixture',
        'period_start_day' => 1,
        'default_currency_view' => 'eur_only',
    ]);
}

/**
 * Seeds account → import_run → category → transaction for a user.
 * Returns [transactionId, categoryA_id, categoryB_id].
 *
 * @return array{0: int, 1: int, 2: int}
 */
function scopeTxn(DatabaseManager $db, int $userId, string $suffix): array
{
    $accountId = $db->connection()->table('accounts')->insertGetId([
        'user_id' => $userId,
        'name' => 'ASN scope test',
        'slug' => 'scope-asn-'.$suffix,
        'kind' => 'bank',
        'iban' => 'NL00ASNB'.strtoupper($suffix),
        'default_currency' => 'EUR',
        'created_at' => '2026-06-01 00:00:00',
        'updated_at' => '2026-06-01 00:00:00',
    ]);

    $runId = $db->connection()->table('import_runs')->insertGetId([
        'user_id' => $userId,
        'source_format' => 'asn-csv',
        'raw_file_path' => '/tmp/scope-'.$suffix.'.csv',
        'sha256' => hash('sha256', 'scope-run-'.$suffix),
        'uploaded_at' => '2026-06-01 00:00:00',
        'status' => 'previewed',
        'created_at' => '2026-06-01 00:00:00',
        'updated_at' => '2026-06-01 00:00:00',
    ]);

    $catA = $db->connection()->table('categories')->insertGetId([
        'user_id' => $userId,
        'name' => 'ScopeCatA '.$suffix,
        'slug' => 'scope-cat-a-'.$suffix,
        'kind' => 'expense',
        'created_at' => '2026-06-01 00:00:00',
        'updated_at' => '2026-06-01 00:00:00',
    ]);

    $catB = $db->connection()->table('categories')->insertGetId([
        'user_id' => $userId,
        'name' => 'ScopeCatB '.$suffix,
        'slug' => 'scope-cat-b-'.$suffix,
        'kind' => 'expense',
        'created_at' => '2026-06-01 00:00:00',
        'updated_at' => '2026-06-01 00:00:00',
    ]);

    $txnId = $db->connection()->table('transactions')->insertGetId([
        'user_id' => $userId,
        'account_id' => $accountId,
        'import_run_id' => $runId,
        'fingerprint' => hash('sha256', 'scope-'.bin2hex(random_bytes(8))),
        'posted_at' => '2026-06-01',
        'booked_at' => '2026-06-01 10:00:00',
        'value_date' => '2026-06-01',
        'amount_minor' => -4999,
        'currency' => 'EUR',
        'settled_amount_minor' => -4999,
        'settled_currency' => 'EUR',
        'counterparty_normalized' => 'albert heijn',
        'counterparty_name' => 'ALBERT HEIJN',
        'normalization_version' => 3,
        'description' => 'cross-user scope fixture',
        'type' => 'expense',  // REQUIRED — transactions_type_check_insert trigger
        'source_format' => 'asn-csv',
        'source_row_index' => 1,
        'fingerprint_version' => 3,
        'created_at' => '2026-06-01 00:00:00',
        'updated_at' => '2026-06-01 00:00:00',
    ]);

    return [$txnId, $catA, $catB];
}

beforeEach(function (): void {
    CarbonImmutable::setTestNow('2026-06-14 10:00:00');

    $this->u1 = scopeUser('scope-u1');
    $this->u2 = scopeUser('scope-u2');

    /** @var DatabaseManager $db */
    $db = app(DatabaseManager::class);

    [$this->txn1, $this->cat1A, $this->cat1B] = scopeTxn($db, (int) $this->u1->id, 'u1');
    [$this->txn2, $this->cat2A, $this->cat2B] = scopeTxn($db, (int) $this->u2->id, 'u2');

    // Shared throwaway keypair for signing.
    $keypair = sodium_crypto_sign_keypair();
    $this->sk = sodium_crypto_sign_secretkey($keypair);
    $this->pkHex = bin2hex(sodium_crypto_sign_publickey($keypair));
    $this->signer = new DeviceKeySigner;
    $this->deviceKeys = ['device-scope' => $this->pkHex];
});

afterEach(function (): void {
    CarbonImmutable::setTestNow();
});

it('replay($entries, u1->id) updates u1 row and leaves u2 row byte-for-byte unchanged (T-10-02)', function (): void {
    /** @var DatabaseManager $db */
    $db = app(DatabaseManager::class);

    // Capture u2's row before replay.
    $u2RowBefore = $db->connection()
        ->table('transactions')
        ->where('id', $this->txn2)
        ->where('user_id', $this->u2->id)
        ->first();
    expect($u2RowBefore)->not->toBeNull();

    // Build a valid u1 op that edits u1's transaction.
    $stub = new OpLogEntry(
        table: 'transactions',
        pk: $this->txn1,
        field: 'category_id',
        value: (string) $this->cat1B,
        hlcL: 1000,
        hlcC: 0,
        deviceId: 'device-scope',
        opType: OpType::Set,
        signature: '',
        userId: (int) $this->u1->id,
    );
    $sig = $this->signer->sign($stub->signingPayload(), $this->sk);
    $u1Entry = new OpLogEntry(
        table: 'transactions',
        pk: $this->txn1,
        field: 'category_id',
        value: (string) $this->cat1B,
        hlcL: 1000,
        hlcC: 0,
        deviceId: 'device-scope',
        opType: OpType::Set,
        signature: $sig,
        userId: (int) $this->u1->id,
    );

    $replayer = new OpLogReplayer($db, $this->deviceKeys);
    $replayer->replay([$u1Entry], (int) $this->u1->id);

    // ASSERTION 1: u1's row is updated as expected.
    $u1CatId = $db->connection()
        ->table('transactions')
        ->where('id', $this->txn1)
        ->where('user_id', $this->u1->id)
        ->value('category_id');
    expect((int) $u1CatId)->toBe($this->cat1B);

    // ASSERTION 2: u2's row is byte-for-byte unchanged after u1's replay.
    $u2RowAfter = $db->connection()
        ->table('transactions')
        ->where('id', $this->txn2)
        ->where('user_id', $this->u2->id)
        ->first();
    expect($u2RowAfter)->not->toBeNull();

    // Compare every column — nothing changed.
    expect((array) $u2RowAfter)->toBe((array) $u2RowBefore);

    // Assert: production replayer persists u1's op to op_log_entries (SYNC-01).
    // RED: fails until Plan 11-02 wires op_log_entries writes in production replayer.
    $logCount = $db->connection()
        ->table('op_log_entries')
        ->where('user_id', $this->u1->id)
        ->count();
    expect($logCount)->toBeGreaterThanOrEqual(1);
});

it('hostile cross-user entry (userId=u2 in replay($entries, u1->id)) does NOT mutate u2 row (T-10-02 defense-in-depth)', function (): void {
    /** @var DatabaseManager $db */
    $db = app(DatabaseManager::class);

    // Capture u2's category_id before hostile replay.
    $u2CatIdBefore = $db->connection()
        ->table('transactions')
        ->where('id', $this->txn2)
        ->where('user_id', $this->u2->id)
        ->value('category_id');

    // Build a HOSTILE entry: userId=u2, but we will replay it with $userId=u1.
    // This simulates a maliciously or accidentally mislabeled entry fed into u1's replay.
    $stub = new OpLogEntry(
        table: 'transactions',
        pk: $this->txn2,          // u2's row id
        field: 'category_id',
        value: (string) $this->cat2B,  // u2's catB — the "attack" value
        hlcL: 9999,               // highest possible HLC — would win LWW if applied
        hlcC: 0,
        deviceId: 'device-scope',
        opType: OpType::Set,
        signature: '',
        userId: (int) $this->u2->id,  // claims to be u2's entry
    );
    $sig = $this->signer->sign($stub->signingPayload(), $this->sk);

    // HOSTILE entry: userId=u2 but about to be replayed under u1's scope.
    $hostileEntry = new OpLogEntry(
        table: 'transactions',
        pk: $this->txn2,          // u2's row id
        field: 'category_id',
        value: (string) $this->cat2B,
        hlcL: 9999,
        hlcC: 0,
        deviceId: 'device-scope',
        opType: OpType::Set,
        signature: $sig,
        userId: (int) $this->u2->id,  // u2's userId — hostile cross-user entry
    );

    $replayer = new OpLogReplayer($db, $this->deviceKeys);

    // Replay with u1's scope. The hostile entry has userId=u2 !== u1.
    // Invariant I1: userId filter drops the entry before any DB write.
    $replayer->replay([$hostileEntry], (int) $this->u1->id);

    // ASSERTION: u2's category_id is UNCHANGED — hostile entry was dropped.
    $u2CatIdAfter = $db->connection()
        ->table('transactions')
        ->where('id', $this->txn2)
        ->where('user_id', $this->u2->id)
        ->value('category_id');

    expect($u2CatIdAfter)->toBe($u2CatIdBefore);

    // WR-08: the hostile cross-user entry must NEVER become a durable
    // op_log_entries row — rejected entries go to quarantine ONLY, so a full
    // rebuild or log export can never resurrect attacker-controlled content.
    $persistedHostile = $db->connection()
        ->table('op_log_entries')
        ->where('table_name', 'transactions')
        ->where('pk', (string) $this->txn2)
        ->where('hlc_l', 9999)
        ->count();
    expect($persistedHostile)->toBe(0);

    // It IS recorded in quarantine as cross_user.
    $quarantined = $db->connection()
        ->table('op_log_quarantine')
        ->where('reason', 'cross_user')
        ->where('pk', (string) $this->txn2)
        ->count();
    expect($quarantined)->toBeGreaterThanOrEqual(1);
});

it('cross-user scope holds even when entry references u2 pk but uses u1 userId in entry (WHERE user_id guard — T-10-02 I2)', function (): void {
    /** @var DatabaseManager $db */
    $db = app(DatabaseManager::class);

    // Capture u2's original category_id.
    $u2CatIdBefore = $db->connection()
        ->table('transactions')
        ->where('id', $this->txn2)
        ->value('category_id');

    // Build an entry that claims userId=u1 but references u2's transaction id (txn2).
    // This entry passes the userId filter (userId=u1) but the DB write includes
    // WHERE user_id = u1->id, so no row is matched (txn2 belongs to u2, not u1).
    $stub = new OpLogEntry(
        table: 'transactions',
        pk: $this->txn2,          // u2's row id (the pk under attack)
        field: 'category_id',
        value: (string) $this->cat1B, // u1's cat
        hlcL: 9999,
        hlcC: 0,
        deviceId: 'device-scope',
        opType: OpType::Set,
        signature: '',
        userId: (int) $this->u1->id, // claims u1 scope — passes the userId filter
    );
    $sig = $this->signer->sign($stub->signingPayload(), $this->sk);

    $craftedEntry = new OpLogEntry(
        table: 'transactions',
        pk: $this->txn2,          // u2's row id
        field: 'category_id',
        value: (string) $this->cat1B,
        hlcL: 9999,
        hlcC: 0,
        deviceId: 'device-scope',
        opType: OpType::Set,
        signature: $sig,
        userId: (int) $this->u1->id, // passes userId filter
    );

    $replayer = new OpLogReplayer($db, $this->deviceKeys);
    // Entry passes userId filter (u1) but the UPDATE WHERE user_id=u1 does not match txn2 (owned by u2).
    $replayer->replay([$craftedEntry], (int) $this->u1->id);

    // ASSERTION: u2's category_id is UNCHANGED — WHERE user_id guard blocked the write.
    $u2CatIdAfter = $db->connection()
        ->table('transactions')
        ->where('id', $this->txn2)
        ->value('category_id');

    expect($u2CatIdAfter)->toBe($u2CatIdBefore);
});
