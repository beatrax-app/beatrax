<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Modules\Core\Models\User;
use Modules\Core\Public\Services\NavCountsService;

beforeEach(function (): void {
    $this->user = User::query()->create([
        'username' => 'nav-counts-fixture',
        'password' => 'fixture-password-12chars',
        'period_start_day' => 1,
    ]);
});

function navCp(int $userId, string $slug): void
{
    DB::table('counterparties')->insert([
        'user_id' => $userId, 'type' => 'merchant', 'slug' => $slug, 'display_name' => $slug,
        'created_at' => '2026-05-01 00:00:00', 'updated_at' => '2026-05-01 00:00:00',
    ]);
}

it('counts items per nav section, scoped to the user', function (): void {
    navCp($this->user->id, 'a');
    navCp($this->user->id, 'b');

    $other = User::query()->create(['username' => 'nav-other', 'password' => 'fixture-password-12chars', 'period_start_day' => 1]);
    navCp($other->id, 'c'); // another user's row must not be counted

    $counts = app(NavCountsService::class)->forUser($this->user->id);

    expect($counts['counterparties'])->toBe(2);
    expect($counts['transactions'])->toBe(0);
    expect($counts)->toHaveKeys(['transactions', 'recurring', 'counterparties', 'drift', 'budgets', 'subscriptions', 'imports']);
});

it('caches the counts and refreshes only after forget()', function (): void {
    navCp($this->user->id, 'a');
    $service = app(NavCountsService::class);

    expect($service->forUser($this->user->id)['counterparties'])->toBe(1);

    // A new row is NOT reflected while the cached payload is warm…
    navCp($this->user->id, 'b');
    expect($service->forUser($this->user->id)['counterparties'])->toBe(1);

    // …until the cache is invalidated.
    $service->forget($this->user->id);
    expect($service->forUser($this->user->id)['counterparties'])->toBe(2);
});

it('does not error and returns every expected key', function (): void {
    // The hasTable guard means a build missing a module's table yields 0
    // rather than a 500; all keys are always present.
    $counts = app(NavCountsService::class)->forUser($this->user->id);

    foreach (['transactions', 'recurring', 'counterparties', 'drift', 'budgets', 'subscriptions', 'imports'] as $key) {
        expect($counts[$key])->toBeInt();
    }
});
