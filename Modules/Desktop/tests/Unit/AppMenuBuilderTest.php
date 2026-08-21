<?php

declare(strict_types=1);

use Modules\Desktop\Internal\Native\AppMenuBuilder;

// The whole menu is serialised and grep-asserted, so the assertions survive
// whichever submenu shape the NativePHP package picks for a given item.
it('builds the standard set of top-level menus', function (): void {
    $items = app(AppMenuBuilder::class)->build();

    $roles = collect($items)
        ->map(fn (object $item) => $item->toArray()['role'] ?? null)
        ->filter()
        ->all();

    expect($roles)->toContain(
        'appMenu',
        'fileMenu',
        'editMenu',
        'viewMenu',
        'windowMenu',
        'help',
    );
});

it('includes the Beatrax-specific File menu entries', function (): void {
    $items = app(AppMenuBuilder::class)->build();

    $rendered = json_encode(
        collect($items)->map(fn (object $item) => $item->toArray())->all(),
        JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE,
    );

    expect($rendered)->toContain('Import file…');
    expect($rendered)->toContain('Scan email now');
});

it('includes the Beatrax-specific Help menu entries', function (): void {
    $items = app(AppMenuBuilder::class)->build();

    $rendered = json_encode(
        collect($items)->map(fn (object $item) => $item->toArray())->all(),
        JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE,
    );

    expect($rendered)->toContain('GitHub repo');
    expect($rendered)->toContain('Report an issue');
    expect($rendered)->toContain('About Beatrax');
});
