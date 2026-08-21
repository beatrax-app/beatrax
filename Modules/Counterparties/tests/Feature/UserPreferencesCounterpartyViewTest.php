<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Schema;
use Modules\Core\Models\User;
use Modules\Core\Models\UserPreference;

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

    // Omitting the key entirely is the point: the default has to fire at the
    // database boundary, not at the Eloquent assignment layer.
    UserPreference::query()->create([
        'user_id' => $user->id,
    ]);

    $row = UserPreference::query()->where('user_id', $user->id)->firstOrFail();

    expect($row->counterparty_index_view)->toBe('cards');
});
