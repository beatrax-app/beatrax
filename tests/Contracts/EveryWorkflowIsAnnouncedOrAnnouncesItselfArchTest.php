<?php

declare(strict_types=1);

use Modules\Core\Public\Support\PatternScan;

// Both composer roots run this suite, and from mobile-app/ the workflows sit
// one level up. Resolving only the desktop path would let the guard pass by
// reading an empty directory.
function workflowDirectory(): string
{
    foreach (['.github/workflows', '../.github/workflows'] as $candidate) {
        $path = base_path($candidate);

        if (is_dir($path)) {
            return $path;
        }
    }

    return '';
}

// Four, not three: the release announcement, the triage post and the
// maintainer queue each hold a webhook of their own, and the build log would
// otherwise report on its own reports. Each carries the secret that earns it
// the exemption, re-read against the file below, so a workflow that stops
// announcing itself fails here rather than going quiet.
const WORKFLOWS_HOLDING_THEIR_OWN_WEBHOOK = [
    'discord-build' => [
        'reason' => 'the build log itself, which listing would make report on its own reports',
        'proves' => 'secrets.DISCORD_BUILD_WEBHOOK',
    ],
    'release-announce' => [
        'reason' => 'the release announcement, posted by the workflow as the release is cut',
        'proves' => 'secrets.DISCORD_ANNOUNCE_WEBHOOK',
    ],
    'triage' => [
        'reason' => 'the triage post, sent to the maintainer channel as the issue is filed',
        'proves' => 'secrets.DISCORD_MAINTAINERS_WEBHOOK',
    ],
    'awaiting-maintainer' => [
        'reason' => 'the maintainer queue, sent to the same channel when a review has been waiting',
        'proves' => 'secrets.DISCORD_MAINTAINERS_WEBHOOK',
    ],
];

/** @return list<string> absolute paths to every workflow file, both extensions */
function workflowFiles(string $directory): array
{
    $files = array_merge(
        (array) glob($directory.'/*.yml'),
        // GitHub reads both spellings, so a rule that globs one of them is
        // silent about a workflow written with the other.
        (array) glob($directory.'/*.yaml'),
    );

    $paths = array_map(strval(...), $files);
    sort($paths);

    return array_values($paths);
}

/**
 * @return array<string, string>
 */
function workflowNames(string $directory): array
{
    $names = [];

    foreach (workflowFiles($directory) as $file) {
        $declared = PatternScan::first('/^name:[ \t]*(\S+)/m', (string) file_get_contents($file));

        if ($declared !== []) {
            $names[basename($file)] = $declared[1];
        }
    }

    return $names;
}

/**
 * @return list<string>
 */
function buildLogListens(string $source): array
{
    $inline = PatternScan::first('/^\s*workflows:[ \t]*\[([^\]]*)\]/m', $source);

    if ($inline !== []) {
        return array_values(array_filter(array_map(trim(...), explode(',', $inline[1]))));
    }

    $block = PatternScan::first('/^\s*workflows:[ \t]*\n((?:[ \t]*-[ \t]*\S+\n)+)/m', $source);

    if ($block === []) {
        return [];
    }

    return array_map(static fn (array $set): string => $set[1], PatternScan::sets('/-[ \t]*(\S+)/', $block[1]));
}

it('reports on every workflow that does not already hold its own webhook', function (): void {
    $directory = workflowDirectory();

    expect($directory)->not->toBe('', '.github/workflows was not found from either composer root');

    $names = workflowNames($directory);

    // Read before the verdict: with no file resolved, or none declaring a
    // name, the two sets below are both empty and compare equal. The floor
    // sits under today's 15.
    expect(count($names))->toBeGreaterThan(
        8,
        'the walk read '.count($names).' named workflows, which is too few to be this repository.'
    );

    expect($names)->toHaveKey('discord-build.yml');

    $expected = array_values(array_diff($names, array_keys(WORKFLOWS_HOLDING_THEIR_OWN_WEBHOOK)));
    $listens = buildLogListens((string) file_get_contents($directory.'/discord-build.yml'));

    sort($expected);
    sort($listens);

    expect($listens)->toBe($expected, sprintf(
        "discord-build.yml listens to a set that is not the workflows in this repository.\n  missing: %s\n  named but absent: %s",
        implode(', ', array_diff($expected, $listens)) ?: 'none',
        implode(', ', array_diff($listens, $expected)) ?: 'none',
    ));
});

