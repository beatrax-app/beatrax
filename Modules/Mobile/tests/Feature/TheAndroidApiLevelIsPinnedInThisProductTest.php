<?php

declare(strict_types=1);

use Modules\Mobile\Internal\Boot\AndroidApiLevels;
use Modules\Mobile\Tests\Support\ConfigFileCode;

// `nativephp/mobile` resolves every Android level as
// env('NATIVEPHP_ANDROID_TARGET_SDK', 36), and mobile-app/config/nativephp.php
// declared no android block at all — so the API level the APK is built and
// submitted against was a package default filtered through a git-ignored .env.
// Nothing in this product had chosen it, and nothing failed when the package
// moved it. The generated project cannot hold the pin: mobile-app/nativephp/ is
// gitignored and ships with REPLACE_COMPILE_SDK placeholders, so the pin lives
// where the packaging step reads it.

// The mobile config sits on the far side of whichever root is running, and
// release.yml is the anchor because both roots have a config/nativephp.php.
function androidPinMobileConfigPath(): string
{
    $desktopRoot = is_file(base_path('.github/workflows/release.yml')) ? base_path() : base_path('..');

    return $desktopRoot.'/mobile-app/config/nativephp.php';
}

/** @return array<string, mixed> */
function androidPinBlock(): array
{
    $config = require androidPinMobileConfigPath();
    $android = is_array($config) ? ($config['android'] ?? null) : null;

    return is_array($android) ? $android : [];
}

it('pins all three Android levels as integers the environment cannot move', function (): void {
    $android = androidPinBlock();

    foreach (['compile_sdk', 'min_sdk', 'target_sdk'] as $key) {
        expect($android[$key] ?? null)->toBeInt(
            "config('nativephp.android.{$key}') is not an integer literal in the mobile root's own "
            .'config, so nativephp/mobile answers it from its package default and a .env nobody reviews.',
        );
    }

    // Comments stripped first: the block explains at length why it reads no
    // environment, and naming the variables it refuses is how it explains that.
    $source = ConfigFileCode::at(androidPinMobileConfigPath());

    foreach (['COMPILE_SDK', 'MIN_SDK', 'TARGET_SDK'] as $variable) {
        expect(str_contains($source, 'NATIVEPHP_ANDROID_'.$variable))->toBeFalse(
            'the pinned levels read NATIVEPHP_ANDROID_'.$variable.' again. An API level a builder '
            .'can move from their shell is not pinned in this product.',
        );
    }
});

it('targets at least the level the store requires of a new submission', function (): void {
    expect(androidPinBlock()['target_sdk'])->toBeGreaterThanOrEqual(AndroidApiLevels::PLAY_TARGET_SDK);
});

// Declaring `android` at all replaces the package's array wholesale —
// mergeConfigFrom() is a shallow array_merge — so a key the restatement misses
// resolves to null rather than to the package's own value. The toolchain paths
// are the ones that hurt: without android_sdk_path a build finds no SDK.
it('restates every key the package block it replaces declares', function (): void {
    $pinned = [
        'gradle_jdk_path', 'android_sdk_path', 'emulator_path', '7zip-location',
        'compile_sdk', 'min_sdk', 'target_sdk',
        'status_bar_style', 'theme', 'build',
    ];

    expect(array_keys(androidPinBlock()))->toBe($pinned);

    // The mobile Composer root is the only one with nativephp/mobile installed,
    // and there the claim is checked against the package rather than a list.
    $packageConfig = base_path('vendor/nativephp/mobile/config/nativephp.php');

    if (! is_file($packageConfig)) {
        return;
    }

    $package = require $packageConfig;
    $packageAndroid = is_array($package) ? ($package['android'] ?? []) : [];

    expect(is_array($packageAndroid) ? array_keys($packageAndroid) : [])->toBe(
        $pinned,
        'nativephp/mobile changed the shape of its android block. Restate the new keys here, or the '
        .'replacement drops them to null on every mobile build.',
    );
});
