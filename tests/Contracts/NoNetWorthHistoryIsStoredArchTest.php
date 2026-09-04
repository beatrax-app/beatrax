<?php

declare(strict_types=1);

use Modules\Core\Public\Support\PatternScan;

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

/** @return list<string> absolute paths to every PHP source the shells ship, tests excluded */
function netWorthHistoryShippedSources(): array
{
    $found = [];

    foreach (['app', 'Modules'] as $directory) {
        $root = base_path($directory);

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

            // A test may name a stored series it asserts is absent.
            if (str_contains($path, '/tests/')) {
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

it('declares no table and no column that keeps a net worth', function (): void {
    $migrations = netWorthHistoryMigrations();

    // Counted before anything is read: a walk that resolved no migrations
    // reports the same clean schema a clean schema reports.
    expect(count($migrations))->toBeGreaterThanOrEqual(50);

    $tables = [];
    $columns = [];
    $offenders = [];

    foreach ($migrations as $path) {
        $source = (string) file_get_contents($path);

        foreach (PatternScan::all('/(?:Schema::create|schema\(\)->create|->create)\(\s*[\'"]([a-z0-9_]+)[\'"]/', $source)[1] as $table) {
            $tables[] = $table;

            if (PatternScan::matches('/net_?worth/i', $table)) {
                $offenders[] = netWorthHistoryRelative($path).' → table '.$table;
            }
        }

        foreach (PatternScan::all('/->[a-zA-Z]+\(\s*[\'"]([a-z0-9_]+)[\'"]/', $source)[1] as $column) {
            $columns[] = $column;

            if (PatternScan::matches('/net_?worth/i', $column)) {
                $offenders[] = netWorthHistoryRelative($path).' → column '.$column;
            }
        }
    }

    expect(count($tables))->toBeGreaterThanOrEqual(50);
    expect(count($columns))->toBeGreaterThanOrEqual(200);

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
    $sources = netWorthHistoryShippedSources();

    expect(count($sources))->toBeGreaterThanOrEqual(200);

    $offenders = [];

    foreach ($sources as $path) {
        $source = PatternScan::replace('#/\*.*?\*/|//[^\n]*#s', '', (string) file_get_contents($path));

        if (PatternScan::matches('/(?:DB::table|->table|->from|->insert\s+into)\s*\(?\s*[\'"][a-z0-9_]*net_?worth[a-z0-9_]*[\'"]/i', $source)
            || PatternScan::matches('/\b(?:INSERT\s+INTO|REPLACE\s+INTO|UPDATE)\s+[`"]?[a-z0-9_]*net_?worth[a-z0-9_]*/i', $source)) {
            $offenders[] = netWorthHistoryRelative($path);
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

    expect(is_file($path))->toBeTrue();

    $source = PatternScan::replace('#/\*.*?\*/|//[^\n]*#s', '', (string) file_get_contents($path));

    // The sampler earns its exemption by still doing the sampling. When it
    // stops calling the balance seam, the pinned table below has outlived the
    // reason it was granted.
    expect(PatternScan::matches('/clearedBalanceAsOf\s*\(/', $source))->toBeTrue();

    $named = PatternScan::all('/->(?:table|from)\(\s*[\'"]([a-z0-9_]+)[\'"]/', $source)[1];

    expect(count($named))->toBeGreaterThanOrEqual(1);

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
