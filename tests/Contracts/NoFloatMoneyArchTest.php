<?php

declare(strict_types=1);

use Illuminate\Database\Eloquent\Model;
use Modules\Core\Public\Support\PatternScan;
use Modules\Ledger\Models\Transaction;

// The columns read back as a string, and the model that owns each. Qualified by
// table because the exemption below is: an unqualified `confidence` excused
// every table's confidence column, present and future, off one table's reason.
const DECIMAL_COLUMNS_CAST_TO_STRING = [
    'transactions.fx_rate_used' => Transaction::class,
];

/**
 * @return list<string> absolute paths to every migration this tree ships
 */
function floatMoneyMigrationFiles(): array
{
    $files = [];

    foreach ((array) glob(base_path('Modules/*/Database/Migrations/*.php')) as $path) {
        $files[] = (string) $path;
    }

    // The shared root as well as the module ones: the application's own
    // migrations declare schema on the same connection, and a walk that opened
    // only Modules/ made them structurally invisible to a rule about "no
    // migration".
    foreach ((array) glob(base_path('database/migrations/*.php')) as $path) {
        $files[] = (string) $path;
    }

    sort($files);

    return $files;
}

/**
 * @return list<string> the money columns $source declares as an inexact type
 */
function floatMoneyColumnsIn(string $source): array
{
    return array_values(array_unique(
        PatternScan::all('/->(?:float|double|real)\(\s*["\'](\w*(?:amount|minor)\w*)["\']/i', $source)[1]
    ));
}

/**
 * Qualified by the table the chain was opened on, so an exemption naming
 * `chain_links.confidence` excuses that column and not a column of the same
 * name on another table. Three spellings open a chain in this tree --
 * `Schema::create`, `$this->schema()->create` and a bare `->table` -- and the
 * two decimal columns that are exempted are both declared under the second.
 *
 * @return list<string> `table.column` for every decimal column $source declares
 */
function decimalColumnsDeclaredIn(string $source): array
{
    $tables = PatternScan::allWithOffsets('/(?:Schema::|schema\(\)->|->)(?:create|table)\(\s*[\'"](\w+)[\'"]/', $source);
    $columns = PatternScan::allWithOffsets('/->(?:decimal|unsignedDecimal)\(\s*[\'"](\w+)[\'"]/', $source);

    $declared = [];

    foreach ($columns[1] as $index => $column) {
        $at = (int) $columns[0][$index][1];
        $table = 'unknown';

        foreach ($tables[1] as $position => $name) {
            if ((int) $tables[0][$position][1] < $at) {
                $table = (string) $name[0];
            }
        }

        $declared[] = $table.'.'.(string) $column[0];
    }

    return array_values(array_unique($declared));
}

it('no migration declares REAL or FLOAT on a money column', function (): void {
    $migrations = floatMoneyMigrationFiles();

    // Read before the verdict: a glob that resolved nothing reports the same
    // clean schema a clean schema does. The floor sits far under today's 222.
    expect(count($migrations))->toBeGreaterThan(
        100,
        'the walk found '.count($migrations).' migrations, which is too few to be this tree.'
    );

    $offenders = [];
    $everything = '';

    foreach ($migrations as $path) {
        $source = (string) file_get_contents($path);
        $everything .= $source."\n";

        foreach (floatMoneyColumnsIn($source) as $column) {
            $offenders[] = str_replace(base_path().'/', '', $path).' → '.$column;
        }
    }

    expect($offenders)->toBe([], implode("\n", [
        'These store an amount in a binary floating-point column, where a count of cents stops',
        'being a count the first time it is summed:',
        ...$offenders,
        '',
        'Money is a minor-unit integer beside the currency it counts: bigInteger(\'amount_minor\').',
    ]));

    expect($everything)->toContain("bigInteger('amount_minor')");
});

/**
 * @link ../../.docs/conventions/invariants-from-shipped-failures.md#a-decimal-column-read-as-a-float
 */
