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
 *   MenuBar      — ABSENT  (live facade call deferred to manual UAT
 *                  for visual concerns; the wire-level payload to
 *                  the NativePHP Electron driver is still asserted
 *                  here via Http::fake() request recording — that
 *                  catches regressions in the boot-time builder
 *                  configuration even though the rendered Tray
 *                  cannot be inspected in PHPUnit)
 *
 * The provider boot() must therefore (a) configure the window via the
 * Window facade — automated against the fake here — and (b) hand the
 * built `Menu` + tray context-menu to the live Menu / MenuBar facades.
 * The Menu / MenuBar legs hit the NativePHP HTTP client at boot; we
 * `Http::fake()` to swallow those calls so the provider boot can
 * complete and the Window-fake assertion can still run.
 */

it('configures the application window', function (): void {
    Http::fake();

    $fake = Window::fake();
    $fake->alwaysReturnWindows([new NativeWindow('main')]);

    app(NativeAppServiceProvider::class)->boot();

    $fake->assertOpened('main');
});

it('creates the system tray in plain-Tray mode so the icon persists across the app lifecycle', function (): void {
    /*
     * NativePHP's Electron-side `menu-bar/create` handler branches on
     * `onlyShowContextMenu`:
     *
     *   true  → plain `new Tray(icon)` retained in `state.tray` for
     *           the entire app lifetime. This is D-09's design:
     *           persistent macOS menu-bar icon with a right-click
     *           context menu.
     *
     *   false → constructs a popover-style menubar app with its own
     *           hidden BrowserWindow that hides on blur. The tray
     *           icon visibly disappears when the stateful main
     *           window takes focus (UAT-2 regression). NOT what
     *           D-09 wants.
     *
     * The assertion below pins the wire-level flag so a future
     * refactor of the boot() chain that drops `onlyShowContextMenu(true)`
     * fails at the test seam, not in manual UAT.
     */
    Http::fake();
    Window::fake()->alwaysReturnWindows([new NativeWindow('main')]);

    app(NativeAppServiceProvider::class)->boot();

    Http::assertSent(function (Request $request): bool {
        if (! str_ends_with($request->url(), 'menu-bar/create')) {
            return false;
        }

        return ($request->data()['onlyShowContextMenu'] ?? null) === true;
    });
});

it('runs the first-launch DB bootstrap before opening the main window — see FirstLaunchBootstrapTest for the post-boot assertion (Unit suite has no DB)')->todo();

it('configures the app menu via Native\\Desktop\\Facades\\Menu — deferred to manual UAT (no v2 fake for Menu)')->todo();
