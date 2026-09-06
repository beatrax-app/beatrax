<?php

declare(strict_types=1);

use Modules\Core\Public\Support\PatternScan;

// Both composer roots run this suite, and from mobile-app/ the workflows sit
// one level up; resolving only the desktop path would let the guard pass by
// reading an empty string.
function signedRecordWorkflowDirectory(): string
{
    foreach (['.github/workflows', '../.github/workflows'] as $candidate) {
        $path = base_path($candidate);

        if (is_dir($path)) {
            return $path;
        }
    }

    return '';
}

// release.yml is the only workflow that puts a file on the release page, which
// is the premise every rule below reads one file on. The last case holds it.
function signedRecordWorkflow(): string
{
    $path = signedRecordWorkflowDirectory().'/release.yml';

    return is_file($path) ? (string) file_get_contents($path) : '';
}

/**
 * Steps are named at twelve spaces of indentation and run to the next one.
 *
 * @return array<string, string>
 */
function signedRecordSteps(string $source): array
{
    $steps = [];
    $current = null;

    foreach (explode("\n", $source) as $line) {
        $named = PatternScan::first('/^ {12}- name: (.+)$/', $line);

        if ($named !== []) {
            $current = trim($named[1]);
            $steps[$current] = '';

            continue;
        }

        if ($current !== null) {
            $steps[$current] .= $line."\n";
        }
    }

    return $steps;
}

/**
 * @param  array<string, string>  $steps
 * @return list<string>
 */
function signedRecordStepsContaining(array $steps, string $needle): array
{
    return array_values(array_filter($steps, static fn (string $body): bool => str_contains($body, $needle)));
}

/**
 * The file shapes a `find` accepts, as its `-name` predicates.
 *
 * @return list<string>
 */
function signedRecordNamePredicates(string $body): array
{
    $names = array_map(
        static fn (array $set): string => $set[1],
        PatternScan::sets("/-name '([^']+)'/", $body),
    );

    // A `.sig` is checked by the key rather than by a hash, and every pass
    // derives its own signature path from the file it covers.
    $names = array_values(array_filter($names, static fn (string $name): bool => ! str_ends_with($name, '.sig')));

    sort($names);

    return $names;
}

it('hashes everything the release downloaded, with no filter that could narrow it', function (): void {
    $workflow = signedRecordWorkflow();

    expect($workflow)->not->toBe('', 'release.yml was not found from either composer root');

    $steps = signedRecordSteps($workflow);
    $hashing = signedRecordStepsContaining($steps, 'sha256sum');

    expect($hashing)->toHaveCount(1, implode("\n", [
        'Exactly one step writes the release checksum file. Two would each',
        'cover part of the page and neither would say which part.',
    ]));

    $body = $hashing[0] ?? '';

    expect(str_contains($body, 'find artifacts -type f'))->toBeTrue(implode("\n", [
        'The checksum file has to be written over the whole downloaded tree.',
        'Three of the published artefacts -- the .msi, the .deb and the .apk --',
        'appear in no publisher manifest, because a manifest binds exactly the',
        'one installer its path: field names. The checksum file is the only',
        'thing they are checked against, so a build job that starts uploading a',
        'new shape has to be covered the day it appears rather than the day',
        'somebody remembers this step.',
    ]));

    expect(signedRecordNamePredicates($body))->toBe([], implode("\n", [
        'The checksum walk names specific files, so it covers those and nothing',
        'else. An artefact added to any build job would then reach the release',
        'page with nothing to verify it against, which is the state this file',
        'was written to end. Walk the tree unfiltered.',
    ]));

    $download = signedRecordStepsContaining($steps, 'actions/download-artifact@');

    expect($download)->toHaveCount(1, implode("\n", [
        'Exactly one step downloads the build artefacts. Two would each bring down part',
        'of the tree the checksum walk above reads, and the file would cover whichever',
        'part happened to be there.',
    ]));

    expect(str_contains($download[0] ?? '', 'path: artifacts'))->toBeTrue(implode("\n", [
        'The artefacts land somewhere other than artifacts/, which is the tree',
        'the checksum walk above reads. The two have to be the same directory',
        'or the file covers nothing.',
    ]));
});

