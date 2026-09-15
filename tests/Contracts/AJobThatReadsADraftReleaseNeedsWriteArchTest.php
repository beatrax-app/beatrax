<?php

declare(strict_types=1);

// A stable tag publishes as a draft, and GitHub shows a draft release only to a
// token with push access. `contents: read` does not see it: `gh release
// download` answers `release not found`, which reads as a missing release
// rather than a missing permission, and sends you looking at the publish step
// that just succeeded.
//
// Narrowing the workflow's top-level permission to `contents: read` for the
// OpenSSF Scorecard is what introduced this. publish and move-preview-feed were
// given write because they obviously write; verify-published only reads, so it
// was left on the default -- and reading a draft is the one read that needs
// write. There is no narrower scope.
// @link ../../.github/workflows/release.yml

const DRAFT_READER_WORKFLOW = '.github/workflows/release.yml';

/** @return array<string, mixed> the parsed workflow, from whichever composer root this run resolves */
function draftReaderWorkflow(): array
{
    foreach ([DRAFT_READER_WORKFLOW, '../'.DRAFT_READER_WORKFLOW] as $candidate) {
        $path = base_path($candidate);

        if (is_file($path)) {
            $body = (string) file_get_contents($path);

            // No YAML reader in the suite, so the two facts this rule needs are
            // lifted by name: the job's own permissions block, and whether the
            // release is created as a draft at all.
            return ['body' => $body];
        }
    }

    return ['body' => ''];
}

/** the body of one top-level job, lifted by indentation */
function draftReaderJob(string $workflow, string $job): string
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
            if ($line !== '' && ! str_starts_with($line, '        ')) {
                break;
            }

            $body[] = $line;
        }
    }

    return implode("\n", $body);
}

it('still creates the release as a draft, which is what makes this rule necessary', function (): void {
    $workflow = draftReaderWorkflow()['body'];
    expect($workflow)->not->toBe('', sprintf('%s is not where this rule looks.', DRAFT_READER_WORKFLOW));

    expect(str_contains($workflow, 'draft: true'))
        ->toBeTrue('The release is no longer created as a draft, so the permission rule below guards a condition that no longer exists -- retarget it or delete it.');
});

it('gives every job that reads the draft the access a draft requires', function (): void {
    $workflow = draftReaderWorkflow()['body'];

    foreach (['verify-published', 'publish'] as $job) {
        $body = draftReaderJob($workflow, $job);
        expect($body)->not->toBe('', sprintf('The %s job was not found, so this rule read nothing.', $job));

        expect(str_contains($body, 'contents: write'))
            ->toBeTrue(sprintf('%s reads the drafted release but does not declare contents: write. A draft is invisible to a read-only token, and the failure reads as `release not found` rather than as a permission.', $job));
    }
});

it('leaves every job that touches no release on the narrow default', function (): void {
    $workflow = draftReaderWorkflow()['body'];

    // The narrowing is the point: only the jobs that reach the release page
    // carry write. A build that gains it has gained it by accident.
    foreach (['gate', 'build-linux', 'build-windows', 'build-macos', 'build-android', 'smoke-server', 'spec-gate'] as $job) {
        $body = draftReaderJob($workflow, $job);
        expect($body)->not->toBe('', sprintf('The %s job was not found, so this rule read nothing.', $job));

        expect(str_contains($body, 'contents: write'))
            ->toBeFalse(sprintf('%s declares contents: write, but it never reads or writes the release page -- the top-level read-only default is what keeps the Scorecard token-permissions score.', $job));
    }
});
