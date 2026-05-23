<?php

declare(strict_types=1);

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
 *   MenuBar      — ABSENT  (live facade call deferred to manual UAT;
 *                  see TrayMenuBuilderTest for the pure-composition
 *                  builder coverage)
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

it('runs the first-launch DB bootstrap before opening the main window — see FirstLaunchBootstrapTest for the post-boot assertion (Unit suite has no DB)')->todo();

it('configures the app menu via Native\\Desktop\\Facades\\Menu — deferred to manual UAT (no v2 fake for Menu)')->todo();

it('configures the system tray via Native\\Desktop\\Facades\\MenuBar — deferred to manual UAT (no v2 fake for MenuBar)')->todo();
