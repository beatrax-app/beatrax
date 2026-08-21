<?php

declare(strict_types=1);

use Illuminate\Database\DatabaseManager;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Core\Models\User;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    /** @var DatabaseManager $db */
    $db = $this->app->make(DatabaseManager::class);
    $this->db = $db;

    $this->user = User::query()->create([
        'username' => 'drift-mig',
        'password' => 'fixture-password',
        'period_start_day' => 1,
        'default_currency_view' => 'eur_only',
    ]);

    $this->series = $this->db->connection()->table('recurring_series')->insertGetId([
        'user_id' => $this->user->id,
        'direction' => 'expense',
        'detected_name' => 'Drift Mig Series',
        'state' => 'approved',
        'cadence' => 'monthly',
        'latest_amount_minor' => -1099,
        'latest_currency' => 'EUR',
        'variance_tolerance_percent' => 25,
        'cluster_key' => 'drift-mig|monthly|EUR',
        'created_at' => '2026-05-19 00:00:00',
        'updated_at' => '2026-05-19 00:00:00',
    ]);

    $this->occurrence = $this->db->connection()->table('recurring_series_occurrences')->insertGetId([
        'user_id' => $this->user->id,
        'recurring_series_id' => $this->series,
        'transaction_id' => seedDriftAlertTransaction($this->db, $this->user->id),
        'observed_at' => '2026-05-15',
        'observed_amount_minor' => -1099,
        'observed_currency' => 'EUR',
        'created_at' => '2026-05-19 00:00:00',
        'updated_at' => '2026-05-19 00:00:00',
    ]);
});

it('creates the drift_alerts table with every required column', function (): void {
    $schema = $this->db->connection()->getSchemaBuilder();

    expect($schema->hasTable('drift_alerts'))->toBeTrue();

    $columns = [
        'id', 'user_id', 'recurring_series_id', 'state', 'direction',
        'baseline_amount_minor', 'latest_amount_minor', 'currency',
        'delta_minor', 'annualized_impact_minor', 'threshold_percent_used',
        'threshold_source', 'latest_occurrence_id', 'snoozed_until',
        'detected_at', 'actioned_at', 'created_at', 'updated_at',
    ];

    foreach ($columns as $column) {
        expect($schema->hasColumn('drift_alerts', $column))->toBeTrue(
            "Expected drift_alerts column '{$column}' to exist",
        );
    }
});

it('rejects an out-of-band INSERT carrying an invalid drift_alerts.state value', function (): void {
    $caught = null;
    try {
        $this->db->connection()->table('drift_alerts')->insert([
            'user_id' => $this->user->id,
            'recurring_series_id' => $this->series,
            'state' => 'garbage',
            'direction' => 'expense',
            'baseline_amount_minor' => -1099,
            'latest_amount_minor' => -1349,
            'currency' => 'EUR',
            'delta_minor' => -250,
            'annualized_impact_minor' => -3000,
            'threshold_percent_used' => 5,
            'threshold_source' => 'global',
            'latest_occurrence_id' => $this->occurrence,
            'detected_at' => '2026-05-19 00:00:00',
            'created_at' => '2026-05-19 00:00:00',
            'updated_at' => '2026-05-19 00:00:00',
        ]);
    } catch (QueryException $e) {
        $caught = $e;
    }

    expect($caught)->not->toBeNull();
    expect($caught?->getMessage())->toContain('Invalid drift_alerts.state value');
    expect($this->db->connection()->table('drift_alerts')->count())->toBe(0);
});

it('rejects an UPDATE that flips drift_alerts.state to an invalid value', function (): void {
    $id = $this->db->connection()->table('drift_alerts')->insertGetId([
        'user_id' => $this->user->id,
        'recurring_series_id' => $this->series,
        'state' => 'open',
        'direction' => 'expense',
        'baseline_amount_minor' => -1099,
        'latest_amount_minor' => -1349,
        'currency' => 'EUR',
        'delta_minor' => -250,
        'annualized_impact_minor' => -3000,
        'threshold_percent_used' => 5,
        'threshold_source' => 'global',
        'latest_occurrence_id' => $this->occurrence,
        'detected_at' => '2026-05-19 00:00:00',
        'created_at' => '2026-05-19 00:00:00',
        'updated_at' => '2026-05-19 00:00:00',
    ]);

    $caught = null;
    try {
        $this->db->connection()->table('drift_alerts')
            ->where('id', $id)
            ->update(['state' => 'wrong']);
    } catch (QueryException $e) {
        $caught = $e;
    }

    expect($caught)->not->toBeNull();
    expect($caught?->getMessage())->toContain('Invalid drift_alerts.state value');

    $row = $this->db->connection()->table('drift_alerts')->where('id', $id)->first();
    expect($row?->state)->toBe('open');
});

