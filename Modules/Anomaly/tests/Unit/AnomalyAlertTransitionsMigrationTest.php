<?php

declare(strict_types=1);

use Illuminate\Database\DatabaseManager;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    /** @var DatabaseManager $db */
    $db = $this->app->make(DatabaseManager::class);
    $this->db = $db;
});

it('creates the anomaly_alert_transitions table with every audit column', function (): void {
    $schema = $this->db->connection()->getSchemaBuilder();

    expect($schema->hasTable('anomaly_alert_transitions'))->toBeTrue();

    $columns = [
        'id', 'user_id', 'anomaly_alert_id', 'from_state', 'to_state',
        'transition_reason', 'actor', 'transitioned_at', 'notes',
        'created_at', 'updated_at',
    ];

    foreach ($columns as $column) {
        expect($schema->hasColumn('anomaly_alert_transitions', $column))->toBeTrue(
            "Expected anomaly_alert_transitions column '{$column}' to exist",
        );
    }
});

it('registers an index on (anomaly_alert_id, transitioned_at)', function (): void {
    $indexNames = collect($this->db->connection()->select(
        "SELECT name FROM sqlite_master WHERE type='index' AND tbl_name='anomaly_alert_transitions'",
    ))
        ->pluck('name')
        ->map(static fn ($name): string => is_string($name) ? $name : (string) $name)
        ->all();

    $match = collect($indexNames)
        ->first(static fn (string $name): bool => str_contains($name, 'anomaly_alert_transitions')
            && str_contains($name, 'anomaly_alert_id')
            && str_contains($name, 'transitioned_at'));

    expect($match)->not->toBeNull();
});

it('does not install any BEFORE INSERT / BEFORE UPDATE trigger on anomaly_alert_transitions', function (): void {
    $triggers = collect($this->db->connection()->select(
        "SELECT name FROM sqlite_master WHERE type='trigger' AND tbl_name='anomaly_alert_transitions'",
    ))
        ->pluck('name')
        ->all();

    expect($triggers)->toBeEmpty();
});
