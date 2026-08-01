<?php

declare(strict_types=1);

use Illuminate\Database\DatabaseManager;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Core\Models\User;

uses(RefreshDatabase::class);

/*
 * Unit coverage for the anomaly_alerts migration — locks the column
 * shape, the UNIQUE(transaction_id) idempotency seam, the two read
 * indexes, and the schema-level state trigger pair that rejects
 * out-of-band INSERT / UPDATE statements with a state value outside the
 * allowed enum.
 */

beforeEach(function (): void {
    /** @var DatabaseManager $db */
    $db = $this->app->make(DatabaseManager::class);
    $this->db = $db;

    $this->user = User::query()->create([
        'username' => 'anomaly-mig',
        'password' => 'fixture-password',
        'period_start_day' => 1,
        'default_currency_view' => 'eur_only',
    ]);

    $this->transactionId = seedAnomalyAlertTransaction($this->db, $this->user->id, 'a');
});

it('creates the anomaly_alerts table with every required column', function (): void {
    $schema = $this->db->connection()->getSchemaBuilder();

    expect($schema->hasTable('anomaly_alerts'))->toBeTrue();

    $columns = [
        'id', 'user_id', 'transaction_id', 'state', 'direction', 'reasons',
        'dismissed_as', 'baseline_amount_minor', 'latest_amount_minor',
        'currency', 'sensitivity_percent_used', 'snoozed_until',
        'detected_at', 'actioned_at', 'created_at', 'updated_at',
    ];

    foreach ($columns as $column) {
        expect($schema->hasColumn('anomaly_alerts', $column))->toBeTrue(
            "Expected anomaly_alerts column '{$column}' to exist",
        );
    }
});

it('rejects an out-of-band INSERT carrying an invalid anomaly_alerts.state value', function (): void {
    $caught = null;
    try {
        $this->db->connection()->table('anomaly_alerts')->insert([
            'user_id' => $this->user->id,
            'transaction_id' => $this->transactionId,
            'state' => 'garbage',
            'direction' => 'expense',
            'reasons' => json_encode(['large']),
            'detected_at' => '2026-06-13 00:00:00',
            'created_at' => '2026-06-13 00:00:00',
            'updated_at' => '2026-06-13 00:00:00',
        ]);
    } catch (QueryException $e) {
        $caught = $e;
    }

    expect($caught)->not->toBeNull();
    expect($caught?->getMessage())->toContain('Invalid anomaly_alerts.state value');
    expect($this->db->connection()->table('anomaly_alerts')->count())->toBe(0);
});

it('rejects an UPDATE that flips anomaly_alerts.state to an invalid value', function (): void {
    $id = $this->db->connection()->table('anomaly_alerts')->insertGetId([
        'user_id' => $this->user->id,
        'transaction_id' => $this->transactionId,
        'state' => 'open',
        'direction' => 'expense',
        'reasons' => json_encode(['large']),
        'detected_at' => '2026-06-13 00:00:00',
        'created_at' => '2026-06-13 00:00:00',
        'updated_at' => '2026-06-13 00:00:00',
    ]);

    $caught = null;
    try {
        $this->db->connection()->table('anomaly_alerts')
            ->where('id', $id)
            ->update(['state' => 'wrong']);
    } catch (QueryException $e) {
        $caught = $e;
    }

    expect($caught)->not->toBeNull();
    expect($caught?->getMessage())->toContain('Invalid anomaly_alerts.state value');

    $row = $this->db->connection()->table('anomaly_alerts')->where('id', $id)->first();
    expect($row?->state)->toBe('open');
});

it('enforces UNIQUE(transaction_id) on anomaly_alerts', function (): void {
    $base = [
        'user_id' => $this->user->id,
        'transaction_id' => $this->transactionId,
        'state' => 'open',
        'direction' => 'expense',
        'reasons' => json_encode(['large']),
        'detected_at' => '2026-06-13 00:00:00',
        'created_at' => '2026-06-13 00:00:00',
        'updated_at' => '2026-06-13 00:00:00',
    ];

    $this->db->connection()->table('anomaly_alerts')->insert($base);

    $caught = null;
    try {
        $this->db->connection()->table('anomaly_alerts')->insert($base);
    } catch (QueryException $e) {
        $caught = $e;
    }

    expect($caught)->not->toBeNull();
    expect($this->db->connection()->table('anomaly_alerts')->count())->toBe(1);
});

