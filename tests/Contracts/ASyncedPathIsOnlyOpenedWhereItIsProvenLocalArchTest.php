<?php

declare(strict_types=1);

use Modules\Core\Public\Support\PatternScan;
use Tests\Contracts\Support\RepoTree;

/**
 * @link ../../.docs/features/import/architecture.md#the-stored-path-is-an-audit-string
 */

// `import_runs.raw_file_path` is in the create set MergeRulesRegistry declares
// for that table, so the value a device reads back may have been written by a
// peer against a filesystem this one has never seen. It is also the column five
// writers put a sentinel in for a run nobody uploaded — `open-banking://`,
// `demo://`, `migration`, and the receipts handoff. Handed straight to
// hash_file() and fopen() it read, copied into this reader's own staging
// directory, and parsed whatever the string named.
const SYNCED_PATH_COLUMN = 'raw_file_path';

// The one file allowed to read the column, because it is the one that asks
// StagedStatementPath whether a local staged file is behind the string.
const SYNCED_PATH_CONTAINMENT_READER = 'Modules/Import/Public/Actions/RunImport.php';

/**
 * A property read of the column — `$row->raw_file_path`. A write spells it as
 * an array key (`'raw_file_path' => …`) and is not the subject here: putting a
 * server-composed path in is what the column is for.
 *
 * @return array<string, int> relative path => how many reads it makes
 */
function syncedPathReads(): array
{
    $reads = [];

    foreach (RepoTree::files(RepoTree::PRODUCTION_PHP) as $path) {
        $relative = str_replace(base_path().'/', '', $path);
        $found = PatternScan::sets(
            '/->'.SYNCED_PATH_COLUMN.'\b/',
            (string) file_get_contents($path),
        );

        if ($found !== []) {
            $reads[$relative] = count($found);
        }
    }

    ksort($reads);

    return $reads;
}

it('reads the stored path in exactly one place, and that place proves it local first', function (): void {
    $reads = syncedPathReads();

    // The control: a walk that opened nothing answers "no offending read" in
    // the same words a contained tree does.
    // array_key_exists() and not toHaveKey(): that matcher reads a second
    // argument as the value the key must hold, not as the failure message.
    expect(array_key_exists(SYNCED_PATH_CONTAINMENT_READER, $reads))->toBeTrue(
        'The one read this rule exists to permit was not found, so the scan read a tree nobody opened '
        .'— or the containment seam has been removed.',
    );

    expect(array_keys($reads))->toBe([SYNCED_PATH_CONTAINMENT_READER], implode("\n  ", [
        'These read import_runs.'.SYNCED_PATH_COLUMN.' directly. The column is synced, so the string may be a',
        'peer\'s path against a peer\'s filesystem, and it carries a sentinel for every run nobody uploaded.',
        'Handed to a file read it opens whatever it names, copies it into this reader\'s staging directory',
        'and parses it. Ask StagedStatementPath::forRun() instead — it answers with this device\'s own staged',
        'copy or with null. Offenders:',
        ...array_keys($reads),
    ]));
});

it('keeps the column in the set a peer can write, so the rule still has a subject', function (): void {
    $registry = (string) file_get_contents(base_path('Modules/Sync/Internal/Config/MergeRulesRegistry.php'));

    // toBeTrue() and not toContain(): the matcher reads every argument as a
    // further needle, so an explanation passed beside one is searched for.
    expect(str_contains($registry, "'".SYNCED_PATH_COLUMN."'"))->toBeTrue(
        'MergeRulesRegistry no longer names '.SYNCED_PATH_COLUMN.'. If the column stopped travelling between '
        .'devices, this rule is about a threat that is gone and should be retired rather than left passing '
        .'for the wrong reason.',
    );
});
