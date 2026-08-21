<?php

declare(strict_types=1);

use Modules\Mobile\Internal\Boot\AndroidVersionCode;

// Android orders releases by versionCode and nothing else. The release workflow
// exports only NATIVEPHP_APP_VERSION, so without a derivation every CI-built APK
// carries nativephp/mobile's package default of 1 — below the code already on a
// developer's device, and refused by Play as a downgrade.

it('derives a code that rises with the version', function (): void {
    expect(AndroidVersionCode::fromVersion('1.3.0'))->toBe(10300)
        ->and(AndroidVersionCode::fromVersion('2.0.0'))->toBe(20000)
        ->and(AndroidVersionCode::fromVersion('1.3.1'))->toBe(10301);
});

it('orders every pair the way the version orders them', function (): void {
    $versions = ['0.0.1', '0.1.0', '1.0.0', '1.2.3', '1.3.0', '2.0.0', '10.0.0'];
    $codes = array_map(
        static fn (string $v): ?int => AndroidVersionCode::fromVersion($v),
        $versions,
    );

    expect($codes)->not->toContain(null)
        ->and($codes)->toBe(array_values(collect($codes)->sort()->all()));
});

it('accepts a tag name and a prerelease suffix', function (): void {
    expect(AndroidVersionCode::fromVersion('v2.0.0'))->toBe(20000)
        ->and(AndroidVersionCode::fromVersion('2.0.0-rc.1'))->toBe(20000);
});

it('refuses a version whose parts would carry into the field above', function (): void {
    // 1.0.100 and 1.1.0 would both be 10100, so the ordering would lie.
    expect(AndroidVersionCode::fromVersion('1.0.100'))->toBeNull()
        ->and(AndroidVersionCode::fromVersion('1.100.0'))->toBeNull();
});

it('refuses what is not a version at all', function (): void {
    expect(AndroidVersionCode::fromVersion('0.0.0-dev'))->toBeNull()
        ->and(AndroidVersionCode::fromVersion('1.3'))->toBeNull()
        ->and(AndroidVersionCode::fromVersion(''))->toBeNull();
});

it('reads the code back out of the generated Gradle file', function (): void {
    $gradle = <<<'KTS'
        defaultConfig {
            applicationId = "com.beatrax.mobile"
            versionCode = 10300
            versionName = "1.3.0"
        }
        KTS;

    expect(AndroidVersionCode::inGradle($gradle))->toBe(10300)
        ->and(AndroidVersionCode::inGradle('versionCode = REPLACEMECODE'))->toBeNull();
});
