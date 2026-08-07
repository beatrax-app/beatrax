<?php

declare(strict_types=1);

use App\Providers\NativeServiceProvider;
use Beatrax\BiometricVault\BiometricVaultServiceProvider;
use Native\Mobile\Providers\BiometricsServiceProvider;
use Native\Mobile\Providers\NetworkServiceProvider;
use Native\Mobile\Providers\ScannerServiceProvider;
use Native\Mobile\Providers\SecureStorageServiceProvider;
use NativePHP\BackgroundTasks\BackgroundTasksServiceProvider;
use NativePHP\LocalNotifications\LocalNotificationsServiceProvider;

/*
 * Device-UAT fix wiring test (Phase 15 close-out): NativeServiceProvider was
 * registered via `php artisan native:plugin:register` outside the normal plan
 * flow — the 4 premium/free NativePHP mobile plugins (mobile-biometrics,
 * mobile-scanner, mobile-background-tasks, mobile-network) MUST be listed in
 * NativeServiceProvider::plugins() or they never compile into the device
 * build, and the provider itself MUST be present in the mobile provider
 * manifest (`mobile-app/bootstrap/providers.php`) or it never boots at all.
 *
 * Mirrors MobileModuleWiringTest's string-assertion style for the manifest
 * check: `Native\Mobile\Providers\*` / `NativePHP\BackgroundTasks\*` are
 * installed ONLY in `mobile-app/vendor` (the Phase 15 sibling-root topology),
 * never in this repo-root toolchain, so this file cannot rely on those
 * classes being autoloadable — a `file_get_contents()` + string-assertion
 * check on the manifest source is deliberate, not a shortcut.
 *
 * NativeServiceProvider::plugins() itself CAN be called directly, though:
 * `SomeClass::class` is a compile-time string literal in PHP and never
 * triggers autoloading, so instantiating NativeServiceProvider and reading
 * the plugins() return value is safe even though the referenced provider
 * classes are absent from this root's vendor/ tree.
 */

it('registers NativeServiceProvider in the mobile provider manifest (mobile-app/bootstrap/providers.php)', function (): void {
    $manifest = (string) file_get_contents(base_path('mobile-app/bootstrap/providers.php'));

    expect($manifest)->toContain('use App\Providers\NativeServiceProvider;');
    expect($manifest)->toContain('NativeServiceProvider::class');
    // ->group('repo-root-only'): both manifest paths are resolved relative to
    // the repo root. Run from the mobile-app root they resolve to
    // mobile-app/mobile-app/… and to the mobile manifest respectively, so the
    // assertions describe a tree that is not the one under test.
})->group('repo-root-only');

it('does NOT register NativeServiceProvider in the desktop provider manifest (bootstrap/providers.php)', function (): void {
    $manifest = (string) file_get_contents(base_path('bootstrap/providers.php'));

    expect($manifest)->not->toContain('NativeServiceProvider');
})->group('repo-root-only');

it('NativeServiceProvider::plugins() lists all 7 registered NativePHP mobile plugin providers', function (): void {
    $provider = new NativeServiceProvider(app());

    $plugins = $provider->plugins();

    expect($plugins)->toBe([
        BiometricsServiceProvider::class,
        ScannerServiceProvider::class,
        BackgroundTasksServiceProvider::class,
        NetworkServiceProvider::class,
        SecureStorageServiceProvider::class,
        LocalNotificationsServiceProvider::class,
        BiometricVaultServiceProvider::class,
    ]);
});

it('NativeServiceProvider.php source references the 7 plugin FQCNs verbatim (belt-and-suspenders on the compiled-build source)', function (): void {
    $source = (string) file_get_contents(base_path('app/Providers/NativeServiceProvider.php'));

    foreach ([
        'Native\Mobile\Providers\BiometricsServiceProvider',
        'Native\Mobile\Providers\ScannerServiceProvider',
        'NativePHP\BackgroundTasks\BackgroundTasksServiceProvider',
        'Native\Mobile\Providers\NetworkServiceProvider',
        'Native\Mobile\Providers\SecureStorageServiceProvider',
        'NativePHP\LocalNotifications\LocalNotificationsServiceProvider',
        'Beatrax\BiometricVault\BiometricVaultServiceProvider',
    ] as $expectedFqcn) {
        expect($source)->toContain($expectedFqcn);
    }
});
