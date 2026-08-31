<?php

declare(strict_types=1);

use Modules\Sync\Internal\Config\MergeRulesRegistry;

// The registry file is excluded from the corpus it is scanned against: its own
// 'column' => ['nullable' => ...] entry matches the write pattern, so leaving it
// in makes every column trivially answer "written" and the whole rule vacuous.
function replicatedColumnsWithoutAWriter(): array
{
    $registry = base_path('Modules/Sync/Internal/Config/MergeRulesRegistry.php');
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

    $orphans = [];

    foreach (app(MergeRulesRegistry::class)->rules() as $table => $columns) {
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

it('scans a corpus that can still see a writer, so a silent scan cannot pass this file', function (): void {
    // If the corpus or the patterns break, everything reads as unwritten and the
    // rule below inverts into noise rather than going quiet. This is the half
    // that fails first when that happens.
    expect(replicatedColumnsWithoutAWriter())->not->toContain('recurring_series.latest_currency');
    expect(app(MergeRulesRegistry::class)->rules())->not->toBe([]);
});

it('replicates no column that nothing writes', function (): void {
    expect(replicatedColumnsWithoutAWriter())->toBe([], implode("\n", [
        'A column in the replication contract that no production code writes.',
        'Offenders:',
        ...replicatedColumnsWithoutAWriter(),
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
