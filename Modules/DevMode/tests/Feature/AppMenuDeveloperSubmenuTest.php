<?php

declare(strict_types=1);

use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Modules\Core\Models\User;
use Modules\Core\Public\Support\Lang;
use Modules\Desktop\Internal\Native\AppMenuBuilder;

// Asserted through the same key the builder reads, so a retitled menu entry
// cannot leave this test passing against a label the app no longer draws.
function devMenuLabel(string $key): string
{
    return Lang::get('desktop::native.menu.'.$key);
}

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

    expect($rendered)->toContain(devMenuLabel('developer_submenu'));
    expect($rendered)->toContain(devMenuLabel('dev_open_console'));
    expect($rendered)->toContain(devMenuLabel('dev_run_command'));

    // ⌘K stays a label hint with no OS accelerator: if the menu claimed it,
    // Cmd+K would navigate to /dev instead of opening the palette.
    expect($rendered)->toContain('Cmd+.');
    expect($rendered)->not->toContain('Cmd+K');
});

it('omits the Developer submenu entirely for is_developer=false (defense-in-depth)', function (): void {
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

    expect($rendered)->not->toContain(devMenuLabel('developer_submenu'));
    expect($rendered)->not->toContain(devMenuLabel('dev_open_console'));
    expect($rendered)->not->toContain(devMenuLabel('dev_run_command'));
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

    expect($rendered)->not->toContain(devMenuLabel('developer_submenu'));
});

// Cmd+. on a shipped build would route to /dev, which that build answers with
// 404 — an accelerator wired to a dead end reads as a broken app, not a shut
// one. Appended to this file rather than a new one because the crossing into
// Desktop's builder is pinned per file.
it('omits the Developer submenu on a shipped build with no flag passed', function (): void {
    $user = devMenuUser(true, 'menu-shipped');

    test()->actingAs($user);

    /** @var ConfigRepository $config */
    $config = app(ConfigRepository::class);
    $config->set('app.env', 'production');
    $config->set('app.dev_mode', false);
    $config->set('app.debug', false);

    /** @var AppMenuBuilder $builder */
    $builder = app(AppMenuBuilder::class);

    $rendered = json_encode(
        array_map(static fn (object $item): array => $item->toArray(), $builder->build()),
        JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE,
    );

    expect($rendered)->not->toContain(devMenuLabel('developer_submenu'))
        ->and($rendered)->not->toContain(devMenuLabel('dev_open_console'))
        ->and($rendered)->not->toContain('Cmd+.');
});

it('keeps the Developer submenu on a shipped build once the flag is passed', function (): void {
    $user = devMenuUser(true, 'menu-shipped-flagged');

    test()->actingAs($user);

    /** @var ConfigRepository $config */
    $config = app(ConfigRepository::class);
    $config->set('app.env', 'production');
    $config->set('app.dev_mode', true);

    /** @var AppMenuBuilder $builder */
    $builder = app(AppMenuBuilder::class);

    $rendered = json_encode(
        array_map(static fn (object $item): array => $item->toArray(), $builder->build()),
        JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE,
    );

    expect($rendered)->toContain(devMenuLabel('developer_submenu'));
});
