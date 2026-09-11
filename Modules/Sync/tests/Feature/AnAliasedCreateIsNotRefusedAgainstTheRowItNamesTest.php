<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Database\DatabaseManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Core\Models\User;
use Modules\Core\Public\Support\DeviceMintedRowId;
use Modules\Sync\Internal\Config\MergeRulesRegistry;
use Modules\Sync\Internal\Merge\OpLogReplayer;
use Modules\Sync\Internal\OpLog\OpLogEntry;
use Modules\Sync\Internal\OpLog\OpLogWriter;
use Modules\Sync\Internal\OpLog\OpType;

uses(RefreshDatabase::class);

// Measured on a paired Mac and Galaxy A51 on 2026-09-12. Eight anomaly_alerts
// creates sat in quarantine as primary_key_collision, and all eight already had
// an alias to a local row that was present: the pass retired all eight and the
// next pass recorded all eight again. The desktop held the mirror image of the
// phone's -- one alert, refused on both devices at once, each against the
// other's id, while each already knew which local row that id meant.

// The detector runs on both devices, so each stamps the alert with its own wall
// clock. That is what makes the second pass different from the first: the
// content comparison sees two birth times and calls one alert two rows.
const ALIASED_CREATE_PEER_DEVICE = 'the-desktop-that-detected-it-too';

function aliasedCreateTransaction(DatabaseManager $db, int $userId): int
{
    $accountId = $db->connection()->table('accounts')->insertGetId([
        'user_id' => $userId,
        'name' => 'ASN betaalrekening',
        'slug' => 'aliased-create-'.bin2hex(random_bytes(4)),
        'kind' => 'bank',
        'iban' => 'NL00ASNB'.strtoupper(bin2hex(random_bytes(4))),
        'default_currency' => 'EUR',
        'created_at' => '2026-09-01 00:00:00',
        'updated_at' => '2026-09-01 00:00:00',
    ]);

    $runId = $db->connection()->table('import_runs')->insertGetId([
        'user_id' => $userId,
        'source_format' => 'asn-csv',
        'raw_file_path' => '/tmp/aliased-create.csv',
        'sha256' => hash('sha256', 'aliased-create-'.bin2hex(random_bytes(8))),
        'uploaded_at' => '2026-09-01 00:00:00',
        'status' => 'confirmed',
        'created_at' => '2026-09-01 00:00:00',
        'updated_at' => '2026-09-01 00:00:00',
    ]);

    return $db->connection()->table('transactions')->insertGetId([
        'user_id' => $userId,
        'account_id' => $accountId,
        'import_run_id' => $runId,
        'fingerprint' => hash('sha256', 'aliased-create-'.bin2hex(random_bytes(8))),
        'fingerprint_version' => 3,
        'posted_at' => '2026-09-08',
        'booked_at' => '2026-09-08 10:00:00',
        'value_date' => '2026-09-08',
        'amount_minor' => -23490,
        'currency' => 'EUR',
        'settled_amount_minor' => -23490,
        'settled_currency' => 'EUR',
        'counterparty_normalized' => 'unusual merchant',
        'normalization_version' => 3,
        'type' => 'expense',
        'source_format' => 'asn-csv',
        'source_row_index' => 1,
        'created_at' => '2026-09-08 00:00:00',
        'updated_at' => '2026-09-08 00:00:00',
    ]);
}

/** @return array<string, mixed> */
function aliasedCreateAlertRow(int $transactionId, string $detectedAt): array
{
    return [
        'transaction_id' => $transactionId,
        'state' => 'open',
        'direction' => 'expense',
        'reasons' => json_encode(['large']),
        'baseline_amount_minor' => -999,
        'latest_amount_minor' => -23490,
        'currency' => 'EUR',
        'sensitivity_percent_used' => 50,
        'detected_at' => $detectedAt,
        'created_at' => $detectedAt,
        'updated_at' => $detectedAt,
    ];
}

function aliasedCreateWriter(int $userId): OpLogWriter
{
    /** @var OpLogWriter $writer */
    $writer = app(OpLogWriter::class, [
        'deviceId' => ALIASED_CREATE_PEER_DEVICE,
        'userId' => $userId,
        'secretKey' => sodium_crypto_sign_secretkey(test()->peerKeypair),
        'publicKey' => sodium_crypto_sign_publickey(test()->peerKeypair),
    ]);

    return $writer;
}

/** @return list<OpLogEntry> */
function aliasedCreateOps(DatabaseManager $db, int $userId): array
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

