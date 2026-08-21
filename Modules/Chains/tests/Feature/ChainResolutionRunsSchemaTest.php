<?php

declare(strict_types=1);

use Illuminate\Database\DatabaseManager;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Schema;
use Modules\Core\Models\User;

// The audit row replaces a `failed_jobs.payload LIKE '%userId:N%'` substring
// match, letting the wizard scope its poll to (user_id, latest) instead.

beforeEach(function (): void {
    /** @var DatabaseManager $db */
    $db = $this->app->make(DatabaseManager::class);
    $this->db = $db;
    $this->conn = $db->connection();

    $this->user = User::query()->create([
        'username' => 'chain-runs-schema',
        'password' => 'fixture-password',
        'period_start_day' => 1,
    ]);
});

it('creates the chain_resolution_runs table with the documented column list', function (): void {
    expect(Schema::hasTable('chain_resolution_runs'))->toBeTrue();

    $cols = Schema::getColumnListing('chain_resolution_runs');
    foreach ([
        'id', 'user_id', 'job_uuid',
        'started_at', 'completed_at', 'status',
        'linked_count', 'last_error',
        'created_at', 'updated_at',
    ] as $col) {
        expect($cols)->toContain($col);
    }
});

it('happy-path inserts a chain_resolution_runs row in pending state', function (): void {
    $this->conn->table('chain_resolution_runs')->insert([
        'user_id' => $this->user->id,
        'job_uuid' => 'aaaa-bbbb-cccc-dddd-eeee-ffff-1111-2222',
        'started_at' => null,
        'completed_at' => null,
        'status' => 'pending',
        'linked_count' => 0,
        'last_error' => null,
        'created_at' => '2026-05-16 00:00:00',
        'updated_at' => '2026-05-16 00:00:00',
    ]);
    expect($this->conn->table('chain_resolution_runs')->count())->toBe(1);
});

it('rejects a chain_resolution_runs row with an invalid status', function (): void {
    expect(fn () => $this->conn->table('chain_resolution_runs')->insert([
        'user_id' => $this->user->id,
        'job_uuid' => null,
        'started_at' => null,
        'completed_at' => null,
        'status' => 'unknown',
        'linked_count' => 0,
        'last_error' => null,
        'created_at' => '2026-05-16 00:00:00',
        'updated_at' => '2026-05-16 00:00:00',
    ]))->toThrow(QueryException::class, 'Invalid chain_resolution_runs.status value');
});

it('rejects a chain_resolution_runs row updated to an invalid status', function (): void {
    $id = (int) $this->conn->table('chain_resolution_runs')->insertGetId([
        'user_id' => $this->user->id,
        'job_uuid' => null,
        'started_at' => null,
        'completed_at' => null,
        'status' => 'pending',
        'linked_count' => 0,
        'last_error' => null,
        'created_at' => '2026-05-16 00:00:00',
        'updated_at' => '2026-05-16 00:00:00',
    ]);

    expect(fn () => $this->conn->table('chain_resolution_runs')
        ->where('id', $id)
        ->update(['status' => 'whoops']))
        ->toThrow(QueryException::class, 'Invalid chain_resolution_runs.status value');
});
