<?php

declare(strict_types=1);

// The suite runs from both Composer roots, so base_path() is not one place.
// mobile-app/config/nativephp.php is tracked, so it is reachable from either —
// unlike the mobile vendor tree, which only exists under the mobile root.
function mobileNativePhpConfig(): array
{
    foreach ([base_path('config/nativephp.php'), base_path('mobile-app/config/nativephp.php')] as $candidate) {
        if (! is_file($candidate)) {
            continue;
        }

        $config = require $candidate;

        if (is_array($config) && array_key_exists('permissions', $config)) {
            return $config;
        }
    }

    throw new RuntimeException('no mobile nativephp config reachable from '.base_path());
}

/** @return array<string, string> the app-level Info.plist overrides */
function appInfoPlistOverrides(): array
{
    $permissions = mobileNativePhpConfig()['permissions'] ?? [];

    expect($permissions)->toBeArray();

    return $permissions;
}

// IOSPluginCompiler::getAppInfoPlistOverrides() returns this array verbatim and
// injects it AFTER every plugin, so it is the only place that wins a key a
// plugin also declares. mobile-scanner and mobile-biometrics both declare one.

it('overrides the plugin usage strings Apple would otherwise read', function (): void {
    expect(appInfoPlistOverrides())
        ->toHaveKey('NSCameraUsageDescription')
        ->toHaveKey('NSFaceIDUsageDescription')
        ->toHaveKey('NSLocalNetworkUsageDescription');
});

// A purpose string is rejected for being vague, and the inherited ones describe
// NativePHP's demo app: one of them claims the app scans barcodes, which it has
// never done. Naming the product is the cheapest proof the string is ours.
it('names the product in every purpose string', function (string $key): void {
    $value = appInfoPlistOverrides()[$key] ?? null;

    expect($value)->toBeString();
    expect($value)->toContain('Beatrax');
})->with([
    'NSCameraUsageDescription',
    'NSFaceIDUsageDescription',
    'NSLocalNetworkUsageDescription',
]);

it('claims no capability the app does not have', function (): void {
    // The inherited scanner string. The app reads pairing codes and nothing
    // else, so a reader granting the camera for "barcodes" was told something
    // untrue about what it would be used for.
    expect(appInfoPlistOverrides()['NSCameraUsageDescription'])->not->toContain('barcode');
});

// App Store Connect refuses a submission whose category is unset, and the
// generated plist ships LSApplicationCategoryType empty unless this array
// carries it.
it('declares an application category App Store Connect accepts', function (): void {
    $category = appInfoPlistOverrides()['LSApplicationCategoryType'] ?? null;

    expect($category)->toBeString();
    expect($category)->toStartWith('public.app-category.');
    expect($category)->not->toBe('public.app-category.');
});
