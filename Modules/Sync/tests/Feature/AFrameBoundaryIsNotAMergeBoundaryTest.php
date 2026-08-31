<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Database\DatabaseManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Sync\Internal\Merge\OpLogReplayer;
use Modules\Sync\Internal\OpLog\OpLogEntry;
use Modules\Sync\Internal\OpLog\OpType;
use Modules\Sync\Internal\Signing\DeviceKeySigner;

uses(RefreshDatabase::class);

// receiveOps() is called once per frame, and a frame caps at 1024 ops, so a
// real history arrives as dozens of separate replay() batches. Every case here
// puts ops that belong to one row on OPPOSITE sides of a batch boundary and
// asserts the same answer a single batch gives — which is the only axis the
// arrival-order tests never varied.

beforeEach(function (): void {
    CarbonImmutable::setTestNow('2026-06-14 10:00:00');

    /** @var DatabaseManager $db */
    $db = app(DatabaseManager::class);
    $this->db = $db;

    $this->userId = (int) $db->connection()->table('users')->insertGetId([
        'username' => 'frame-boundary-'.bin2hex(random_bytes(4)),
        'password' => 'fixture',
        'period_start_day' => 1,
        'default_currency_view' => 'eur_only',
    ]);

    $keypair = sodium_crypto_sign_keypair();
    $this->sk = sodium_crypto_sign_secretkey($keypair);
    $pkHex = bin2hex(sodium_crypto_sign_publickey($keypair));
    $this->signer = new DeviceKeySigner;
    $this->deviceKeys = ['device-laptop' => $pkHex, 'device-phone' => $pkHex];
});

afterEach(function (): void {
    CarbonImmutable::setTestNow();
});

function frameBoundaryOp(
    DeviceKeySigner $signer,
    string $sk,
    int $userId,
    string $table,
    int|string $pk,
    string $field,
    ?string $value,
    int $hlcL,
    string $deviceId,
    OpType $opType = OpType::Set,
): OpLogEntry {
    $make = static fn (string $signature): OpLogEntry => new OpLogEntry(
        table: $table,
        pk: $pk,
        field: $field,
        value: $value,
        hlcL: $hlcL,
        hlcC: 0,
        deviceId: $deviceId,
        opType: $opType,
        signature: $signature,
        userId: $userId,
    );

    return $make($signer->sign($make('')->signingPayload(), $sk));
}

function frameBoundaryLedgerRow(DatabaseManager $db, int $userId): int
{
    $accountId = $db->connection()->table('accounts')->insertGetId([
        'user_id' => $userId,
        'name' => 'Frame boundary account',
        'slug' => 'frame-boundary-'.bin2hex(random_bytes(4)),
        'kind' => 'bank',
        'iban' => 'NL00FRAM'.strtoupper(bin2hex(random_bytes(4))),
        'default_currency' => 'EUR',
        'created_at' => '2026-06-01 00:00:00',
        'updated_at' => '2026-06-01 00:00:00',
    ]);

    $runId = $db->connection()->table('import_runs')->insertGetId([
        'user_id' => $userId,
        'source_format' => 'asn-csv',
        'raw_file_path' => '/tmp/frame-boundary-'.bin2hex(random_bytes(4)).'.csv',
        'sha256' => hash('sha256', 'frame-boundary-'.bin2hex(random_bytes(8))),
        'uploaded_at' => '2026-06-01 00:00:00',
        'status' => 'previewed',
        'created_at' => '2026-06-01 00:00:00',
        'updated_at' => '2026-06-01 00:00:00',
    ]);

    return (int) $db->connection()->table('transactions')->insertGetId([
        'user_id' => $userId,
        'account_id' => $accountId,
        'import_run_id' => $runId,
        'fingerprint' => hash('sha256', 'frame-boundary-'.bin2hex(random_bytes(8))),
        'posted_at' => '2026-06-01',
        'booked_at' => '2026-06-01 10:00:00',
        'value_date' => '2026-06-01',
        'amount_minor' => -1,
        'currency' => 'EUR',
        'settled_amount_minor' => -1,
        'settled_currency' => 'EUR',
        'counterparty_normalized' => 'albert heijn',
        'counterparty_name' => 'ALBERT HEIJN',
        'normalization_version' => 3,
        'description' => 'frame boundary fixture',
        'type' => 'expense',
        'source_format' => 'asn-csv',
        'source_row_index' => 1,
        'fingerprint_version' => 3,
        'created_at' => '2026-06-01 00:00:00',
        'updated_at' => '2026-06-01 00:00:00',
    ]);
}

