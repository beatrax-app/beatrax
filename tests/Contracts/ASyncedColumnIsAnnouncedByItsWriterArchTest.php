<?php

declare(strict_types=1);

use Modules\Core\Public\Support\PatternScan;
use Modules\Sync\Internal\Config\MergeRulesRegistry;
use Tests\Contracts\Support\RepoTree;
use Tests\Contracts\Support\SyncedColumnWrites;

// A row travels as a whole-row create once, and after that only the Sets its
// writer announces. So a plain `->update()` on a column the merge registry
// declares mergeable does not merely fail to replicate — it makes the two
// devices permanently disagree, with nothing anywhere saying so.
//
// The delete guard beside this one asks the same question of a removal and the
// users guard asks it of the settings row. This is the third and widest: every
// covered table, every column the registry names.

// The writers that update such a column and announce nothing, each with the
// reason it is allowed to and a pattern re-run against the file that carries
// that reason. Compared with toBe() in both directions, so a pin that stops
// being needed fails as loudly as a site that stops being announced.
const SILENT_COLUMN_WRITERS = [
    'Modules/CashBook/Internal/Services/ManualEntryAnchors.php' => [
        'columns' => ['accounts.name'],
        'reason' => "the cash account's name is the reader's own word in THIS device's language, and `locale` is device-local: announcing it would have two devices in two languages overwrite each other's word on every manual entry",
        'announcedBy' => 'Modules/Sync/Internal/Config/MergeRulesRegistry.php',
        'proves' => "/DEVICE_LOCAL_COLUMNS = \\[.*?'locale',/s",
    ],
    'Modules/Ledger/Public/Actions/ReassignCounterparty.php' => [
        'columns' => ['transactions.counterparty_id'],
        'reason' => 'both callers announce the column, gated on the affected count this returns',
        'announcedBy' => 'Modules/Ledger/Internal/Http/Livewire/TransactionDetail.php',
        'proves' => "/'counterparty_id' => /",
    ],
    'Modules/Ledger/Public/Actions/SetTransactionNote.php' => [
        'columns' => ['transactions.note'],
        'reason' => "both callers announce the note, and the appending one re-reads it: only the write knows the final text. The stored value is ciphertext, so the caller's plaintext is what has to travel",
        'announcedBy' => 'Modules/Categorization/Internal/Services/RuleApplier.php',
        'proves' => "/'note' => /",
    ],
    'Modules/Ledger/Public/Actions/UpdateTransactionCategory.php' => [
        'columns' => ['transactions.category_id'],
        'reason' => 'both callers announce the column, gated on the affected count this returns',
        'announcedBy' => 'Modules/Categorization/Internal/Actions/AssignCategory.php',
        'proves' => "/'category_id' => /",
    ],
    'Modules/Ledger/Public/Services/CounterpartyKeyBackfill.php' => [
        'columns' => ['chain_links.evidence', 'merchants.normalized_name'],
        'reason' => 'a re-encoding of a stored value is not a change, and an op per row would carry identical plaintext',
        'announcedBy' => '.docs/features/sync/sensitive-columns-at-rest.md',
        'proves' => '/would emit a `Set` op for a change that is not/',
    ],
    'Modules/Migration/Internal/Services/SourceMapWriter.php' => [
        'columns' => [
            'migration_import_baseline.baseline_value',
            'migration_import_baseline.imported_at',
            'migration_source_map.beatrax_id',
            'migration_source_map.natural_key',
        ],
        'reason' => 'both tables are rewritten wholesale by a migration run and never edited by hand',
        'announcedBy' => '.docs/features/sync/architecture.md',
        'proves' => '/are rewritten wholesale\s+by a migration run/',
    ],
    'Modules/Reports/Internal/Support/PinOrderCompactor.php' => [
        'columns' => ['saved_reports.pin_order'],
        'reason' => 'it hands every row it renumbered back, and both callers announce each one inside the transaction its own contract demands',
        'announcedBy' => 'Modules/Reports/Internal/Actions/TogglePin.php',
        'proves' => '/PinOrderCompactor::compact\(/',
    ],
    'Modules/Tax/Internal/Actions/TaxCategoryStore.php' => [
        'columns' => ['tax_deduction_categories.name', 'tax_deduction_categories.name_is_default', 'tax_deduction_categories.status'],
        'reason' => 'an Internal store every production caller reaches through TaxCategoryWriter, which announces each edit it delegates -- the rename carries the provenance flag it clears, so a peer stops resolving the corpus line for that row too',
        'announcedBy' => 'Modules/Tax/Public/Services/TaxCategoryWriter.php',
        'proves' => "/->store->rename\(.*?'name_is_default' => false/s",
    ],
    'Modules/Transfers/Public/Services/PairUnlinker.php' => [
        'columns' => ['transactions.type'],
        'reason' => 'the retype is announced by both callers: DeleteTransaction as an edit, TransferPairCascade as a system-cascade op with the tombstone HLC',
        'announcedBy' => 'Modules/Ledger/Public/Actions/DeleteTransaction.php',
        'proves' => "/'type' => \\\$newType->value/",
    ],
];

