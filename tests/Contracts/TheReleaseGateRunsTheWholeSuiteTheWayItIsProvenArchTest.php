<?php

declare(strict_types=1);

// The release gate ran `php artisan test` with no --parallel: the only serial
// whole-suite invocation in the repository. It died at test 2,728 of 17,667
// with exit 2 and printed no summary, so the job showed a red mark and no test
// output -- the shape that gets read as infrastructure flake. Every other
// invocation passes the same tests, and the gate only runs on a tag push, so
// nothing surfaced it between May and the first v2 candidate.
//
// Two halves are pinned here because losing either brings the failure back:
// the parallel runner, and the absence of a --testsuite filter that would
// quietly narrow the gate to a subset.

const GATE_WORKFLOW = '.github/workflows/release.yml';

/** the one root both composer roots agree on — mobile-app/Modules is a symlink onto this tree */
function gateWorkflowBody(): string
{
    foreach ([GATE_WORKFLOW, '../'.GATE_WORKFLOW] as $candidate) {
        $path = base_path($candidate);

        if (is_file($path)) {
            return (string) file_get_contents($path);
        }
    }

    return '';
}

/** The `run:` line of the gate's test step, which is what actually decides this. */
function gateTestCommand(): string
{
    $body = gateWorkflowBody();
    $at = strpos($body, 'name: Pest (full suite)');

    if ($at === false) {
        return '';
    }

    $line = strpos($body, 'run: php artisan test', $at);

    return $line === false
        ? ''
        : trim(substr($body, $line, (int) strpos($body, "\n", $line) - $line));
}

it('finds the gate step this rule is about', function (): void {
    expect(gateWorkflowBody())->not->toBe('', 'The release workflow is not where this rule looks, so it read nothing and would pass over any command at all.');
    expect(gateTestCommand())->not->toBe('', 'The gate no longer has a "Pest (full suite)" step running artisan test, so this rule cannot see what the release is gated on.');
});

it('runs the suite in parallel, which is the invocation the rest of CI proves', function (): void {
    expect(str_contains(gateTestCommand(), '--parallel'))->toBeTrue(
        'The gate runs the suite serially. That invocation exists nowhere else in this repository, and it exits part-way through with no summary, so the gate reports nothing about the release it is gating. Command: '.gateTestCommand()
    );
});

it('narrows the gate to no subset of the suite', function (): void {
    // A --testsuite here would gate a release on a share of the tests while
    // still reading as the full suite in the job name.
    expect(str_contains(gateTestCommand(), '--testsuite'))->toBeFalse(
        'The gate names a testsuite, so it no longer runs every suite. Command: '.gateTestCommand()
    );
});
