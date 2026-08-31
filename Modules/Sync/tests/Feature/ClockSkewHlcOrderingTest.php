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

// Three ops whose HLCs deliberately disagree with the order they arrive in,
// one of them from a slow clock and one with a high counter but a lower
// physical timestamp. Arrival order within one batch is the easy axis; the
// axis that actually broke is the BATCH BOUNDARY, so the same three are also
// replayed one call at a time, which is what a 1024-op frame cap produces.

function skewUser(string $username): User
{
    return User::query()->create([
        'username' => $username,
        'password' => 'fixture',
        'period_start_day' => 1,
        'default_currency_view' => 'eur_only',
    ]);
}

/**
 * @return array{0: int, 1: int, 2: int, 3: int}
 */
function skewTxn(DatabaseManager $db, int $userId, string $suffix): array
{
    $accountId = $db->connection()->table('accounts')->insertGetId([
        'user_id' => $userId,
        'name' => 'ASN skew test',
        'slug' => 'sync-skew-asn-'.$suffix,
        'kind' => 'bank',
        'iban' => 'NL00ASNB'.strtoupper($suffix),
        'default_currency' => 'EUR',
        'created_at' => '2026-06-01 00:00:00',
        'updated_at' => '2026-06-01 00:00:00',
    ]);

    $runId = $db->connection()->table('import_runs')->insertGetId([
        'user_id' => $userId,
        'source_format' => 'asn-csv',
        'raw_file_path' => '/tmp/sync-skew-'.$suffix.'.csv',
        'sha256' => hash('sha256', 'sync-skew-run-'.$suffix),
        'uploaded_at' => '2026-06-01 00:00:00',
        'status' => 'previewed',
        'created_at' => '2026-06-01 00:00:00',
        'updated_at' => '2026-06-01 00:00:00',
    ]);

    $catA = $db->connection()->table('categories')->insertGetId([
        'user_id' => $userId,
        'name' => 'SkewCatA '.$suffix,
        'slug' => 'skew-cat-a-'.$suffix,
        'kind' => 'expense',
        'created_at' => '2026-06-01 00:00:00',
        'updated_at' => '2026-06-01 00:00:00',
    ]);

    $catB = $db->connection()->table('categories')->insertGetId([
        'user_id' => $userId,
        'name' => 'SkewCatB '.$suffix,
        'slug' => 'skew-cat-b-'.$suffix,
        'kind' => 'expense',
        'created_at' => '2026-06-01 00:00:00',
        'updated_at' => '2026-06-01 00:00:00',
    ]);

    $catC = $db->connection()->table('categories')->insertGetId([
        'user_id' => $userId,
        'name' => 'SkewCatC '.$suffix,
        'slug' => 'skew-cat-c-'.$suffix,
        'kind' => 'expense',
        'created_at' => '2026-06-01 00:00:00',
        'updated_at' => '2026-06-01 00:00:00',
    ]);

    $txnId = $db->connection()->table('transactions')->insertGetId([
        'user_id' => $userId,
        'account_id' => $accountId,
        'import_run_id' => $runId,
        'fingerprint' => hash('sha256', 'sync-skew-'.bin2hex(random_bytes(8))),
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
        'description' => 'sync skew fixture',
        'type' => 'expense',  // REQUIRED — transactions_type_check_insert trigger
        'source_format' => 'asn-csv',
        'source_row_index' => 1,
        'fingerprint_version' => 3,
        'created_at' => '2026-06-01 00:00:00',
        'updated_at' => '2026-06-01 00:00:00',
    ]);

    return [$txnId, $catA, $catB, $catC];
}

beforeEach(function (): void {
    CarbonImmutable::setTestNow('2026-06-14 10:00:00');
    $this->user = skewUser('sync-skew-u');

    /** @var DatabaseManager $db */
    $db = app(DatabaseManager::class);
    [$this->txnId, $this->catA, $this->catB, $this->catC] = skewTxn($db, (int) $this->user->id, 'u');

    $keypair = sodium_crypto_sign_keypair();
    $this->sk = sodium_crypto_sign_secretkey($keypair);
    $this->pkHex = bin2hex(sodium_crypto_sign_publickey($keypair));
    $this->signer = new DeviceKeySigner;
    $this->deviceKeys = [
        'device-a' => $this->pkHex,
        'device-b' => $this->pkHex,
        'device-c' => $this->pkHex,
    ];
});

afterEach(function (): void {
    CarbonImmutable::setTestNow();
});

/**
 * @return list<OpLogEntry>
 */
function buildSkewedOps(DeviceKeySigner $signer, string $sk, int $txnId, int $catA, int $catB, int $catC, int $userId): array
{
    $makeEntry = static function (int $catId, int $hlcL, int $hlcC, string $deviceId) use ($signer, $sk, $txnId, $userId): OpLogEntry {
        // The signing payload comes from the entry itself, so it is built
        // unsigned first and reconstructed with the signature after.
        $stub = new OpLogEntry(
            table: 'transactions',
            pk: $txnId,
            field: 'category_id',
            value: (string) $catId,
            hlcL: $hlcL,
            hlcC: $hlcC,
            deviceId: $deviceId,
            opType: OpType::Set,
            signature: '',
            userId: $userId,
        );
        $sig = $signer->sign($stub->signingPayload(), $sk);

        return new OpLogEntry(
            table: 'transactions',
            pk: $txnId,
            field: 'category_id',
            value: (string) $catId,
            hlcL: $hlcL,
            hlcC: $hlcC,
            deviceId: $deviceId,
            opType: OpType::Set,
            signature: $sig,
            userId: $userId,
        );
    };

    return [
        $makeEntry($catA, 2000, 0, 'device-a'),  // highest hlc_l → winner
        $makeEntry($catB, 1500, 0, 'device-b'),  // lowest hlc_l → loser
        $makeEntry($catC, 1999, 5, 'device-c'),  // high counter but lower ms → loser
    ];
}