it('signs, re-downloads and re-verifies the same shapes', function (): void {
    $steps = signedRecordSteps(signedRecordWorkflow());

    $signing = signedRecordStepsContaining($steps, 'sodium_crypto_sign_detached');
    $verifying = signedRecordStepsContaining($steps, 'sodium_crypto_sign_verify_detached');
    $downloading = signedRecordStepsContaining($steps, 'gh release download');

    expect($signing)->toHaveCount(1, 'One step signs the release; a second would sign part of it with nothing saying which part.')
        ->and($verifying)->toHaveCount(1, 'One step verifies the published page against the signature.')
        ->and($downloading)->toHaveCount(1, 'One step re-downloads from the page, which is the only evidence about the page there is.');

    $signed = signedRecordNamePredicates($signing[0] ?? '');
    $verified = signedRecordNamePredicates($verifying[0] ?? '');

    $requested = array_map(
        static fn (array $set): string => $set[1],
        PatternScan::sets("/--pattern '([^']+)'/", $downloading[0] ?? ''),
    );
    // A `.sig` is checked by the key rather than by a hash, here for the same
    // reason it is dropped from the find predicates above: every pass derives
    // its own signature path from the file it covers.
    $requested = array_values(array_unique(array_filter(
        $requested,
        static fn (string $pattern): bool => ! str_ends_with($pattern, '.sig'),
    )));
    sort($requested);

    $drift = implode("\n", [
        'What the publish job signs, what the verify job asks the release page',
        'for, and what it then checks the signature of have drifted apart:',
        '  signed:     '.implode(', ', $signed),
        '  downloaded: '.implode(', ', $requested),
        '  verified:   '.implode(', ', $verified),
        '',
        'A shape signed but not downloaded is verified by nobody. A shape',
        'downloaded but not verified is a file the job holds and never reads.',
        'Add it to all three or to none.',
    ]);

    expect($signed)->toBe($requested, $drift)
        ->and($verified)->toBe($requested, $drift)
        ->and(in_array('*-checksums.txt', $signed, true))->toBeTrue($drift);
});

it('checks the page against the checksum file rather than the tree that produced it', function (): void {
    $workflow = signedRecordWorkflow();
    $steps = signedRecordSteps($workflow);

    $coverage = signedRecordStepsContaining($steps, 'uncovered');

    expect($coverage)->toHaveCount(1, implode("\n", [
        'No step compares the release page against the checksum file. Without',
        'one, a checksum file that quietly stopped covering an artefact looks',
        'exactly like one that covers them all.',
    ]));

    $body = $coverage[0] ?? '';

    expect(str_contains($body, 'gh release view'))->toBeTrue(implode("\n", [
        'The coverage check reads its asset list from somewhere other than the',
        'release page. The tree the publish job holds is not evidence about the',
        'page: a silently skipped upload or a partial CDN write leaves the two',
        'disagreeing, which is the whole reason a verify-published job exists.',
    ]));

    // The publish job is where the checksum file is written, so a coverage
    // check living there would be asking the writer whether it wrote.
    $publishJob = PatternScan::first('/\n {4}publish:\n(.*?)\n {4}[a-z-]+:\n/s', $workflow);

    $published = $publishJob[1] ?? '';

    expect($published)->not->toBe('', 'release.yml no longer has a publish job this guard can find.');

    expect(str_contains($published, 'gh release view'))->toBeFalse(implode("\n", [
        'The coverage check sits in the publish job. It has to run after',
        'publishing, against the published page, or it proves only that the',
        'job agrees with itself.',
    ]));
});

// The premise the three rules above are read on. Written down rather than
// assumed: a second workflow uploading to the page would put an artefact there
// that this file has never looked at, with every case still green.
it('leaves release.yml the only workflow that puts a file on the release page', function (): void {
    $directory = signedRecordWorkflowDirectory();

    expect($directory)->not->toBe('', 'no .github/workflows directory was found from either composer root');

    $workflows = (array) glob($directory.'/*.yml');

    expect(count($workflows))->toBeGreaterThan(5, 'Read '.count($workflows).' workflows, too few to have read the directory.');

    $uploaders = [];

    foreach ($workflows as $path) {
        $source = (string) file_get_contents((string) $path);

        foreach (['action-gh-release', 'gh release upload', 'gh release create'] as $upload) {
            if (str_contains($source, $upload) && basename((string) $path) !== 'release.yml') {
                $uploaders[] = basename((string) $path).' — '.$upload;
            }
        }
    }

    sort($uploaders);

    expect($uploaders)->toBe([], implode("\n  ", [
        'These workflows put a file on the release page, and the rules above read release.yml alone:',
        ...$uploaders,
        'Either the upload belongs in release.yml beside the checksum walk that covers it,',
        'or this file has to read both.',
    ]));
});

it('reads a step by its own indentation, and the shapes a narrowed find accepts', function (): void {
    $workflow = implode("\n", [
        'jobs:',
        '  publish:',
        '    steps:',
        '            - name: Write the checksums',
        "              run: find artifacts -type f -name '*.msi' -o -name '*.msi.sig'",
        '            - name: Upload',
        '              run: gh release upload',
    ]);

    $steps = signedRecordSteps($workflow);

    expect(array_keys($steps))->toBe(
        ['Write the checksums', 'Upload'],
        'a step is named at twelve spaces and runs to the next one, which is how each rule above finds its body',
    );

    expect(signedRecordNamePredicates($steps['Write the checksums'] ?? ''))->toBe(
        ['*.msi'],
        'a find narrowed to named shapes is the whole defect this rule reports, and a .sig is checked by the key rather than by a hash',
    );

    expect(signedRecordNamePredicates($steps['Upload'] ?? ''))->toBe(
        [],
        'a step naming no shape at all is the unfiltered walk the rule asks for',
    );
});
