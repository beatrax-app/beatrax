<?php

declare(strict_types=1);

use Modules\Desktop\Internal\Native\AppMenuBuilder;

/**
 * @return list<array<string, mixed>>
 */
function appMenuItems(): array
{
    return collect(app(AppMenuBuilder::class)->build())
        ->map(fn (object $item): array => $item->toArray())
        ->all();
}

it('builds the standard set of top-level menus', function (): void {
    $roles = collect(appMenuItems())
        ->map(fn (array $item) => $item['role'] ?? null)
        ->filter()
        ->all();

    expect($roles)->toContain('appMenu', 'editMenu', 'viewMenu', 'windowMenu');
});

// The shell drops the submenu of a role item, so a role can never carry one.
// See .docs/features/desktop/architecture.md — "Submenus never hang off a role".
it('never hangs a submenu off a role item', function (): void {
    $offenders = collect(appMenuItems())
        ->filter(fn (array $item): bool => isset($item['role'], $item['submenu']))
        ->map(fn (array $item): string => (string) $item['role'])
        ->all();

    expect($offenders)->toBe([]);
});

// Electron renders a submenu only for type `submenu`; a `normal` item that
// carries one is drawn as a dead entry, so the menu never appears.
it('types every menu that owns its entries as a submenu', function (): void {
    $owning = collect(appMenuItems())
        ->filter(fn (array $item): bool => isset($item['submenu']));

    expect($owning)->not->toBeEmpty();
    expect($owning->pluck('type')->unique()->all())->toBe(['submenu']);
    expect($owning->pluck('label')->all())->toContain('File', 'Help');
});

it('includes the Beatrax-specific File menu entries', function (): void {
    $file = collect(appMenuItems())->firstWhere('label', 'File');

    expect(json_encode($file, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE))
        ->toContain('Import file…')
        ->toContain('Scan email now');
});

it('includes the Beatrax-specific Help menu entries', function (): void {
    $help = collect(appMenuItems())->firstWhere('label', 'Help');

    expect(json_encode($help, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE))
        ->toContain('GitHub repo')
        ->toContain('Report an issue')
        ->toContain('About Beatrax');
});