it('resolves to category_id=C_a (hlc_l=2000 wins) when ops arrive in forward order', function (): void {
    [$opA, $opB, $opC] = buildSkewedOps(
        $this->signer, $this->sk,
        $this->txnId, $this->catA, $this->catB, $this->catC,
        (int) $this->user->id,
    );

    $replayer = new OpLogReplayer(app(DatabaseManager::class), $this->deviceKeys);
    $replayer->replay([$opA, $opB, $opC], (int) $this->user->id);

    $catId = app(DatabaseManager::class)
        ->connection()
        ->table('transactions')
        ->where('id', $this->txnId)
        ->value('category_id');

    expect((int) $catId)->toBe($this->catA);

    $logCount = app(DatabaseManager::class)
        ->connection()
        ->table('op_log_entries')
        ->where('user_id', $this->user->id)
        ->count();
    expect($logCount)->toBeGreaterThanOrEqual(3);
});

it('resolves to category_id=C_a (hlc_l=2000 wins) when ops arrive in reverse order', function (): void {
    [$opA, $opB, $opC] = buildSkewedOps(
        $this->signer, $this->sk,
        $this->txnId, $this->catA, $this->catB, $this->catC,
        (int) $this->user->id,
    );

    $replayer = new OpLogReplayer(app(DatabaseManager::class), $this->deviceKeys);
    $replayer->replay([$opC, $opB, $opA], (int) $this->user->id);

    $catId = app(DatabaseManager::class)
        ->connection()
        ->table('transactions')
        ->where('id', $this->txnId)
        ->value('category_id');

    expect((int) $catId)->toBe($this->catA);

    $logCount = app(DatabaseManager::class)
        ->connection()
        ->table('op_log_entries')
        ->where('user_id', $this->user->id)
        ->count();
    expect($logCount)->toBeGreaterThanOrEqual(3);
});

it('resolves to category_id=C_a (hlc_l=2000 wins) when ops arrive in shuffled order', function (): void {
    [$opA, $opB, $opC] = buildSkewedOps(
        $this->signer, $this->sk,
        $this->txnId, $this->catA, $this->catB, $this->catC,
        (int) $this->user->id,
    );

    $replayer = new OpLogReplayer(app(DatabaseManager::class), $this->deviceKeys);
    $replayer->replay([$opB, $opA, $opC], (int) $this->user->id);

    $catId = app(DatabaseManager::class)
        ->connection()
        ->table('transactions')
        ->where('id', $this->txnId)
        ->value('category_id');

    expect((int) $catId)->toBe($this->catA);

    $logCount = app(DatabaseManager::class)
        ->connection()
        ->table('op_log_entries')
        ->where('user_id', $this->user->id)
        ->count();
    expect($logCount)->toBeGreaterThanOrEqual(3);
});

// One replay() per op — the shape receiveOps() produces, since it is called
// once per frame. Each batch then holds a single candidate for the field, so a
// merge that resolves over the batch alone lets whichever op arrived LAST win
// no matter what its HLC says.
/**
 * @param  list<int>  $order  Indices into [opA, opB, opC].
 * @param  array<string, string>  $deviceKeys  device-id => hex Ed25519 public key.
 */
function replaySkewedOpsOneAtATime(array $order, DeviceKeySigner $signer, string $sk, int $txnId, int $catA, int $catB, int $catC, int $userId, array $deviceKeys): void
{
    $ops = buildSkewedOps($signer, $sk, $txnId, $catA, $catB, $catC, $userId);
    $replayer = new OpLogReplayer(app(DatabaseManager::class), $deviceKeys);

    foreach ($order as $index) {
        $replayer->replay([$ops[$index]], $userId);
    }
}

it('resolves to category_id=C_a when each op arrives in its own frame, oldest HLC last', function (): void {
    replaySkewedOpsOneAtATime(
        [0, 2, 1],
        $this->signer, $this->sk,
        $this->txnId, $this->catA, $this->catB, $this->catC,
        (int) $this->user->id, $this->deviceKeys,
    );

    $catId = app(DatabaseManager::class)
        ->connection()
        ->table('transactions')
        ->where('id', $this->txnId)
        ->value('category_id');

    expect((int) $catId)->toBe($this->catA);
});

it('resolves to category_id=C_a when each op arrives in its own frame, winner first', function (): void {
    replaySkewedOpsOneAtATime(
        [0, 1, 2],
        $this->signer, $this->sk,
        $this->txnId, $this->catA, $this->catB, $this->catC,
        (int) $this->user->id, $this->deviceKeys,
    );

    $catId = app(DatabaseManager::class)
        ->connection()
        ->table('transactions')
        ->where('id', $this->txnId)
        ->value('category_id');

    expect((int) $catId)->toBe($this->catA);
});

it('resolves to category_id=C_a when the two frames straddle the winner', function (): void {
    $ops = buildSkewedOps(
        $this->signer, $this->sk,
        $this->txnId, $this->catA, $this->catB, $this->catC,
        (int) $this->user->id,
    );

    $replayer = new OpLogReplayer(app(DatabaseManager::class), $this->deviceKeys);
    $replayer->replay([$ops[0], $ops[1]], (int) $this->user->id);
    $replayer->replay([$ops[2]], (int) $this->user->id);

    $catId = app(DatabaseManager::class)
        ->connection()
        ->table('transactions')
        ->where('id', $this->txnId)
        ->value('category_id');

    expect((int) $catId)->toBe($this->catA);
});