it('does not let an older remote op overwrite the newer local amount it arrives after', function (): void {
    $txnId = frameBoundaryLedgerRow($this->db, $this->userId);

    $replayer = new OpLogReplayer($this->db, $this->deviceKeys);

    // The laptop's own 10:05 edit, already applied and already in the log.
    $replayer->replay([
        frameBoundaryOp($this->signer, $this->sk, $this->userId, 'transactions', $txnId, 'note', json_encode('laptop 10:05', JSON_THROW_ON_ERROR), 2000, 'device-laptop'),
        frameBoundaryOp($this->signer, $this->sk, $this->userId, 'transactions', $txnId, 'amount_minor', json_encode(-9999, JSON_THROW_ON_ERROR), 2000, 'device-laptop'),
    ], $this->userId);

    // The phone was offline at 10:00 and sends its whole history on the first
    // meeting, so the batch holds no local op to compare against.
    $replayer->replay([
        frameBoundaryOp($this->signer, $this->sk, $this->userId, 'transactions', $txnId, 'note', json_encode('phone 10:00 (older)', JSON_THROW_ON_ERROR), 1000, 'device-phone'),
        frameBoundaryOp($this->signer, $this->sk, $this->userId, 'transactions', $txnId, 'amount_minor', json_encode(-1111, JSON_THROW_ON_ERROR), 1000, 'device-phone'),
    ], $this->userId);

    $row = $this->db->connection()->table('transactions')->where('id', $txnId)->first();

    expect($row?->note)->toBe('laptop 10:05')
        ->and((int) ($row?->amount_minor ?? 0))->toBe(-9999);
});

it('does not let a stale tombstone in a later frame delete a row a newer edit kept', function (): void {
    $txnId = frameBoundaryLedgerRow($this->db, $this->userId);

    $replayer = new OpLogReplayer($this->db, $this->deviceKeys);

    $replayer->replay([
        frameBoundaryOp($this->signer, $this->sk, $this->userId, 'transactions', $txnId, 'note', json_encode('kept at 3000', JSON_THROW_ON_ERROR), 3000, 'device-laptop'),
    ], $this->userId);

    // A bare tombstone: the frame carries no field op for this row, so a
    // batch-scoped tombstoneWins() has nothing left to lose to.
    $replayer->replay([
        frameBoundaryOp($this->signer, $this->sk, $this->userId, 'transactions', $txnId, '', null, 1000, 'device-phone', OpType::DeleteTombstone),
    ], $this->userId);

    $exists = $this->db->connection()->table('transactions')->where('id', $txnId)->exists();

    expect($exists)->toBeTrue();
});

it('applies a SET that arrived before its own CreateRow once the create lands', function (): void {
    $replayer = new OpLogReplayer($this->db, $this->deviceKeys);
    $merchantPk = 4242;

    $replayer->replay([
        frameBoundaryOp($this->signer, $this->sk, $this->userId, 'merchants', $merchantPk, 'name', json_encode('Set before create', JSON_THROW_ON_ERROR), 3000, 'device-phone'),
    ], $this->userId);

    expect($this->db->connection()->table('merchants')->where('id', $merchantPk)->exists())->toBeFalse();

    $replayer->replay([
        frameBoundaryOp($this->signer, $this->sk, $this->userId, 'merchants', $merchantPk, 'name', json_encode('Created name', JSON_THROW_ON_ERROR), 1000, 'device-phone', OpType::CreateRow),
        frameBoundaryOp($this->signer, $this->sk, $this->userId, 'merchants', $merchantPk, 'normalized_name', json_encode('created name', JSON_THROW_ON_ERROR), 1000, 'device-phone', OpType::CreateRow),
    ], $this->userId);

    $row = $this->db->connection()->table('merchants')->where('id', $merchantPk)->first();

    expect($row)->not->toBeNull()
        ->and($row?->name)->toBe('Set before create');
});

