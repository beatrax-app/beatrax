<?php

declare(strict_types=1);

use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Livewire\Livewire;
use Modules\Core\Models\User;
use Modules\Core\Public\Contracts\CurrentUser;
use Modules\Core\Public\Services\DevConsoleBuildGate;
use Modules\DevMode\Internal\Http\Livewire\CommandPaletteModal;
use Modules\DevMode\Internal\Navigation\NavigationRegistryImpl;
use Modules\DevMode\Public\Contracts\AppActionRegistry;
use Modules\DevMode\Public\Contracts\DevCommandRegistry;
use Modules\DevMode\Public\Contracts\NavigationRegistry;
use Modules\DevMode\Public\Dto\NavigationEntry;

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

    expect($ids)->toContain('dashboard')
        ->and($ids)->toContain('transactions.index')
        ->and($ids)->toContain('forecast.index')
        ->and($ids)->toContain('recurring.index')
        ->and($ids)->toContain('chains.index')
        ->and($ids)->toContain('drift.index')
        ->and($ids)->toContain('imports.new')
        ->and($ids)->toContain('inboxes.index')
        ->and($ids)->toContain('uncategorized')
        ->and($ids)->toContain('settings');

    // Dev rows sit in the registry for everyone; the JSON-emit filter is the
    // single place non-developers are excluded.
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
        app(DevConsoleBuildGate::class),
    );

    $sources = array_map(static fn (array $row): string => $row['source'], $registry);

    expect($sources)->toContain('view');
    expect($sources)->toContain('dev-view'); // Dev Console nav sub-routes
    expect($sources)->toContain('dev');      // SAFE-tier commands
    expect($sources)->toContain('action');

    $destructiveTiered = array_filter(
        $registry,
        static fn (array $row): bool => ($row['tier'] ?? null) === 'destructive',
    );
    expect($destructiveTiered)->toBe([]);

    $names = array_filter(array_map(static fn (array $row): ?string => $row['name'] ?? null, $registry));
    expect($names)->toContain('beatrax:doctor');
    expect($names)->toContain('cache:clear');
    expect($names)->not->toContain('db:restore');
    expect($names)->not->toContain('migrate:fresh');
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
        app(DevConsoleBuildGate::class),
    );

    $sources = array_unique(array_map(static fn (array $row): string => $row['source'], $registry));

    expect($sources)->not->toContain('dev');
    expect($sources)->not->toContain('dev-view');

    // The palette itself still works for a non-developer; only the Dev
    // Console rows are elided.
    expect($sources)->toContain('view');
    expect($sources)->toContain('action');
});

it('persists Recent picks to dev_mode.palette_recent:{userId} with dedupe + cap-at-5 semantics', function (): void {
    $user = cpUser(true, 'cp-recent');
    $cache = app(CacheRepository::class);
    $key = 'dev_mode.palette_recent:'.$user->id;
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

    $pick('dashboard');
    $stored = $cache->get($key);
    expect($stored)->toBeArray()->toHaveCount(1)
        ->and($stored[0]['id'])->toBe('dashboard');

    $pick('dashboard');
    $stored = $cache->get($key);
    expect($stored)->toHaveCount(1);

    $pick('transactions.index');
    $pick('forecast.index');
    $pick('recurring.index');
    $pick('chains.index');
    $pick('imports.new');

    $stored = $cache->get($key);
    expect($stored)->toHaveCount(CommandPaletteModal::RECENT_LIMIT);

    expect($stored[0]['id'])->toBe('imports.new');
    // 'dashboard' deduped down to one entry, then five later picks pushed it
    // past the cap.
    $ids = array_column($stored, 'id');
    expect($ids)->not->toContain('dashboard');
});
