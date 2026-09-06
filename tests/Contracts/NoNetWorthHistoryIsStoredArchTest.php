<?php

declare(strict_types=1);

use Modules\Core\Public\Support\PatternScan;
use Tests\Contracts\Support\RepoTree;

// Net worth over time is a question the ledger can always answer from the
// balances it already holds, so the series is sampled when it is asked for.
// Storing the points instead would make them a second record of the same
// facts, and a second record goes stale the first time a transaction is
// edited, re-categorised, deleted or arrives late over sync — a chart of a
// past nobody had.

// The one table the sampler reads. It reads it for the SET of accounts to walk
// and nothing else; the balances themselves come from the Ledger's own seam.
const NET_WORTH_SAMPLER_TABLE_ALLOW_LIST = ['accounts'];

const NET_WORTH_SAMPLER_PATH = 'Modules/Reports/Internal/Aggregation/NetWorthSeriesQuery.php';

/** @return list<string> absolute paths to every migration the tree ships */
function netWorthHistoryMigrations(): array
{
    $found = [];

    foreach ([base_path('Modules'), base_path('database/migrations')] as $root) {
        if (! is_dir($root)) {
            continue;
        }

        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($root, RecursiveDirectoryIterator::SKIP_DOTS),
        );

        /** @var SplFileInfo $file */
        foreach ($iterator as $file) {
            $path = $file->getPathname();

            if (! $file->isFile() || ! str_ends_with($path, '.php')) {
                continue;
            }

            if ($root === base_path('Modules') && ! str_contains($path, '/Database/Migrations/')) {
                continue;
            }

            $found[] = $path;
        }
    }

    sort($found);

    return $found;
}

function netWorthHistoryRelative(string $path): string
{
    return str_replace(base_path().'/', '', $path);
}

/**
 * The tables and columns $source declares, and the ones of them that name a
 * place for a net worth to be kept. The two counts travel with the verdict so
 * the walk's denominators come off the same reader the control below drives.
 *
 * @return array{tables: int, columns: int, offenders: list<string>}
 */
function netWorthSchemaReadOf(string $source): array
{
    $tables = 0;
    $columns = 0;
    $offenders = [];

    foreach (PatternScan::all('/(?:Schema::create|schema\(\)->create|->create)\(\s*[\'"]([a-z0-9_]+)[\'"]/', $source)[1] as $table) {
        $tables++;

        if (PatternScan::matches('/net_?worth/i', $table)) {
            $offenders[] = 'table '.$table;
        }
    }

    foreach (PatternScan::all('/->[a-zA-Z]+\(\s*[\'"]([a-z0-9_]+)[\'"]/', $source)[1] as $column) {
        $columns++;

        if (PatternScan::matches('/net_?worth/i', $column)) {
            $offenders[] = 'column '.$column;
        }
    }

    return ['tables' => $tables, 'columns' => $columns, 'offenders' => $offenders];
}

/** whether $source names a stored net worth in a query, comments removed first */
function netWorthQueryNamesAStore(string $source): bool
{
    $stripped = PatternScan::replace('#/\*.*?\*/|//[^\n]*#s', '', $source);

    return PatternScan::matches('/(?:DB::table|->table|->from|->insert\s+into)\s*\(?\s*[\'"][a-z0-9_]*net_?worth[a-z0-9_]*[\'"]/i', $stripped)
        || PatternScan::matches('/\b(?:INSERT\s+INTO|REPLACE\s+INTO|UPDATE)\s+[`"]?[a-z0-9_]*net_?worth[a-z0-9_]*/i', $stripped);
}

it('declares no table and no column that keeps a net worth', function (): void {
    $migrations = netWorthHistoryMigrations();

    // Counted before anything is read: a walk that resolved no migrations
    // reports the same clean schema a clean schema reports.
    expect(count($migrations))->toBeGreaterThanOrEqual(
        50,
        'the walk found '.count($migrations).' migrations, which is too few to be this tree.'
    );

    $tables = 0;
    $columns = 0;
    $offenders = [];

    foreach ($migrations as $path) {
        $read = netWorthSchemaReadOf((string) file_get_contents($path));
        $tables += $read['tables'];
        $columns += $read['columns'];

        foreach ($read['offenders'] as $offender) {
            $offenders[] = netWorthHistoryRelative($path).' → '.$offender;
        }
    }

    expect($tables)->toBeGreaterThanOrEqual(
        50,
        'the walk read '.$tables.' created tables, which is too few to be this schema.'
    );

    expect($columns)->toBeGreaterThanOrEqual(
        200,
        'the walk read '.$columns.' declared columns, which is too few to be this schema.'
    );

    sort($offenders);

    expect($offenders)->toBe([], implode("\n", [
        'These declare somewhere for a net worth to be kept:',
        ...$offenders,
        '',
        'A stored net worth is a second record of balances the ledger already',
        'holds, and it is wrong the moment a transaction behind it moves. The',
        'series is sampled from those balances when a reader asks for it, which',
        'is why there is nothing to keep in step and nothing to backfill.',
    ]));
});

