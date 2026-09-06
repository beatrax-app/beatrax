<?php

declare(strict_types=1);

use Modules\Sync\Internal\Config\MergeRulesRegistry;

// The registry file is excluded from the corpus it is scanned against: its own
// 'column' => ['nullable' => ...] entry matches the write pattern, so leaving it
// in makes every column trivially answer "written" and the whole rule vacuous.
// That is the one exclusion, and it is the exact path rather than a name any
// second copy of the file would also answer to.
const REPLICATED_COLUMN_REGISTRY = 'Modules/Sync/Internal/Config/MergeRulesRegistry.php';

/** @return array<string, string> absolute path => contents, the production code a column can be written by */
function replicatedColumnCorpus(): array
{
    $registry = base_path(REPLICATED_COLUMN_REGISTRY);
    $sources = [];

    foreach (['Modules', 'app'] as $root) {
        $walk = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(base_path($root)));

        foreach ($walk as $file) {
            if (! $file->isFile() || $file->getExtension() !== 'php') {
                continue;
            }
            $path = $file->getPathname();

            if (str_contains($path, '/tests/') || str_contains($path, '/Database/Migrations/') || $path === $registry) {
                continue;
            }

            $sources[$path] = (string) file_get_contents($path);
        }
    }

    return $sources;
}

/**
 * @param  array<string, string>  $sources
 * @param  array<string, mixed>  $rules
 * @return list<string>
 */
function replicatedColumnOrphans(array $sources, array $rules): array
{
    $orphans = [];

    foreach ($rules as $table => $columns) {
        if (! is_array($columns)) {
            continue;
        }

        foreach ($columns as $column => $rule) {
            // The registry mixes per-table directives (_create_required,
            // _delete_wins) in with the columns; a column is the entry that
            // states its own nullability.
            if (! is_array($rule) || ! array_key_exists('nullable', $rule)) {
                continue;
            }

            $written = false;

            foreach ($sources as $source) {
                if (str_contains($source, "'{$column}' =>")
                    || str_contains($source, "\"{$column}\" =>")
                    || str_contains($source, "->{$column} =")
                    || str_contains($source, "['{$column}'] =")
                    || str_contains($source, "set {$column} =")) {
                    $written = true;
                    break;
                }
            }

            if (! $written) {
                $orphans[] = $table.'.'.$column;
            }
        }
    }

    sort($orphans);

    return $orphans;
}

/** @return list<string> */
function replicatedColumnsWithoutAWriter(): array
{
    return replicatedColumnOrphans(replicatedColumnCorpus(), app(MergeRulesRegistry::class)->rules());
}

it('scans a corpus that can still see a writer, so a silent scan cannot pass this file', function (): void {
    // If the corpus or the patterns break, everything reads as unwritten and the
    // rule below inverts into noise rather than going quiet. This is the half
    // that fails first when that happens.
    expect(replicatedColumnsWithoutAWriter())->not->toContain('recurring_series.latest_currency');

    expect(count(replicatedColumnCorpus()))->toBeGreaterThan(
        4000,
        'The corpus is '.count(replicatedColumnCorpus()).' files, which is too few to be Modules and app.'
    );

    expect(count(app(MergeRulesRegistry::class)->rules()))->toBeGreaterThan(
        20,
        'The merge registry declared too few tables to be the replication contract.'
    );
});

// The other direction, and the one a corpus cannot demonstrate: a second file
// echoing the registry's shape would make every column read as written and the
// rule below vacuous, with nothing to look wrong. Driven over data, so the
// reader is proved to still separate a written column from an unwritten one.
it('reports a replicated column the corpus does not write, and only that one', function (): void {
    $rules = ['fixture_table' => [
        'fixture_column_nothing_writes' => ['nullable' => false],
        'fixture_column_something_writes' => ['nullable' => true],
        '_delete_wins' => true,
    ]];

    $corpus = ['Fixture.php' => "\$row['fixture_column_something_writes'] = 1;"];

    expect(replicatedColumnOrphans($corpus, $rules))->toBe(
        ['fixture_table.fixture_column_nothing_writes'],
        'The reader no longer separates a column the corpus writes from one it does not, so the rule below '
        .'reports whatever the corpus happens to contain.'
    );

    expect(replicatedColumnOrphans([], $rules))->toBe(
        ['fixture_table.fixture_column_nothing_writes', 'fixture_table.fixture_column_something_writes'],
        'An empty corpus must report every column as unwritten. A reader that answers otherwise would pass '
        .'this file over a tree it never opened.'
    );
});

it('replicates no column that nothing writes', function (): void {
    $orphans = replicatedColumnsWithoutAWriter();

    expect($orphans)->toBe([], implode("\n", [
        'A column in the replication contract that no production code writes.',
        'Offenders:',
        ...$orphans,
        '',
        'It is not merely dead weight. recurring_series.latest_fx_rate_used was in',
        'this state and RangeProjector fed it to DailyFold, which THREW on the null',
        'it always found — one dollar subscription took the whole forecast run down,',
        'and the single fixture covering cross-currency forecasting hand-supplied the',
        'value, so the one test that could have caught it never could.',
        '',
        'Either write the column, or drop it from the registry AND the schema together',
        '— MergeRulesMatchSchemaTest holds those two to each other.',
        '',
        'This rule reads literal writes only: "col" =>, ->col =, [\'col\'] =, set col =.',
        'A writer that builds the column name dynamically — from an enum backing value',
        'the way PairingSide::confirmedAtColumn() does, or from a class constant — is',
        'invisible to it and will report here falsely. That is a real writer: pin it in',
        'this file with the reason, rather than widening the patterns until they match',
        'reads as well as writes.',
    ]));
});
