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

it('adds the nullable drift_threshold_percent column to recurring_series', function (): void {
    $schema = $this->db->connection()->getSchemaBuilder();

    expect($schema->hasColumn('recurring_series', 'drift_threshold_percent'))->toBeTrue();

    $user = User::query()->create([
        'username' => 'rec-override-null',
        'password' => 'fixture-password',
        'period_start_day' => 1,
        'default_currency_view' => 'eur_only',
    ]);

    $id = $this->db->connection()->table('recurring_series')->insertGetId([
        'user_id' => $user->id,
        'direction' => 'expense',
        'detected_name' => 'Threshold probe',
        'state' => 'approved',
        'cadence' => 'monthly',
        'latest_amount_minor' => -1099,
        'latest_currency' => 'EUR',
        'variance_tolerance_percent' => 25,
        'cluster_key' => 'threshold-probe|monthly|EUR',
        'created_at' => '2026-05-19 00:00:00',
        'updated_at' => '2026-05-19 00:00:00',
    ]);

    $row = $this->db->connection()->table('recurring_series')->where('id', $id)->first();
    expect($row?->drift_threshold_percent)->toBeNull();
});

it('lets a per-series drift_threshold_percent override carry an integer value', function (): void {
    $user = User::query()->create([
        'username' => 'rec-override-set',
        'password' => 'fixture-password',
        'period_start_day' => 1,
        'default_currency_view' => 'eur_only',
    ]);

    $id = $this->db->connection()->table('recurring_series')->insertGetId([
        'user_id' => $user->id,
        'direction' => 'expense',
        'detected_name' => 'Override probe',
        'state' => 'approved',
        'cadence' => 'monthly',
        'latest_amount_minor' => -1099,
        'latest_currency' => 'EUR',
        'variance_tolerance_percent' => 25,
        'drift_threshold_percent' => 12,
        'cluster_key' => 'override-probe|monthly|EUR',
        'created_at' => '2026-05-19 00:00:00',
        'updated_at' => '2026-05-19 00:00:00',
    ]);

    $row = $this->db->connection()->table('recurring_series')->where('id', $id)->first();
    expect((int) $row?->drift_threshold_percent)->toBe(12);
});

it('adds users.drift_alert_threshold_percent with default 5 and integer cast', function (): void {
    $schema = $this->db->connection()->getSchemaBuilder();

    expect($schema->hasColumn('users', 'drift_alert_threshold_percent'))->toBeTrue();

    $created = User::query()->create([
        'username' => 'drift-threshold-defaults',
        'password' => 'fixture-password',
        'period_start_day' => 1,
        'default_currency_view' => 'eur_only',
    ]);

    $fresh = User::query()->findOrFail($created->id);
    expect($fresh->drift_alert_threshold_percent)->toBe(5);
    expect($fresh->drift_alert_threshold_percent)->toBeInt();
});

it('respects an explicit drift_alert_threshold_percent override on insert', function (): void {
    $created = User::query()->create([
        'username' => 'drift-threshold-override',
        'password' => 'fixture-password',
        'period_start_day' => 1,
        'default_currency_view' => 'eur_only',
        'drift_alert_threshold_percent' => 20,
    ]);

    $fresh = User::query()->findOrFail($created->id);
    expect($fresh->drift_alert_threshold_percent)->toBe(20);
});