// An exemption whose reason nobody re-checks is a claim that goes quiet the
// day it stops being true: a workflow dropped from the announce list because
// it "posts its own" and then stopped posting is a workflow nothing reports.
it('still holds each self-announcing workflow to the webhook that earned it the exemption', function (): void {
    $directory = workflowDirectory();

    expect($directory)->not->toBe('', '.github/workflows was not found from either composer root');

    $declared = workflowNames($directory);
    $expired = [];
    $absent = [];

    foreach (WORKFLOWS_HOLDING_THEIR_OWN_WEBHOOK as $workflow => $pin) {
        if (! in_array($workflow, array_values($declared), true)) {
            $absent[] = $workflow.' — '.$pin['reason'];

            continue;
        }

        $file = array_search($workflow, $declared, true);
        $source = (string) file_get_contents($directory.'/'.$file);

        if (! str_contains($source, $pin['proves'])) {
            $expired[] = $file.' no longer holds "'.$pin['proves'].'", so it is no longer '.$pin['reason'];
        }
    }

    expect($absent)->toBe([], implode("\n", [
        'These are excused from the announce list and no workflow declares that name, so the',
        'exemption excuses nothing and reads as considered. Delete the entry:',
        ...$absent,
    ]));

    expect($expired)->toBe([], implode("\n", [
        'These exemptions have outlived what earned them — the workflow was left off the announce',
        'list because it posts its own outcome, and it no longer names a webhook:',
        ...$expired,
    ]));
});

// The rule above reads a name with `\S+`, and `workflow_run` matches the whole
// name. A two-word name therefore satisfies that rule as its first word and then
// matches no workflow at all, which is an announce list that looks complete and
// a trigger that never fires. Every name here is one token; this keeps it so.
it('gives every workflow a one-token name, so the announce list names it exactly', function (): void {
    $directory = workflowDirectory();

    expect($directory)->not->toBe('', '.github/workflows was not found from either composer root');

    $files = workflowFiles($directory);

    expect(count($files))->toBeGreaterThan(
        8,
        'the walk read '.count($files).' workflow files, which is too few to be this repository.'
    );

    $offenders = [];

    foreach ($files as $file) {
        $declared = PatternScan::first('/^name:[ \t]*(.+?)[ \t]*$/m', (string) file_get_contents($file));

        if ($declared !== [] && PatternScan::matches('/\s/', $declared[1])) {
            $offenders[] = basename($file).' → '.$declared[1];
        }
    }

    expect($offenders)->toBe(
        [],
        "A workflow name with a space in it is truncated to its first word by the\n".
        "rule above, so the announce list can be complete while the webhook never\n".
        "fires. Hyphenate it. Offenders:\n  ".implode("\n  ", $offenders),
    );
});

it('never decides a commit is green from a list of checks written down here', function (): void {
    $directory = workflowDirectory();

    expect($directory)->not->toBe('', '.github/workflows was not found from either composer root');

    $source = (string) file_get_contents($directory.'/discord-build.yml');

    expect($source)->not->toBe('', 'discord-build.yml read empty, so the three checks below assert about nothing.');

    // The required contexts are read from the branch ruleset at run time. A
    // copy kept here would go stale the first time a job was renamed, and a
    // notifier reading a short list calls a commit green too early.
    expect($source)->not->toContain('quality (PHP 8.5)')
        ->and($source)->not->toContain('SonarCloud')
        ->and($source)->toContain('required-checks-verdict.py');
});

// The announce list is compared with a set derived from the same directory, so
// a reader that stopped parsing would make both sides empty and equal. This
// drives it against the two shapes GitHub accepts and the one that names none.
it('reads the announce list in both spellings, and reports none where there is none', function (): void {
    $inline = "on:\n  workflow_run:\n    workflows: [ci, coverage, shared]\n    types: [completed]\n";
    $block = "on:\n  workflow_run:\n    workflows:\n      - ci\n      - coverage\n      - shared\n    types: [completed]\n";

    expect(buildLogListens($inline))->toBe(['ci', 'coverage', 'shared'])
        ->and(buildLogListens($block))->toBe(['ci', 'coverage', 'shared'])
        ->and(buildLogListens("on:\n  push:\n    branches: [main]\n"))->toBe([]);
});
