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

/**
 * @return array<string, string>
 */
function workflowNames(string $directory): array
{
    $names = [];

    foreach ((array) glob($directory.'/*.yml') as $file) {
        $declared = PatternScan::first('/^name:[ \t]*(\S+)/m', (string) file_get_contents((string) $file));

        if ($declared !== []) {
            $names[basename((string) $file)] = $declared[1];
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

    expect($names)->toHaveKey('discord-build.yml');

    // These three carry a webhook of their own — the release announcement, the
    // triage post and the maintainer queue — so the build log repeating them
    // would post every one of their outcomes twice.
    $selfAnnouncing = ['discord-build', 'release-announce', 'triage', 'awaiting-maintainer'];

    $expected = array_values(array_diff($names, $selfAnnouncing));
    $listens = buildLogListens((string) file_get_contents($directory.'/discord-build.yml'));

    sort($expected);
    sort($listens);

    expect($listens)->toBe($expected, sprintf(
        "discord-build.yml listens to a set that is not the workflows in this repository.\n  missing: %s\n  named but absent: %s",
        implode(', ', array_diff($expected, $listens)) ?: 'none',
        implode(', ', array_diff($listens, $expected)) ?: 'none',
    ));
});

it('never decides a commit is green from a list of checks written down here', function (): void {
    $directory = workflowDirectory();

    expect($directory)->not->toBe('', '.github/workflows was not found from either composer root');

    $source = (string) file_get_contents($directory.'/discord-build.yml');

    // The required contexts are read from the branch ruleset at run time. A
    // copy kept here would go stale the first time a job was renamed, and a
    // notifier reading a short list calls a commit green too early.
    expect($source)->not->toContain('quality (PHP 8.5)')
        ->and($source)->not->toContain('SonarCloud')
        ->and($source)->toContain('required-checks-verdict.py');
});
