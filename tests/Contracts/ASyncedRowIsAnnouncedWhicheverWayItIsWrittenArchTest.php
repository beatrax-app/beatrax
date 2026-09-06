<?php

declare(strict_types=1);

use Modules\Core\Public\Support\PatternScan;
use Modules\Sync\Internal\Config\MergeRulesRegistry;
use Tests\Contracts\Support\RepoTree;
use Tests\Contracts\Support\SyncedColumnWrites;
use Tests\Contracts\Support\UnannouncedWrites;

// The fourth of the capture family, and the one that asks about the SPELLING
// rather than the write. Its three siblings all root their scan at a table
// literal or at a model used as a static query root, so two ways of changing a
// row are invisible to every one of them: a statement that names its table
// inside a SQL string, and an update or a delete called on a row already in
// hand. `transactions.field_provenance` was written the first way for months —
// a raw UPDATE, announcing nothing, so a map the reader's own hand-edits were
// protected by travelled only inside a create, where it is always null.

// The raw statements against a travelling table that are allowed to tell no
// peer, each with the reason and a pattern re-run against the file that carries
// it. Compared in both directions, so a pin that stops being needed fails as
// loudly as a write that stops being announced.
const WRITES_NO_PEER_HEARS = [
    'Modules/Auth/Public/Actions/SignupAction.php' => [
        'tables' => ['users'],
        'reason' => 'a no-op UPDATE that matches nothing and is there to take the write lock, so a concurrent signup blocks rather than reading a stale count under WAL',
        'proves' => "/'UPDATE users SET id = id WHERE 0 = 1'/",
    ],
];

// Floors, not counts: a walk that stopped reading answers "nothing found" in
// the same words a clean tree does.
const SPELLING_FLOORS = ['files' => 2000, 'tables' => 30, 'raw' => 2, 'instance' => 3];

/**
 * @return array{raw: array<string, list<string>>, instance: array<string, list<string>>, announces: array<string, bool>}
 */
function writesSpeltPastTheTableLiteral(): array
{
    $registry = app(MergeRulesRegistry::class);
    $tables = UnannouncedWrites::travellingTables($registry);
    $columns = SyncedColumnWrites::mergeableColumns($registry);

    // Enumerated off the registry rather than off the column map: the four
    // append-only ledgers carry no strategy key at all, so a map keyed on
    // "tables with mergeable columns" drops them — and a row deleted from one
    // is exactly the write that leaves a peer holding a ledger entry forever.
    // `users` is left out because its own guard holds it, and every file in
    // this tree names User in a signature.
    $models = SyncedColumnWrites::modelsByTable(
        array_fill_keys(array_diff($tables, ['users']), []),
    );

    $raw = [];
    $instance = [];
    $announces = [];

    foreach (SyncedColumnWrites::writerFiles() as $file) {
        $source = SyncedColumnWrites::stripped((string) file_get_contents($file));
        $relative = str_replace(RepoTree::root().'/', '', $file);
        $announces[$relative] = SyncedColumnWrites::announces($source);

        foreach (UnannouncedWrites::rawStatementTargets($source, $tables) as $table) {
            $raw[$relative][] = $table;
        }

        foreach ($models as $table => $model) {
            if (UnannouncedWrites::instanceDeletesRow($model, $source)) {
                $instance[$relative][] = $table.' (delete)';
            }

            foreach ($columns[$table] ?? [] as $column) {
                if (UnannouncedWrites::instanceUpdatesColumn($model, $column, $source)) {
                    $instance[$relative][] = $table.'.'.$column;
                }
            }
        }
    }

    ksort($raw);
    ksort($instance);

    return ['raw' => $raw, 'instance' => $instance, 'announces' => $announces];
}

/**
 * @param  array<string, list<string>>  $sites
 * @param  array<string, bool>  $announces
 * @return array<string, list<string>>
 */
function sitesThatAnnounceNothing(array $sites, array $announces): array
{
    $silent = [];

    foreach ($sites as $relative => $targets) {
        if ($announces[$relative] ?? false) {
            continue;
        }

        sort($targets);
        $silent[$relative] = array_values(array_unique($targets));
    }

    return $silent;
}

it('has a denominator to read a verdict from', function (): void {
    $sites = writesSpeltPastTheTableLiteral();

    expect(count(SyncedColumnWrites::writerFiles()))->toBeGreaterThan(SPELLING_FLOORS['files'])
        ->and(count(UnannouncedWrites::travellingTables(app(MergeRulesRegistry::class))))->toBeGreaterThan(SPELLING_FLOORS['tables'])
        ->and(count($sites['raw']))->toBeGreaterThanOrEqual(SPELLING_FLOORS['raw'])
        ->and(count($sites['instance']))->toBeGreaterThanOrEqual(SPELLING_FLOORS['instance']);

    expect(RepoTree::accountOf(RepoTree::RUNTIME_DOMAIN_PHP))->toBe(
        ['unaccounted' => [], 'stale' => [], 'silent' => []],
        implode("\n", [
            'A top-level directory holds PHP this walk neither reads nor names as',
            "somebody else's to read, so anything in it is invisible here — and a",
            'guard reporting nothing about a hole reads exactly like a clean tree.',
            '',
            'The scope is RepoTree::RUNTIME_DOMAIN_PHP. Add the directory to its',
            '`covers`, or to its `declines` with the reason it is out of scope.',
        ])
    );
});

