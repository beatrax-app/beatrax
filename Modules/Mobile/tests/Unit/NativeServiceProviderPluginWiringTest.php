<?php

declare(strict_types=1);

use App\Providers\NativeServiceProvider;
use Beatrax\BiometricVault\BiometricVaultServiceProvider;
use Native\Mobile\Providers\BiometricsServiceProvider;
use Native\Mobile\Providers\NetworkServiceProvider;
use Native\Mobile\Providers\ScannerServiceProvider;
use Native\Mobile\Providers\SecureStorageServiceProvider;
use Native\Mobile\UI\NativeUIServiceProvider;
use NativePHP\BackgroundTasks\BackgroundTasksServiceProvider;
use NativePHP\LocalNotifications\LocalNotificationsServiceProvider;

// The plugin providers install only in mobile-app/vendor, so the manifest is
// checked as source text rather than through autoloading. plugins() itself can
// still be called: SomeClass::class is a compile-time literal in PHP and never
// triggers an autoload, even for a class absent from this root's vendor tree.

it('registers NativeServiceProvider in the mobile provider manifest (mobile-app/bootstrap/providers.php)', function (): void {
    $manifest = (string) file_get_contents(base_path('mobile-app/bootstrap/providers.php'));

    expect($manifest)->toContain('use App\Providers\NativeServiceProvider;');
    expect($manifest)->toContain('NativeServiceProvider::class');
    // Both manifest paths resolve relative to the repo root. Run from the
    // mobile-app root they resolve to mobile-app/mobile-app/… instead, describing
    // a tree that is not the one under test.
})->group('repo-root-only');

it('does NOT register NativeServiceProvider in the desktop provider manifest (bootstrap/providers.php)', function (): void {
    $manifest = (string) file_get_contents(base_path('bootstrap/providers.php'));

    expect($manifest)->not->toContain('NativeServiceProvider');
})->group('repo-root-only');

it('NativeServiceProvider::plugins() lists all 8 registered NativePHP mobile plugin providers', function (): void {
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
        NativeUIServiceProvider::class,
    ]);
});

it('NativeServiceProvider.php source references the 8 plugin FQCNs verbatim (belt-and-suspenders on the compiled-build source)', function (): void {
    $source = (string) file_get_contents(base_path('app/Providers/NativeServiceProvider.php'));

    foreach ([
        'Native\Mobile\Providers\BiometricsServiceProvider',
        'Native\Mobile\Providers\ScannerServiceProvider',
        'NativePHP\BackgroundTasks\BackgroundTasksServiceProvider',
        'Native\Mobile\Providers\NetworkServiceProvider',
        'Native\Mobile\Providers\SecureStorageServiceProvider',
        'NativePHP\LocalNotifications\LocalNotificationsServiceProvider',
        'Beatrax\BiometricVault\BiometricVaultServiceProvider',
        'Native\Mobile\UI\NativeUIServiceProvider',
    ] as $expectedFqcn) {
        expect($source)->toContain($expectedFqcn);
    }
});
