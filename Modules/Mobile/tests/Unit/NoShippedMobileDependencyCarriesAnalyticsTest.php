<?php

declare(strict_types=1);

// The product's answer to both stores' privacy forms is "nothing collected".
// That answer is a statement to a regulator, and one analytics dependency
// anywhere in the phone build makes it false — whether it arrives as a Composer
// package or as a Gradle coordinate inside a NativePHP plugin's own manifest,
// which no Composer tooling reads at all.

/**
 * Every product whose presence would contradict the declaration, keyed by the
 * token that identifies it. The value says what it is, so a failure reads as a
 * finding rather than a name on a list.
 *
 * @return array<string, string>
 */
function analyticsForbiddenTokens(): array
{
    return [
        'adjust' => 'attribution analytics',
        'airbrake' => 'error reporting',
        'amplitude' => 'product analytics',
        'appcenter' => 'crash reporting and analytics',
        'appsflyer' => 'attribution analytics',
        'bugsnag' => 'crash reporting',
        'bugly' => 'crash reporting',
        'countly' => 'product analytics',
        'crashlytics' => 'crash reporting',
        'datadog' => 'telemetry',
        'elastic-apm' => 'telemetry',
        'firebase-analytics' => 'usage analytics',
        'firebase-crashlytics' => 'crash reporting',
        'flurry' => 'usage analytics',
        'google-analytics' => 'usage analytics',
        'honeybadger' => 'error reporting',
        'instabug' => 'crash reporting',
        'kochava' => 'attribution analytics',
        'logrocket' => 'session replay',
        'matomo' => 'usage analytics',
        'mixpanel' => 'product analytics',
        'newrelic' => 'telemetry',
        'opentelemetry' => 'telemetry',
        'play-services-measurement' => 'usage analytics',
        'posthog' => 'product analytics',
        'raygun' => 'crash reporting',
        'rollbar' => 'error reporting',
        'sensorsdata' => 'usage analytics',
        'sentry' => 'error reporting',
        'smartlook' => 'session replay',
        'umeng' => 'usage analytics',
    ];
}

/**
 * @param  array<string, string>  $subjects  what it is => where it was found
 * @return list<string>
 */
function analyticsOffendersAmong(array $subjects): array
{
    $offenders = [];

    foreach ($subjects as $name => $origin) {
        foreach (analyticsForbiddenTokens() as $token => $what) {
            // The first match is the finding. Naming a package twice because
            // two tokens both cover it reads as two problems.
            if (str_contains(strtolower($name), $token)) {
                $offenders[] = $name.' ('.$what.') — '.$origin;

                break;
            }
        }
    }

    sort($offenders);

    return $offenders;
}

/** The lock the phone's own vendor tree is installed from, reachable from either root. */
function analyticsMobileLockPath(): string
{
    foreach ([base_path('composer.lock'), base_path('mobile-app/composer.lock')] as $candidate) {
        if (! is_file($candidate)) {
            continue;
        }

        $decoded = json_decode((string) file_get_contents($candidate), true);

        foreach ($decoded['packages'] ?? [] as $package) {
            if (($package['name'] ?? null) === 'nativephp/mobile') {
                return $candidate;
            }
        }
    }

    throw new RuntimeException('no mobile composer.lock reachable from '.base_path());
}

/**
 * Both sections, deliberately. `cleanup_exclude_files` in
 * mobile-app/config/nativephp.php does not trim vendor/ — the bundle ships a
 * classmap generated from the full tree and dies on boot without the files it
 * names — so a require-dev package is as present on the phone as a runtime one.
 *
 * @return array<string, string>
 */
function analyticsMobilePackages(): array
{
    $lock = analyticsMobileLockPath();

    /** @var array{packages?: list<array{name?: string}>, packages-dev?: list<array{name?: string}>} $decoded */
    $decoded = json_decode((string) file_get_contents($lock), true, flags: JSON_THROW_ON_ERROR);

    $packages = [];

    foreach (['packages', 'packages-dev'] as $section) {
        foreach ($decoded[$section] ?? [] as $package) {
            $name = $package['name'] ?? null;

            if (is_string($name)) {
                $packages[$name] = $section.' of '.str_replace(base_path().'/', '', $lock);
            }
        }
    }

    return $packages;
}