// The guard above stops money being stored as a float; this one stops it being
// read as one, which is the half that actually shipped.
it('casts every decimal column to string so it never reaches BigDecimal as a float', function (): void {
    $missing = [];

    foreach (DECIMAL_COLUMNS_CAST_TO_STRING as $qualified => $modelClass) {
        $column = explode('.', $qualified)[1];
        /** @var Model $model */
        $model = new $modelClass;

        expect($model->getTable())->toBe(
            explode('.', $qualified)[0],
            $modelClass.' no longer maps to '.explode('.', $qualified)[0].', so this entry names a table nobody writes.'
        );

        if (($model->getCasts()[$column] ?? null) !== 'string') {
            $missing[] = $modelClass.'::$casts['.$column.']';
        }
    }

    expect($missing)->toBe(
        [],
        "A decimal column read without a string cast arrives as a float and\n".
        "loses its fraction inside brick/math. Add a 'string' cast for:\n  ".
        implode("\n  ", $missing),
    );
});

it('names every decimal column the schema declares, so a new one cannot be forgotten', function (): void {
    // Keeps the hand-maintained map above honest: a new decimal column fails
    // here until it is either cast or consciously exempted.
    $exempt = [
        // A score compared against thresholds, never fed to BigDecimal.
        'chain_links.confidence',
        // Reaches brick through ExchangeRateService::toString() instead.
        'exchange_rates.rate',
    ];

    $declared = [];

    foreach (floatMoneyMigrationFiles() as $path) {
        foreach (decimalColumnsDeclaredIn((string) file_get_contents($path)) as $column) {
            $declared[] = $column;
        }
    }

    $declared = array_values(array_unique($declared));

    // Read before the verdict: with nothing declared, both directions below are
    // vacuous. The floor is today's three, which is also the floor a schema
    // holding decimals at all must clear.
    expect(count($declared))->toBeGreaterThan(
        0,
        'the walk found no decimal column at all across '.count(floatMoneyMigrationFiles()).' migrations.'
    );

    $known = array_merge(array_keys(DECIMAL_COLUMNS_CAST_TO_STRING), $exempt);

    expect(array_values(array_diff($declared, $known)))->toBe(
        [],
        'A decimal column exists that is neither cast to string nor listed as exempt.',
    );

    expect(array_values(array_diff($known, $declared)))->toBe(
        [],
        'These are cast or exempted and the schema declares no such column, so the entry excuses '.
        'nothing and reads as considered. Delete it, or correct the table it names.',
    );
});

// Both scans above are lists that come back empty over a clean tree and over a
// walk that read nothing, so the readers are driven against planted migrations.
it('sees a money column stored as a float, and a decimal column under its own table', function (): void {
    $float = '->float'.'(';
    $decimal = '->decimal'.'(';

    expect(floatMoneyColumnsIn('<?php $table'.$float."'amount_minor', 8, 2);"))->toBe(['amount_minor'])
        ->and(floatMoneyColumnsIn('<?php $table'.$float."'settled_amount_minor');"))->toBe(['settled_amount_minor'])
        ->and(floatMoneyColumnsIn("<?php \$table->bigInteger('amount_minor');"))->toBe([])
        ->and(floatMoneyColumnsIn('<?php $table'.$float."'confidence', 4, 3);"))->toBe([]);

    $migration = "<?php Schema::create('chain_links', function (\$table) {\n    \$table".$decimal."'confidence', 4, 3);\n});\n"
        ."Schema::create('exchange_rates', function (\$table) {\n    \$table".$decimal."'rate', 18, 8);\n});";

    expect(decimalColumnsDeclaredIn($migration))->toBe(['chain_links.confidence', 'exchange_rates.rate'])
        ->and(decimalColumnsDeclaredIn("<?php Schema::create('t', function (\$table) {\n    \$table->bigInteger('n');\n});"))->toBe([]);
});
