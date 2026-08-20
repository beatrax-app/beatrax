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
        'username' => 'system-alerts-mig',
        'password' => 'fixture-password',
        'period_start_day' => 1,
        'default_currency_view' => 'eur_only',
    ]);
});

it('creates the system_alerts table with every required column', function (): void {
    $schema = $this->db->connection()->getSchemaBuilder();

    expect($schema->hasTable('system_alerts'))->toBeTrue();

    $columns = [
        'id', 'user_id', 'kind', 'severity', 'message',
        'metadata', 'created_at', 'acknowledged_at',
    ];

    foreach ($columns as $column) {
        expect($schema->hasColumn('system_alerts', $column))->toBeTrue(
            "Expected system_alerts column '{$column}' to exist",
        );
    }
});

it('registers the two documented indexes on system_alerts', function (): void {
    $indexNames = collect($this->db->connection()->select(
        "SELECT name FROM sqlite_master WHERE type='index' AND tbl_name='system_alerts'",
    ))
        ->pluck('name')
        ->map(static fn ($name): string => is_string($name) ? $name : (string) $name)
        ->all();

    $userAckIndex = collect($indexNames)
        ->first(static fn (string $name): bool => str_contains($name, 'system_alerts')
            && str_contains($name, 'user_id')
            && str_contains($name, 'acknowledged_at'));
    expect($userAckIndex)->not->toBeNull('expected (user_id, acknowledged_at) index');

    $kindAckIndex = collect($indexNames)
        ->first(static fn (string $name): bool => str_contains($name, 'system_alerts')
            && str_contains($name, 'kind')
            && str_contains($name, 'acknowledged_at'));
    expect($kindAckIndex)->not->toBeNull('expected (kind, acknowledged_at) index');
});

it('rejects an out-of-band INSERT carrying an invalid system_alerts.severity value', function (string $severity): void {
    $caught = null;
    try {
        $this->db->connection()->table('system_alerts')->insert([
            'user_id' => $this->user->id,
            'kind' => 'backup_corrupt',
            'severity' => $severity,
            'message' => 'should be rejected',
            'metadata' => null,
            'created_at' => '2026-05-20 00:00:00',
            'acknowledged_at' => null,
        ]);
    } catch (QueryException $e) {
        $caught = $e;
    }

    expect($caught)->not->toBeNull();
    expect($caught?->getMessage())->toContain('Invalid system_alerts.severity value');
    expect($this->db->connection()->table('system_alerts')->count())->toBe(0);
})->with([
    'invalid',
    '',
    'CRITICAL',
    'urgent',
]);

it('accepts every documented system_alerts.severity enum value', function (string $severity): void {
    $id = $this->db->connection()->table('system_alerts')->insertGetId([
        'user_id' => $this->user->id,
        'kind' => 'backup_corrupt',
        'severity' => $severity,
        'message' => 'fixture',
        'metadata' => null,
        'created_at' => '2026-05-20 00:00:00',
        'acknowledged_at' => null,
    ]);

    $row = $this->db->connection()->table('system_alerts')->where('id', $id)->first();
    expect($row?->severity)->toBe($severity);
})->with([
    'info',
    'warning',
    'critical',
]);

it('rejects an UPDATE that flips system_alerts.severity to an invalid value', function (): void {
    $id = $this->db->connection()->table('system_alerts')->insertGetId([
        'user_id' => $this->user->id,
        'kind' => 'backup_overdue',
        'severity' => 'warning',
        'message' => 'fixture',
        'metadata' => null,
        'created_at' => '2026-05-20 00:00:00',
        'acknowledged_at' => null,
    ]);

    $caught = null;
    try {
        $this->db->connection()->table('system_alerts')
            ->where('id', $id)
            ->update(['severity' => 'extreme']);
    } catch (QueryException $e) {
        $caught = $e;
    }

    expect($caught)->not->toBeNull();
    expect($caught?->getMessage())->toContain('Invalid system_alerts.severity value');

    $row = $this->db->connection()->table('system_alerts')->where('id', $id)->first();
    expect($row?->severity)->toBe('warning');
});

it('allows a system-wide alert with user_id NULL', function (): void {
    $id = $this->db->connection()->table('system_alerts')->insertGetId([
        'user_id' => null,
        'kind' => 'wal_mode_missing',
        'severity' => 'warning',
        'message' => 'PRAGMA journal_mode is not WAL',
        'metadata' => null,
        'created_at' => '2026-05-20 00:00:00',
        'acknowledged_at' => null,
    ]);

    $row = $this->db->connection()->table('system_alerts')->where('id', $id)->first();
    expect($row)->not->toBeNull();
    expect($row?->user_id)->toBeNull();
});

it('cascades a system_alerts row delete when the owning user is deleted', function (): void {
    $this->db->connection()->table('system_alerts')->insert([
        'user_id' => $this->user->id,
        'kind' => 'backup_corrupt',
        'severity' => 'critical',
        'message' => 'fixture',
        'metadata' => null,
        'created_at' => '2026-05-20 00:00:00',
        'acknowledged_at' => null,
    ]);

    expect($this->db->connection()->table('system_alerts')->count())->toBe(1);

    $this->user->delete();

    expect($this->db->connection()->table('system_alerts')->count())->toBe(0);
});
