<?php

declare(strict_types=1);

use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Modules\Desktop\Internal\Native\OsThemeProbe;
use Modules\Desktop\Providers\DesktopServiceProvider;
use Modules\Desktop\Public\Contracts\OsThemeSignal;

/*
 * `OsThemeProbe` wraps `Native\Desktop\Facades\System::theme()` so the
 * dark-theme layout can read the OS appearance preference without
 * importing any `Native\Desktop\*` symbol. The probe is the SOLE
 * place the OS theme is read; the layout consumes it through the
 * `OsThemeSignal` contract.
 *
 * The `System` facade has no v2 fake (see NATIVEPHP-FAKES.md), so the
 * test asserts the structural contract — the binding lights up only
 * inside the NativePHP bundle (`nativephp-internal.running === true`),
 * the concrete probe implements the interface, and the contract
 * surface returns a string. The live `System::theme()` call is
 * verified manually in HUMAN-UAT.
 */

it('binds OsThemeProbe to the OsThemeSignal contract when running inside the NativePHP bundle', function (): void {
    // Simulate the bundle-runtime signal. The provider re-runs
    // `register()` to pick up the new config value and register the
    // contract binding.
    $config = app(ConfigRepository::class);
    $config->set('nativephp-internal.running', true);

    $provider = new DesktopServiceProvider(app());
    $provider->register();

    expect(app(OsThemeSignal::class))->toBeInstanceOf(OsThemeProbe::class);
});

it('does NOT bind OsThemeSignal under Herd / in tests by default', function (): void {
    $config = app(ConfigRepository::class);

    // The default test environment leaves `nativephp-internal.running`
    // unset / false — the layout's `app()->bound(...)` check should
    // return false, leaving the client-side `prefers-color-scheme`
    // pre-paint script as the sole OS-theme signal.
    expect($config->get('nativephp-internal.running'))->not->toBeTrue();
    expect(app()->bound(OsThemeSignal::class))->toBeFalse();
});

it('exposes a nullable currentOsTheme() from the contract surface', function (): void {
    // Phase 15 WR-05: the contract returns `?string` so the layout can
    // distinguish an explicit OS preference from a no-signal SYSTEM
    // case. The pre-paint `prefers-color-scheme` script runs only for
    // null returns — eliminating the silent "SYSTEM-OS-no-explicit-
    // preference" → light fallback the original probe collapsed into.
    expect(method_exists(OsThemeProbe::class, 'currentOsTheme'))->toBeTrue();

    $reflection = new ReflectionMethod(OsThemeProbe::class, 'currentOsTheme');
    $returnType = $reflection->getReturnType();

    expect($returnType)->not->toBeNull();
    expect((string) $returnType)->toBe('?string');
});