// Floors, not counts. A walk that stopped reading answers "nothing found" in
// the same words a clean tree does, so every denominator is asserted before a
// verdict is read from it.
const SYNCED_COLUMN_FLOORS = ['files' => 2000, 'tables' => 20, 'columns' => 90, 'sites' => 45];

/**
 * @return array<string, list<string>> offending file => sorted `table.column`
 */
function unannouncedColumnWrites(int &$sites): array
{
    $registry = app(MergeRulesRegistry::class);
    $tables = SyncedColumnWrites::mergeableColumns($registry);
    $models = SyncedColumnWrites::modelsByTable($tables);

    $offenders = [];
    $sites = 0;

    foreach (SyncedColumnWrites::writerFiles() as $file) {
        $source = SyncedColumnWrites::stripped((string) file_get_contents($file));

        if (! str_contains($source, '->update(') && ! str_contains($source, '->save(')) {
            continue;
        }

        $announces = SyncedColumnWrites::announces($source);

        foreach ($tables as $table => $columns) {
            if (! SyncedColumnWrites::mayName($table, $models[$table] ?? null, $source)) {
                continue;
            }

            foreach ($columns as $column) {
                if (! SyncedColumnWrites::updatesColumn($table, $column, $source, $models[$table] ?? null)) {
                    continue;
                }

                $sites++;

                if (! $announces) {
                    $offenders[str_replace(base_path().'/', '', $file)][] = $table.'.'.$column;
                }
            }
        }
    }

    ksort($offenders);

    return array_map(static function (array $columns): array {
        sort($columns);

        return array_values(array_unique($columns));
    }, $offenders);
}

it('has a denominator to read a verdict from', function (): void {
    $registry = app(MergeRulesRegistry::class);
    $tables = SyncedColumnWrites::mergeableColumns($registry);

    $sites = 0;
    unannouncedColumnWrites($sites);

    expect(count(SyncedColumnWrites::writerFiles()))->toBeGreaterThan(
        SYNCED_COLUMN_FLOORS['files'],
        'the walk read almost no writer file, so every verdict in this file is about a tree nobody opened',
    )
        ->and(count($tables))->toBeGreaterThan(
            SYNCED_COLUMN_FLOORS['tables'],
            'the merge registry named almost no covered table, so the offender list below is built over nothing',
        )
        ->and(array_sum(array_map('count', $tables)))->toBeGreaterThan(
            SYNCED_COLUMN_FLOORS['columns'],
            'the registry named almost no mergeable column, so a silent writer of one would be invisible',
        )
        ->and($sites)->toBeGreaterThan(
            SYNCED_COLUMN_FLOORS['sites'],
            'the walk matched almost no write site, so it stopped reading rather than finding a tree that announces everything',
        );

    // The tables whose columns most often move after the create. A scan that
    // lost them would report a clean tree in the same words a clean one does.
    expect(array_keys($tables))->toContain('transactions', 'accounts', 'goals')
        ->not->toContain('users', 'categorization_rules', 'rule_conditions', 'rule_actions');

    expect(RepoTree::accountOf(RepoTree::RUNTIME_DOMAIN_PHP))->toBe(
        ['unaccounted' => [], 'stale' => [], 'silent' => []],
        implode("\n", [
            'A top-level directory holds PHP and this walk neither reads it nor names',
            'it as somebody else\'s to read, so anything in it is invisible here — and',
            'a guard that reports nothing about a hole reads exactly like a clean tree.',
            'The delete guard beside this one walked Modules/ alone, which is how a',
            'console command purging two travelling tables went unseen.',
            '',
            'The scope is RepoTree::RUNTIME_DOMAIN_PHP. Add the directory to its',
            '`covers`, or to its `declines` with the reason it is out of scope.',
        ])
    );

    expect(SyncedColumnWrites::modelsByTable($tables))
        ->toHaveKeys(['transactions', 'accounts', 'notifications']);
});

