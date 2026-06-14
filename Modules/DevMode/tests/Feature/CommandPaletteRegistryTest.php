<?php

declare(strict_types=1);

use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Livewire\Livewire;
use Modules\Core\Models\User;
use Modules\Core\Public\Contracts\CurrentUser;
use Modules\DevMode\Internal\Http\Livewire\CommandPaletteModal;
use Modules\DevMode\Internal\Navigation\NavigationRegistryImpl;
use Modules\DevMode\Public\Contracts\AppActionRegistry;
use Modules\DevMode\Public\Contracts\DevCommandRegistry;
use Modules\DevMode\Public\Contracts\NavigationRegistry;
use Modules\DevMode\Public\Dto\NavigationEntry;

/*
 * CommandPaletteModal registry + filter + Recent-cache invariants.
 *
 * Test 1: app(NavigationRegistry::class) resolves to the concrete
 *         NavigationRegistryImpl (NOT NullNavigationRegistry).
 * Test 2: The all() list contains every authenticated main-app
 *         view AND every Dev Console sub-route registered in the
 *         current bundle.
 * Test 3: Developer's palette JSON contains view + dev + action
 *         sources; ZERO DESTRUCTIVE-tier dev rows.
 * Test 4: Non-developer's palette JSON contains ZERO `source: dev`
 *         rows AND zero `dev.*` view rows.
 * Test 5: pickEntry() writes the Recent list to the per-user
 *         cache key, dedupes on id, and evicts the oldest when
 *         the list grows beyond RECENT_LIMIT.
 */

function cpUser(bool $isDeveloper, string $username): User
{
    return User::query()->create([
        'username' => $username,
        'password' => 'fixture-password',
        'period_start_day' => 1,
        'default_currency_view' => 'eur_only',
        'is_developer' => $isDeveloper,
    ]);
}

it('binds NavigationRegistry to NavigationRegistryImpl (NOT NullNavigationRegistry)', function (): void {
    expect(app(NavigationRegistry::class))->toBeInstanceOf(NavigationRegistryImpl::class);
});

it('exposes the full main-app + Dev Console nav roster from NavigationRegistry::all()', function (): void {
    /** @var NavigationRegistry $nav */
    $nav = app(NavigationRegistry::class);

    $ids = array_map(static fn (NavigationEntry $e): string => $e->id, $nav->all());

    // Main-app entries
    expect($ids)->toContain('dashboard')
        ->and($ids)->toContain('transactions.index')
        ->and($ids)->toContain('forecast.index')
        ->and($ids)->toContain('recurring.index')
        ->and($ids)->toContain('chains.review')
        ->and($ids)->toContain('drift.index')
        ->and($ids)->toContain('imports.new')
        ->and($ids)->toContain('inboxes.index')
        ->and($ids)->toContain('uncategorized')
        ->and($ids)->toContain('settings');

    // Dev Console sub-routes (filtered at JSON-emit time for
    // non-developers; present in the registry regardless so the
    // palette filter is the single source of truth).
    expect($ids)->toContain('dev.overview')
        ->and($ids)->toContain('dev.artisan')
        ->and($ids)->toContain('dev.audit')
        ->and($ids)->toContain('dev.logs')
        ->and($ids)->toContain('dev.queue')
        ->and($ids)->toContain('dev.doctor')
        ->and($ids)->toContain('dev.sql')
        ->and($ids)->toContain('dev.system')
        ->and($ids)->toContain('dev.sync-health');
});

