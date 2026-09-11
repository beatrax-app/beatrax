<?php

declare(strict_types=1);

use Tests\Contracts\Support\RepoTree;
use Tests\Contracts\Support\SyncedColumnWrites;

// The guard beside this one asks which mergeable column each writer updates,
// and it roots every question at a table literal. A statement naming its table
// any other way is a write it cannot read at all -- so this one asks the only
// question left: does the file tell a peer ANYTHING?

// Coarser on purpose. It cannot know which table such a writer touches, so it
// cannot name a column. What it can do is refuse a new writer of this shape
// that nobody has thought about, which is how the last one arrived.

/**
 * @link ../../.docs/features/sync/architecture.md#the-apply-path-writes-raw-on-purpose
 */
const INDIRECT_TABLE_WRITERS = [
    'Modules/Auth/Internal/Account/UserScopedDataPurge.php' => [
        'reason' => 'the account leaving THIS device, one owned table at a time, and op_log_entries goes with it -- the log that would carry the announcement is deleted by the same sweep, and a paired household device keeping its own replica is the documented intent',
        'announcedBy' => '.docs/features/auth/user-scoped-purge.md',
        'proves' => '/everything a single account owns on this device/',
    ],
    'Modules/CashBook/Internal/Services/ManualEntryAnchors.php' => [
        'reason' => "the cash account's name is this device's own word in this device's language; the reason is spelled out where that column is pinned, beside this guard",
        'announcedBy' => 'tests/Contracts/ASyncedColumnIsAnnouncedByItsWriterArchTest.php',
        'proves' => "/ManualEntryAnchors\\.php' => \\[/",
    ],
    'Modules/Core/Internal/Encryption/PreMigrationSnapshot.php' => [
        'reason' => 're-encrypting a column in place: the plaintext is unchanged, and what travels is the plaintext an announcement carries rather than the bytes on disk, so an op here would announce a storage format as an edit',
        'announcedBy' => 'tests/Contracts/ASyncedColumnIsAnnouncedByItsWriterArchTest.php',
        'proves' => "/The stored value is ciphertext, so the caller's plaintext is what has to travel/",
    ],
    'Modules/Core/Public/StateMachine/GuardedStateMachine.php' => [
        'reason' => 'the transition algorithm three machines share; each names its own table and each announces the move, and this base class has no table of its own to announce',
        'announcedBy' => 'Modules/Anomaly/Internal/StateMachines/AnomalyAlertStateMachine.php',
        'proves' => '/dispatch\\(new EntityMutated\\(/',
    ],
    'Modules/Migration/Internal/Pipeline/StagingWriter.php' => [
        'reason' => 'the import staging area, which no peer replicates: promotion is what turns these rows into domain rows, and promotion is the write that announces',
        'announcedBy' => 'Modules/Migration/Internal/Pipeline/PromoteStagingToDomain.php',
        'proves' => '/RecordTransactions dispatches TransactionImported per row/',
    ],
    'Modules/Sync/Internal/Merge/OpLogEntryApplier.php' => [
        'reason' => "the path that writes a peer's row, which must not announce",
        'announcedBy' => '.docs/features/sync/architecture.md',
        'proves' => '/would hand that peer back what it just sent/',
    ],
    'Modules/Sync/Internal/Merge/SelfReferenceDeferral.php' => [
        'reason' => "the second pass over a peer's row, repairing a reference the first pass could not resolve yet; same path, same refusal",
        'announcedBy' => '.docs/features/sync/architecture.md',
        'proves' => '/would hand that peer back what it just sent/',
    ],
    'Modules/Sync/Internal/Merge/SplitCreateTail.php' => [
        'reason' => "the columns a peer's create did not carry, filled from this device's defaults; the row is still the peer's and still must not travel back",
        'announcedBy' => '.docs/features/sync/architecture.md',
        'proves' => '/would hand that peer back what it just sent/',
    ],
    'Modules/Sync/Internal/OpLog/OpLogRebuilder.php' => [
        'reason' => 'the whole persisted log replayed from scratch against this device; every row it writes is one the log already holds, so announcing would mint a second op for each',
        'announcedBy' => '.docs/features/sync/architecture.md',
        'proves' => '/would hand that peer back what it just sent/',
    ],
];

/**
 * @return array<string, bool> file => whether it announces anything at all
 */
function indirectTableWriters(int &$read): array
{
    $found = [];

    foreach (SyncedColumnWrites::writerFiles() as $file) {
        $source = SyncedColumnWrites::stripped((string) file_get_contents($file));
        $read++;

        if (SyncedColumnWrites::namesItsTableIndirectly($source)) {
            $found[str_replace(base_path().'/', '', $file)] = SyncedColumnWrites::announces($source);
        }
    }

    ksort($found);

    return $found;
}

