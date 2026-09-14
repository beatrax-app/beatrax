<?php

declare(strict_types=1);

// A platform bundle is smoke-tested by being launched and asked for its health
// endpoint, before it is uploaded. The release workflow verified
// signatures, read bundle contents and scanned Android's merged permissions,
// and launched nothing: every one of those passes for a bundle that dies on
// start. The workflow said so in a comment, claiming an Electron bundle cannot
// be launched on a hosted runner; xvfb is the display it was missing.
//
// All three desktop legs now have it, and each is pinned inside its own job
// rather than by position in the file, so a step that drifts into the wrong
// job fails here instead of reading as covered. Android is deliberately not in
// this list: an APK is installed onto a device or an emulator and has no
// process a runner can ask for /health, so the same shape does not apply to it.
// @link ../../scripts/desktop_smoke.sh

const LAUNCH_WORKFLOW = '.github/workflows/release.yml';

const LAUNCH_SCRIPT = 'scripts/desktop_smoke.sh';

/** the one root both composer roots agree on — mobile-app/Modules is a symlink onto this tree */
function launchRead(string $relative): string
{
    foreach ([$relative, '../'.$relative] as $candidate) {
        $path = base_path($candidate);

        if (is_file($path)) {
            return (string) file_get_contents($path);
        }
    }

    return '';
}

it('finds the workflow and the smoke script to hold to each other', function (): void {
    expect(launchRead(LAUNCH_WORKFLOW))->not->toBe('', 'The release workflow is not where this rule looks, so it read nothing and would pass over a pipeline that launches nothing.')
        ->and(launchRead(LAUNCH_SCRIPT))->not->toBe('', 'The smoke script is not where this rule looks.');
});

/** One job's body, so a step is read against the job it actually sits in. */
function launchJobBody(string $job): string
{
    $workflow = launchRead(LAUNCH_WORKFLOW);
    $at = strpos($workflow, sprintf("\n    %s:\n", $job));

    if ($at === false) {
        return '';
    }

    $next = preg_match('/\n    [a-z][a-z0-9-]*:\n/', $workflow, $m, PREG_OFFSET_CAPTURE, $at + 1) === 1
        ? $m[0][1]
        : strlen($workflow);

    return substr($workflow, $at, $next - $at);
}

it('launches every desktop bundle and asks it for health before uploading it', function (string $job, string $upload): void {
    $body = launchJobBody($job);
    expect($body)->not->toBe('', sprintf('The release workflow has no %s job, so this rule read nothing about the bundle it builds.', $job));

    $smokeAt = strpos($body, LAUNCH_SCRIPT);
    expect($smokeAt)->not->toBeFalse(sprintf('No step in %s runs %s, so that bundle is published without anything ever starting it.', $job, LAUNCH_SCRIPT));

    // "before upload" is the half a step in the wrong place loses: a bundle
    // smoke-tested after upload has already been published when the test
    // speaks.
    $uploadAt = strpos($body, sprintf('name: %s', $upload));
    expect($uploadAt)->not->toBeFalse(sprintf('The %s upload step is no longer called "%s", so this rule can no longer tell whether the smoke test runs before it.', $job, $upload));
    expect($smokeAt)->toBeLessThan($uploadAt, sprintf('The smoke test in %s runs after its upload, so a bundle that cannot start is published before anything asks it.', $job));
})->with([
    'linux' => ['build-linux', 'Upload Linux artifacts'],
    'macos' => ['build-macos', 'Upload macOS artifacts (arm64)'],
    'windows' => ['build-windows', 'Upload Windows artifacts'],
]);

it('asks the bundle for the version the tag built, not merely for a reply', function (): void {
    $script = launchRead(LAUNCH_SCRIPT);

    // A probe that accepts any 200 passes for a bundle built from the wrong
    // ref, and for whatever else happens to be listening on the port.
    // str_contains rather than toContain: toContain reads every argument as
    // another needle, so a trailing explanation becomes a second search.
    expect(str_contains($script, 'app_version'))->toBeTrue('The smoke script never reads app_version, so it cannot tell the tagged bundle from any other build.');
    expect(str_contains($script, 'network_boundary'))->toBeTrue('The smoke script never reads network_boundary, so a bundle serving beyond loopback would pass.');
    expect(str_contains($script, 'already answers /health'))->toBeTrue('The smoke script does not check the port range is clear first, so a foreign responder would answer for a bundle that never started.');
});
