<?php

declare(strict_types=1);

use Illuminate\Database\DatabaseManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Core\Models\User;
use Modules\Forecasting\Internal\Pipeline\ProjectionPipeline;

uses(RefreshDatabase::class);

// forecast_runs was append-only. Every reader takes the newest row for a
// (user, scenario, horizon) and nothing has a foreign key into it, so each
// projection left its predecessor behind for good: on the round-6 desktop the
// table reached 1,305 rows and 54.6 MB of result_json in thirteen hours, taking
// the database from 9 MB to 62 MB — a cost every encrypted backup then carries.

function pruneUser(): User
{
    return User::query()->create([
        'username' => 'prune-'.bin2hex(random_bytes(4)),
        'password' => 'fixture',
        'period_start_day' => 1,
        'default_currency_view' => 'eur_only',
    ]);
}

function pruneAccount(DatabaseManager $db, int $userId): void
{
    $hex = bin2hex(random_bytes(4));

    $db->connection()->table('accounts')->insert([
        'user_id' => $userId,
        'name' => 'ASN',
        'slug' => 'prune-'.$hex,
        'kind' => 'bank',
        'iban' => 'NL00PRN'.strtoupper($hex),
        'default_currency' => 'EUR',
        'opening_balance_minor' => 100000,
        'opening_balance_as_of_date' => '2026-06-01',
        'created_at' => '2026-06-01 00:00:00',
        'updated_at' => '2026-06-01 00:00:00',
    ]);
}

function pruneRunCount(DatabaseManager $db, int $userId): int
{
    return $db->connection()->table('forecast_runs')->where('user_id', $userId)->count();
}

it('keeps one run per horizon however many times it projects', function (): void {
    $db = app(DatabaseManager::class);
    $user = pruneUser();
    pruneAccount($db, $user->id);

    $pipeline = app(ProjectionPipeline::class);

    foreach (range(1, 5) as $ignored) {
        $pipeline->project($user, null, 30);
    }

    expect(pruneRunCount($db, $user->id))->toBe(1);
});

// The survivor has to be the newest one, complete, and still carrying its
// result — pruning to a single EMPTY row would pass a bare count.
it('leaves the newest completed run intact', function (): void {
    $db = app(DatabaseManager::class);
    $user = pruneUser();
    pruneAccount($db, $user->id);

    $pipeline = app(ProjectionPipeline::class);
    $pipeline->project($user, null, 30);
    $firstId = (int) $db->connection()->table('forecast_runs')->where('user_id', $user->id)->max('id');

    $pipeline->project($user, null, 30);

    $rows = $db->connection()->table('forecast_runs')->where('user_id', $user->id)->get();

    expect($rows)->toHaveCount(1);
    expect((int) $rows[0]->id)->toBeGreaterThan($firstId);
    expect($rows[0]->status)->toBe('complete');
    expect($rows[0]->result_json)->toContain('"accounts"');
});

// Horizons are separate cache keys, so pruning one must not evict another.
it('never prunes a different horizon or another user', function (): void {
    $db = app(DatabaseManager::class);
    $user = pruneUser();
    $other = pruneUser();
    pruneAccount($db, $user->id);
    pruneAccount($db, $other->id);

    $pipeline = app(ProjectionPipeline::class);
    $pipeline->project($user, null, 30);
    $pipeline->project($user, null, 90);
    $pipeline->project($other, null, 30);
    $pipeline->project($user, null, 30);

    expect(pruneRunCount($db, $user->id))->toBe(2);
    expect(pruneRunCount($db, $other->id))->toBe(1);
});
