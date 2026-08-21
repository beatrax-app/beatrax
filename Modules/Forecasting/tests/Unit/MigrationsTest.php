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
        'username' => 'forecast-mig',
        'password' => 'fixture-password',
        'period_start_day' => 1,
        'default_currency_view' => 'eur_only',
    ]);
});

it('creates the forecast_scenarios table with every required column', function (): void {
    $schema = $this->db->connection()->getSchemaBuilder();

    expect($schema->hasTable('forecast_scenarios'))->toBeTrue();

    $columns = ['id', 'user_id', 'name', 'description', 'created_at', 'updated_at'];
    foreach ($columns as $column) {
        expect($schema->hasColumn('forecast_scenarios', $column))->toBeTrue(
            "Expected forecast_scenarios column '{$column}' to exist",
        );
    }
});

it('enforces UNIQUE(user_id, name) on forecast_scenarios', function (): void {
    $base = [
        'user_id' => $this->user->id,
        'name' => 'My scenario',
        'description' => null,
        'created_at' => '2026-05-19 00:00:00',
        'updated_at' => '2026-05-19 00:00:00',
    ];

    $this->db->connection()->table('forecast_scenarios')->insert($base);

    $caught = null;
    try {
        $this->db->connection()->table('forecast_scenarios')->insert($base);
    } catch (QueryException $e) {
        $caught = $e;
    }

    expect($caught)->not->toBeNull();
    expect($this->db->connection()->table('forecast_scenarios')->count())->toBe(1);
});

it('creates the forecast_scenario_mutations table with every required column', function (): void {
    $schema = $this->db->connection()->getSchemaBuilder();

    expect($schema->hasTable('forecast_scenario_mutations'))->toBeTrue();

    $columns = [
        'id', 'user_id', 'forecast_scenario_id', 'kind',
        'target_series_id', 'payload', 'created_at', 'updated_at',
    ];
    foreach ($columns as $column) {
        expect($schema->hasColumn('forecast_scenario_mutations', $column))->toBeTrue(
            "Expected forecast_scenario_mutations column '{$column}' to exist",
        );
    }
});

it('creates the forecast_shortfall_windows table with every required column', function (): void {
    $schema = $this->db->connection()->getSchemaBuilder();

    expect($schema->hasTable('forecast_shortfall_windows'))->toBeTrue();

    $columns = [
        'id', 'user_id', 'account_id', 'scenario_id',
        'starts_at', 'ends_at', 'lowest_balance_minor', 'currency',
        'buffer_used_minor', 'created_at', 'updated_at',
    ];
    foreach ($columns as $column) {
        expect($schema->hasColumn('forecast_shortfall_windows', $column))->toBeTrue(
            "Expected forecast_shortfall_windows column '{$column}' to exist",
        );
    }
});

it('preserves signed bigInteger semantics on forecast_shortfall_windows.lowest_balance_minor and buffer_used_minor', function (): void {
    // Seed an accounts row to satisfy the FK.
    $accountId = $this->db->connection()->table('accounts')->insertGetId([
        'user_id' => $this->user->id,
        'name' => 'forecast-mig asn',
        'slug' => 'forecast-mig-asn',
        'kind' => 'bank',
        'iban' => 'NL00FORECASTMIG',
        'default_currency' => 'EUR',
        'created_at' => '2026-05-19 00:00:00',
        'updated_at' => '2026-05-19 00:00:00',
    ]);

    $rowId = $this->db->connection()->table('forecast_shortfall_windows')->insertGetId([
        'user_id' => $this->user->id,
        'account_id' => $accountId,
        'scenario_id' => null,
        'starts_at' => '2026-06-01',
        'ends_at' => '2026-06-10',
        'lowest_balance_minor' => -1234567890,
        'currency' => 'EUR',
        'buffer_used_minor' => 50000,
        'created_at' => '2026-05-19 00:00:00',
        'updated_at' => '2026-05-19 00:00:00',
    ]);

    $row = $this->db->connection()->table('forecast_shortfall_windows')->where('id', $rowId)->first();
    expect($row)->not->toBeNull();
    expect((int) $row->lowest_balance_minor)->toBe(-1234567890);
    expect((int) $row->buffer_used_minor)->toBe(50000);
});

it('creates the forecast_runs table with every required column', function (): void {
    $schema = $this->db->connection()->getSchemaBuilder();

    expect($schema->hasTable('forecast_runs'))->toBeTrue();

    $columns = [
        'id', 'user_id', 'scenario_id', 'horizon_days',
        'started_at', 'completed_at', 'status',
        'created_at', 'updated_at',
    ];
    foreach ($columns as $column) {
        expect($schema->hasColumn('forecast_runs', $column))->toBeTrue(
            "Expected forecast_runs column '{$column}' to exist",
        );
    }
});

