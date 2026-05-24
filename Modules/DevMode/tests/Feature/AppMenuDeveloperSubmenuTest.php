<?php

declare(strict_types=1);

use Modules\Core\Models\User;
use Modules\Desktop\Internal\Native\AppMenuBuilder;

/*
 * AppMenuBuilder Developer-submenu invariants (Phase 16-08 Task 2).
 *
 * 16-08 extends AppMenuBuilder (which already builds the standard
 * App/File/Edit/View/Window/Help set + the beatrax-specific File +
 * Help entries) with a CONDITIONAL Developer submenu that appears
 * only for `is_developer=true` users:
 *
 *   Developer → "Open Dev Console" (⌘.) + "⌘K Run a command" (⌘K)
 *
 * The two entries route to `dev.overview` via Menu::route() (B-4
 * fix: name-based so the menu works in both Herd dev + shipped
 * Electron bundles). Accelerators use the cross-platform `Cmd+.` /
 * `Cmd+K` strings Electron interprets verbatim on macOS, Windows,
 * and Linux.
 *
 * RESEARCH Pitfall 9: NativePHP app-menu changes do NOT propagate
 * until full bundle relaunch. The build() output is correct here
 * — what changes a running bundle's menu is a separate concern.
 *
 * Test design: AppMenuBuilder is a non-Livewire pure-composition
 * class. The fixture uses `actingAs(...)` to seed the CurrentUser
 * the container resolves into the builder's constructor.
 */

function devMenuUser(bool $isDeveloper, string $username = 'menu-fixture'): User
{
    return User::query()->create([
        'username' => $username,
        'password' => 'fixture-password',
        'period_start_day' => 1,
        'default_currency_view' => 'eur_only',
        'is_developer' => $isDeveloper,
    ]);
}

it('appends a Developer submenu with the Open Dev Console + Run-a-command entries when is_developer=true', function (): void {
    $user = devMenuUser(true, 'menu-dev');

    test()->actingAs($user);

    /** @var AppMenuBuilder $builder */
    $builder = app(AppMenuBuilder::class);

    $items = $builder->build();

    $rendered = json_encode(
        array_map(static fn (object $item): array => $item->toArray(), $items),
        JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE,
    );

    expect($rendered)->toContain(AppMenuBuilder::DEVELOPER_SUBMENU);
    expect($rendered)->toContain(AppMenuBuilder::DEV_OPEN_CONSOLE);
    expect($rendered)->toContain(AppMenuBuilder::DEV_RUN_COMMAND);

    // "Open Dev Console" carries the Cmd+. accelerator. "Run a command"
    // does NOT register an OS-menu accelerator — the ⌘K visual hint
    // lives in its label, and the body-level Blade keybind handler
    // owns the actual ⌘K → palette:open dispatch. Letting the OS menu
    // intercept Cmd+K would navigate to /dev instead of opening the
    // palette.
    expect($rendered)->toContain('Cmd+.');
    expect($rendered)->not->toContain('Cmd+K');
});

it('omits the Developer submenu entirely for is_developer=false (T-16-36 defense-in-depth)', function (): void {
    devMenuUser(true, 'menu-non-dev-seed');
    $user = devMenuUser(false, 'menu-non-dev');

    test()->actingAs($user);

    /** @var AppMenuBuilder $builder */
    $builder = app(AppMenuBuilder::class);

    $items = $builder->build();

    $rendered = json_encode(
        array_map(static fn (object $item): array => $item->toArray(), $items),
        JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE,
    );

    expect($rendered)->not->toContain(AppMenuBuilder::DEVELOPER_SUBMENU);
    expect($rendered)->not->toContain(AppMenuBuilder::DEV_OPEN_CONSOLE);
    expect($rendered)->not->toContain(AppMenuBuilder::DEV_RUN_COMMAND);
});

it('omits the Developer submenu for an unauthenticated request', function (): void {
    devMenuUser(true, 'menu-unauth-seed');

    /** @var AppMenuBuilder $builder */
    $builder = app(AppMenuBuilder::class);

    $items = $builder->build();

    $rendered = json_encode(
        array_map(static fn (object $item): array => $item->toArray(), $items),
        JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE,
    );

    expect($rendered)->not->toContain(AppMenuBuilder::DEVELOPER_SUBMENU);
});