it('enforces UNIQUE(recurring_series_id, latest_occurrence_id) on drift_alerts', function (): void {
    $base = [
        'user_id' => $this->user->id,
        'recurring_series_id' => $this->series,
        'state' => 'open',
        'direction' => 'expense',
        'baseline_amount_minor' => -1099,
        'latest_amount_minor' => -1349,
        'currency' => 'EUR',
        'delta_minor' => -250,
        'annualized_impact_minor' => -3000,
        'threshold_percent_used' => 5,
        'threshold_source' => 'global',
        'latest_occurrence_id' => $this->occurrence,
        'detected_at' => '2026-05-19 00:00:00',
        'created_at' => '2026-05-19 00:00:00',
        'updated_at' => '2026-05-19 00:00:00',
    ];

    $this->db->connection()->table('drift_alerts')->insert($base);

    $caught = null;
    try {
        $this->db->connection()->table('drift_alerts')->insert($base);
    } catch (QueryException $e) {
        $caught = $e;
    }

    expect($caught)->not->toBeNull();
    expect($this->db->connection()->table('drift_alerts')->count())->toBe(1);
});

it('registers the four documented indexes on drift_alerts', function (): void {
    $indexNames = collect($this->db->connection()->select(
        "SELECT name FROM sqlite_master WHERE type='index' AND tbl_name='drift_alerts'",
    ))
        ->pluck('name')
        ->map(static fn ($name): string => is_string($name) ? $name : (string) $name)
        ->all();

    expect($indexNames)->toContain('drift_alerts_uniq');

    $userStateIndex = collect($indexNames)
        ->first(static fn (string $name): bool => str_contains($name, 'drift_alerts')
            && str_contains($name, 'user_id')
            && str_contains($name, 'state'));
    expect($userStateIndex)->not->toBeNull();

    $userStateDetectedIndex = collect($indexNames)
        ->first(static fn (string $name): bool => str_contains($name, 'drift_alerts')
            && str_contains($name, 'detected_at'));
    expect($userStateDetectedIndex)->not->toBeNull();

    $userSeriesStateIndex = collect($indexNames)
        ->first(static fn (string $name): bool => str_contains($name, 'drift_alerts')
            && str_contains($name, 'recurring_series_id'));
    expect($userSeriesStateIndex)->not->toBeNull();
});

it('accepts every documented drift_alerts.state enum value', function (string $state): void {
    $id = $this->db->connection()->table('drift_alerts')->insertGetId([
        'user_id' => $this->user->id,
        'recurring_series_id' => $this->series,
        'state' => $state,
        'direction' => 'expense',
        'baseline_amount_minor' => -1099,
        'latest_amount_minor' => -1349,
        'currency' => 'EUR',
        'delta_minor' => -250,
        'annualized_impact_minor' => -3000,
        'threshold_percent_used' => 5,
        'threshold_source' => 'global',
        'latest_occurrence_id' => $this->occurrence,
        'detected_at' => '2026-05-19 00:00:00',
        'created_at' => '2026-05-19 00:00:00',
        'updated_at' => '2026-05-19 00:00:00',
    ]);

    $row = $this->db->connection()->table('drift_alerts')->where('id', $id)->first();
    expect($row?->state)->toBe($state);
})->with([
    'open',
    'acknowledged',
    'snoozed',
    'dismissed_cancelled',
]);

function seedDriftAlertTransaction(DatabaseManager $db, int $userId): int
{
    $accountId = $db->connection()->table('accounts')->insertGetId([
        'user_id' => $userId,
        'name' => 'ASN test',
        'slug' => 'drift-mig-asn-'.bin2hex(random_bytes(4)),
        'kind' => 'bank',
        'iban' => 'NL00ASNB',
        'default_currency' => 'EUR',
        'created_at' => '2026-05-19 00:00:00',
        'updated_at' => '2026-05-19 00:00:00',
    ]);

    $runId = $db->connection()->table('import_runs')->insertGetId([
        'user_id' => $userId,
        'source_format' => 'asn-csv',
        'raw_file_path' => '/tmp/drift-mig.csv',
        'sha256' => str_repeat('a', 64),
        'uploaded_at' => '2026-05-19 00:00:00',
        'status' => 'previewed',
        'created_at' => '2026-05-19 00:00:00',
        'updated_at' => '2026-05-19 00:00:00',
    ]);

    return $db->connection()->table('transactions')->insertGetId([
        'user_id' => $userId,
        'account_id' => $accountId,
        'import_run_id' => $runId,
        'fingerprint' => str_repeat('b', 64),
        'posted_at' => '2026-05-15',
        'booked_at' => '2026-05-15 00:00:00',
        'value_date' => '2026-05-15',
        'amount_minor' => -1099,
        'currency' => 'EUR',
        'settled_amount_minor' => -1099,
        'settled_currency' => 'EUR',
        'counterparty_normalized' => 'netflix',
        'counterparty_name' => 'NETFLIX.COM',
        'normalization_version' => 1,
        'description' => 'drift mig fixture',
        'type' => 'expense',
        'source_format' => 'asn-csv',
        'source_row_index' => 1,
        'fingerprint_version' => 3,
        'created_at' => '2026-05-19 00:00:00',
        'updated_at' => '2026-05-19 00:00:00',
    ]);
}
