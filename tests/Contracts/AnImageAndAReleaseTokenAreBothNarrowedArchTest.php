<?php

declare(strict_types=1);

// Two supply-chain properties an OpenSSF Scorecard run found open on this
// repository, pinned here because nothing else re-reads them: a base image
// named only by tag can be moved under the build by whoever owns the tag, and
// a workflow token that can write from every job hands that write to every
// step those jobs run.
//
// Not pinned here, deliberately: the `beatrax-app/spec/...@v1` workflow
// references. Scorecard reads them as unpinned third-party actions; they are
// first-party, and the moving major tag is the shared-workflow contract, which
// has its own check that the tag still matches the default branch.
// @link ../../renovate.json

const SUPPLY_RELEASE_WORKFLOWS = ['.github/workflows/release.yml', '.github/workflows/release-build.yml'];

/** the one root both composer roots agree on — mobile-app/Modules is a symlink onto this tree */
function supplyRead(string $relative): string
{
    foreach ([$relative, '../'.$relative] as $candidate) {
        $path = base_path($candidate);

        if (is_file($path)) {
            return (string) file_get_contents($path);
        }
    }

    return '';
}

/** @return list<string> every tracked Dockerfile, relative to the app root */
function supplyDockerfiles(): array
{
    $found = [];

    foreach (['deploy/server/Dockerfile', 'docker/php8.5/Dockerfile'] as $known) {
        if (supplyRead($known) !== '') {
            $found[] = $known;
        }
    }

    return $found;
}

it('names every base image by digest, not by a tag someone else can move', function (): void {
    $files = supplyDockerfiles();

    // Counted first: a resolver that found no Dockerfiles would otherwise
    // report a fully pinned tree.
    expect($files)->not->toBeEmpty('This rule found no Dockerfile at all, so it proved nothing about base images.');

    $unpinned = [];
    $checked = 0;

    foreach ($files as $file) {
        foreach (explode("\n", supplyRead($file)) as $number => $line) {
            if (preg_match('/^FROM\s+(?<ref>\S+)/i', $line, $m) !== 1) {
                continue;
            }

            // A later stage may build on an earlier one by name, which is
            // internal to the file and has no digest to carry.
            if (! str_contains($m['ref'], '/') && ! str_contains($m['ref'], ':')) {
                continue;
            }

            $checked++;

            if (! str_contains($m['ref'], '@sha256:')) {
                $unpinned[] = sprintf('%s:%d %s', $file, $number + 1, $m['ref']);
            }
        }
    }

    expect($checked)->toBeGreaterThan(0, 'No FROM line was read, so nothing here is evidence that base images are pinned.');
    expect($unpinned)->toBe([], sprintf('A base image is named by tag alone, so whoever owns the tag chooses what this builds: %s', implode(', ', $unpinned)));
});

it('does not let the release token write from every job', function (string $workflow): void {
    $body = supplyRead($workflow);
    expect($body)->not->toBe('', sprintf('%s is not where this rule looks, so it read nothing.', $workflow));

    // The top-level block is the one at column zero; a job's own block is
    // indented, and raising it there is the point.
    expect(preg_match('/^permissions:\n(?<block>(?:[ \t]+\S.*\n)+)/m', $body, $m))->toBe(1, sprintf('%s has no top-level permissions block, so its jobs take the repository default rather than a stated one.', $workflow));

    expect(str_contains($m['block'], 'write'))->toBeFalse(sprintf('%s grants write at the top level, so every job it runs — checkout, build, upload — holds a token that can write to the repository. Grant it on the job that publishes instead. Block: %s', $workflow, trim($m['block'])));
})->with(SUPPLY_RELEASE_WORKFLOWS);
