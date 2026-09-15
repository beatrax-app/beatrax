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
