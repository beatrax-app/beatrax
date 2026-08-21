<?php

declare(strict_types=1);

use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Modules\Desktop\Internal\Native\OsThemeProbe;
use Modules\Desktop\Providers\DesktopServiceProvider;
use Modules\Desktop\Public\Contracts\OsThemeSignal;

// The probe is the only place `Native\Desktop` is touched for the OS theme, so
// the layout can stay free of those symbols. The `System` facade has no v2 fake,
// so only the structural contract is asserted here.
it('binds OsThemeProbe to the OsThemeSignal contract when running inside the NativePHP bundle', function (): void {
    // The binding is decided in register(), so the provider has to be re-run
    // after flipping the config flag.
    $config = app(ConfigRepository::class);
    $config->set('nativephp-internal.running', true);

    $provider = new DesktopServiceProvider(app());
    $provider->register();

    expect(app(OsThemeSignal::class))->toBeInstanceOf(OsThemeProbe::class);
});

it('does NOT bind OsThemeSignal under local dev / in tests by default', function (): void {
    $config = app(ConfigRepository::class);

    // Off-bundle the binding is absent, which leaves the client-side
    // `prefers-color-scheme` pre-paint script as the only OS-theme signal.
    expect($config->get('nativephp-internal.running'))->not->toBeTrue();
    expect(app()->bound(OsThemeSignal::class))->toBeFalse();
});

it('exposes a nullable currentOsTheme() from the contract surface', function (): void {
    // `?string` lets the layout tell an explicit OS preference apart from no
    // signal at all; the pre-paint script runs only on null. Collapsing the two
    // is what gave the original probe its silent light fallback.
    expect(method_exists(OsThemeProbe::class, 'currentOsTheme'))->toBeTrue();

    $reflection = new ReflectionMethod(OsThemeProbe::class, 'currentOsTheme');
    $returnType = $reflection->getReturnType();

    expect($returnType)->not->toBeNull();
    expect((string) $returnType)->toBe('?string');
});
