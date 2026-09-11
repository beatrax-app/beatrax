<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Database\DatabaseManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Core\Models\User;
use Modules\Ledger\Public\Dto\FingerprintTuple;
use Modules\Ledger\Public\Services\FingerprintComposer;
use Modules\Sync\Internal\Config\MergeRulesRegistry;
use Modules\Sync\Internal\Merge\OpLogReplayer;
use Modules\Sync\Internal\OpLog\OpLogEntry;
use Modules\Sync\Internal\OpLog\OpLogWriter;
use Modules\Sync\Internal\OpLog\OpType;

uses(RefreshDatabase::class);

// partitionByOpType keys a field group by table and pk and nothing else. Where
// two devices minted one id, one of those rows is now stored under an id this
// device gave it -- and both devices' Sets still arrive in one group, to be
// resolved by a single LWW and written wherever the earliest of them points.

const SPANROW_REHOMED = 'the-phone-whose-row-moved';

const SPANROW_OTHER = 'the-tablet-that-agrees-with-this-device';

function spanrowWriter(int $userId, string $deviceId, string $which): OpLogWriter
{
    /** @var OpLogWriter $writer */
    $writer = app(OpLogWriter::class, [
        'deviceId' => $deviceId,
        'userId' => $userId,
        'secretKey' => sodium_crypto_sign_secretkey(test()->keys[$which]),
        'publicKey' => sodium_crypto_sign_publickey(test()->keys[$which]),
    ]);

    return $writer;
}

/** @return array<string, mixed> */
function spanrowRow(int $userId, int $accountId, int $runId, string $day, int $amountMinor): array
{
    /** @var FingerprintComposer $composer */
    $composer = app(FingerprintComposer::class);

    return [
        'account_id' => $accountId,
        'import_run_id' => $runId,
        'type' => 'expense',
        'posted_at' => $day,
        'booked_at' => $day.' 00:00:00',
        'value_date' => $day,
        'amount_minor' => $amountMinor,
        'currency' => 'EUR',
        'settled_amount_minor' => $amountMinor,
        'settled_currency' => 'EUR',
        'counterparty_normalized' => 'albert heijn',
        'normalization_version' => FingerprintComposer::NORMALIZATION_VERSION,
        'source_format' => 'asn-csv',
        'source_row_index' => 0,
        'occurrence_ordinal' => 0,
        'fingerprint' => $composer->composeTuple(new FingerprintTuple(
            $userId, $accountId, $day, $day.' 00:00:00', $amountMinor, 'EUR', 'albert heijn', 0,
        )),
        'fingerprint_version' => FingerprintComposer::NORMALIZATION_VERSION,
        'status' => 'cleared',
        'created_at' => $day.' 09:00:00',
        'updated_at' => $day.' 09:00:00',
    ];
}

/** @return list<OpLogEntry> */
function spanrowOps(DatabaseManager $db, int $userId): array
{
    return $db->connection()->table('op_log_entries')
        ->where('user_id', $userId)
        ->orderBy('id')
        ->get()
        ->map(static fn (object $row): OpLogEntry => new OpLogEntry(
            table: (string) $row->table_name,
            pk: is_numeric($row->pk) ? (int) $row->pk : (string) $row->pk,
            field: (string) $row->field,
            value: $row->value !== null ? (string) $row->value : null,
            hlcL: (int) $row->hlc_l,
            hlcC: (int) $row->hlc_c,
            deviceId: (string) $row->device_id,
            opType: OpType::from((string) $row->op_type),
            signature: (string) $row->signature,
            userId: (int) $row->user_id,
        ))
        ->all();
}

function spanrowReplay(DatabaseManager $db, int $userId): void
{
    $keys = [
        SPANROW_REHOMED => bin2hex(sodium_crypto_sign_publickey(test()->keys['rehomed'])),
        SPANROW_OTHER => bin2hex(sodium_crypto_sign_publickey(test()->keys['other'])),
    ];

    (new OpLogReplayer($db, $keys, new MergeRulesRegistry))->replay(spanrowOps($db, $userId), $userId);
}

