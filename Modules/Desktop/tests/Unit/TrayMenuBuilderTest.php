<?php

declare(strict_types=1);

use Modules\Desktop\Internal\Native\TrayMenuBuilder;

/*
 * Pure-composition unit test for the system-tray right-click menu
 * (D-09). The plan locks the verbatim three rows in exact order:
 * "Open diederik" / "Scan email now" / "Quit". No NativePHP facade
 * fake is needed — only the builder output is asserted (see
 * NATIVEPHP-FAKES.md: `MenuBar` fake is ABSENT in v2; the live facade
 * call lives in NativeAppServiceProvider and is verified manually).
 */

it('builds the tray context menu with the three D-09 rows in order', function (): void {
    $built = app(TrayMenuBuilder::class)->build();

    $payload = $built->toArray();
    $items = $payload['submenu'] ?? [];

    $labels = collect($items)
        ->pluck('label')
        ->all();

    expect($labels)->toBe([
        'Open diederik',
        'Scan email now',
        'Quit',
    ]);
});
