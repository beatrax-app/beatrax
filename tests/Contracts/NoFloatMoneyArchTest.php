<?php

declare(strict_types=1);
use Illuminate\Database\Eloquent\Model;
use Modules\Ledger\Models\Transaction;

it('no migration declares REAL or FLOAT on a money column', function (): void {
    $migrationDirs = glob(base_path('Modules/*/Database/Migrations')) ?: [];
    $offenders = [];

    foreach ($migrationDirs as $dir) {
        foreach (glob($dir.'/*.php') ?: [] as $file) {
            $contents = file_get_contents($file);
            if (preg_match('/->(float|double|real)\(["\']\w*(amount|minor)\w*["\']/i', $contents) === 1) {
                $offenders[] = $file;
            }
        }
    }
    expect($offenders)->toBeEmpty();

    $allMigrationContents = '';
    foreach ($migrationDirs as $dir) {
        foreach (glob($dir.'/*.php') ?: [] as $file) {
            $allMigrationContents .= file_get_contents($file)."\n";
        }
    }
    expect($allMigrationContents)->toContain("bigInteger('amount_minor')");
});

/*
 * The migration guard above stops money being stored as a float. It does not
 * stop one being *read* as a float, which is the half that actually shipped.
 *
 * SQLite hands `decimal`/`numeric` columns back as PHP floats, and brick/math
 * 0.18 accepts only BigNumber|int|string — a float argument is coerced to int,
 * so `0.92917629` silently becomes `0`. That is how the transaction detail
 * page came to render "€0.000 / USD" against a correctly stored rate, with
 * nothing but a deprecation notice to say so.
 *
 * Every decimal-shaped column therefore needs a string cast on its model, so
 * the value is a decimal string from the database to the arithmetic.
 */
it('casts every decimal column to string so it never reaches BigDecimal as a float', function (): void {
    $decimalColumns = [
        // column => the model that owns it
        'fx_rate_used' => Transaction::class,
    ];

    $missing = [];

    foreach ($decimalColumns as $column => $modelClass) {
        /** @var Model $model */
        $model = new $modelClass;
        $casts = $model->getCasts();

        if (($casts[$column] ?? null) !== 'string') {
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
    // The map above is hand-maintained; this keeps it honest against the real
    // schema. A new decimal column fails here until it is either cast or
    // consciously listed as exempt.
    $exempt = [
        // Confidence is a score compared against thresholds, never money and
        // never fed to BigDecimal.
        'chain_links.confidence',
        // Read through ExchangeRateService::toString() before it reaches
        // brick, which is the same guarantee by a different route.
        'exchange_rates.rate',
    ];

    $migrationDirs = glob(base_path('Modules/*/Database/Migrations')) ?: [];
    $declared = [];

    foreach ($migrationDirs as $dir) {
        foreach (glob($dir.'/*.php') ?: [] as $file) {
            $contents = (string) file_get_contents($file);
            if (preg_match_all('/->(?:decimal|unsignedDecimal)\(\s*[\'"](\w+)[\'"]/', $contents, $m) === false) {
                continue;
            }
            foreach ($m[1] as $column) {
                $declared[] = $column;
            }
        }
    }

    $known = array_merge(
        ['fx_rate_used'],
        array_map(static fn (string $q): string => explode('.', $q)[1], $exempt),
    );

    expect(array_values(array_unique(array_diff($declared, $known))))->toBe(
        [],
        'A decimal column exists that is neither cast to string nor listed as exempt.',
    );
});
