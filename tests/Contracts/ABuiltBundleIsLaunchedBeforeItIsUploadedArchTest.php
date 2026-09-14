<?php

declare(strict_types=1);

// A platform bundle is smoke-tested by being launched and asked for its health
// endpoint, before it is uploaded. The release workflow verified
// signatures, read bundle contents and scanned Android's merged permissions,
// and launched nothing: every one of those passes for a bundle that dies on
// start. The workflow said so in a comment, claiming an Electron bundle cannot
// be launched on a hosted runner; xvfb is the display it was missing.
//
// This pins the Linux leg, which is the one that has it. macOS, Windows and
// Android are owed the same step, and adding one here is what makes this rule
// cover it.
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

it('launches the Linux bundle and asks it for health before uploading it', function (): void {
    $workflow = launchRead(LAUNCH_WORKFLOW);

    $smokeAt = strpos($workflow, LAUNCH_SCRIPT);
    expect($smokeAt)->not->toBeFalse('No step in the release workflow runs '.LAUNCH_SCRIPT.', so no bundle is launched before it is published.');

    // "before upload" is the half a step in the wrong place loses: a bundle
    // smoke-tested after upload has already been published when the test
    // speaks.
    $uploadAt = strpos($workflow, 'name: Upload Linux artifacts');
    expect($uploadAt)->not->toBeFalse('The Linux upload step was renamed, so this rule can no longer tell whether the smoke test runs before it.');
    expect($smokeAt)->toBeLessThan($uploadAt, 'The smoke test runs after the Linux upload, so a bundle that cannot start is published before anything asks it.');
});

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
