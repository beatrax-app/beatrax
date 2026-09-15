<?php

declare(strict_types=1);

// GITHUB_REPO is set at workflow level for NativePHP's updater, which wants a
// bare repository name. git-cliff reads the same variable as the fallback for
// --github-repo and requires OWNER/REPO. Every build passed, every smoke passed,
// and the publish job then died on `invalid value 'beatrax'` -- the most
// expensive place in the pipeline to discover a name collision.
//
// Two tools, one variable name, two formats. The publish job runs the second
// kind and never runs native:build, so it states the owner/repo form for itself.
// @link ../../.github/workflows/release.yml

const PUBLISH_REPO_WORKFLOW = '.github/workflows/release.yml';

function publishRepoWorkflow(): string
{
    foreach ([PUBLISH_REPO_WORKFLOW, '../'.PUBLISH_REPO_WORKFLOW] as $candidate) {
        $path = base_path($candidate);

        if (is_file($path)) {
            return (string) file_get_contents($path);
        }
    }

    return '';
}

/** the body of one top-level job, lifted by indentation because the suite has no YAML reader */
function publishRepoJob(string $workflow, string $job): string
{
    $lines = explode("\n", $workflow);
    $body = [];
    $inside = false;

    foreach ($lines as $line) {
        if ($line === sprintf('    %s:', $job)) {
            $inside = true;

            continue;
        }

        if ($inside) {
            // A non-blank line at job indentation ends this job.
            if ($line !== '' && ! str_starts_with($line, '        ') && ! str_starts_with($line, '         ')) {
                break;
            }

            $body[] = $line;
        }
    }

    return implode("\n", $body);
}

it('finds the publish job it is written to guard', function (): void {
    $workflow = publishRepoWorkflow();
    expect($workflow)->not->toBe('', sprintf('%s is not where this rule looks.', PUBLISH_REPO_WORKFLOW));

    $publish = publishRepoJob($workflow, 'publish');
    expect($publish)->not->toBe('', 'No publish job was lifted, so the rule below would pass against nothing.');
    expect(str_contains($publish, 'git-cliff-action'))->toBeTrue('The publish job no longer runs git-cliff, so this rule is aimed at a step that is gone -- retarget it or delete it.');
});

it('hands the release-notes step a repository name it can parse', function (): void {
    $publish = publishRepoJob(publishRepoWorkflow(), 'publish');

    expect(str_contains($publish, 'GITHUB_REPO: ${{ github.repository }}'))
        ->toBeTrue('The publish job does not restate GITHUB_REPO as the owner/repo form, so it inherits the bare name NativePHP wants and git-cliff refuses it.');

    expect(str_contains($publish, 'GITHUB_REPO: ${{ github.event.repository.name }}'))
        ->toBeFalse('The publish job sets GITHUB_REPO to the bare repository name, which git-cliff rejects with `invalid value` after every build has already run.');
});

it('leaves the build jobs on the name NativePHP wants', function (): void {
    $workflow = publishRepoWorkflow();

    // The workflow-level value is the updater's, and the builds depend on it.
    // Correcting it globally would fix git-cliff and break every bundle's feed.
    expect(str_contains($workflow, 'GITHUB_REPO: ${{ github.event.repository.name }}'))
        ->toBeTrue('The workflow-level GITHUB_REPO is no longer the bare repository name NativePHP reads, so the updater feed in every built bundle would name the wrong repo.');
});

it('builds the notes without reaching for the network', function (): void {
    $publish = publishRepoJob(publishRepoWorkflow(), 'publish');

    // Naming the repository is what switches git-cliff's GitHub integration on.
    // Measured: a valid name with no token panics on a 401, and with a token it
    // pages /commits across the whole span -- roughly nineteen requests for a
    // major -- inside a ten-minute job. `--offline` produces byte-identical
    // notes, because this config builds its links from a literal URL.
    expect(str_contains($publish, '--offline'))
        ->toBeTrue('The release-notes step does not run git-cliff with --offline, so naming the repository turns on a GitHub fetch the changelog does not need and a rate limit can fail the publish.');
});

it('moves the release body by path, never through the environment', function (): void {
    $publish = publishRepoJob(publishRepoWorkflow(), 'publish');

    // A single environment variable is capped at MAX_ARG_STRLEN -- 128KB on
    // Linux -- and a major release's notes are about 246KB. Interpolating them
    // made execve fail with "Argument list too long", so the step written to
    // trim an oversized body was the one thing an oversized body could not
    // reach. It never reproduced on macOS, whose cap is larger.
    expect(str_contains($publish, 'steps.cliff.outputs.content'))
        ->toBeFalse('The publish job interpolates git-cliff content into the environment. A body over 128KB cannot be passed that way, and the failure is "Argument list too long" rather than anything naming the notes.');

    expect(str_contains($publish, 'steps.cliff.outputs.changelog'))
        ->toBeTrue('The publish job does not read the notes from the file git-cliff wrote, which is the only route that survives a body larger than one environment variable can hold.');
});

it('lists every published asset exactly once', function (): void {
    $publish = publishRepoJob(publishRepoWorkflow(), 'publish');

    // `artifacts/**/*` already matches the detached signatures. Naming them
    // again put all eight in the upload list twice -- 35 uploads for 27 assets
    // -- and each duplicate pair raced, one winning while the other tried to
    // update an asset mid-replace. The release failed on `Not Found` with
    // every build and both smoke tests already green.
    $duplicated = [];

    foreach (['latest*.yml.sig', 'beta*.yml.sig'] as $pattern) {
        if (str_contains($publish, 'artifacts/**/'.$pattern)) {
            $duplicated[] = $pattern;
        }
    }

    expect($duplicated)->toBe([], sprintf(
        'The upload list names these beside `artifacts/**/*`, which already matches them, so each is uploaded twice and the pair races:
  %s',
        implode('
  ', $duplicated),
    ));

    expect(str_contains($publish, 'files: artifacts/**/*'))
        ->toBeTrue('The publish job no longer uploads every artefact under artifacts/, so a bundle, a manifest or a signature would be missing from the release page.');
});
