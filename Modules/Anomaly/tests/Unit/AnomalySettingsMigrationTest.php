<?php

declare(strict_types=1);

use Illuminate\Database\DatabaseManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Core\Models\User;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    /** @var DatabaseManager $db */
    $db = $this->app->make(DatabaseManager::class);
    $this->db = $db;
});

it('adds users.anomaly_sensitivity_percent with default 50 and integer cast', function (): void {
    $schema = $this->db->connection()->getSchemaBuilder();
    expect($schema->hasColumn('users', 'anomaly_sensitivity_percent'))->toBeTrue();

    $created = User::query()->create([
        'username' => 'anomaly-sensitivity-default',
        'password' => 'fixture-password',
        'period_start_day' => 1,
        'default_currency_view' => 'eur_only',
    ]);

    $fresh = User::query()->findOrFail($created->id);
    expect($fresh->anomaly_sensitivity_percent)->toBe(50);
    expect($fresh->anomaly_sensitivity_percent)->toBeInt();
});

it('adds users.anomaly_min_amount_minor with default 1000 and integer cast', function (): void {
    $schema = $this->db->connection()->getSchemaBuilder();
    expect($schema->hasColumn('users', 'anomaly_min_amount_minor'))->toBeTrue();

    $created = User::query()->create([
        'username' => 'anomaly-floor-default',
        'password' => 'fixture-password',
        'period_start_day' => 1,
        'default_currency_view' => 'eur_only',
    ]);

    $fresh = User::query()->findOrFail($created->id);
    expect($fresh->anomaly_min_amount_minor)->toBe(1000);
    expect($fresh->anomaly_min_amount_minor)->toBeInt();
});

it('adds users.anomaly_backfilled_at as a nullable column defaulting to null', function (): void {
    $schema = $this->db->connection()->getSchemaBuilder();
    expect($schema->hasColumn('users', 'anomaly_backfilled_at'))->toBeTrue();

    $created = User::query()->create([
        'username' => 'anomaly-backfill-null',
        'password' => 'fixture-password',
        'period_start_day' => 1,
        'default_currency_view' => 'eur_only',
    ]);

    $row = $this->db->connection()->table('users')->where('id', $created->id)->first();
    expect($row?->anomaly_backfilled_at)->toBeNull();
});

it('respects explicit anomaly settings overrides on insert', function (): void {
    $created = User::query()->create([
        'username' => 'anomaly-overrides',
        'password' => 'fixture-password',
        'period_start_day' => 1,
        'default_currency_view' => 'eur_only',
        'anomaly_sensitivity_percent' => 80,
        'anomaly_min_amount_minor' => 2500,
    ]);

    $fresh = User::query()->findOrFail($created->id);
    expect($fresh->anomaly_sensitivity_percent)->toBe(80);
    expect($fresh->anomaly_min_amount_minor)->toBe(2500);
});

it('creates the anomaly_suppression_rules table with every required column', function (): void {
    $schema = $this->db->connection()->getSchemaBuilder();

    expect($schema->hasTable('anomaly_suppression_rules'))->toBeTrue();

    $columns = [
        'id', 'user_id', 'counterparty_id', 'detector', 'direction',
        'amount_band_low_minor', 'amount_band_high_minor', 'currency',
        'source_anomaly_alert_id', 'created_at', 'updated_at',
    ];

    foreach ($columns as $column) {
        expect($schema->hasColumn('anomaly_suppression_rules', $column))->toBeTrue(
            "Expected anomaly_suppression_rules column '{$column}' to exist",
        );
    }
});

it('registers the (user_id, counterparty_id, detector) index on anomaly_suppression_rules', function (): void {
    $indexNames = collect($this->db->connection()->select(
        "SELECT name FROM sqlite_master WHERE type='index' AND tbl_name='anomaly_suppression_rules'",
    ))
        ->pluck('name')
        ->map(static fn ($name): string => is_string($name) ? $name : (string) $name)
        ->all();

    $match = collect($indexNames)
        ->first(static fn (string $name): bool => str_contains($name, 'anomaly_suppression_rules')
            && str_contains($name, 'user_id')
            && str_contains($name, 'counterparty_id')
            && str_contains($name, 'detector'));

    expect($match)->not->toBeNull();
});
