<?php

declare(strict_types=1);

use Illuminate\Database\DatabaseManager;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Schema;
use Modules\Core\Models\User;
use Modules\Core\Models\UserPreference;

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

    // Presence, not strict equality: downstream modules add columns additively,
    // so the foundation contract survives a new column without a coordinated
    // rewrite here.
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

    $this->actingAs($this->userA);
    $rowsForA = UserPreference::query()->get();
    expect($rowsForA)->toHaveCount(1);
    expect($rowsForA->first()?->user_id)->toBe($this->userA->id);

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