function aliasedCreateReplay(DatabaseManager $db, int $userId): void
{
    $keys = [ALIASED_CREATE_PEER_DEVICE => bin2hex(sodium_crypto_sign_publickey(test()->peerKeypair))];

    (new OpLogReplayer($db, $keys, new MergeRulesRegistry))->replay(aliasedCreateOps($db, $userId), $userId);
}

function aliasedCreateAliasFor(DatabaseManager $db, int $userId, int|string $peerPk): ?string
{
    $local = $db->connection()->table('op_log_row_aliases')
        ->where('user_id', $userId)
        ->where('table_name', 'anomaly_alerts')
        ->where('remote_id', (string) $peerPk)
        ->value('local_id');

    return is_string($local) ? $local : null;
}

/** @return list<string> */
function aliasedCreateHolds(DatabaseManager $db, int $userId): array
{
    /** @var list<string> $reasons */
    $reasons = $db->connection()->table('op_log_quarantine')->where('user_id', $userId)->pluck('reason')->all();

    return $reasons;
}

beforeEach(function (): void {
    CarbonImmutable::setTestNow('2026-09-12 00:54:10');

    $this->user = User::query()->create([
        'username' => 'aliased-create-'.bin2hex(random_bytes(4)),
        'password' => 'fixture-password-12chars',
        'period_start_day' => 1,
    ]);
    $this->peerKeypair = sodium_crypto_sign_keypair();

    /** @var DatabaseManager $db */
    $db = app(DatabaseManager::class);
    $this->db = $db;

    $userId = (int) $this->user->id;
    $this->transactionId = aliasedCreateTransaction($db, $userId);

    // What this device's own detector opened, the evening before the peer's.
    $this->localAlertId = DeviceMintedRowId::mint();
    $db->connection()->table('anomaly_alerts')->insert(
        ['id' => $this->localAlertId, 'user_id' => $userId]
        + aliasedCreateAlertRow($this->transactionId, '2026-09-10 19:07:01'),
    );

    // And the peer's, under an id no second device could have minted.
    $this->peerAlertId = DeviceMintedRowId::mint();
    aliasedCreateWriter($userId)->writeCreateRow(
        'anomaly_alerts',
        $this->peerAlertId,
        aliasedCreateAlertRow($this->transactionId, '2026-09-11 17:00:04'),
    );
});

afterEach(fn () => CarbonImmutable::setTestNow());

it('aliases the peer create onto the alert this device already holds', function (): void {
    $userId = (int) $this->user->id;

    aliasedCreateReplay($this->db, $userId);

    expect($this->db->connection()->table('anomaly_alerts')->where('user_id', $userId)->count())->toBe(1)
        ->and(aliasedCreateAliasFor($this->db, $userId, $this->peerAlertId))->toBe((string) $this->localAlertId)
        ->and(aliasedCreateHolds($this->db, $userId))->toBe([]);
});

// The treadmill. The first pass wrote the alias and answered; the second was
// handed the id the alias resolves to, asked the alias map which local row THAT
// peer id means, got nothing because no peer ever used it, and went on to
// compare the arriving row against the row it is.
it('does not refuse the create a pass before it already placed', function (): void {
    $userId = (int) $this->user->id;

    aliasedCreateReplay($this->db, $userId);
    aliasedCreateReplay($this->db, $userId);

    expect(aliasedCreateHolds($this->db, $userId))->toBe(
        [],
        'A create already aliased onto a local row was quarantined against that same row.',
    );

    expect($this->db->connection()->table('anomaly_alerts')->where('user_id', $userId)->count())->toBe(1)
        ->and(aliasedCreateAliasFor($this->db, $userId, $this->peerAlertId))->toBe((string) $this->localAlertId);
});

// What the hold costs beyond the hold: every pass after the first re-decides
// the same create, so a reader is told about a change that could not be saved
// while the change is sitting in front of them.
it('leaves the alert it aliased onto untouched across repeated passes', function (): void {
    $userId = (int) $this->user->id;

    aliasedCreateReplay($this->db, $userId);
    aliasedCreateReplay($this->db, $userId);
    aliasedCreateReplay($this->db, $userId);

    $alert = $this->db->connection()->table('anomaly_alerts')->where('user_id', $userId)->first();

    expect($alert)->not->toBeNull()
        ->and((int) $alert->id)->toBe($this->localAlertId)
        ->and((int) $alert->transaction_id)->toBe($this->transactionId)
        ->and((string) $alert->created_at)->toBe('2026-09-10 19:07:01');
});
