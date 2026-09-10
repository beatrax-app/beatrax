<?php

declare(strict_types=1);

use Modules\Core\Public\Support\PatternScan;
use Tests\Contracts\Support\BackendSourceFiles;

// Some tables record which currency a stored figure is in beside the figure
// itself, rather than leaving it to the account or the row's owner. Where a
// schema says that much, reading the amount and not the column beside it is
// reading a number and guessing its money: three readers took
// `statement_summaries.opening_balance_minor` and `closing_balance_minor` as
// the ACCOUNT's, so a statement printed in dollars anchored a euro account at
// its own figure, offered it in the import wizard, and prefilled the reconcile
// box with it.
// @link ../../.docs/features/ledger/reconcile-needs-an-anchor.md

/**
 * The `<prefix>_minor` / `<prefix>_currency` pairs the schema declares, per
 * table. Derived from the migrations rather than listed, so a table that grows
 * one later is covered without this file being edited.
 *
 * @return list<array{table: string, minor: string, currency: string}>
 */
function figureCurrencyPairs(): array
{
    $columnsByTable = [];

    foreach (glob(base_path('Modules/*/Database/Migrations/*.php')) ?: [] as $migration) {
        $source = (string) file_get_contents($migration);

        foreach (PatternScan::sets("/->(?:create|table)\('([a-z_]+)',/", $source) as $opening) {
            $table = $opening[1];
            $offset = (int) strpos($source, $opening[0]) + strlen($opening[0]);
            $block = substr($source, $offset);
            $next = PatternScan::firstWithOffsets("/->(?:create|table)\('[a-z_]+',/", $block);
            $block = $next === [] ? $block : substr($block, 0, $next[0][1]);

            foreach (PatternScan::all("/'([a-z_]+)'/", $block)[1] as $column) {
                $columnsByTable[$table][$column] = true;
            }
        }
    }

    $owners = [];
    foreach ($columnsByTable as $table => $columns) {
        foreach (array_keys($columns) as $column) {
            $owners[$column][$table] = true;
        }
    }

    $pairs = [];
    foreach ($columnsByTable as $table => $columns) {
        foreach (array_keys($columns) as $column) {
            $prefix = str_ends_with($column, '_minor') ? substr($column, 0, -6) : null;

            if ($prefix === null || ! isset($columns[$prefix.'_currency'])) {
                continue;
            }

            $pairs[] = [
                'table' => $table,
                'minor' => $column,
                'currency' => $prefix.'_currency',
                // `accounts` and `statement_summaries` both hold a column
                // called opening_balance_minor and only one of them says which
                // currency it is in, so a bare mention names neither.
                'qualifiedOnly' => count($owners[$column]) > 1,
            ];
        }
    }

    return $pairs;
}

it('declares at least one table that names the currency of a stored figure', function (): void {
    // Without this the rule below passes by describing nothing, which is how a
    // guard keyed on a schema shape goes quiet when the shape is renamed.
    expect(figureCurrencyPairs())->not->toBe([]);
});

it('reads no such figure without the currency column beside it', function (): void {
    $pairs = figureCurrencyPairs();
    $offenders = [];

    foreach (BackendSourceFiles::all() as $path) {
        $relative = str_replace(base_path().'/', '', $path);
        $source = (string) file_get_contents($path);

        foreach ($pairs as $pair) {
            $reads = $pair['qualifiedOnly']
                ? str_contains($source, $pair['table'].'.'.$pair['minor'])
                : str_contains($source, $pair['minor']);

            if (! $reads || str_contains($source, $pair['currency'])) {
                continue;
            }
            $offenders[] = $relative.' reads '.$pair['table'].'.'.$pair['minor'].' and never '.$pair['currency'];
        }
    }

    expect($offenders)->toBe([], implode("\n  ", [
        'A stored figure whose table records its own currency must be read with that column, or the amount is taken for money it is not. Offenders:',
        ...$offenders,
    ]));
});