it('announces every travelling row it writes in a SQL string', function (): void {
    $sites = writesSpeltPastTheTableLiteral();
    $silent = sitesThatAnnounceNothing($sites['raw'], $sites['announces']);

    $offenders = [];
    foreach ($silent as $relative => $tables) {
        $pinned = WRITES_NO_PEER_HEARS[$relative]['tables'] ?? [];

        foreach (array_diff($tables, $pinned) as $table) {
            $offenders[] = $relative.' writes '.$table.' in a SQL string and announces nothing';
        }
    }

    sort($offenders);

    expect($offenders)->toBe([], implode("\n", [
        'A statement that names its table inside a string is a write no capture',
        'guard beside this one can read, and it reaches no peer either:',
        ...$offenders,
        '',
        "Route it through the table's write seam — AccountWriter, PairLinkWriter,",
        'TransactionTypeWriter, FieldProvenanceWriter each own a column and the op',
        'that carries it — or dispatch the mutation event after the write. A',
        'statement that writes nothing is pinned in WRITES_NO_PEER_HEARS.',
    ]));
});

it('announces every travelling row it writes through a row already in hand', function (): void {
    $sites = writesSpeltPastTheTableLiteral();
    $silent = sitesThatAnnounceNothing($sites['instance'], $sites['announces']);

    $offenders = [];
    foreach ($silent as $relative => $targets) {
        foreach ($targets as $target) {
            $offenders[] = $relative.' writes '.$target.' on a hydrated row and announces nothing';
        }
    }

    sort($offenders);

    expect($offenders)->toBe([], implode("\n", [
        'An update or a delete called on a model instance names its table nowhere,',
        'so the column and delete guards cannot see it. This one can, and these',
        'tell no peer:',
        ...$offenders,
        '',
        "Dispatch the table's mutation event after the write — 'edit' with the",
        "column in dirtyFields, 'delete' for a removal — or route the write",
        'through a seam that does.',
    ]));
});

it('keeps no pin for a statement that now announces', function (): void {
    $sites = writesSpeltPastTheTableLiteral();
    $silent = sitesThatAnnounceNothing($sites['raw'], $sites['announces']);

    $stale = [];
    foreach (WRITES_NO_PEER_HEARS as $relative => $pin) {
        foreach (array_diff($pin['tables'], $silent[$relative] ?? []) as $table) {
            $stale[] = $relative.' is pinned for '.$table.', which it no longer writes unannounced';
        }

        $path = RepoTree::root().'/'.$relative;

        if (! is_file($path)) {
            $stale[] = $relative.' is pinned and no longer exists';

            continue;
        }

        if (! PatternScan::matches($pin['proves'], (string) file_get_contents($path))) {
            $stale[] = $relative.' is exempt because "'.$pin['reason'].'", and it no longer reads that way';
        }
    }

    sort($stale);

    expect($stale)->toBe([], implode("\n", [
        'An exemption that has outlived its reason is a gap nobody chose:',
        ...$stale,
        '',
        'Delete the entry from WRITES_NO_PEER_HEARS.',
    ]));
});

it('reports a write spelt either way past the guards that root at a table literal', function (): void {
    // Assembled at runtime so the guard scanning its own tree cannot read these
    // fixtures as real offenders.
    $update = '->update'.'(';
    $delete = '->delete'.'()';
    $tables = ['transactions', 'import_runs'];

    $rawWrite = '<?php $c->update("UPDATE transactions SET type = ? WHERE id = ?", [$t, $id]);';
    expect(UnannouncedWrites::rawStatementTargets($rawWrite, $tables))->toBe(['transactions'])
        ->and(SyncedColumnWrites::announces($rawWrite))->toBeFalse();

    // The shape the column guard reads is the shape this one must not double up
    // on: a builder rooted at the table literal is somebody else's offender.
    $viaBuilder = "<?php \$c->table('transactions')->where('id', \$id)".$update."['type' => \$t]);";
    expect(UnannouncedWrites::rawStatementTargets($viaBuilder, $tables))->toBe([]);

    $onTheRow = '<?php $run = ImportRun::query()->find($id); $run'.$update."['status' => 'discarded']);";
    expect(UnannouncedWrites::instanceUpdatesColumn('ImportRun', 'status', $onTheRow))->toBeTrue()
        ->and(UnannouncedWrites::instanceUpdatesColumn('ImportRun', 'confirmed_at', $onTheRow))->toBeFalse()
        // The same statement in a file that never queries the model is a write
        // to something else: every action in this tree names User in a hint.
        ->and(UnannouncedWrites::instanceUpdatesColumn('Transaction', 'status', $onTheRow))->toBeFalse();

    $announced = $onTheRow." \$events->dispatch(new EntityMutated(table: 'import_runs'));";
    expect(SyncedColumnWrites::announces($announced))->toBeTrue();

    $rowDelete = '<?php $report = SavedReport::query()->first(); $report'.$delete.';';
    expect(UnannouncedWrites::instanceDeletesRow('SavedReport', $rowDelete))->toBeTrue()
        ->and(UnannouncedWrites::instanceDeletesRow('Goal', $rowDelete))->toBeFalse();

    // A reason quoted in prose beside a write is not the write.
    $inProse = SyncedColumnWrites::stripped("<?php // UPDATE transactions here\n\$c->select('select 1');");
    expect(UnannouncedWrites::rawStatementTargets($inProse, $tables))->toBe([]);
});