it('has a denominator to read a verdict from', function (): void {
    $read = 0;
    $writers = indirectTableWriters($read);

    expect($read)->toBeGreaterThan(2_000, 'the walk opened almost no file, so an empty offender list below means nothing')
        ->and(count($writers))->toBeGreaterThan(5, 'the scan matched almost no indirect writer, so it stopped reading rather than finding a tree that names every table outright');

    // The two this guard was written for: one that announces and one that does
    // not. A scan losing either reports a clean tree in a clean tree's words.
    expect(array_keys($writers))->toContain(
        'Modules/Migration/Internal/Pipeline/EntityChangeApplier.php',
        'Modules/Sync/Internal/Merge/OpLogEntryApplier.php',
    );

    expect(RepoTree::accountOf(RepoTree::RUNTIME_DOMAIN_PHP))
        ->toBe(['unaccounted' => [], 'stale' => [], 'silent' => []]);
});

it('announces, or carries the reason it must not, in every writer that names its table indirectly', function (): void {
    $read = 0;

    $unpinned = array_keys(array_diff_key(
        array_filter(indirectTableWriters($read), static fn (bool $announces): bool => ! $announces),
        INDIRECT_TABLE_WRITERS,
    ));

    expect($unpinned)->toBe([], implode("\n", [
        'These files write a row to a table named by a variable, a constant or an',
        'argument, and tell no peer anything at all. The column guard beside this',
        'one cannot read such a statement, so nothing else will ask:',
        ...$unpinned,
        '',
        "Dispatch the table's mutation event after the write commits, or route the",
        'write through a seam that does. A writer that must NOT announce is pinned',
        'in INDIRECT_TABLE_WRITERS with the reason and a file that carries it.',
    ]));
});

it('keeps no pin for a writer that now announces', function (): void {
    $read = 0;
    $writers = indirectTableWriters($read);

    $stale = [];
    foreach (INDIRECT_TABLE_WRITERS as $file => $pin) {
        if (! array_key_exists($file, $writers)) {
            $stale[] = $file.' no longer writes to a table it names indirectly';

            continue;
        }

        if ($writers[$file]) {
            $stale[] = $file.' announces now, so its pin excuses nothing';
        }
    }

    expect($stale)->toBe([], implode("\n", [
        'A pin outlived the shape it was granted for. Remove it:',
        ...$stale,
    ]));
});

it('re-checks the reason every pin was granted for', function (): void {
    $broken = [];

    foreach (INDIRECT_TABLE_WRITERS as $file => $pin) {
        $carrier = base_path($pin['announcedBy']);

        if (! is_file($carrier)) {
            $broken[] = $file.' names '.$pin['announcedBy'].', which is not there';

            continue;
        }

        if (preg_match($pin['proves'], (string) file_get_contents($carrier)) !== 1) {
            $broken[] = $file.' names '.$pin['announcedBy'].', which no longer says '.$pin['proves'];
        }
    }

    expect($broken)->toBe([], implode("\n", [
        'A pin here is granted against something another file says. That sentence',
        'moved or went, so the exemption now rests on nothing:',
        ...$broken,
    ]));
});

it('reads a write whose table is not a literal', function (): void {
    $viaVariable = "<?php \$c->table(\$table)->where('id', 1)->update(['a' => 1]);";
    $viaMethod = "<?php \$c->table(\$this->table())->where('id', 1)->update(['a' => 1]);";
    $viaConstant = "<?php \$c->table(Searched::DOCS)->insert(['a' => 1]);";
    $viaArgument = '<?php $this->ownership->scopeToUser($q, $table, $userId)->update($values);';

    expect(SyncedColumnWrites::namesItsTableIndirectly($viaVariable))->toBeTrue()
        ->and(SyncedColumnWrites::namesItsTableIndirectly($viaMethod))->toBeTrue()
        ->and(SyncedColumnWrites::namesItsTableIndirectly($viaConstant))->toBeTrue()
        ->and(SyncedColumnWrites::namesItsTableIndirectly($viaArgument))->toBeTrue();

    // The three shapes that are NOT this: a literal, a literal with an alias,
    // and a $table that only ever reads.
    $literal = "<?php \$c->table('accounts')->where('id', 1)->update(['a' => 1]);";
    $aliased = "<?php \$c->table('categories as c')->where('id', 1)->update(['a' => 1]);";
    $readOnly = "<?php \$row = \$c->table(\$table)->where('id', 1)->first(); \$c->table('accounts')->update(['a' => 1]);";

    expect(SyncedColumnWrites::namesItsTableIndirectly($literal))->toBeFalse()
        ->and(SyncedColumnWrites::namesItsTableIndirectly($aliased))->toBeFalse()
        ->and(SyncedColumnWrites::namesItsTableIndirectly($readOnly))->toBeFalse();
});
