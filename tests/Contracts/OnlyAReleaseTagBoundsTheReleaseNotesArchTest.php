<?php

declare(strict_types=1);

use Modules\Core\Public\Support\PatternScan;

// git-cliff reads EVERY tag as a previous release unless told otherwise, and
// ten `2.0.0-probe.N` tags sit on main's own history. Counted as releases they
// put the notes' left edge a fortnight back: `--unreleased` reported 21 entries
// where the span since v1.3.0 holds 1,370, and the release body carried no
// Breaking changes group at all — the category-linked-pot retirement, the change
// that forces the major version, appeared nowhere in the notes announcing it.
//
// The workflow already knows which tags are releases. This holds the notes to
// the same answer.
// @link ../../.docs/runbooks/release-cut.md

const RELEASE_NOTES_WORKFLOW = '.github/workflows/release.yml';

const RELEASE_NOTES_CONFIG = 'cliff.toml';

// Names the pipeline builds, and names it ignores. The second list is the point:
// every one of them exists in this repository or has existed in it.
const RELEASE_NOTES_BUILT = ['v2.0.0', 'v1.4.0', 'v1.4.0-rc.1', 'v10.2.3-beta'];

const RELEASE_NOTES_IGNORED = ['2.0.0-probe.13', '2.0.0-probe.9', '0.1.0', 'nightly', 'latest'];

/** the one root both composer roots agree on — mobile-app/Modules is a symlink onto this tree */
function releaseNotesRead(string $relative): string
{
    foreach ([$relative, '../'.$relative] as $candidate) {
        $path = base_path($candidate);

        if (is_file($path)) {
            return (string) file_get_contents($path);
        }
    }

    return '';
}

/** The tag globs the release workflow fires on. */
function releaseNotesTriggerGlobs(string $workflow): array
{
    $found = PatternScan::first('/^on:\\s*$.*?^\\s+tags:\\s*\\[([^\\]]+)\\]/ms', $workflow);

    if ($found === []) {
        return [];
    }

    /** @var list<string> $globs */
    $globs = PatternScan::all("/'([^']+)'/", $found[1])[1];

    return $globs;
}

/** The regex git-cliff is told to accept a release tag by. */
function releaseNotesTagPattern(string $config): string
{
    $found = PatternScan::first('/^\s*tag_pattern\s*=\s*"([^"]+)"/m', $config);

    return $found === [] ? '' : $found[1];
}

/** fnmatch, but only the `*` these globs actually use. */
function releaseNotesGlobAccepts(string $glob, string $tag): bool
{
    return fnmatch($glob, $tag);
}

it('finds a trigger and a notes config to compare', function (): void {
    $workflow = releaseNotesRead(RELEASE_NOTES_WORKFLOW);
    $config = releaseNotesRead(RELEASE_NOTES_CONFIG);

    expect($workflow)->not->toBe('', RELEASE_NOTES_WORKFLOW.' was not found from either composer root')
        ->and($config)->not->toBe('', RELEASE_NOTES_CONFIG.' was not found from either composer root');

    // Parsed, not assumed: a trigger this cannot read would let every rule
    // below compare the config against an empty set of globs.
    expect(releaseNotesTriggerGlobs($workflow))->not->toBe([], implode("\n", [
        'The release workflow declares no tag glob this file can read.',
        'Either it no longer fires on a tag push, or `tags: [...]` is no longer',
        'written inline, and the comparison below is against nothing.',
    ]));
});

it('tells the notes which tags are releases at all', function (): void {
    expect(releaseNotesTagPattern(releaseNotesRead(RELEASE_NOTES_CONFIG)))->not->toBe('', implode("\n", [
        RELEASE_NOTES_CONFIG.' sets no tag_pattern.',
        '',
        'Without one git-cliff treats every tag in the history as a previous',
        'release, so any throwaway tag left on the branch silently becomes the',
        'left edge of the next release body. Ten of them once cut a major',
        'version\'s notes down to a fortnight, breaking change included.',
    ]));
});

it('agrees with the workflow about what a release tag looks like', function (): void {
    $globs = releaseNotesTriggerGlobs(releaseNotesRead(RELEASE_NOTES_WORKFLOW));
    $pattern = releaseNotesTagPattern(releaseNotesRead(RELEASE_NOTES_CONFIG));

    $disagreements = [];

    foreach (RELEASE_NOTES_BUILT as $tag) {
        $built = array_filter($globs, static fn (string $g): bool => releaseNotesGlobAccepts($g, $tag)) !== [];

        if ($built && PatternScan::first('/'.str_replace('/', '\/', $pattern).'/', $tag) === []) {
            $disagreements[] = $tag.' — the pipeline builds it, the notes do not treat it as a release';
        }
    }

    foreach (RELEASE_NOTES_IGNORED as $tag) {
        $built = array_filter($globs, static fn (string $g): bool => releaseNotesGlobAccepts($g, $tag)) !== [];

        if (! $built && PatternScan::first('/'.str_replace('/', '\/', $pattern).'/', $tag) !== []) {
            $disagreements[] = $tag.' — the pipeline ignores it, the notes would end a release on it';
        }
    }

    expect($disagreements)->toBe([], implode("\n", [
        'The tags the pipeline builds and the tags the notes end a release on have drifted apart:',
        ...$disagreements,
        '',
        'A tag the pipeline ignores must not bound a release body, or a name',
        'nobody meant as a release decides where the notes begin.',
    ]));
});
