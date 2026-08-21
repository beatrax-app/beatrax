<?php

declare(strict_types=1);

use Illuminate\Database\DatabaseManager;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Core\Models\SystemAlert;
use Modules\Core\Models\User;
use Modules\Core\Public\Enums\SystemAlertSeverity;
use Modules\Core\Public\Services\SystemAlertQuery;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    /** @var DatabaseManager $db */
    $db = $this->app->make(DatabaseManager::class);
    $this->db = $db;

    $this->userA = User::query()->create([
        'username' => 'saq-a',
        'password' => 'fixture',
        'period_start_day' => 1,
        'default_currency_view' => 'eur_only',
    ]);
    $this->userB = User::query()->create([
        'username' => 'saq-b',
        'password' => 'fixture',
        'period_start_day' => 1,
        'default_currency_view' => 'eur_only',
    ]);
});

it('scopes active() to the caller user — never returns another user\'s alerts', function (): void {
    $this->db->connection()->table('system_alerts')->insert([
        [
            'user_id' => $this->userA->id,
            'kind' => 'backup_corrupt',
            'severity' => 'critical',
            'message' => 'A1',
            'metadata' => null,
            'created_at' => '2026-05-20 01:00:00',
            'acknowledged_at' => null,
        ],
        [
            'user_id' => $this->userB->id,
            'kind' => 'backup_corrupt',
            'severity' => 'critical',
            'message' => 'B1',
            'metadata' => null,
            'created_at' => '2026-05-20 02:00:00',
            'acknowledged_at' => null,
        ],
    ]);

    /** @var SystemAlertQuery $query */
    $query = $this->app->make(SystemAlertQuery::class);

    $messages = $query->active($this->userA)->pluck('message')->all();

    expect($messages)->toBe(['A1']);
});

it('includes system-wide (user_id IS NULL) rows alongside the caller\'s own rows in active()', function (): void {
    $this->db->connection()->table('system_alerts')->insert([
        [
            'user_id' => $this->userA->id,
            'kind' => 'backup_corrupt',
            'severity' => 'critical',
            'message' => 'A-personal',
            'metadata' => null,
            'created_at' => '2026-05-20 01:00:00',
            'acknowledged_at' => null,
        ],
        [
            'user_id' => null,
            'kind' => 'wal_mode_missing',
            'severity' => 'warning',
            'message' => 'system-wide',
            'metadata' => null,
            'created_at' => '2026-05-20 02:00:00',
            'acknowledged_at' => null,
        ],
        [
            'user_id' => $this->userB->id,
            'kind' => 'backup_corrupt',
            'severity' => 'critical',
            'message' => 'B-other',
            'metadata' => null,
            'created_at' => '2026-05-20 03:00:00',
            'acknowledged_at' => null,
        ],
    ]);

    /** @var SystemAlertQuery $query */
    $query = $this->app->make(SystemAlertQuery::class);

    $messages = $query->active($this->userA)->pluck('message')->all();

    sort($messages);
    expect($messages)->toBe(['A-personal', 'system-wide']);
});

it('sorts active() rows critical → warning → info, ordering by created_at ascending within each tier', function (): void {
    // Shuffled severities with spread created_at, so a severity-first sort
    // cannot be mistaken for a chronological accident.
    $this->db->connection()->table('system_alerts')->insert([
        [
            'user_id' => $this->userA->id,
            'kind' => 'wal_mode_missing',
            'severity' => 'warning',
            'message' => 'W1',
            'metadata' => null,
            'created_at' => '2026-05-20 02:00:00',
            'acknowledged_at' => null,
        ],
        [
            'user_id' => $this->userA->id,
            'kind' => 'backup_corrupt',
            'severity' => 'critical',
            'message' => 'C1',
            'metadata' => null,
            'created_at' => '2026-05-20 03:00:00',
            'acknowledged_at' => null,
        ],
        [
            'user_id' => $this->userA->id,
            'kind' => 'wal_mode_missing',
            'severity' => 'info',
            'message' => 'I1',
            'metadata' => null,
            'created_at' => '2026-05-20 01:00:00',
            'acknowledged_at' => null,
        ],
        [
            'user_id' => $this->userA->id,
            'kind' => 'backup_corrupt',
            'severity' => 'critical',
            'message' => 'C0',
            'metadata' => null,
            'created_at' => '2026-05-20 02:30:00',
            'acknowledged_at' => null,
        ],
    ]);

    /** @var SystemAlertQuery $query */
    $query = $this->app->make(SystemAlertQuery::class);
    $messages = $query->active($this->userA)->pluck('message')->all();

    // Critical by created_at (C0 then C1), then warning, then info.
    expect($messages)->toBe(['C0', 'C1', 'W1', 'I1']);
});

