<?php

declare(strict_types=1);

use Modules\Desktop\Internal\Native\AppMenuBuilder;

/*
 * Pure-composition unit tests: the builder collects `Native\Desktop`
 * menu items and the assertions read each item's rendered `toArray()`
 * payload — no NativePHP facade fakes are needed (see
 * NATIVEPHP-FAKES.md).
 *
 * The plan locks the verbatim labels: File → "Import file…",
 * "Scan email now"; Help → "GitHub repo", "Report an issue", "About
 * beatrax". The whole menu is serialised and the rendered string is
 * grep-asserted so the test is robust against the exact submenu shape
 * the NativePHP package picks (label-on-Link vs label-on-Role etc.).
 */

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

it('includes the beatrax-specific File menu entries', function (): void {
    $items = app(AppMenuBuilder::class)->build();

    $rendered = json_encode(
        collect($items)->map(fn (object $item) => $item->toArray())->all(),
        JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE,
    );

    // The two File entries appear verbatim somewhere in the
    // serialised tree (they live on the File submenu).
    expect($rendered)->toContain('Import file…');
    expect($rendered)->toContain('Scan email now');
});

it('includes the beatrax-specific Help menu entries', function (): void {
    $items = app(AppMenuBuilder::class)->build();

    $rendered = json_encode(
        collect($items)->map(fn (object $item) => $item->toArray())->all(),
        JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE,
    );

    expect($rendered)->toContain('GitHub repo');
    expect($rendered)->toContain('Report an issue');
    expect($rendered)->toContain('About Beatrax');
});
