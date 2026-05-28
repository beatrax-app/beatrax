<?php

declare(strict_types=1);

use Illuminate\Database\DatabaseManager;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Schema;
use Modules\Core\Models\User;
use Modules\Core\Models\UserPreference;

/*
 * Schema + Eloquent coverage for the shared `user_preferences` table.
 *
 * The table is the per-user single-row preference store; downstream
 * domain modules extend it with their own additive column-add
 * migrations. The foundation migration owns three invariants only:
 *
 *   1. The column list is exactly (id, user_id, created_at, updated_at).
 *   2. user_id is UNIQUE — one preference row per user.
 *   3. Deleting a user cascades the matching user_preferences row.
 *
 * The UserPreference Eloquent model carries the BelongsToUser global
 * scope so any Eloquent surface that reaches this model inside an
 * authenticated request is automatically scoped to the current user.
 */

beforeEach(function (): void {
    /** @var DatabaseManager $db */
    $db = $this->app->make(DatabaseManager::class);
    $this->db = $db;
    $this->conn = $db->connection();

    $this->userA = User::query()->create([
        'username' => 'pref-user-a',
        'password' => 'fixture-password',
        'period_start_day' => 1,
        'default_currency_view' => 'eur_only',
    ]);

    $this->userB = User::query()->create([
        'username' => 'pref-user-b',
        'password' => 'fixture-password',
        'period_start_day' => 1,
        'default_currency_view' => 'eur_only',
    ]);
});

it('creates the user_preferences table with the foundation column list (plus any later additive columns)', function (): void {
    expect(Schema::hasTable('user_preferences'))->toBeTrue();

    $cols = Schema::getColumnListing('user_preferences');

    // The foundation migration owns these four columns; additive
    // column-add migrations from downstream domain modules (e.g.
    // `skipped_update_versions` for the auto-update banner) extend
    // the table over time. The test asserts presence rather than
    // strict equality so the foundation contract survives any
    // future additive column without a coordinated rewrite.
    foreach (['id', 'user_id', 'created_at', 'updated_at'] as $expected) {
        expect($cols)->toContain($expected);
    }
});

it('enforces a unique constraint on user_id at the database boundary', function (): void {
    $this->conn->table('user_preferences')->insert([
        'user_id' => $this->userA->id,
        'created_at' => '2026-05-27 00:00:00',
        'updated_at' => '2026-05-27 00:00:00',
    ]);

    expect(fn () => $this->conn->table('user_preferences')->insert([
        'user_id' => $this->userA->id,
        'created_at' => '2026-05-27 00:00:00',
        'updated_at' => '2026-05-27 00:00:00',
    ]))->toThrow(QueryException::class);
});

it('cascades deletion of a user to the user_preferences row', function (): void {
    $this->conn->table('user_preferences')->insert([
        'user_id' => $this->userA->id,
        'created_at' => '2026-05-27 00:00:00',
        'updated_at' => '2026-05-27 00:00:00',
    ]);

    expect($this->conn->table('user_preferences')
        ->where('user_id', $this->userA->id)
        ->count())->toBe(1);

    $this->userA->delete();

    expect($this->conn->table('user_preferences')
        ->where('user_id', $this->userA->id)
        ->count())->toBe(0);
});

it('scopes UserPreference Eloquent queries to the authenticated user via BelongsToUser', function (): void {
    // Seed one preference row per user via the raw connection so the
    // global scope cannot mask the insert side.
    $this->conn->table('user_preferences')->insert([
        [
            'user_id' => $this->userA->id,
            'created_at' => '2026-05-27 00:00:00',
            'updated_at' => '2026-05-27 00:00:00',
        ],
        [
            'user_id' => $this->userB->id,
            'created_at' => '2026-05-27 00:00:00',
            'updated_at' => '2026-05-27 00:00:00',
        ],
    ]);

    // Acting as user A: Eloquent reads only user A's row.
    $this->actingAs($this->userA);
    $rowsForA = UserPreference::query()->get();
    expect($rowsForA)->toHaveCount(1);
    expect($rowsForA->first()?->user_id)->toBe($this->userA->id);

    // Acting as user B: Eloquent reads only user B's row.
    $this->actingAs($this->userB);
    $rowsForB = UserPreference::query()->get();
    expect($rowsForB)->toHaveCount(1);
    expect($rowsForB->first()?->user_id)->toBe($this->userB->id);
});

it('exposes a UserPreference->user() BelongsTo relationship pointing back to User', function (): void {
    $this->actingAs($this->userA);

    $pref = UserPreference::query()->create([
        'user_id' => $this->userA->id,
    ]);

    expect($pref->user)->not()->toBeNull();
    expect($pref->user->id)->toBe($this->userA->id);
});
