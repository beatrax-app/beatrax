<?php

declare(strict_types=1);

use Tests\Contracts\Support\RepoTree;

/**
 * @link ../../.docs/features/sync/sensitive-columns-at-rest.md#five-producers-and-the-three-that-were-not-asked
 */

// `VACUUM INTO` copies the database and nothing else, and the keys that open a
// reader's sealed columns are a file beside it. So a snapshot without them
// restores anywhere else as a ledger nothing can read — silently, because the
// codec blanks a column it has no key for rather than erroring.
//
// This was applied as if it were a property of one screen. Three of the five
// producers did not carry the keys: `db:backup`, which the daily schedule runs
// and the runbook calls supported, and both pre-restore snapshots, which are
// the documented undo. A sixth producer is free to be a deliberate exception —
// it has to be argued here rather than shipped by omission.

/**
 * The line has to ISSUE the statement, not name it. Four files mention
 * `VACUUM INTO` only in a comment or a command description, and one of them is
 * the class that does the packing.
 *
 * @return array<string, int> repo-relative path => how many snapshots it takes
 */
function snapshotProducers(): array
{
    $producers = [];
    $prefix = RepoTree::root().'/';

    foreach (RepoTree::files(RepoTree::PRODUCTION_PHP) as $path) {
        $source = (string) file_get_contents($path);

        if (! str_contains($source, 'VACUUM INTO')) {
            continue;
        }

        foreach (explode("\n", $source) as $line) {
            if (str_contains($line, 'VACUUM INTO') && str_contains($line, 'statement(')) {
                $relative = str_replace($prefix, '', $path);
                $producers[$relative] = ($producers[$relative] ?? 0) + 1;
            }
        }
    }

    ksort($producers);

    return $producers;
}

it('takes no database snapshot without packing the keys that open it', function (): void {
    $producers = snapshotProducers();

    // The positive control. A walk that stopped reading, or a spelling of the
    // statement this scan cannot see, leaves an empty set that reads exactly
    // like a tree where every producer packs.
    expect($producers)->toHaveCount(
        5,
        'the walk found '.count($producers).' files taking a VACUUM INTO snapshot: '
        .implode(', ', array_keys($producers))
        .'. Five is this tree. A sixth is fine — add it, and pack the keyring in it.'
    );

    $silent = [];

    foreach (array_keys($producers) as $relative) {
        $source = (string) file_get_contents(RepoTree::root().'/'.$relative);

        if (! str_contains($source, 'packInto(')) {
            $silent[] = $relative;
        }
    }

    expect($silent)->toBe(
        [],
        "A VACUUM INTO snapshot is the whole ledger, and for a reader with encryption\n".
        "at rest it is ciphertext: the epoch keys live in storage/app/sync/gdk/<id>.enc,\n".
        "beside the database rather than inside it. Restored where that file is not — a\n".
        "fresh install, a rebuilt machine, the phone's route home from a wipe — every\n".
        "sealed column renders blank and the restore reports success.\n".
        "Call Modules\\Core\\Internal\\Backup\\BackupKeyMaterial::packInto() on the\n".
        "snapshot, and lift it back out with unpackFrom() before the swap. It writes\n".
        "nothing when there is no keyring on disk, so an install with no sealed columns\n".
        'pays nothing. Producers that pack nothing: '
    );
});