it('omits acknowledged rows from active()', function (): void {
    $this->db->connection()->table('system_alerts')->insert([
        [
            'user_id' => $this->userA->id,
            'kind' => 'backup_corrupt',
            'severity' => 'critical',
            'message' => 'still active',
            'metadata' => null,
            'created_at' => '2026-05-20 01:00:00',
            'acknowledged_at' => null,
        ],
        [
            'user_id' => $this->userA->id,
            'kind' => 'backup_corrupt',
            'severity' => 'critical',
            'message' => 'already acked',
            'metadata' => null,
            'created_at' => '2026-05-20 02:00:00',
            'acknowledged_at' => '2026-05-20 03:00:00',
        ],
    ]);

    /** @var SystemAlertQuery $query */
    $query = $this->app->make(SystemAlertQuery::class);
    $messages = $query->active($this->userA)->pluck('message')->all();

    expect($messages)->toBe(['still active']);
});

it('returns an Eloquent Collection of SystemAlert instances from active()', function (): void {
    $this->db->connection()->table('system_alerts')->insert([
        'user_id' => $this->userA->id,
        'kind' => 'backup_corrupt',
        'severity' => 'critical',
        'message' => 'fixture',
        'metadata' => null,
        'created_at' => '2026-05-20 01:00:00',
        'acknowledged_at' => null,
    ]);

    /** @var SystemAlertQuery $query */
    $query = $this->app->make(SystemAlertQuery::class);
    $collection = $query->active($this->userA);

    expect($collection)->toBeInstanceOf(Collection::class);
    expect($collection->first())->toBeInstanceOf(SystemAlert::class);
});

it('count() returns the per-user-or-system-wide active row count', function (): void {
    $this->db->connection()->table('system_alerts')->insert([
        [
            'user_id' => $this->userA->id,
            'kind' => 'backup_corrupt',
            'severity' => 'critical',
            'message' => 'A',
            'metadata' => null,
            'created_at' => '2026-05-20 01:00:00',
            'acknowledged_at' => null,
        ],
        [
            'user_id' => null,
            'kind' => 'wal_mode_missing',
            'severity' => 'warning',
            'message' => 'system',
            'metadata' => null,
            'created_at' => '2026-05-20 02:00:00',
            'acknowledged_at' => null,
        ],
        [
            'user_id' => $this->userB->id,
            'kind' => 'backup_corrupt',
            'severity' => 'critical',
            'message' => 'B',
            'metadata' => null,
            'created_at' => '2026-05-20 03:00:00',
            'acknowledged_at' => null,
        ],
        [
            'user_id' => $this->userA->id,
            'kind' => 'backup_corrupt',
            'severity' => 'critical',
            'message' => 'A-acked',
            'metadata' => null,
            'created_at' => '2026-05-20 04:00:00',
            'acknowledged_at' => '2026-05-20 05:00:00',
        ],
    ]);

    /** @var SystemAlertQuery $query */
    $query = $this->app->make(SystemAlertQuery::class);

    expect($query->count($this->userA))->toBe(2);
});

it('active(null) returns ONLY system-wide rows when no user is supplied', function (): void {
    $this->db->connection()->table('system_alerts')->insert([
        [
            'user_id' => $this->userA->id,
            'kind' => 'backup_corrupt',
            'severity' => 'critical',
            'message' => 'A-personal',
            'metadata' => null,
            'created_at' => '2026-05-20 01:00:00',
            'acknowledged_at' => null,
        ],
        [
            'user_id' => null,
            'kind' => 'wal_mode_missing',
            'severity' => 'warning',
            'message' => 'system-only',
            'metadata' => null,
            'created_at' => '2026-05-20 02:00:00',
            'acknowledged_at' => null,
        ],
    ]);

    /** @var SystemAlertQuery $query */
    $query = $this->app->make(SystemAlertQuery::class);
    $messages = $query->active(null)->pluck('message')->all();

    expect($messages)->toBe(['system-only']);
});

// The banner order used to name the severities as text inside the orderByRaw,
// so renaming a case value would have left every row of that severity falling
// through the ELSE arm — a critical row displayed under a warning, with nothing
// failing. Sourcing them as bindings makes the SQL follow the enum by itself.
it('sends the severity spellings to SQLite as bindings taken from the enum, never spliced into the ORDER BY text', function (): void {
    $captured = [];
    $this->db->connection()->listen(function (QueryExecuted $query) use (&$captured): void {
        $captured[] = $query;
    });

    /** @var SystemAlertQuery $query */
    $query = $this->app->make(SystemAlertQuery::class);
    $query->active($this->userA);

    $ordered = array_values(array_filter(
        $captured,
        static fn (QueryExecuted $executed): bool => str_contains($executed->sql, 'CASE severity'),
    ));

    expect($ordered)->not->toBeEmpty()
        ->and($ordered[0]->sql)->not->toContain(SystemAlertSeverity::Critical->value)
        ->and($ordered[0]->sql)->not->toContain(SystemAlertSeverity::Warning->value)
        ->and($ordered[0]->bindings)->toContain(SystemAlertSeverity::Critical->value)
        ->and($ordered[0]->bindings)->toContain(SystemAlertSeverity::Warning->value);
});