it('emits the merged palette JSON for a developer (view + dev SAFE + action; ZERO DESTRUCTIVE)', function (): void {
    $user = cpUser(true, 'cp-dev');

    /** @var CommandPaletteModal $component */
    $component = Livewire::actingAs($user)->test(CommandPaletteModal::class);

    $registry = $component->instance()->buildRegistry(
        app(CurrentUser::class),
        app(NavigationRegistry::class),
        app(DevCommandRegistry::class),
        app(AppActionRegistry::class),
    );

    $sources = array_map(static fn (array $row): string => $row['source'], $registry);

    // At least one of each source for a developer.
    expect($sources)->toContain('view');
    expect($sources)->toContain('dev-view'); // Dev Console nav sub-routes
    expect($sources)->toContain('dev');      // SAFE-tier commands
    expect($sources)->toContain('action');

    // Every dev row is SAFE-tier — DESTRUCTIVE commands are
    // structurally absent from the palette JSON.
    $destructiveTiered = array_filter(
        $registry,
        static fn (array $row): bool => ($row['tier'] ?? null) === 'destructive',
    );
    expect($destructiveTiered)->toBe([]);

    // Sanity: at least the locked SAFE-tier commands appear by name.
    $names = array_filter(array_map(static fn (array $row): ?string => $row['name'] ?? null, $registry));
    expect($names)->toContain('beatrax:doctor');
    expect($names)->toContain('cache:clear');
    expect($names)->not->toContain('db:restore');     // DESTRUCTIVE — excluded
    expect($names)->not->toContain('migrate:fresh');  // DESTRUCTIVE — excluded
});

it('emits a palette JSON without any dev rows for a non-developer', function (): void {
    cpUser(true, 'cp-non-dev-seed'); // bypass first-launch redirect
    $user = cpUser(false, 'cp-non-dev');

    /** @var CommandPaletteModal $component */
    $component = Livewire::actingAs($user)->test(CommandPaletteModal::class);

    $registry = $component->instance()->buildRegistry(
        app(CurrentUser::class),
        app(NavigationRegistry::class),
        app(DevCommandRegistry::class),
        app(AppActionRegistry::class),
    );

    $sources = array_unique(array_map(static fn (array $row): string => $row['source'], $registry));

    // Zero dev / dev-view rows for non-developers.
    expect($sources)->not->toContain('dev');
    expect($sources)->not->toContain('dev-view');

    // View + action rows still present (the palette is useful even
    // to non-developers; it just elides the Dev Console).
    expect($sources)->toContain('view');
    expect($sources)->toContain('action');
});

it('persists Recent picks to dev_mode.palette_recent.{userId} with dedupe + cap-at-5 semantics', function (): void {
    $user = cpUser(true, 'cp-recent');
    $cache = app(CacheRepository::class);
    $key = 'dev_mode.palette_recent.'.$user->id;
    $cache->forget($key);

    /** @var CommandPaletteModal $component */
    $component = Livewire::actingAs($user)->test(CommandPaletteModal::class);

    $pick = function (string $id) use ($component): void {
        $component->call('pickEntry', [
            'id' => $id,
            'label' => 'Label '.$id,
            'icon' => '◆',
            'hint' => 'Hint '.$id,
            'source' => 'view',
            'url' => '/'.$id,
            'handler' => null,
            'name' => null,
            'tier' => null,
        ]);
    };

    // 1. Single pick → 1 entry.
    $pick('dashboard');
    $stored = $cache->get($key);
    expect($stored)->toBeArray()->toHaveCount(1)
        ->and($stored[0]['id'])->toBe('dashboard');

    // 2. Dedupe: re-picking the same id does NOT duplicate.
    $pick('dashboard');
    $stored = $cache->get($key);
    expect($stored)->toHaveCount(1);

    // 3. Eviction: 6 distinct picks → cap at 5 (oldest evicts).
    $pick('transactions.index');
    $pick('forecast.index');
    $pick('recurring.index');
    $pick('chains.review');
    $pick('imports.new');

    $stored = $cache->get($key);
    expect($stored)->toHaveCount(CommandPaletteModal::RECENT_LIMIT);

    // The most recent pick lands at the head.
    expect($stored[0]['id'])->toBe('imports.new');
    // The oldest pick ('dashboard') evicted — only one occurrence
    // remained after dedupe, and 5 later distinct picks pushed it
    // off the list.
    $ids = array_column($stored, 'id');
    expect($ids)->not->toContain('dashboard');
});