beforeEach(function (): void {
    CarbonImmutable::setTestNow('2026-09-11 12:00:00');

    $this->keys = ['rehomed' => sodium_crypto_sign_keypair(), 'other' => sodium_crypto_sign_keypair()];

    $this->user = User::query()->create([
        'username' => 'spanrow-'.bin2hex(random_bytes(4)),
        'password' => 'fixture-password-12chars',
        'period_start_day' => 1,
    ]);

    /** @var DatabaseManager $db */
    $db = app(DatabaseManager::class);
    $this->db = $db;
    $userId = (int) $this->user->id;

    $this->accountId = (int) $db->connection()->table('accounts')->insertGetId([
        'user_id' => $userId,
        'name' => 'ASN betaalrekening',
        'slug' => 'spanrow-'.bin2hex(random_bytes(4)),
        'kind' => 'bank',
        'iban' => 'NL00SPAN'.strtoupper(bin2hex(random_bytes(4))),
        'default_currency' => 'EUR',
        'created_at' => '2026-01-01 00:00:00',
        'updated_at' => '2026-01-01 00:00:00',
    ]);

    $this->runId = (int) $db->connection()->table('import_runs')->insertGetId([
        'user_id' => $userId,
        'source_format' => 'asn-csv',
        'raw_file_path' => '/tmp/spanrow.csv',
        'sha256' => hash('sha256', 'spanrow-'.bin2hex(random_bytes(8))),
        'uploaded_at' => '2026-01-02 00:00:00',
        'status' => 'confirmed',
        'created_at' => '2026-01-02 00:00:00',
        'updated_at' => '2026-01-02 00:00:00',
    ]);

    // What this device imported before pairing, at the id both devices took.
    $this->localId = (int) $db->connection()->table('transactions')->insertGetId([
        'user_id' => $userId,
        ...spanrowRow($userId, $this->accountId, $this->runId, '2026-08-01', -125000),
    ]);
});

afterEach(fn () => CarbonImmutable::setTestNow());

it('writes each device\'s set to the row that device means', function (): void {
    $userId = (int) $this->user->id;

    // The phone's own charge, taking the same id. It is re-homed under an id
    // this device minted, and the pair is remembered.
    $moved = spanrowRow($userId, $this->accountId, $this->runId, '2026-02-02', -399);
    unset($moved['user_id']);
    spanrowWriter($userId, SPANROW_REHOMED, 'rehomed')->writeCreateRow('transactions', $this->localId, $moved);
    spanrowReplay($this->db, $userId);

    $rehomedId = (int) $this->db->connection()->table('op_log_row_aliases')
        ->where('table_name', 'transactions')->where('remote_id', (string) $this->localId)->value('local_id');

    expect($rehomedId)->toBeGreaterThan(0)->and($rehomedId)->not->toBe($this->localId);

    $this->db->connection()->table('op_log_entries')->where('user_id', $userId)->delete();

    // The tablet writes first, so its entry is the earliest in the group and
    // the one the pk used to be resolved through. The phone writes after, so
    // last-writer-wins picks the phone's value.
    spanrowWriter($userId, SPANROW_OTHER, 'other')->writeSet('transactions', $this->localId, 'note', 'from the tablet');
    CarbonImmutable::setTestNow('2026-09-11 12:05:00');
    spanrowWriter($userId, SPANROW_REHOMED, 'rehomed')->writeSet('transactions', $this->localId, 'note', 'from the phone');

    spanrowReplay($this->db, $userId);

    expect($this->db->connection()->table('transactions')->where('id', $rehomedId)->value('note'))
        ->toBe('from the phone')
        ->and($this->db->connection()->table('transactions')->where('id', $this->localId)->value('note'))
        ->toBe('from the tablet');
});
