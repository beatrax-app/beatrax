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

// The composite action is one bash script under a `run:` key. Lifted by
// indentation, the same way the staging rule beside this one reads it, because
// the suite has no YAML reader.
function shippedVersionScript(string $action): string
{
    $lines = explode("\n", $action);
    $body = [];
    $indent = null;

    foreach ($lines as $number => $line) {
        if ($indent === null) {
            if (PatternScan::matches('/^\s+run: \|\s*$/', $line)) {
                $indent = strspn((string) ($lines[$number + 1] ?? ''), ' ');
            }

            continue;
        }

        if (trim($line) !== '' && strspn($line, ' ') < $indent) {
            break;
        }

        $body[] = substr($line, $indent);
    }

    return implode("\n", $body);
}

/** Runs it the way a bundling job would, and answers what that job would have got. */
function shippedVersionRun(string $script, string $directory, string $appVersion): int
{
    $path = $directory.'/run.sh';
    file_put_contents($path, $script);

    $command = 'cd '.escapeshellarg($directory)
        .' && EXTRA='.escapeshellarg('')
        .' APP_VERSION='.escapeshellarg($appVersion)
        .' bash '.escapeshellarg($path).' 2>&1';

    exec($command, $output, $status);

    return $status;
}

it('writes the tag into the file a bundle carries, when it is actually run', function (): void {
    $action = shippedVersionRead(SHIPPED_VERSION_ACTION);
    $script = shippedVersionScript($action);
    expect($script)->not->toBe('', 'No script could be lifted out of the staging action, so nothing below ran it.');

    $directory = sys_get_temp_dir().'/shipped-version-'.bin2hex(random_bytes(6));
    mkdir($directory);

    try {
        file_put_contents($directory.'/.env.bundled', "APP_ENV=local\nAPP_DEBUG=true\n");

        // The leading `v` belongs to the tag. A bundle reporting `v2.0.0` fails
        // every comparison against a manifest that says `2.0.0`.
        expect(shippedVersionRun($script, $directory, 'v9.9.9'))->toBe(0, 'The action exits non-zero when given a version, so this is what a bundling job would have got.');
        expect((string) file_get_contents($directory.'/.env'))->toContain('NATIVEPHP_APP_VERSION=9.9.9');
        expect((string) file_get_contents($directory.'/.env'))->not->toContain('NATIVEPHP_APP_VERSION=v9.9.9');

        // A caller that passes nothing still stages: the version is how a
        // release bundle is told which one it is, not a condition of staging.
        expect(shippedVersionRun($script, $directory, ''))->toBe(0, 'The action refuses to stage at all when no version is given, which would break every caller that does not build a release.');
        expect((string) file_get_contents($directory.'/.env'))->not->toContain('NATIVEPHP_APP_VERSION=');
    } finally {
        @unlink($directory.'/.env.bundled');
        @unlink($directory.'/.env');
        @unlink($directory.'/run.sh');
        @rmdir($directory);
    }
});
