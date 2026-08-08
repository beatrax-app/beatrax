<?php

declare(strict_types=1);

/*
 * Static-fixture regression guards for `scripts/nativephp_inject_persistent_tray.php`.
 *
 * The injector is the central plank of the UAT-2 / UAT-3 fix: it patches
 * the NativePHP-published `nativephp/electron/src/main/index.js` to create
 * a persistent macOS menu-bar `Tray` directly in the Electron main process,
 * replacing the NativePHP `MenuBar` facade (which couples tray menu items
 * to a focused BrowserWindow and breaks "Open Beatrax" once the user
 * closes the main window via the X button).
 *
 * The tests below verify the patch on representative input shapes so a
 * future drift in either upstream or our own template surfaces as a
 * failing test rather than a silently-broken build.
 */

beforeEach(function (): void {
    // Loading the script registers its helpers. The top-level executable
    // block is guarded by an `$isDirectlyInvoked` check that is false
    // under the Pest runner, so requiring the file is a pure
    // function-definition side effect.
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

    // The whole point of the new architecture: the Tray's NativeImage is
    // flagged as a template so macOS auto-tints it for the active menu
    // bar appearance (white in dark, black in light).
    expect($patched)->toContain('nativeImage.createFromPath');
    expect($patched)->toContain('setTemplateImage(true)');
    expect($patched)->toContain('new Tray(image)');
});

it('builds the verbatim D-09 three-row context menu — Open Beatrax / Scan email now / Quit', function (): void {
    $upstream = "import { app } from 'electron';\nNativePHP.bootstrap(app);\n";

    [$patched] = injectPersistentTray($upstream);

    expect($patched)->toContain("label: 'Open Beatrax'");
    expect($patched)->toContain("label: 'Scan email now'");
    expect($patched)->toContain("label: 'Quit'");
    // Quit handler must call `app.quit()` directly — no HTTP roundtrip.
    expect($patched)->toContain('app.quit()');
});

it('wires the show-or-recreate helper to the NativePHP /api/window/open endpoint with the secret header', function (): void {
    // The fallback path when the main window has been closed: POST to the
    // Electron API to construct a fresh BrowserWindow with the same
    // payload `WindowManager::open(\'main\')` would have produced. The
    // request must carry the NativePHP shared secret.
    $upstream = "import { app } from 'electron';\nNativePHP.bootstrap(app);\n";

    [$patched] = injectPersistentTray($upstream);

    expect($patched)->toContain('/api/window/open');
    expect($patched)->toContain("'x-nativephp-secret'");
});

it('keeps the window-open payload aligned with NativeAppServiceProvider dimensions', function (): void {
    // Soft contract: the dimensions in the JS payload must mirror the
    // `WINDOW_WIDTH` / `WINDOW_HEIGHT` constants in
    // `NativeAppServiceProvider`. A drift in either side produces a
    // re-opened window with different geometry than the originally-
    // opened one (jarring for the user).
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
    // A future NativePHP release that reshapes the electron import (e.g.
    // splits it across multiple lines, switches to default-import syntax)
    // must surface as a loud failure rather than a silently-broken patch.
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
    // Defensive: a NativePHP release that reshapes the bootstrap call to
    // pass an expression like `path.join('a', 'b')` must still parse.
    $upstream = "import { app } from 'electron';\nNativePHP.bootstrap(app, path.join('a', 'b'), c);\n";

    [$patched, $status] = injectPersistentTray($upstream);

    expect($status)->toBe('patched');
    // The bootstrap call itself must remain intact in the patched output.
    expect($patched)->toContain("NativePHP.bootstrap(app, path.join('a', 'b'), c);");
});

it('splices the injection AFTER the bootstrap statement, not before', function (): void {
    // Critical ordering: NativePHP.bootstrap() registers
    // `app.whenReady()` first. Our additional `app.whenReady()` listener
    // must come after, otherwise NativePHP could clobber our handler
    // (Electron supports multiple listeners but ordering matters for the
    // electronApiPort polling).
    $upstream = <<<'JS'
        import { app } from 'electron';
        NativePHP.bootstrap(app);
        JS;

    [$patched] = injectPersistentTray($upstream);

    $bootstrapPos = strpos($patched, 'NativePHP.bootstrap(app);');
    $injectionPos = strpos($patched, '// ── Beatrax persistent menu-bar tray ──');

    expect($bootstrapPos)->toBeLessThan($injectionPos);
});