it('announces every mergeable column its writers update', function (): void {
    $sites = 0;
    $offenders = unannouncedColumnWrites($sites);

    $pinned = array_map(static fn (array $pin): array => $pin['columns'], SILENT_COLUMN_WRITERS);
    ksort($pinned);

    $unpinned = [];
    foreach ($offenders as $file => $columns) {
        $allowed = $pinned[$file] ?? [];

        foreach (array_diff($columns, $allowed) as $column) {
            $unpinned[] = $file.' updates '.$column.' and announces nothing';
        }
    }

    sort($unpinned);

    expect($unpinned)->toBe([], implode("\n", [
        'These writers change a column the merge registry declares mergeable and',
        'tell no peer, so the two devices disagree about that column for good:',
        ...$unpinned,
        '',
        "Dispatch the table's mutation event with mutationType: 'edit' and the",
        'column in dirtyFields, AFTER the write transaction commits — or route',
        'the write through a seam that does (AccountWriter, PairLinkWriter,',
        'WriteUserPreference). A column that must NOT travel is removed from',
        'MergeRulesRegistry; a writer whose CALLER announces is pinned in',
        'SILENT_COLUMN_WRITERS with the file that proves it.',
    ]));
});

it('keeps no pin for a writer that now announces', function (): void {
    $sites = 0;
    $offenders = unannouncedColumnWrites($sites);

    $stale = [];
    foreach (SILENT_COLUMN_WRITERS as $file => $pin) {
        foreach (array_diff($pin['columns'], $offenders[$file] ?? []) as $column) {
            $stale[] = $file.' is pinned for '.$column.', which it no longer writes unannounced';
        }
    }

    sort($stale);

    expect($stale)->toBe([], implode("\n", [
        'A pin that has outlived what earned it silently widens the exemption:',
        ...$stale,
        '',
        'Delete the entry from SILENT_COLUMN_WRITERS.',
    ]));
});

it('re-checks the reason every pin was granted for', function (): void {
    $broken = [];

    foreach (SILENT_COLUMN_WRITERS as $file => $pin) {
        $path = base_path($pin['announcedBy']);

        if (! is_file($path)) {
            $broken[] = $file.' points at '.$pin['announcedBy'].', which does not exist';

            continue;
        }

        if (! PatternScan::matches($pin['proves'], (string) file_get_contents($path))) {
            $broken[] = $file.' is exempt because "'.$pin['reason'].'", and '.$pin['announcedBy'].' no longer reads that way';
        }
    }

    sort($broken);

    expect($broken)->toBe([], implode("\n", [
        'An exemption whose reason no longer holds is a gap nobody chose:',
        ...$broken,
    ]));
});

it('reports an update that leaves a mergeable column unannounced', function (): void {
    // Assembled at runtime so the guard scanning its own tree cannot read these
    // fixtures as real offenders.
    $update = '->update'.'([';

    $planted = "<?php \$db->table('accounts')->where('id', \$id)".$update."'default_currency' => \$code]);";
    expect(SyncedColumnWrites::updatesColumn('accounts', 'default_currency', $planted, 'Account'))->toBeTrue()
        ->and(SyncedColumnWrites::announces($planted))->toBeFalse();

    $announced = $planted." \$events->dispatch(new EntityMutated(table: 'accounts'));";
    expect(SyncedColumnWrites::announces($announced))->toBeTrue();

    // The same column name on a table that is not the one being guarded.
    $elsewhere = "<?php \$db->table('statement_summaries')->where('id', \$id)".$update."'default_currency' => \$code]);";
    expect(SyncedColumnWrites::updatesColumn('accounts', 'default_currency', $elsewhere, 'Account'))->toBeFalse();

    $viaModel = "<?php Transaction::query()->where('id', \$id)".$update."'category_id' => \$categoryId]);";
    expect(SyncedColumnWrites::updatesColumn('transactions', 'category_id', $viaModel, 'Transaction'))->toBeTrue()
        ->and(SyncedColumnWrites::updatesColumn('transactions', 'category_id', $viaModel, null))->toBeFalse();

    // The third shape: an assignment and a save, two statements apart, with
    // the model class standing in for the table.
    $save = '->save'.'()';
    $viaSave = '<?php $goal = Goal::find($id); $goal->target_minor = $m; $goal'.$save.';';
    expect(SyncedColumnWrites::updatesColumn('goals', 'target_minor', $viaSave, 'Goal'))->toBeTrue()
        ->and(SyncedColumnWrites::updatesColumn('goals', 'target_minor', $viaSave, null))->toBeFalse()
        ->and(SyncedColumnWrites::updatesColumn('goals', 'target_minor', '<?php $goal->target_minor = $m;', 'Goal'))->toBeFalse();

    // A model whose name is a prefix of another's is not that other model.
    $splitSave = '<?php $leg = new TransactionSplit; $leg->note = $n; $leg'.$save.';';
    expect(SyncedColumnWrites::updatesColumn('transactions', 'note', $splitSave, 'Transaction'))->toBeFalse();

    // A reason quoted in prose beside a write is not the write.
    $commented = "<?php // updates accounts.name here\n\$db->table('merchants')".$update."'name' => \$n]);";
    expect(SyncedColumnWrites::updatesColumn('accounts', 'name', SyncedColumnWrites::stripped($commented), 'Account'))->toBeFalse();
});
