<?php

declare(strict_types=1);

use Illuminate\Database\DatabaseManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Modules\Auth\Public\Actions\PurgeUserDataAction;
use Modules\Core\Models\User;
use Modules\Core\Public\Contracts\CurrentUser;
use Modules\DevMode\Internal\Http\Livewire\CommandPaletteModal;

uses(RefreshDatabase::class);

// The Recent rail stores a transaction hit as the query that found it, so the
// row holds a merchant and an amount the reader typed. Keyed with a `.` before
// the id it outlived the account by the entry's 30 days: UserScopedDataPurge
// matches the `:` suffix every other user-keyed cache row uses.
it('does not leave a deleted reader\'s palette searches in the cache', function (): void {
    /** @var DatabaseManager $db */
    $db = $this->app->make(DatabaseManager::class);
    $cache = Cache::store('database');

    $user = User::query()->create([
        'username' => 'palette-owner',
        'password' => 'fixture-password',
        'period_start_day' => 1,
        'default_currency_view' => 'eur_only',
    ]);

    $this->actingAs($user);

    (new CommandPaletteModal)->pickEntry([
        'id' => 'search:txn:1',
        'label' => 'albert heijn 42,15',
        'source' => 'search',
        'url' => '/transactions?q=albert+heijn+42%2C15',
    ], $this->app->make(CurrentUser::class), $cache);

    expect($db->connection()->table('cache')->pluck('key')->all())->not->toBe([]);

    /** @var PurgeUserDataAction $purge */
    $purge = $this->app->make(PurgeUserDataAction::class);
    ($purge)($db->connection(), $user->id);

    expect($db->connection()->table('cache')->pluck('key')->all())->toBe([]);
});