it('rejects NULL user_id on forecast_runs (NOT NULL constraint)', function (): void {
    $caught = null;
    try {
        $this->db->connection()->table('forecast_runs')->insert([
            'user_id' => null,
            'scenario_id' => null,
            'horizon_days' => 30,
            'status' => 'pending',
            'started_at' => null,
            'completed_at' => null,
            'created_at' => '2026-05-19 00:00:00',
            'updated_at' => '2026-05-19 00:00:00',
        ]);
    } catch (QueryException $e) {
        $caught = $e;
    }

    expect($caught)->not->toBeNull();
    expect($this->db->connection()->table('forecast_runs')->count())->toBe(0);
});

it('rejects NULL user_id on forecast_scenarios (NOT NULL constraint)', function (): void {
    $caught = null;
    try {
        $this->db->connection()->table('forecast_scenarios')->insert([
            'user_id' => null,
            'name' => 'orphan',
            'description' => null,
            'created_at' => '2026-05-19 00:00:00',
            'updated_at' => '2026-05-19 00:00:00',
        ]);
    } catch (QueryException $e) {
        $caught = $e;
    }

    expect($caught)->not->toBeNull();
    expect($this->db->connection()->table('forecast_scenarios')->count())->toBe(0);
});

it('rejects an unknown kind on forecast_scenario_mutations via the kind-enum trigger', function (): void {
    $scenarioId = $this->db->connection()->table('forecast_scenarios')->insertGetId([
        'user_id' => $this->user->id,
        'name' => 'kind-trigger-parent',
        'description' => null,
        'created_at' => '2026-05-19 00:00:00',
        'updated_at' => '2026-05-19 00:00:00',
    ]);

    $caught = null;
    try {
        $this->db->connection()->table('forecast_scenario_mutations')->insert([
            'user_id' => $this->user->id,
            'forecast_scenario_id' => $scenarioId,
            'kind' => 'definitely_not_allowed',
            'target_series_id' => null,
            'payload' => json_encode(['kind' => 'definitely_not_allowed']),
            'created_at' => '2026-05-19 00:00:00',
            'updated_at' => '2026-05-19 00:00:00',
        ]);
    } catch (QueryException $e) {
        $caught = $e;
    }

    expect($caught)->not->toBeNull();
    expect($this->db->connection()->table('forecast_scenario_mutations')->count())->toBe(0);
});

it('rejects NULL user_id on forecast_scenario_mutations (NOT NULL constraint)', function (): void {
    // A satisfiable FK keeps the NOT NULL on user_id as the only thing that
    // can fail the insert below.
    $scenarioId = $this->db->connection()->table('forecast_scenarios')->insertGetId([
        'user_id' => $this->user->id,
        'name' => 'parent',
        'description' => null,
        'created_at' => '2026-05-19 00:00:00',
        'updated_at' => '2026-05-19 00:00:00',
    ]);

    $caught = null;
    try {
        $this->db->connection()->table('forecast_scenario_mutations')->insert([
            'user_id' => null,
            'forecast_scenario_id' => $scenarioId,
            'kind' => 'cancel_series',
            'target_series_id' => null,
            'payload' => json_encode(['kind' => 'cancel_series', 'seriesId' => 1]),
            'created_at' => '2026-05-19 00:00:00',
            'updated_at' => '2026-05-19 00:00:00',
        ]);
    } catch (QueryException $e) {
        $caught = $e;
    }

    expect($caught)->not->toBeNull();
    expect($this->db->connection()->table('forecast_scenario_mutations')->count())->toBe(0);
});

it('rejects NULL user_id on forecast_shortfall_windows (NOT NULL constraint)', function (): void {
    // Seed a parent account so the FK is satisfiable.
    $accountId = $this->db->connection()->table('accounts')->insertGetId([
        'user_id' => $this->user->id,
        'name' => 'mig-test',
        'slug' => 'mig-test',
        'kind' => 'bank',
        'iban' => 'MIGTEST123',
        'default_currency' => 'EUR',
        'created_at' => '2026-05-19 00:00:00',
        'updated_at' => '2026-05-19 00:00:00',
    ]);

    $caught = null;
    try {
        $this->db->connection()->table('forecast_shortfall_windows')->insert([
            'user_id' => null,
            'account_id' => $accountId,
            'scenario_id' => null,
            'starts_at' => '2026-05-19',
            'ends_at' => '2026-05-20',
            'lowest_balance_minor' => -100,
            'currency' => 'EUR',
            'buffer_used_minor' => 0,
            'created_at' => '2026-05-19 00:00:00',
            'updated_at' => '2026-05-19 00:00:00',
        ]);
    } catch (QueryException $e) {
        $caught = $e;
    }

    expect($caught)->not->toBeNull();
    expect($this->db->connection()->table('forecast_shortfall_windows')->count())->toBe(0);
});

