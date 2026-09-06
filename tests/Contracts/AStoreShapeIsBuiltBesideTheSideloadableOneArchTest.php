<?php

declare(strict_types=1);

// Play refuses an APK and takes an AAB, so the artifact a reader sideloads is
// not a submission and cannot stand in for one. Both ship: the direct download
// is not retired by store distribution, and the store shape is not optional
// because the pipeline already produces something installable.

/** @return array<string, string> workflow basename => contents */
function storeShapeWorkflows(): array
{
    foreach (['.github/workflows', '../.github/workflows'] as $candidate) {
        $directory = base_path($candidate);

        if (! is_dir($directory)) {
            continue;
        }

        $found = [];

        foreach (['release.yml', 'release-build.yml'] as $name) {
            $path = $directory.'/'.$name;

            if (is_file($path)) {
                $found[$name] = (string) file_get_contents($path);
            }
        }

        return $found;
    }

    return [];
}

it('builds the store shape in every workflow that builds the sideloadable one', function (): void {
    $workflows = storeShapeWorkflows();

    // Both Composer roots run this file, and only one of them has the
    // workflows beside it. Nothing found is that root, not a tree that obeys.
    if ($workflows === []) {
        expect(true)->toBeTrue();

        return;
    }

    $missing = [];
    $judged = 0;

    foreach ($workflows as $name => $body) {
        if (! str_contains($body, 'mobile:package-android --build-type=release')) {
            continue;
        }

        $judged++;

        if (! str_contains($body, 'mobile:package-android --build-type=bundle')) {
            $missing[] = $name.': builds the APK and never the AAB';

            continue;
        }

        // A shape nothing checked the signer of is a shape that can ship
        // debug-signed, which Play refuses on upload and nothing here would
        // have caught first.
        if (! str_contains($body, 'AAB is signed by an unexpected key')) {
            $missing[] = $name.': builds the AAB and never proves who signed it';
        }
    }

    expect($judged)->toBeGreaterThanOrEqual(
        2,
        'no workflow was found building the sideloadable APK, so this rule judged nothing',
    );

    expect($missing)->toBe([], implode("\n", $missing));
});
