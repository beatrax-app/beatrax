<?php

declare(strict_types=1);

use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Modules\Core\Internal\Http\Livewire\AppSidebar;
use Modules\Core\Models\User;

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

it('still hides the Dev block (and the live data) from a non-developer', function (): void {
    sidebarLiveUser(true, 'sidebar-live-non-dev-seed');
    $user = sidebarLiveUser(false, 'sidebar-live-non-dev');

    $component = Livewire::actingAs($user)->test(AppSidebar::class);

    $html = (string) $component->html();

    expect($html)->not->toContain('side-dev-block')
        ->and($html)->not->toContain('Queue ')
        ->and($html)->not->toContain('Open Dev Console')
        ->and($html)->not->toContain('dev-pulse');
});

it('drops the poll on mobile, where it queues ahead of the taps the user is making', function (): void {
    // The mobile shell has no HTTP server: NativePHP serialises every request
    // through one persistent PHP interpreter. The sidebar is mounted inside the
    // drawer on every page, so a 5s poll competes with navigation — observed as
    // the drawer scrim painting over a page that then never changes.
    $user = sidebarLiveUser(true, 'sidebar-live-mobile');

    $_SERVER['NATIVEPHP_PLATFORM'] = 'android';

    try {
        $html = (string) Livewire::actingAs($user)->test(AppSidebar::class)->html();
    } finally {
        unset($_SERVER['NATIVEPHP_PLATFORM']);
    }

    expect($html)->not->toContain('wire:poll');
    // The values are still rendered — they refresh on page load rather than
    // on a timer, so the block is informative without being expensive.
    expect($html)->toContain('side-dev-block');
    expect($html)->toContain('dev-pulse');
});
