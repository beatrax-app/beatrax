<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Database\DatabaseManager;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Core\Models\SystemAlert;
use Modules\Core\Models\User;

uses(RefreshDatabase::class);

/*
 * Locks the Eloquent surface for system_alerts rows: cast map (metadata
 * array + acknowledged_at / created_at immutable_datetime), the active()
 * + byKind() scopes that the SystemAlertQuery and the banner consumer
 * read through, and the BelongsToUser-trait-provided user() relation
 * back into Modules\Core\Models\User.
 */

beforeEach(function (): void {
    /** @var DatabaseManager $db */
    $db = $this->app->make(DatabaseManager::class);
    $this->db = $db;
});

it('casts metadata to array and acknowledged_at + created_at to immutable datetimes', function (): void {
    $user = User::query()->create([
        'email' => 'sa-model@diederik.test',
        'password' => 'fixture-password',
        'period_start_day' => 1,
        'default_currency_view' => 'eur_only',
    ]);

    $this->db->connection()->table('system_alerts')->insert([
        'user_id' => $user->id,
        'kind' => 'backup_corrupt',
        'severity' => 'critical',
        'message' => 'fixture',
        'metadata' => json_encode(['hours_old' => 49, 'path' => '/tmp/foo']),
        'created_at' => '2026-05-20 03:00:00',
        'acknowledged_at' => '2026-05-20 04:00:00',
    ]);

    /** @var SystemAlert $alert */
    $alert = SystemAlert::query()->where('kind', 'backup_corrupt')->firstOrFail();

    expect($alert->metadata)->toBe(['hours_old' => 49, 'path' => '/tmp/foo']);
    expect($alert->acknowledged_at)->toBeInstanceOf(CarbonImmutable::class);
    expect($alert->created_at)->toBeInstanceOf(CarbonImmutable::class);
});

it('exposes a user() BelongsTo relation back to Modules\\Core\\Models\\User via the BelongsToUser trait', function (): void {
    $relation = (new SystemAlert)->user();
    expect($relation)->toBeInstanceOf(BelongsTo::class);
    expect($relation->getRelated())->toBeInstanceOf(User::class);
});

it('scopeActive filters out rows whose acknowledged_at is non-null', function (): void {
    $user = User::query()->create([
        'email' => 'sa-active@diederik.test',
        'password' => 'fixture-password',
        'period_start_day' => 1,
        'default_currency_view' => 'eur_only',
    ]);

    $this->db->connection()->table('system_alerts')->insert([
        [
            'user_id' => $user->id,
            'kind' => 'backup_corrupt',
            'severity' => 'critical',
            'message' => 'still active',
            'metadata' => null,
            'created_at' => '2026-05-20 01:00:00',
            'acknowledged_at' => null,
        ],
        [
            'user_id' => $user->id,
            'kind' => 'backup_corrupt',
            'severity' => 'critical',
            'message' => 'already acked',
            'metadata' => null,
            'created_at' => '2026-05-20 02:00:00',
            'acknowledged_at' => '2026-05-20 03:00:00',
        ],
    ]);

    $messages = SystemAlert::query()->active()->pluck('message')->all();

    expect($messages)->toBe(['still active']);
});

it('scopeByKind filters rows by the given kind value', function (): void {
    $user = User::query()->create([
        'email' => 'sa-bykind@diederik.test',
        'password' => 'fixture-password',
        'period_start_day' => 1,
        'default_currency_view' => 'eur_only',
    ]);

    $this->db->connection()->table('system_alerts')->insert([
        [
            'user_id' => $user->id,
            'kind' => 'backup_corrupt',
            'severity' => 'critical',
            'message' => 'corrupt fixture',
            'metadata' => null,
            'created_at' => '2026-05-20 01:00:00',
            'acknowledged_at' => null,
        ],
        [
            'user_id' => $user->id,
            'kind' => 'wal_mode_missing',
            'severity' => 'warning',
            'message' => 'wal fixture',
            'metadata' => null,
            'created_at' => '2026-05-20 02:00:00',
            'acknowledged_at' => null,
        ],
    ]);

    $messages = SystemAlert::query()
        ->byKind('backup_corrupt')
        ->pluck('message')
        ->all();

    expect($messages)->toBe(['corrupt fixture']);
});

it('compiles scopeActive() into a WHERE acknowledged_at IS NULL predicate', function (): void {
    $sql = SystemAlert::query()->active()->toSql();
    expect($sql)->toContain('acknowledged_at');
    expect($sql)->toContain('is null');
});

it('compiles scopeByKind() into an equality predicate on the kind column', function (): void {
    $builder = SystemAlert::query()->byKind('backup_overdue');
    expect($builder->toSql())->toContain('kind');
    expect($builder->getBindings())->toContain('backup_overdue');
});