it('registers the anomaly_alerts_uniq index plus the two read indexes', function (): void {
    $indexNames = collect($this->db->connection()->select(
        "SELECT name FROM sqlite_master WHERE type='index' AND tbl_name='anomaly_alerts'",
    ))
        ->pluck('name')
        ->map(static fn ($name): string => is_string($name) ? $name : (string) $name)
        ->all();

    expect($indexNames)->toContain('anomaly_alerts_uniq');

    $userStateIndex = collect($indexNames)
        ->first(static fn (string $name): bool => str_contains($name, 'anomaly_alerts')
            && str_contains($name, 'user_id')
            && str_contains($name, 'state'));
    expect($userStateIndex)->not->toBeNull();

    $userStateDetectedIndex = collect($indexNames)
        ->first(static fn (string $name): bool => str_contains($name, 'anomaly_alerts')
            && str_contains($name, 'detected_at'));
    expect($userStateDetectedIndex)->not->toBeNull();
});

it('accepts every documented anomaly_alerts.state enum value', function (string $state): void {
    $txnId = seedAnomalyAlertTransaction($this->db, $this->user->id, $state[0].bin2hex(random_bytes(3)));

    $id = $this->db->connection()->table('anomaly_alerts')->insertGetId([
        'user_id' => $this->user->id,
        'transaction_id' => $txnId,
        'state' => $state,
        'direction' => 'expense',
        'reasons' => json_encode(['large']),
        'detected_at' => '2026-06-13 00:00:00',
        'created_at' => '2026-06-13 00:00:00',
        'updated_at' => '2026-06-13 00:00:00',
    ]);

    $row = $this->db->connection()->table('anomaly_alerts')->where('id', $id)->first();
    expect($row?->state)->toBe($state);
})->with([
    'open',
    'acknowledged',
    'snoozed',
    'dismissed',
]);

/**
 * Seeds a transactions row sufficient to satisfy the foreign-key
 * constraint on anomaly_alerts.transaction_id without requiring the
 * full Ingestion fixture stack. The $salt keeps the account slug +
 * transaction fingerprint unique across repeated calls.
 */
function seedAnomalyAlertTransaction(DatabaseManager $db, int $userId, string $salt): int
{
    $accountId = $db->connection()->table('accounts')->insertGetId([
        'user_id' => $userId,
        'name' => 'ASN test',
        'slug' => 'anomaly-mig-asn-'.$salt.'-'.bin2hex(random_bytes(4)),
        'kind' => 'bank',
        'iban' => 'NL00ASNB'.substr(bin2hex(random_bytes(8)), 0, 10),
        'default_currency' => 'EUR',
        'created_at' => '2026-06-13 00:00:00',
        'updated_at' => '2026-06-13 00:00:00',
    ]);

    $runId = $db->connection()->table('import_runs')->insertGetId([
        'user_id' => $userId,
        'source_format' => 'asn-csv',
        'raw_file_path' => '/tmp/anomaly-mig.csv',
        'sha256' => hash('sha256', $salt.bin2hex(random_bytes(8))),
        'uploaded_at' => '2026-06-13 00:00:00',
        'status' => 'previewed',
        'created_at' => '2026-06-13 00:00:00',
        'updated_at' => '2026-06-13 00:00:00',
    ]);

    return $db->connection()->table('transactions')->insertGetId([
        'user_id' => $userId,
        'account_id' => $accountId,
        'import_run_id' => $runId,
        'fingerprint' => hash('sha256', 'anomaly-mig-fp-'.$salt.bin2hex(random_bytes(8))),
        'posted_at' => '2026-06-10',
        'booked_at' => '2026-06-10 00:00:00',
        'value_date' => '2026-06-10',
        'amount_minor' => -1149,
        'currency' => 'EUR',
        'settled_amount_minor' => -1149,
        'settled_currency' => 'EUR',
        'counterparty_normalized' => 'spotify',
        'counterparty_name' => 'SPOTIFY',
        'normalization_version' => 1,
        'description' => 'anomaly mig fixture',
        'type' => 'expense',
        'source_format' => 'asn-csv',
        'source_row_index' => 1,
        'fingerprint_version' => 3,
        'created_at' => '2026-06-13 00:00:00',
        'updated_at' => '2026-06-13 00:00:00',
    ]);
}
