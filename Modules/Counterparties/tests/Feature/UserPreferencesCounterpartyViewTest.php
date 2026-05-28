<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Schema;
use Modules\Core\Models\User;
use Modules\Core\Models\UserPreference;

/*
 * Foundation guard for the additive `counterparty_index_view` column on
 * `user_preferences` (Plan 17-06b Task 1). The migration extends the
 * 17-04a foundation table with a per-user view-mode preference for
 * `/counterparties` (cards | list). The column drives the index view
 * toggle's persistence; default `cards` matches the locked UI-SPEC.
 *
 * The tests pin four orthogonal contracts:
 *
 *   1. The column exists on `user_preferences` after migrate.
 *   2. The column type is varchar so a future engine swap reads the
 *      schema honestly.
 *   3. A `UserPreference::create(['counterparty_index_view' => 'list'])`
 *      persists the value and a subsequent fetch returns it verbatim
 *      (the model's `$fillable` extension is load-bearing for this).
 *   4. A `UserPreference::create()` WITHOUT specifying the column
 *      defaults to `cards` — proves the column default fires at the
 *      database boundary, not just at the Eloquent assignment layer.
 *
 * Schema facade is the in-test convention here; the project's DI rule
 * applies to production code only.
 */

function cpViewUser(string $username = 'cp-view-fixture'): User
{
    return User::query()->create([
        'username' => $username,
        'password' => 'fixture-password-12chars',
        'period_start_day' => 1,
    ]);
}

it('adds the counterparty_index_view column to user_preferences', function (): void {
    expect(Schema::hasColumn('user_preferences', 'counterparty_index_view'))->toBeTrue();
});

it('declares counterparty_index_view as a varchar column', function (): void {
    expect(Schema::getColumnType('user_preferences', 'counterparty_index_view'))->toBe('varchar');
});

it('persists a written counterparty_index_view value end to end', function (): void {
    $user = cpViewUser('cp-view-persist');

    UserPreference::query()->create([
        'user_id' => $user->id,
        'counterparty_index_view' => 'list',
    ]);

    $row = UserPreference::query()->where('user_id', $user->id)->firstOrFail();

    expect($row->counterparty_index_view)->toBe('list');
});

it('defaults counterparty_index_view to cards when omitted on insert', function (): void {
    $user = cpViewUser('cp-view-default');

    UserPreference::query()->create([
        'user_id' => $user->id,
    ]);

    $row = UserPreference::query()->where('user_id', $user->id)->firstOrFail();

    expect($row->counterparty_index_view)->toBe('cards');
});
