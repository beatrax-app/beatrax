<?php

declare(strict_types=1);

use Modules\Core\Public\Support\PatternScan;

// The runbook opened its watch list with "Four things to confirm" while the
// publish step declared five dependencies. `smoke (self-host server)` gated
// every release and appeared nowhere on the page, so the operator watching
// four green builds and a skipped publish was reading the one sentence that
// ruled the cause out.
//
// The jobs are read off `needs:` rather than listed here, so a sixth is in
// scope the day it is added rather than the day somebody remembers this file.
// @link ../../.docs/runbooks/release-cut.md

const PUBLISH_NEEDS_WORKFLOW = '.github/workflows/release.yml';

const PUBLISH_NEEDS_RUNBOOK = '.docs/runbooks/release-cut.md';

// Under four letters a word is a preposition rather than a subject.
const PUBLISH_NEEDS_TOKEN_FLOOR = 4;

/** the one root both composer roots agree on — mobile-app/Modules is a symlink onto this tree */
function publishNeedsRead(string $relative): string
{
    foreach ([$relative, '../'.$relative] as $candidate) {
        $path = base_path($candidate);

        if (is_file($path)) {
            return (string) file_get_contents($path);
        }
    }

    return '';
}

/**
 * The ids the publish step waits on. The workflow indents a job four spaces
 * and its keys eight, and writes `needs:` as an inline sequence.
 *
 * @return list<string>
 */
function publishNeedsJobIds(string $workflow): array
{
    $found = PatternScan::first('/^    publish:$.*?^        needs: \[([^\]]+)\]/ms', $workflow);

    if ($found === []) {
        return [];
    }

    return array_values(array_filter(array_map('trim', explode(',', $found[1]))));
}

/**
 * Every job the workflow defines, against the name it displays. A job's first
 * `name:` is its own; the ones below it belong to its steps.
 *
 * @return array<string, string>
 */
function publishNeedsJobNames(string $workflow): array
{
    $names = [];
    $current = null;

    foreach (explode("\n", $workflow) as $line) {
        $job = PatternScan::first('/^    ([A-Za-z0-9_-]+):$/', $line);

        if ($job !== []) {
            $current = $job[1];

            continue;
        }

        $named = PatternScan::first('/^        name: (.+)$/', $line);

        if ($named !== [] && $current !== null && ! isset($names[$current])) {
            $names[$current] = trim($named[1]);
        }
    }

    return $names;
}

/**
 * What a name says the job is about. The parenthetical carries the subject:
 * every platform leg is called "build (...)", so on the leading word alone
 * they would all pass on each other's mention.
 *
 * @return list<string>
 */
function publishNeedsSubjectTokens(string $name): array
{
    $inside = PatternScan::first('/\(([^)]+)\)/', $name);
    $subject = $inside === [] ? $name : $inside[1];

    /** @var list<string> $words */
    $words = PatternScan::all('/[A-Za-z][A-Za-z0-9]*/', $subject)[0];

    return array_values(array_filter(
        $words,
        static fn (string $word): bool => strlen($word) >= PUBLISH_NEEDS_TOKEN_FLOOR
    ));
}

it('finds a publish step and a runbook to compare it against', function (): void {
    $workflow = publishNeedsRead(PUBLISH_NEEDS_WORKFLOW);
    $runbook = publishNeedsRead(PUBLISH_NEEDS_RUNBOOK);

    expect($workflow)->not->toBe('', PUBLISH_NEEDS_WORKFLOW.' was not found from either composer root')
        ->and($runbook)->not->toBe('', PUBLISH_NEEDS_RUNBOOK.' was not found from either composer root');

    $needs = publishNeedsJobIds($workflow);

    // Parsed rather than counted. A `needs:` broken across several lines reads
    // as nothing here, and an empty list would satisfy every rule below.
    expect($needs)->not->toBe([], implode("\n", [
        'The publish step declares no dependencies, which cannot be right.',
        'Its `needs:` is no longer the inline list this file knows how to read,',
        'so the rule below is comparing the runbook against an empty set.',
    ]));

    $names = publishNeedsJobNames($workflow);
    $undefined = array_values(array_diff($needs, array_keys($names)));

    expect($undefined)->toBe([], implode("\n", [
        'The publish step waits on jobs this workflow does not define:',
        ...$undefined,
        '',
        'Either `needs:` names something that no longer exists, or the job-name',
        'scan stopped matching and every name below is missing for that reason.',
    ]));

    $unsearchable = [];
    foreach ($needs as $id) {
        if (publishNeedsSubjectTokens($names[$id]) === []) {
            $unsearchable[] = $id.' — displays as "'.$names[$id].'"';
        }
    }

    expect($unsearchable)->toBe([], implode("\n", [
        'These jobs display under a name carrying no word long enough to look for:',
        ...$unsearchable,
        '',
        'The rule below would pass them whatever the runbook says.',
    ]));
});

it('names on the release runbook every job the publish step waits on', function (): void {
    $workflow = publishNeedsRead(PUBLISH_NEEDS_WORKFLOW);
    $runbook = publishNeedsRead(PUBLISH_NEEDS_RUNBOOK);
    $names = publishNeedsJobNames($workflow);

    $unmentioned = [];

    foreach (publishNeedsJobIds($workflow) as $id) {
        $name = $names[$id] ?? $id;

        $mentioned = false;
        foreach (publishNeedsSubjectTokens($name) as $token) {
            // On a boundary, never a substring: `self` sits inside `itself`,
            // and the tag-is-the-trigger paragraph carries one. Matched loosely
            // this rule passed the very page it was written against.
            if (PatternScan::first('/\\b'.preg_quote($token, '/').'\\b/i', $runbook) !== []) {
                $mentioned = true;

                break;
            }
        }

        if (! $mentioned) {
            $unmentioned[] = $id.' — displays as "'.$name.'"';
        }
    }

    expect($unmentioned)->toBe([], implode("\n", [
        'The publish step waits on these jobs and the release runbook never names them:',
        ...$unmentioned,
        '',
        'A release stops at publish when any one of them fails. A page that does',
        'not name a job cannot tell an operator to watch it, and the four green',
        'builds above will read as ruling the cause out. Add each to the watch',
        'list in '.PUBLISH_NEEDS_RUNBOOK.'.',
    ]));
});
