<?php

declare(strict_types=1);

use Modules\Core\Public\Support\PatternScan;

// The Ed25519 chain proves a manifest and a checksum file came from this
// pipeline. It says nothing about whether this pipeline built the binaries they
// name — a signed list of hashes is a signed list of whatever was on disk.
// Provenance is the other half: each artefact bound to the workflow, repository
// and commit that produced it, signed by Sigstore with the job's own OIDC
// identity.
//
// Three things make it real rather than present, and each is pinned below: the
// attestation has to read the checksum file (so its subjects are the published
// set and not a glob), the bundle has to reach the release page (the
// attestation API is not what a downloader reads), and the publish job has to
// hold the two scopes Sigstore needs.
// @link ../../.github/workflows/release.yml

const PROVENANCE_WORKFLOW = '.github/workflows/release.yml';

/** the one root both composer roots agree on — mobile-app/Modules is a symlink onto this tree */
function provenanceRead(string $relative): string
{
    foreach ([$relative, '../'.$relative] as $candidate) {
        $path = base_path($candidate);

        if (is_file($path)) {
            return (string) file_get_contents($path);
        }
    }

    return '';
}

/** One job's body, so a step is read against the job it actually sits in. */
function provenanceJob(string $job): string
{
    $body = provenanceRead(PROVENANCE_WORKFLOW);
    $at = strpos($body, sprintf("\n    %s:\n", $job));

    if ($at === false) {
        return '';
    }

    $next = preg_match('/\n    [a-z][a-z0-9-]*:\n/', $body, $m, PREG_OFFSET_CAPTURE, $at + 1) === 1
        ? $m[0][1]
        : strlen($body);

    return substr($body, $at, $next - $at);
}

it('attests the set the checksum file covers, before the release is published', function (): void {
    $publish = provenanceJob('publish');
    expect($publish)->not->toBe('', 'The release workflow has no publish job, so this rule read nothing about what it attests.');

    $attestAt = strpos($publish, 'actions/attest-build-provenance@');
    expect($attestAt)->not->toBeFalse('The publish job attests nothing, so every artefact reaches the page with no statement of which build produced it.');

    // Driven from the checksum file, so the subjects are exactly what the
    // release publishes. A path glob drifts from that set silently.
    expect(str_contains($publish, 'subject-checksums:'))->toBeTrue('The attestation names its subjects by something other than the checksum file, so the set it covers can drift from the set the release publishes.');

    // After the checksum file exists, and before the release that carries it.
    $checksumAt = strpos($publish, 'name: Write the checksum file');
    expect($checksumAt)->not->toBeFalse('The checksum step was renamed, so this rule cannot tell whether the attestation reads a file that exists yet.');
    expect($checksumAt)->toBeLessThan($attestAt, 'The attestation runs before the checksum file is written, so it reads a subject list that does not exist.');

    $publishAt = strpos($publish, 'name: Publish the GitHub Release');
    expect($publishAt)->not->toBeFalse('The publishing step was renamed, so this rule cannot tell whether the attestation happens before it.');
    expect($attestAt)->toBeLessThan($publishAt, 'The attestation runs after the release is published, so the page goes up without it.');
});

it('puts the bundle where a downloader can reach it', function (): void {
    $publish = provenanceJob('publish');

    // `gh attestation verify` reads GitHub's attestation store. Somebody with a
    // downloaded file and this page reads the page.
    expect(str_contains($publish, '.intoto.jsonl'))->toBeTrue('The attestation is never written onto the release page, so it exists only in the attestation API and no downloader can reach it.');
    expect(str_contains($publish, 'steps.attest.outputs.bundle-path'))->toBeTrue('Nothing reads the attestation step\'s bundle, so whatever lands on the page is not what the step produced.');

    // An attestation carries its own Sigstore signature, which is why the
    // coverage check exempts it the way it already exempts a detached `.sig`.
    $verify = provenanceJob('verify-published');
    expect($verify)->not->toBe('', 'The release workflow has no verify-published job, so this rule read nothing about what it accepts.');
    expect(str_contains($verify, '*.intoto.jsonl'))->toBeTrue('verify-published does not exempt the attestation bundle, so it fails the release for an asset the checksum file cannot list.');
});

it('gives the publish job the two scopes Sigstore needs, and no others the top level', function (): void {
    $publish = provenanceJob('publish');

    expect(str_contains($publish, 'id-token: write'))->toBeTrue('The publish job cannot mint an OIDC token, so the attestation step has no identity to sign with.');
    expect(str_contains($publish, 'attestations: write'))->toBeTrue('The publish job cannot write an attestation, so the step fails at the point it would record one.');

    // The point of raising them here is that they are not raised everywhere.
    $body = provenanceRead(PROVENANCE_WORKFLOW);
    $top = PatternScan::first('/^permissions:\n(?<block>(?:[ \t]+\S.*\n)+)/m', $body);
    expect($top)->not->toBeEmpty('The release workflow has no top-level permissions block, so its jobs take the repository default rather than a stated one.');
    expect(str_contains($top['block'], 'id-token'))->toBeFalse('id-token is granted at the top level, so every job in the release can mint an OIDC identity, not just the one that signs with it.');
    expect(str_contains($top['block'], 'attestations'))->toBeFalse('attestations is granted at the top level, so every job in the release can write one.');
});
