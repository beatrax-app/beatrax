<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Modules\Core\Models\User;
use Modules\Core\Public\Services\NavCountsService;

// The badges were cached for five minutes and the documented contract was that
// every write that changes a count calls forget(). One module out of eight ever
// did, so acknowledging a drift alert left the badge reading 2 against a
// database that said 1, for the whole five minutes.

function navBadgeReader(string $username): User
{
    return User::query()->create([
        'username' => $username,
        'password' => 'nav-counts-password',
        'period_start_day' => 1,
        'default_currency_view' => 'eur_only',
    ]);
}

function seedRecurringSeries(int $userId, string $state): int
{
    return DB::connection()->table('recurring_series')->insertGetId([
        'user_id' => $userId,
        'direction' => 'expense',
        'detected_name' => 'Netflix',
        'state' => $state,
        'cadence' => 'monthly',
        'latest_amount_minor' => -1299,
        'latest_currency' => 'EUR',
        'variance_tolerance_percent' => 25,
        'cluster_key' => 'nav-badge::'.bin2hex(random_bytes(4)),
        'created_at' => '2026-07-18 00:00:00',
        'updated_at' => '2026-07-18 00:00:00',
    ]);
}

it('drops the badge the moment the reader retires the series behind it', function (): void {
    $user = navBadgeReader('recurring-badge-reader');
    $first = seedRecurringSeries($user->id, 'approved');
    seedRecurringSeries($user->id, 'approved');

    /** @var NavCountsService $counts */
    $counts = $this->app->make(NavCountsService::class);

    expect($counts->forUser($user->id)['recurring'])->toBe(2);

    DB::connection()->table('recurring_series')->where('id', $first)->update(['state' => 'rejected']);

    expect($counts->forUser($user->id)['recurring'])->toBe(1);
});

it('follows an insert into a counted table no module ever announced', function (): void {
    $user = navBadgeReader('counterparty-badge-reader');

    /** @var NavCountsService $counts */
    $counts = $this->app->make(NavCountsService::class);

    expect($counts->forUser($user->id)['counterparties'])->toBe(0);

    DB::connection()->table('counterparties')->insert([
        'user_id' => $user->id,
        'type' => 'merchant',
        'slug' => 'nav-badge-netflix',
        'display_name' => 'Netflix',
        'created_at' => '2026-07-18 09:00:00',
        'updated_at' => '2026-07-18 09:00:00',
    ]);

    expect($counts->forUser($user->id)['counterparties'])->toBe(1);
});

it('leaves the cache standing for a write to a table no badge counts', function (): void {
    $user = navBadgeReader('unrelated-write-reader');

    /** @var NavCountsService $counts */
    $counts = $this->app->make(NavCountsService::class);
    $counts->forUser($user->id);

    $before = DB::connection()->table('users')->where('id', $user->id)->value('period_start_day');
    DB::connection()->table('users')->where('id', $user->id)->update(['period_start_day' => 2]);

    expect($before)->toBe(1);

    // No assertion on the count itself: what is being pinned is that an
    // unrelated write is not paying for eight COUNT queries on every request.
    expect(NavCountsService::countedTables())->not->toContain('users');
});

it('counts every badge from the one table list the invalidation reads', function (): void {
    $user = navBadgeReader('badge-table-list-reader');

    /** @var NavCountsService $counts */
    $counts = $this->app->make(NavCountsService::class);

    expect(array_keys($counts->forUser($user->id)))
        ->toBe(['transactions', 'recurring', 'counterparties', 'drift', 'budgets', 'subscriptions', 'imports', 'tax_tagged']);
});
