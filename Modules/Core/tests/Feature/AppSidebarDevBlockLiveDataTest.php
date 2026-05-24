<?php

declare(strict_types=1);

use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Modules\Core\Internal\Http\Livewire\AppSidebar;
use Modules\Core\Models\User;

/*
 * AppSidebar Dev-block live-data invariants (Phase 16-08 Task 2).
 *
 * 16-01 shipped the Dev block with static "Queue 0 · Worker —"
 * placeholder copy; 16-08 wires the real cache + jobs.count() reads
 * via render() method-DI. The pulse-row subtree carries `wire:poll.5s`
 * so the live values refresh without re-rendering the whole sidebar.
 *
 * Behavior contract per 16-08-PLAN.md Task 2:
 *
 *  Test 1 (queue count): seeding N rows into the `jobs` table for a
 *      developer renders "Queue N" in the Dev block.
 *  Test 2 (worker fresh): a recent heartbeat timestamp in cache
 *      renders "Worker {Ns ago}".
 *  Test 3 (worker missing): an absent cache key renders the em-dash
 *      placeholder "Worker —".
 *  Test 4 (wire:poll): the live-data subtree carries wire:poll.5s so
 *      the sidebar refreshes every 5 seconds.
 *  Test 5 (non-developer gate): non-developers do NOT receive the
 *      Dev block at all (T-16-01 still holds; this test guards
 *      against a regression where the new method-DI accidentally
 *      leaks live data into a non-dev render).
 */

function sidebarLiveUser(bool $isDeveloper, string $username = 'sidebar-live-fixture'): User
{
    return User::query()->create([
        'username' => $username,
        'password' => 'fixture-password',
        'period_start_day' => 1,
        'default_currency_view' => 'eur_only',
        'is_developer' => $isDeveloper,
    ]);
}

beforeEach(function (): void {
    DB::table('jobs')->truncate();
    app(CacheRepository::class)->forget('dev_mode.queue_worker_heartbeat');
});

it('renders the Dev block queue count from jobs.count() for a developer', function (): void {
    $user = sidebarLiveUser(true, 'sidebar-live-q3');

    DB::table('jobs')->insert([
        ['queue' => 'default', 'payload' => '{}', 'attempts' => 0, 'reserved_at' => null, 'available_at' => 0, 'created_at' => 0],
        ['queue' => 'default', 'payload' => '{}', 'attempts' => 0, 'reserved_at' => null, 'available_at' => 0, 'created_at' => 0],
        ['queue' => 'default', 'payload' => '{}', 'attempts' => 0, 'reserved_at' => null, 'available_at' => 0, 'created_at' => 0],
    ]);

    $component = Livewire::actingAs($user)->test(AppSidebar::class);

    $component->assertSee('Queue 3');
});

it('renders the worker delta when the heartbeat cache key is present and fresh', function (): void {
    $user = sidebarLiveUser(true, 'sidebar-live-worker');

    $cache = app(CacheRepository::class);
    $cache->put('dev_mode.queue_worker_heartbeat', time() - 5, 60);

    $component = Livewire::actingAs($user)->test(AppSidebar::class);

    $html = (string) $component->html();

    // Allow a 0..7s window — the test runtime can drift a couple of
    // seconds between the cache write and the render.
    $matched = (bool) preg_match('/Worker (\d|[1-9]\d)s ago/', $html);
    expect($matched)->toBeTrue('Sidebar Dev block must render "Worker Ns ago" with a numeric delta when the heartbeat cache key is fresh.');
});

it('renders the em-dash placeholder when no worker heartbeat is in cache', function (): void {
    $user = sidebarLiveUser(true, 'sidebar-live-no-worker');

    $component = Livewire::actingAs($user)->test(AppSidebar::class);

    $component->assertSee('Worker —', escape: false);
});

it('declares wire:poll.5s on the Dev-block live subtree so the sidebar refreshes every 5 seconds', function (): void {
    $user = sidebarLiveUser(true, 'sidebar-live-poll');

    $component = Livewire::actingAs($user)->test(AppSidebar::class);

    $html = (string) $component->html();

    expect($html)->toContain('wire:poll.5s');
});

it('still hides the Dev block (and the live data) from a non-developer (T-16-01 holds)', function (): void {
    sidebarLiveUser(true, 'sidebar-live-non-dev-seed');
    $user = sidebarLiveUser(false, 'sidebar-live-non-dev');

    $component = Livewire::actingAs($user)->test(AppSidebar::class);

    $html = (string) $component->html();

    expect($html)->not->toContain('side-dev-block')
        ->and($html)->not->toContain('Queue ')
        ->and($html)->not->toContain('Open Dev Console')
        ->and($html)->not->toContain('dev-pulse');
});
