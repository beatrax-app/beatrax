<?php

declare(strict_types=1);

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Modules\Desktop\Internal\NativeAppServiceProvider;
use Native\Desktop\Facades\Window;
use Native\Desktop\Windows\Window as NativeWindow;

/*
 * NATIVEPHP-FAKES.md records v2 fake availability for this plan:
 *
 *   Window       — PRESENT (fake-backed assertions are automated)
 *   Menu         — ABSENT  (live facade call deferred to manual UAT;
 *                  see AppMenuBuilderTest for the pure-composition
 *                  builder coverage)
 *
 * The provider boot() must therefore (a) configure the window via the
 * Window facade — automated against the fake here — and (b) hand the
 * built `Menu` to the live Menu facade. The Menu leg hits the NativePHP
 * HTTP client at boot; we `Http::fake()` to swallow those calls so the
 * provider boot can complete and the Window-fake assertion can still run.
 *
 * The macOS menu-bar tray (D-09) is NOT installed via NativePHP's
 * `MenuBar` facade — the persistent tray is created directly in the
 * Electron main process by `scripts/nativephp_inject_persistent_tray.php`
 * (see the `NativeAppServiceProvider` class docblock for the
 * architectural rationale). The regression guard for the tray lives in
 * `InjectPersistentTrayScriptTest`; this provider must NOT post to
 * NativePHP's `menu-bar/create` endpoint, which is what the second test
 * below asserts.
 */

it('configures the application window', function (): void {
    Http::fake();

    $fake = Window::fake();
    $fake->alwaysReturnWindows([new NativeWindow('main')]);

    app(NativeAppServiceProvider::class)->boot();

    $fake->assertOpened('main');
});

it('does not call the NativePHP `menu-bar/create` endpoint — the persistent tray lives in the Electron main process', function (): void {
    /*
     * UAT-2 / UAT-3 architectural fix: the tray is no longer routed
     * through NativePHP's `MenuBar` facade. Calling `MenuBar::create()`
     * lands in the popover-style menubar paradigm whose context-menu
     * link items early-return when no window is focused — once the
     * user closes the main window via the X button, the tray's
     * "Open diederik" item does nothing. Our fix relocates the tray
     * to the Electron main process via a prebuild patch. If a future
     * refactor accidentally reintroduces a `MenuBar::create()` call,
     * the resulting POST to `/api/menu-bar/create` will be observed
     * here and the assertion fails.
     */
    Http::fake();
    Window::fake()->alwaysReturnWindows([new NativeWindow('main')]);

    app(NativeAppServiceProvider::class)->boot();

    Http::assertNotSent(function (Request $request): bool {
        return str_ends_with($request->url(), 'menu-bar/create');
    });
});

it('runs the first-launch DB bootstrap before opening the main window — see FirstLaunchBootstrapTest for the post-boot assertion (Unit suite has no DB)')->todo();

it('configures the app menu via Native\\Desktop\\Facades\\Menu — deferred to manual UAT (no v2 fake for Menu)')->todo();