it('writes no net worth anywhere a query can name', function (): void {
    // Every root that ships, not app/ and Modules/ alone: "anywhere" is a claim
    // about the tree, and a seeder or a route closure writes to the same
    // database the two walked directories do.
    $sources = RepoTree::files(RepoTree::PRODUCTION_PHP);

    expect(count($sources))->toBeGreaterThanOrEqual(
        3000,
        'RepoTree returned '.count($sources).' shipped PHP files, which is too few to have read the tree.'
    );

    $offenders = [];

    foreach ($sources as $path) {
        if (netWorthQueryNamesAStore((string) file_get_contents($path))) {
            $offenders[] = str_replace(RepoTree::root().'/', '', $path);
        }
    }

    expect($offenders)->toBe([], implode("\n", [
        'These name a stored net worth in a query:',
        ...$offenders,
        '',
        'Nothing persists the series, so nothing may read one back. Sample it from',
        'the account balances the Ledger already answers for as of a date.',
    ]));
});

it('samples each point from the balances as of its own date', function (): void {
    $path = base_path(NET_WORTH_SAMPLER_PATH);

    expect(is_file($path))->toBeTrue(
        NET_WORTH_SAMPLER_PATH.' is gone, so the table pinned below is pinned for a file that no longer exists.'
    );

    $source = PatternScan::replace('#/\*.*?\*/|//[^\n]*#s', '', (string) file_get_contents($path));

    // The sampler earns its exemption by still doing the sampling. When it
    // stops calling the balance seam, the pinned table below has outlived the
    // reason it was granted.
    expect(PatternScan::matches('/clearedBalanceAsOf\s*\(/', $source))->toBeTrue(
        NET_WORTH_SAMPLER_PATH.' no longer calls the balance-as-of seam, so it is no longer sampling and '
        .'the table it is allowed to read is allowed for a reason that has stopped reading.'
    );

    $named = PatternScan::all('/->(?:table|from)\(\s*[\'"]([a-z0-9_]+)[\'"]/', $source)[1];

    expect(count($named))->toBeGreaterThanOrEqual(
        1,
        'the sampler names no table at all, so the allow-list below excuses nothing.'
    );

    $unpinned = array_values(array_unique(array_diff($named, NET_WORTH_SAMPLER_TABLE_ALLOW_LIST)));
    sort($unpinned);

    expect($unpinned)->toBe([], implode("\n", [
        'The net-worth sampler reads these tables directly: '.implode(', ', $unpinned).'.',
        '',
        'It is allowed one, and only to learn which accounts to walk: '
            .implode(', ', NET_WORTH_SAMPLER_TABLE_ALLOW_LIST).'.',
        'Every figure it plots comes from the Ledger\'s balance-as-of seam, so a',
        'point is recomputed from the ledger as it stands now rather than read',
        'back from whatever was true when it was last written down.',
    ]));
});

// Nothing in the tree stores a net worth, so both rules above report on what
// they cannot find. The readers are driven against planted sources instead,
// assembled at runtime so this file is not itself a subject of the scan it is
// checking. The near-miss is the shape the tree really carries: the words in a
// comment explaining why there is no such table.
it('sees a table, a column and a query that keep a net worth', function (): void {
    $stored = 'net_'.'worth';

    $table = netWorthSchemaReadOf("<?php Schema::create('".$stored."_points', function (\$t) {\n    \$t->id();\n});");
    expect($table['offenders'])->toBe(['table '.$stored.'_points'])
        ->and($table['tables'])->toBe(1);

    $column = netWorthSchemaReadOf("<?php \$table->bigInteger('".$stored."_minor');");
    expect($column['offenders'])->toBe(['column '.$stored.'_minor']);

    $clean = netWorthSchemaReadOf("<?php Schema::create('accounts', function (\$t) {\n    \$t->bigInteger('opening_balance_minor');\n});");
    expect($clean['offenders'])->toBe([])
        ->and($clean['columns'])->toBeGreaterThan(0);

    expect(netWorthQueryNamesAStore('<?php DB::table(\''.$stored.'_points\')->insert($rows);'))->toBeTrue()
        ->and(netWorthQueryNamesAStore('<?php $db->statement(\'INSERT INTO '.$stored.'_points VALUES (1)\');'))->toBeTrue()
        ->and(netWorthQueryNamesAStore('<?php DB::table(\'accounts\')->get();'))->toBeFalse()
        ->and(netWorthQueryNamesAStore("<?php // nothing stores a ".$stored." series\n"))->toBeFalse();
});