it('sums a G-Counter across frames instead of restarting it at the newest frame', function (): void {
    $replayer = new OpLogReplayer($this->db, $this->deviceKeys);

    $merchantId = (int) $this->db->connection()->table('merchants')->insertGetId([
        'user_id' => $this->userId,
        'name' => 'Esso',
        'normalized_name' => 'esso',
        'created_at' => '2026-06-01 00:00:00',
        'updated_at' => '2026-06-01 00:00:00',
    ]);

    $categoryId = (int) $this->db->connection()->table('categories')->insertGetId([
        'user_id' => $this->userId,
        'name' => 'Fuel',
        'slug' => 'fuel-'.bin2hex(random_bytes(4)),
        'kind' => 'expense',
        'created_at' => '2026-06-01 00:00:00',
        'updated_at' => '2026-06-01 00:00:00',
    ]);

    $memoryId = (int) $this->db->connection()->table('merchant_memories')->insertGetId([
        'user_id' => $this->userId,
        'merchant_id' => $merchantId,
        'category_id' => $categoryId,
        'occurrence_count' => 0,
        'created_at' => '2026-06-01 00:00:00',
        'updated_at' => '2026-06-01 00:00:00',
    ]);

    $replayer->replay([
        frameBoundaryOp($this->signer, $this->sk, $this->userId, 'merchant_memories', $memoryId, 'occurrence_count', json_encode(5, JSON_THROW_ON_ERROR), 1000, 'device-laptop'),
    ], $this->userId);

    $replayer->replay([
        frameBoundaryOp($this->signer, $this->sk, $this->userId, 'merchant_memories', $memoryId, 'occurrence_count', json_encode(3, JSON_THROW_ON_ERROR), 2000, 'device-phone'),
    ], $this->userId);

    $count = $this->db->connection()->table('merchant_memories')->where('id', $memoryId)->value('occurrence_count');

    expect((int) $count)->toBe(8);
});

it('keeps an OR-Set element added in an earlier frame when a later frame adds another', function (): void {
    $replayer = new OpLogReplayer($this->db, $this->deviceKeys);

    $aliasId = (int) $this->db->connection()->table('merchant_aliases')->insertGetId([
        'user_id' => $this->userId,
        'pattern' => 'ESSO*',
        'generalized_pattern' => 'esso',
        'friendly_name' => 'Esso',
        'merged_from' => null,
        'created_at' => '2026-06-01 00:00:00',
        'updated_at' => '2026-06-01 00:00:00',
    ]);

    $add = static fn (string $value, string $tag): string => json_encode(
        ['added' => [['v' => $value, 'tag' => $tag]], 'removed' => []],
        JSON_THROW_ON_ERROR,
    );

    $replayer->replay([
        frameBoundaryOp($this->signer, $this->sk, $this->userId, 'merchant_aliases', $aliasId, 'merged_from', $add('ESSO', 't1'), 1000, 'device-laptop'),
    ], $this->userId);

    $replayer->replay([
        frameBoundaryOp($this->signer, $this->sk, $this->userId, 'merchant_aliases', $aliasId, 'merged_from', $add('BP', 't2'), 2000, 'device-phone'),
    ], $this->userId);

    $merged = $this->db->connection()->table('merchant_aliases')->where('id', $aliasId)->value('merged_from');

    /** @var list<array{v: string, tag: string}> $decoded */
    $decoded = json_decode(is_string($merged) ? $merged : '[]', true, 512, JSON_THROW_ON_ERROR);
    $tags = array_column($decoded, 'tag');
    sort($tags);

    expect($tags)->toBe(['t1', 't2']);
});
