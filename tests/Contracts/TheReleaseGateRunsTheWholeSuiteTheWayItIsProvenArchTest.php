<?php

declare(strict_types=1);

// The release gate ran `php artisan test` with no --parallel: the only serial
// whole-suite invocation in the repository. It died at test 2,728 of 17,667
// with exit 2 and printed no summary, so the job showed a red mark and no test
// output -- the shape that gets read as infrastructure flake. Every other
// invocation passes the same tests, and the gate only runs on a tag push, so
// nothing surfaced it between May and the first v2 candidate.
//
// Three halves are pinned here because losing any one brings a red gate back:
// the parallel runner, the absence of a --testsuite filter that would quietly
// narrow the gate to a subset, and a budget big enough to reach the end. The
// three ci.yml shards sum to 11m55s of Pest and already saturate their cores,
// so one job running all of them does not compress -- 15 minutes was a budget
// set for a run that had never once finished.

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

/** The gate job's own timeout-minutes, read from its block rather than the file's first match. */
function gateTimeoutMinutes(): ?int
{
    $body = gateWorkflowBody();
    $at = strpos($body, "\n    gate:\n");

    if ($at === false) {
        return null;
    }

    return preg_match('/^\s+timeout-minutes:\s*(\d+)/m', substr($body, $at, 600), $m) === 1
        ? (int) $m[1]
        : null;
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

it('gives the whole suite longer than the whole suite takes', function (): void {
    // Measured on the rc.1 tag: 288s of setup, Pint and PHPStan before Pest
    // even starts. Caching Pint and PHPStan the way ci.yml does buys most of
    // that back, but the floor has to clear ~12 minutes of tests regardless.
    expect(gateTimeoutMinutes())->not->toBeNull('The gate job has no timeout-minutes this rule can read, so nothing here checks that the suite is given time to finish.');

    expect(gateTimeoutMinutes())->toBeGreaterThanOrEqual(
        25,
        'The gate is given '.gateTimeoutMinutes().' minutes for a suite whose tests alone take about 12. It would be cancelled part-way through, which reads as a red gate carrying no test output -- the same shape as the serial failure this rule exists to prevent.'
    );
});
