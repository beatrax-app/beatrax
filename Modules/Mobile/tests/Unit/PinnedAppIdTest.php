<?php

declare(strict_types=1);

use Modules\Mobile\Internal\Boot\PinnedAppId;

/*
 * nativephp/mobile's InstallCommand reads the .env FILE to decide whether the
 * bundle id is already set. If it decides no, it generates com.<user>.<three
 * random words>, writes that back, and the build ships under it — which is how
 * a phone ended up running com.wessel.stormlunarbold while iOS ran
 * com.beatrax.mobile.
 *
 * So this predicate is not a convenience: it has to agree with the vendor's own
 * guard exactly. Anything it accepts that the vendor rejects lets the generator
 * fire on a build we declared safe. The commented-key case is the one that
 * matters most, because commenting the key out is what the env template asks
 * for everywhere else.
 */

it('accepts a plain assignment', function (): void {
    expect(PinnedAppId::isPinnedIn("APP_ENV=local\nNATIVEPHP_APP_ID=com.beatrax.mobile\n"))->toBeTrue();
});

it('rejects a commented key, which is what the generator treats as absent', function (): void {
    expect(PinnedAppId::isPinnedIn("# NATIVEPHP_APP_ID=com.beatrax.mobile\n"))->toBeFalse();
});

it('rejects the key when it is absent altogether', function (): void {
    expect(PinnedAppId::isPinnedIn("APP_ENV=local\nAPP_DEBUG=true\n"))->toBeFalse();
});

it('rejects a key that is present but carries nothing usable', function (string $line): void {
    expect(PinnedAppId::isPinnedIn($line))->toBeFalse();
})->with([
    'blank' => "NATIVEPHP_APP_ID=\n",
    'whitespace' => "NATIVEPHP_APP_ID=   \n",
    'empty double quotes' => "NATIVEPHP_APP_ID=\"\"\n",
    'empty single quotes' => "NATIVEPHP_APP_ID=''\n",
]);

it('does not match a key that merely ends in the right name', function (): void {
    expect(PinnedAppId::isPinnedIn("BEATRAX_NATIVEPHP_APP_ID=com.beatrax.mobile\n"))->toBeFalse();
});

it('finds the key on a CRLF line among others', function (): void {
    $env = "APP_ENV=local\r\nNATIVEPHP_APP_ID=com.beatrax.mobile\r\nAPP_DEBUG=true\r\n";

    expect(PinnedAppId::isPinnedIn($env))->toBeTrue();
});

it('reads the applicationId the generated Android project would ship', function (): void {
    $gradle = <<<'KTS'
        android {
            namespace = "com.nativephp.mobile"
            defaultConfig {
                applicationId = "com.beatrax.mobile"
                versionCode = 10300
            }
        }
        KTS;

    expect(PinnedAppId::inGradle($gradle))->toBe('com.beatrax.mobile');
});

it('returns null rather than guessing when the Android project has no applicationId', function (): void {
    expect(PinnedAppId::inGradle("android {\n    namespace = \"com.nativephp.mobile\"\n}\n"))->toBeNull();
});

/*
 * native:install lays the project down carrying REPLACE_APP_ID, and only
 * prepareAndroidBuild() — which runs inside native:package — substitutes the
 * real one. So the identity check has to read this back AFTER packaging; before
 * it, every freshly installed project would look like the wrong app.
 */
it('reads back the placeholder a freshly installed project still carries', function (): void {
    expect(PinnedAppId::inGradle('applicationId = "REPLACE_APP_ID"'))->toBe('REPLACE_APP_ID');
});
