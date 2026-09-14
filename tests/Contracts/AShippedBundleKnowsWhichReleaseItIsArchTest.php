<?php

declare(strict_types=1);

use Modules\Core\Public\Support\PatternScan;

// A bundle learns its own version from the `.env` it ships with, and nothing
// was writing it there. NATIVEPHP_APP_VERSION was exported around the build
// step, which is where electron-builder reads it to stamp the package — the
// PHP runtime inside the installed app never saw it, so every shipped desktop
// bundle answered `0.0.0-dev`.
//
// Three readers, one cause: the sidebar's version chip renders
// config('nativephp.version'); ElectronUpdateChannel::installedVersion()
// measures an offered release against it; and /health reports it. v2.0.0-rc.3
// is where it surfaced, because the Windows bundle launched, answered, and
// said `dev` when the tag said 2.0.0-rc.3.
// @link ../../.github/actions/stage-shipped-env/action.yml

const SHIPPED_VERSION_ACTION = '.github/actions/stage-shipped-env/action.yml';

const SHIPPED_VERSION_WORKFLOWS = ['.github/workflows/release.yml', '.github/workflows/release-build.yml'];

/** the one root both composer roots agree on — mobile-app/Modules is a symlink onto this tree */
function shippedVersionRead(string $relative): string
{
    foreach ([$relative, '../'.$relative] as $candidate) {
        $path = base_path($candidate);

        if (is_file($path)) {
            return (string) file_get_contents($path);
        }
    }

    return '';
}

it('writes the version into the env the bundle carries', function (): void {
    $action = shippedVersionRead(SHIPPED_VERSION_ACTION);
    expect($action)->not->toBe('', sprintf('%s is not where this rule looks, so it read nothing about what a bundle ships with.', SHIPPED_VERSION_ACTION));

    expect(str_contains($action, 'app-version:'))->toBeTrue('The staging action takes no app-version, so a bundle has no way to be told which release it is.');
    expect(str_contains($action, 'NATIVEPHP_APP_VERSION='))->toBeTrue('The staging action never writes NATIVEPHP_APP_VERSION, so the runtime falls back to its development default.');

    // The leading `v` belongs to the tag, not to the version. Normalised in the
    // action so no caller can get it half right.
    expect(str_contains($action, '${APP_VERSION#v}'))->toBeTrue('The staging action writes the tag unchanged, so a bundle built from `v2.0.0` reports `v2.0.0` and every comparison against a manifest version fails.');

    // A staging step that ran is not one that wrote: read it back.
    expect(str_contains($action, 'carries no NATIVEPHP_APP_VERSION'))->toBeTrue('Nothing reads the staged file back, so a bundle that shipped without a version would do so silently — which is exactly how this survived to a release.');
});

it('tells every bundle it stages which release it is', function (string $workflow): void {
    $body = shippedVersionRead($workflow);
    expect($body)->not->toBe('', sprintf('%s is not where this rule looks, so it read nothing.', $workflow));

    // Each call that stages a shipped .env has to pass the version. One that
    // does not produces a bundle reporting 0.0.0-dev, and nothing downstream
    // fails on it.
    $stagings = substr_count($body, 'uses: ./.github/actions/stage-shipped-env');
    $versioned = substr_count($body, 'app-version:');

    expect($stagings)->toBeGreaterThan(0, sprintf('%s stages no shipped .env, so this rule cannot tell whether a bundle is told its version.', $workflow));
    expect($versioned)->toBe($stagings, sprintf('%s stages a shipped .env %d times and passes app-version %d times. A bundle staged without it reports itself as a development build to the version chip, the update comparison and /health.', $workflow, $stagings, $versioned));
})->with(SHIPPED_VERSION_WORKFLOWS);

it('reads the version from somewhere the shipped bundle actually has', function (): void {
    $snapshot = shippedVersionRead('Modules/Core/Internal/Support/RuntimeHealthSnapshot.php');
    expect($snapshot)->not->toBe('', 'The health snapshot is not where this rule looks.');

    // getenv() rather than env(): it works only because Laravel's putenv bridge
    // is on, which is the default and is not disabled here. Pinning the pair
    // together so that turning one off cannot quietly turn the other into `dev`.
    $usesGetenv = PatternScan::matches('/getenv\(\s*.NATIVEPHP_APP_VERSION./', $snapshot);
    $disabled = PatternScan::matches('/Env::disablePutenv\(/', shippedVersionRead('bootstrap/app.php'));

    expect($usesGetenv && $disabled)->toBeFalse('The health snapshot reads NATIVEPHP_APP_VERSION with getenv() while putenv is disabled, so it can never see a value that came from the shipped .env and will always answer `dev`.');
});