it('adds three nullable forecast columns to accounts without breaking existing inserts', function (): void {
    $schema = $this->db->connection()->getSchemaBuilder();

    expect($schema->hasColumn('accounts', 'forecast_min_buffer_minor'))->toBeTrue();
    expect($schema->hasColumn('accounts', 'opening_balance_minor'))->toBeTrue();
    expect($schema->hasColumn('accounts', 'opening_balance_as_of_date'))->toBeTrue();

    // Every pre-existing accounts writer omits the three new columns, so this
    // insert failing would mean the migration broke them.
    $accountId = $this->db->connection()->table('accounts')->insertGetId([
        'user_id' => $this->user->id,
        'name' => 'phase1-9 compat',
        'slug' => 'phase1-9-compat',
        'kind' => 'bank',
        'iban' => 'NL00COMPAT',
        'default_currency' => 'EUR',
        'created_at' => '2026-05-19 00:00:00',
        'updated_at' => '2026-05-19 00:00:00',
    ]);

    $row = $this->db->connection()->table('accounts')->where('id', $accountId)->first();
    expect($row)->not->toBeNull();
    expect($row->forecast_min_buffer_minor)->toBeNull();
    expect($row->opening_balance_minor)->toBeNull();
    expect($row->opening_balance_as_of_date)->toBeNull();
});

it('accepts forecast/opening-balance values on accounts when supplied', function (): void {
    $accountId = $this->db->connection()->table('accounts')->insertGetId([
        'user_id' => $this->user->id,
        'name' => 'with buffer',
        'slug' => 'with-buffer',
        'kind' => 'bank',
        'iban' => 'NL00WITHBUF',
        'default_currency' => 'EUR',
        'forecast_min_buffer_minor' => 5000_00,
        'opening_balance_minor' => 1234_56,
        'opening_balance_as_of_date' => '2026-05-01',
        'created_at' => '2026-05-19 00:00:00',
        'updated_at' => '2026-05-19 00:00:00',
    ]);

    $row = $this->db->connection()->table('accounts')->where('id', $accountId)->first();
    expect($row)->not->toBeNull();
    expect((int) $row->forecast_min_buffer_minor)->toBe(500000);
    expect((int) $row->opening_balance_minor)->toBe(123456);
    expect((string) $row->opening_balance_as_of_date)->toBe('2026-05-01');
});

it('registers the documented indexes on the four new tables', function (): void {
    $indexNamesFor = function (string $table): array {
        return collect($this->db->connection()->select(
            "SELECT name FROM sqlite_master WHERE type='index' AND tbl_name=?",
            [$table],
        ))
            ->pluck('name')
            ->map(static fn ($name): string => is_string($name) ? $name : (string) $name)
            ->all();
    };

    $scenarios = $indexNamesFor('forecast_scenarios');
    expect(collect($scenarios)->first(static fn (string $name): bool => str_contains($name, 'user_id')
        && str_contains($name, 'name')))->not->toBeNull();
    expect(collect($scenarios)->first(static fn (string $name): bool => str_contains($name, 'user_id')
        && str_contains($name, 'created_at')))->not->toBeNull();

    $mutations = $indexNamesFor('forecast_scenario_mutations');
    expect(collect($mutations)->first(static fn (string $name): bool => str_contains($name, 'user_id')
        && str_contains($name, 'forecast_scenario_id')))->not->toBeNull();
    expect(collect($mutations)->first(static fn (string $name): bool => str_contains($name, 'forecast_scenario_mutations')
        && str_contains($name, 'kind')))->not->toBeNull();

    $shortfalls = $indexNamesFor('forecast_shortfall_windows');
    expect(collect($shortfalls)->first(static fn (string $name): bool => str_contains($name, 'account_id')
        && str_contains($name, 'starts_at')))->not->toBeNull();
    expect(collect($shortfalls)->first(static fn (string $name): bool => str_contains($name, 'scenario_id')))->not->toBeNull();
    expect(collect($shortfalls)->first(static fn (string $name): bool => str_contains($name, 'ends_at')))->not->toBeNull();

    $runs = $indexNamesFor('forecast_runs');
    expect(collect($runs)->first(static fn (string $name): bool => str_contains($name, 'horizon_days')
        && str_contains($name, 'status')))->not->toBeNull();
    expect(collect($runs)->first(static fn (string $name): bool => str_contains($name, 'started_at')))->not->toBeNull();
});

it('forbids facade imports inside the five Phase 10 migrations', function (): void {
    $migrations = glob(__DIR__.'/../../Database/Migrations/*.php');
    expect($migrations)->not->toBeEmpty();

    foreach ($migrations as $file) {
        $contents = (string) file_get_contents($file);
        $stripped = preg_replace('#/\*.*?\*/|//[^\n]*#s', '', $contents) ?? $contents;
        expect($stripped)->not->toContain(
            'use Illuminate\\Support\\Facades\\',
            "Migration {$file} must not import any facade.",
        );
    }
});
