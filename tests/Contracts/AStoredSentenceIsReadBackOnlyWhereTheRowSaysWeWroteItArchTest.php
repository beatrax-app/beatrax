<?php

declare(strict_types=1);

use Modules\Core\Public\Support\PatternScan;
use Tests\Contracts\Support\RepoTree;

/**
 * @link ../../.docs/features/notifications/reader-language-copy.md
 */

// `StoredCopy::read()` decides whether a column holds the app's own line or
// somebody's own words by how the value STARTS. That was written against a
// person typing into a form — "no sentence a person writes starts this way" —
// and it holds for a person. It does not hold for a column whose writer copies
// a string out of an email, a statement or a migration file: the envelope is
// public, unsigned, and a From display name can carry it. The row then renders
// a shipped sentence of the sender's choosing, in the reader's own language.
//
// So a read is admitted only where something OUTSIDE the value says the app
// wrote it, and every site says here what that something is.
const STORED_COPY_READS = [
    'Modules/EmailScan/Public/Services/KnownSenderQuery.php' => [
        'reads' => 1,
        'why' => 'Gated on known_senders.source, which a CHECK trigger pair holds to system-or-user. The seeder writes system; PromoteDiscoveredSender writes user for every row whose label is a From display name, and a create arriving over sync carries no source at all, so it takes the column default of user.',
    ],
    'Modules/Migration/Internal/Pipeline/PreviewSummaryBuilder.php' => [
        'reads' => 4,
        'why' => 'KNOWN UNGATED. migration_staging_unmapped_items carries no column saying who wrote display_label, and ActualParser writes two of them straight off the reader\'s Actual file — a schedule name and a custom-report name. A file naming a report with the envelope prints a shipped migration line on the preview instead.',
    ],
];

/**
 * @return array<string, int> relative path => how many reads it makes
 */
function storedCopyReadSites(): array
{
    $sites = [];

    foreach (RepoTree::files(RepoTree::PRODUCTION_PHP) as $path) {
        $relative = str_replace(base_path().'/', '', $path);
        $found = PatternScan::sets('/StoredCopy::read\s*\(/', (string) file_get_contents($path));

        if ($found !== []) {
            $sites[$relative] = count($found);
        }
    }

    ksort($sites);

    return $sites;
}

it('reads a stored sentence back only at a site this rule has weighed', function (): void {
    $sites = storedCopyReadSites();

    // The control: a walk that opened nothing answers "no unweighed site" in
    // the same words a fully weighed tree does.
    expect(count($sites))->toBeGreaterThan(
        0,
        'The scan found no StoredCopy::read() anywhere, so every verdict below is about a tree nobody opened.',
    );

    $unweighed = array_values(array_diff(array_keys($sites), array_keys(STORED_COPY_READS)));

    expect($unweighed)->toBe([], implode("\n  ", [
        'These read a stored sentence back without this rule having weighed the column. The envelope is not',
        'a trust boundary — it is public bytes a From header, a statement cell or a migration file can carry.',
        'Say what OUTSIDE the value proves the app wrote it (a source column, a writer nothing third-party',
        'reaches) and add the entry:',
        ...$unweighed,
    ]));
});

it('holds each weighed site to the count it was weighed at', function (): void {
    $sites = storedCopyReadSites();
    $drifted = [];

    foreach (STORED_COPY_READS as $path => $entry) {
        $found = $sites[$path] ?? 0;

        if ($found !== $entry['reads']) {
            $drifted[] = $path.' (weighed '.$entry['reads'].', found '.$found.')';
        }
    }

    expect($drifted)->toBe([], implode("\n  ", [
        'An entry admits a fixed number of reads, in both directions: a read added to a weighed file is one',
        'nobody has weighed, and a read that went away leaves an entry excusing something that is not there.',
        ...$drifted,
    ]));
});

// The sites nothing outside the value gates are a baseline, not a licence.
// The count may fall. It may not rise.
it('does not let the ungated baseline grow', function (): void {
    $ungated = array_keys(array_filter(
        STORED_COPY_READS,
        static fn (array $entry): bool => str_contains($entry['why'], 'KNOWN UNGATED'),
    ));

    expect(count($ungated))->toBe(1, implode("\n  ", [
        'One file still reads a stored sentence back with nothing but the value\'s first bytes deciding',
        'whether the app wrote it. Delete a line here when one is fixed; a second is a new way for somebody',
        'else to choose a sentence the app speaks in its own voice.',
        ...$ungated,
    ]));
});

it('gives every weighed site a reason somebody can act on', function (): void {
    $thin = [];

    foreach (STORED_COPY_READS as $path => $entry) {
        if (strlen($entry['why']) < 60) {
            $thin[] = $path;
        }
    }

    expect($thin)->toBe([], implode("\n  ", [
        'An entry is a claim under review, and a claim nobody can act on is a waiver. Name the column or the',
        'writer that settles it. Too thin to act on:',
        ...$thin,
    ]));
});
