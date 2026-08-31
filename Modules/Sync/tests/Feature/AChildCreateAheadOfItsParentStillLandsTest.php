<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Database\DatabaseManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Core\Models\User;
use Modules\Core\Public\Support\DerivedRowId;
use Modules\Sync\Internal\Config\MergeRulesRegistry;
use Modules\Sync\Internal\Merge\OpLogReplayer;
use Modules\Sync\Internal\OpLog\OpLogEntry;
use Modules\Sync\Internal\OpLog\OpLogWriter;
use Modules\Sync\Internal\OpLog\OpType;

uses(RefreshDatabase::class);

// The replayer says creates arrive in HLC order, which says nothing about
// referential order, and orders them parents-first before it writes. It asked
// the container for CoveredTableOrder to do that — and Container::bound()
// answers false for a concrete class nobody registered, so the answer was null
// and BOTH ordering passes fell through to "leave it as it came". Every child
// whose parent's clock ran later was refused by its own foreign key and
// quarantined one row at a time, with the parent landing fine right after.

beforeEach(function (): void {
    CarbonImmutable::setTestNow('2026-05-17 12:00:00');

    $this->user = User::create([
        'username' => 'child-first-'.bin2hex(random_bytes(4)),
        'password' => 'fixture-password-12chars',
        'period_start_day' => 1,
    ]);

    /** @var DatabaseManager $db */
    $db = app(DatabaseManager::class);
    $this->db = $db;
});

afterEach(function (): void {
    CarbonImmutable::setTestNow();
});

function ccpSeedTransaction(DatabaseManager $db, int $userId): int
{
    $accountId = $db->connection()->table('accounts')->insertGetId([
        'user_id' => $userId,
        'name' => 'ASN child first',
        'slug' => 'child-first-'.bin2hex(random_bytes(4)),
        'kind' => 'bank',
        'iban' => 'NL00ASNB'.strtoupper(bin2hex(random_bytes(4))),
        'default_currency' => 'EUR',
        'created_at' => '2026-01-01 00:00:00',
        'updated_at' => '2026-01-01 00:00:00',
    ]);

    $runId = $db->connection()->table('import_runs')->insertGetId([
        'user_id' => $userId,
        'source_format' => 'asn-csv',
        'raw_file_path' => '/tmp/child-first.csv',
        'sha256' => hash('sha256', 'child-first-'.bin2hex(random_bytes(8))),
        'uploaded_at' => '2026-01-01 00:00:00',
        'status' => 'previewed',
        'created_at' => '2026-01-01 00:00:00',
        'updated_at' => '2026-01-01 00:00:00',
    ]);

    return $db->connection()->table('transactions')->insertGetId([
        'user_id' => $userId,
        'account_id' => $accountId,
        'import_run_id' => $runId,
        'type' => 'expense',
        'posted_at' => '2026-05-04',
        'booked_at' => '2026-05-04 12:00:00',
        'value_date' => '2026-05-04',
        'amount_minor' => -1099,
        'currency' => 'EUR',
        'settled_amount_minor' => -1099,
        'settled_currency' => 'EUR',
        'counterparty_name' => 'Spotify',
        'counterparty_normalized' => 'spotify',
        'normalization_version' => 3,
        'source_format' => 'asn-csv',
        'source_row_index' => 1,
        'fingerprint' => hash('sha256', 'child-first-'.bin2hex(random_bytes(8))),
        'fingerprint_version' => 3,
        'created_at' => '2026-05-04 12:00:00',
        'updated_at' => '2026-05-04 12:00:00',
    ]);
}

/** @return list<OpLogEntry> */
function ccpAllOps(DatabaseManager $db, int $userId): array
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

it('writes a parent whose create op arrived after its child, rather than quarantining the child', function (): void {
    $userId = (int) $this->user->id;
    $transactionId = ccpSeedTransaction($this->db, $userId);

    $keypair = sodium_crypto_sign_keypair();
    /** @var OpLogWriter $writer */
    $writer = app(OpLogWriter::class, [
        'deviceId' => 'device-out-of-order',
        'userId' => $userId,
        'secretKey' => sodium_crypto_sign_secretkey($keypair),
        'publicKey' => sodium_crypto_sign_publickey($keypair),
    ]);

    $seriesId = DerivedRowId::for('recurring_series', [
        'user_id' => $userId,
        'direction' => 'expense',
        'cluster_counterparty_key' => 'spotify',
        'latest_currency' => 'EUR',
    ]);
    $occurrenceId = DerivedRowId::for('recurring_series_occurrences', [
        'recurring_series_id' => $seriesId,
        'transaction_id' => $transactionId,
    ]);

    // The child first, so its HLC is strictly lower than the parent's — the
    // order the peer receives, and the order the replayer is responsible for
    // undoing before it writes anything.
    $writer->writeCreateRow('recurring_series_occurrences', $occurrenceId, [
        'user_id' => $userId,
        'recurring_series_id' => $seriesId,
        'transaction_id' => $transactionId,
        'observed_at' => '2026-05-04',
        'observed_amount_minor' => -1099,
        'observed_currency' => 'EUR',
        'created_at' => '2026-05-17 12:00:00',
        'updated_at' => '2026-05-17 12:00:00',
    ]);

    $writer->writeCreateRow('recurring_series', $seriesId, [
        'user_id' => $userId,
        'direction' => 'expense',
        'detected_name' => 'Spotify',
        'state' => 'pending',
        'cadence' => 'monthly',
        'latest_amount_minor' => -1099,
        'latest_currency' => 'EUR',
        'monthly_equivalent_minor' => -1099,
        'variance_tolerance_percent' => 25,
        'cluster_key' => 'expense::spotify::eur::monthly',
        'cluster_counterparty_key' => 'spotify',
        'created_at' => '2026-05-17 12:00:00',
        'updated_at' => '2026-05-17 12:00:00',
    ]);

    $ops = ccpAllOps($this->db, $userId);
    expect($ops)->not->toBeEmpty();

    $replayer = new OpLogReplayer(
        $this->db,
        ['device-out-of-order' => bin2hex(sodium_crypto_sign_publickey($keypair))],
        new MergeRulesRegistry,
    );
    $replayer->replay($ops, $userId);

    expect($this->db->connection()->table('recurring_series')->where('id', $seriesId)->exists())->toBeTrue()
        ->and($this->db->connection()->table('recurring_series_occurrences')->where('id', $occurrenceId)->exists())->toBeTrue()
        ->and($this->db->connection()->table('op_log_quarantine')->where('user_id', $userId)->count())->toBe(0);
});
