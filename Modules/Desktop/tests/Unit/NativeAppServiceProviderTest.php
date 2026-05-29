<?php

declare(strict_types=1);

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Modules\Desktop\Internal\NativeAppServiceProvider;
use Native\Desktop\Contracts\ProvidesPhpIni;
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
     * "Open beatrax" item does nothing. Our fix relocates the tray
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

it('publishes php.ini overrides that lift the upload ceiling above the wizard validator', function (): void {
    /*
     * The bundled NativePHP runtime ships with the stock PHP defaults
     * `upload_max_filesize = 2M` / `post_max_size = 8M`, both below
     * the wizard's own server-side 10 MB Livewire validator. A user-
     * sized statement upload then fails at the PHP layer BEFORE
     * Livewire's validator sees the file and surfaces the failure as
     * "The files.0 failed to upload." with no actionable context (the
     * UAT batch 7 ICS PDF report).
     *
     * NativePHP discovers per-app overrides by calling
     * `php artisan native:php-ini` at boot; that command JSON-encodes
     * whatever the configured provider's `phpIni()` method returns
     * and the Electron shell merges the keys on top of its own
     * defaults via `-d key=value` flags at PHP spawn time. Locking
     * the returned shape here prevents an accidental rename / type
     * coercion drop from silently re-stranding the upload path.
     */
    $provider = app(NativeAppServiceProvider::class);
    $phpIni = $provider->phpIni();

    expect($phpIni)->toHaveKey('upload_max_filesize');
    expect($phpIni)->toHaveKey('post_max_size');

    // Sized to match UploadWizard's largest single-file upload (eml
    // arm: 20 MB), plus multipart-envelope headroom comes from
    // post_max_size being identical to upload_max_filesize.
    expect($phpIni['upload_max_filesize'])->toBe('20M');
    expect($phpIni['post_max_size'])->toBe('20M');
});

it('publishes a max_execution_time override that absorbs the auto-updater Guzzle call', function (): void {
    /*
     * The bundled `php.ini` ships `max_execution_time = 30`, which is
     * shorter than the NativePHP auto-updater's GitHub-feed Guzzle
     * request on slow networks. The fatal observed in production
     * (`Maximum execution time of 30 seconds exceeded at
     * vendor/guzzlehttp/guzzle/src/Handler/CurlFactory.php`) is exactly
     * that race. Lock the override here so a casual rename of the
     * phpIni() key does not silently re-strand the updater.
     */
    $provider = app(NativeAppServiceProvider::class);
    $phpIni = $provider->phpIni();

    expect($phpIni)->toHaveKey('max_execution_time');
    expect($phpIni['max_execution_time'])->toBe('120');
});

it('implements the NativePHP ProvidesPhpIni contract so the LoadPHPConfigurationCommand picks up the overrides', function (): void {
    $provider = app(NativeAppServiceProvider::class);

    expect($provider)->toBeInstanceOf(ProvidesPhpIni::class);
});