/**
 * Every nativephp.json a mobile build compiles plugins from. Globbed at the one
 * depth a package sits at rather than walked: a recursive descent of two vendor
 * trees costs seconds and reaches fixtures nothing installs.
 *
 * @return list<string>
 */
function analyticsPluginManifestPaths(): array
{
    $paths = [];

    foreach (['nativephp-plugins', 'mobile-app/nativephp-plugins', 'vendor', 'mobile-app/vendor'] as $root) {
        foreach (['/*/nativephp.json', '/*/*/nativephp.json'] as $shape) {
            $paths = array_merge($paths, glob(base_path($root).$shape) ?: []);
        }
    }

    $paths = array_values(array_unique($paths));
    sort($paths);

    return $paths;
}

/**
 * The native coordinates a plugin manifest adds to the merged build: Gradle
 * dependencies on Android, Swift packages and CocoaPods on iOS. None of these
 * appears in any Composer manifest, so nothing else in the suite can see them.
 *
 * @return array<string, string>
 */
function analyticsPluginNativeDependencies(): array
{
    $found = [];

    foreach (analyticsPluginManifestPaths() as $path) {
        /** @var array{android?: array{dependencies?: array<string, mixed>}, ios?: array{dependencies?: array<string, mixed>}} $manifest */
        $manifest = json_decode((string) file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);

        $origin = str_replace(base_path().'/', '', $path);

        foreach (['android', 'ios'] as $platform) {
            foreach ((array) ($manifest[$platform]['dependencies'] ?? []) as $configuration => $coordinates) {
                foreach ((array) $coordinates as $coordinate) {
                    if (is_string($coordinate) && $coordinate !== '') {
                        $found[$coordinate] = $origin.' ('.$platform.'.'.$configuration.')';
                    }
                }
            }
        }
    }

    return $found;
}

it('detects an analytics dependency when one is present', function (): void {
    // A scan that has never matched anything is indistinguishable from one that
    // cannot match anything, and the two assertions below are both silence.
    expect(analyticsOffendersAmong([
        'sentry/sentry-laravel' => 'a planted Composer package',
        'com.google.firebase:firebase-crashlytics:19.0.0' => 'a planted Gradle coordinate',
    ]))->toHaveCount(2);
});

it('has manifests and packages to read in the first place', function (): void {
    expect(analyticsMobilePackages())->not->toBe([]);

    // The vendored plugin manifests only exist under the mobile root. From the
    // desktop root the path-repository plugin is the whole set, and finding
    // none at all would mean the walk is looking in the wrong place.
    expect(analyticsPluginManifestPaths())->not->toBe([]);
});

it('ships no Composer package carrying analytics, telemetry or crash reporting', function (): void {
    $offenders = analyticsOffendersAmong(analyticsMobilePackages());

    expect($offenders)->toBe([], sprintf(
        "The store privacy declaration answers \"nothing collected\", and a package\n".
        "in the phone's vendor tree makes that a false statement rather than a\n".
        "stale one. require-dev counts: the mobile bundle does not trim vendor/.\n".
        "Offenders:\n  %s",
        implode("\n  ", $offenders),
    ));
});

it('names no analytics coordinate in any mobile plugin manifest', function (): void {
    $offenders = analyticsOffendersAmong(analyticsPluginNativeDependencies());

    expect($offenders)->toBe([], sprintf(
        "A NativePHP plugin's nativephp.json adds Gradle and CocoaPods coordinates\n".
        "straight into the merged native build. No Composer tooling reads them, so\n".
        "this is the only place the declaration can be checked against them.\n".
        "Offenders:\n  %s",
        implode("\n  ", $offenders),
    ));
});
