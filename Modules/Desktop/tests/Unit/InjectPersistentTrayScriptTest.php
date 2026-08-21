<?php

declare(strict_types=1);

// The script patches NativePHP's Electron entrypoint to build the macOS
// menu-bar Tray in the main process: the `MenuBar` facade couples its items to
// a focused BrowserWindow, so "Open Beatrax" dies once the window is closed.

beforeEach(function (): void {
    // The script's top-level block is guarded by an `$isDirectlyInvoked` check
    // that is false under Pest, so requiring it only defines its helpers.
    require_once base_path('scripts/nativephp_inject_persistent_tray.php');
});

it('broadens the electron named imports to include Menu, Tray and nativeImage', function (): void {
    $upstream = <<<'JS'
        import NativePHP from '#plugin';
        import { app } from 'electron';
        import path from 'path';
        NativePHP.bootstrap(app, defaultIcon, phpBinary, certificate, appPath);
        JS;

    [$patched, $status] = injectPersistentTray($upstream);

    expect($status)->toBe('patched');
    expect($patched)->toContain("import { app, Menu, Tray, nativeImage } from 'electron';");
    expect($patched)->not->toContain("import { app } from 'electron';");
});

it('injects the persistent-tray marker comment exactly once', function (): void {
    $upstream = <<<'JS'
        import { app } from 'electron';
        NativePHP.bootstrap(app, defaultIcon, phpBinary, certificate, appPath);
        JS;

    [$patched] = injectPersistentTray($upstream);

    expect(substr_count($patched, '// ── Beatrax persistent menu-bar tray ──'))->toBe(1);
});

it('emits a Tray construction that flags the loaded image as a macOS template image', function (): void {
    $upstream = <<<'JS'
        import { app } from 'electron';
        NativePHP.bootstrap(app);
        JS;

    [$patched] = injectPersistentTray($upstream);

    // A template image is what makes macOS auto-tint the tray icon for the
    // active menu-bar appearance: white in dark, black in light.
    expect($patched)->toContain('nativeImage.createFromPath');
    expect($patched)->toContain('setTemplateImage(true)');
    expect($patched)->toContain('new Tray(image)');
});

it('builds the verbatim three-row context menu — Open Beatrax / Scan email now / Quit', function (): void {
    $upstream = "import { app } from 'electron';\nNativePHP.bootstrap(app);\n";

    [$patched] = injectPersistentTray($upstream);

    expect($patched)->toContain("label: 'Open Beatrax'");
    expect($patched)->toContain("label: 'Scan email now'");
    expect($patched)->toContain("label: 'Quit'");
    // Quit handler must call `app.quit()` directly — no HTTP roundtrip.
    expect($patched)->toContain('app.quit()');
});

it('wires the show-or-recreate helper to the NativePHP /api/window/open endpoint with the secret header', function (): void {
    // With the main window closed there is nothing to show, so the tray POSTs to
    // the Electron API to build a fresh one; the shared secret gates that call.
    $upstream = "import { app } from 'electron';\nNativePHP.bootstrap(app);\n";

    [$patched] = injectPersistentTray($upstream);

    expect($patched)->toContain('/api/window/open');
    expect($patched)->toContain("'x-nativephp-secret'");
});

it('keeps the window-open payload aligned with NativeAppServiceProvider dimensions', function (): void {
    // The dimensions in the JS payload mirror the WINDOW_WIDTH / WINDOW_HEIGHT
    // constants in NativeAppServiceProvider; a drift on either side re-opens the
    // window at a different size than the one the user closed.
    $upstream = "import { app } from 'electron';\nNativePHP.bootstrap(app);\n";

    [$patched] = injectPersistentTray($upstream);

    expect($patched)->toContain('width: 1100');
    expect($patched)->toContain('height: 800');
    expect($patched)->toContain('rememberState: true');
});

it('is idempotent — a source containing the marker is returned unchanged', function (): void {
    $alreadyPatched = <<<'JS'
        import { app, Menu, Tray, nativeImage } from 'electron';
        NativePHP.bootstrap(app);

        // ── Beatrax persistent menu-bar tray ──
        let tray = null;
        JS;

    [$patched, $status] = injectPersistentTray($alreadyPatched);

    expect($status)->toBe('already-patched');
    expect($patched)->toBe($alreadyPatched);
});

it('reports failure when the upstream import line is missing', function (): void {
    // A future NativePHP release that reshapes the electron import must fail
    // loudly rather than leave a silently-broken patch.
    $upstream = "import electron from 'electron';\nNativePHP.bootstrap(app);\n";

    [$patched, $status] = injectPersistentTray($upstream);

    expect($patched)->toBeNull();
    expect($status)->toContain('import { app }');
});

it('reports failure when the NativePHP.bootstrap(...) call site is missing', function (): void {
    $upstream = "import { app } from 'electron';\n// no bootstrap here\n";

    [$patched, $status] = injectPersistentTray($upstream);

    expect($patched)->toBeNull();
    expect($status)->toContain('NativePHP.bootstrap');
});

it('balances nested parens inside NativePHP.bootstrap arguments so a future refactor stays patchable', function (): void {
    // A NativePHP release that passes an expression like `path.join('a', 'b')`
    // to bootstrap must still parse.
    $upstream = "import { app } from 'electron';\nNativePHP.bootstrap(app, path.join('a', 'b'), c);\n";

    [$patched, $status] = injectPersistentTray($upstream);

    expect($status)->toBe('patched');
    expect($patched)->toContain("NativePHP.bootstrap(app, path.join('a', 'b'), c);");
});

it('splices the injection AFTER the bootstrap statement, not before', function (): void {
    // NativePHP.bootstrap() registers its own `app.whenReady()` first. Ours has
    // to come after: Electron allows multiple listeners, but the ordering is
    // what the electronApiPort polling depends on.
    $upstream = <<<'JS'
        import { app } from 'electron';
        NativePHP.bootstrap(app);
        JS;

    [$patched] = injectPersistentTray($upstream);

    $bootstrapPos = strpos($patched, 'NativePHP.bootstrap(app);');
    $injectionPos = strpos($patched, '// ── Beatrax persistent menu-bar tray ──');

    expect($bootstrapPos)->toBeLessThan($injectionPos);
});
