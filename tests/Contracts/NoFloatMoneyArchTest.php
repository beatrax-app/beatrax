<?php

declare(strict_types=1);
use Illuminate\Database\Eloquent\Model;
use Modules\Core\Public\Support\PatternScan;
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

/**
 * @link ../../.docs/conventions/invariants-from-shipped-failures.md#a-decimal-column-read-as-a-float
 */
// The guard above stops money being stored as a float; this one stops it being
// read as one, which is the half that actually shipped.
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
    // Keeps the hand-maintained map above honest: a new decimal column fails
    // here until it is either cast or consciously exempted.
    $exempt = [
        // A score compared against thresholds, never fed to BigDecimal.
        'chain_links.confidence',
        // Reaches brick through ExchangeRateService::toString() instead.
        'exchange_rates.rate',
    ];

    $migrationDirs = glob(base_path('Modules/*/Database/Migrations')) ?: [];
    $declared = [];

    foreach ($migrationDirs as $dir) {
        foreach (glob($dir.'/*.php') ?: [] as $file) {
            $contents = (string) file_get_contents($file);
            $m = PatternScan::all('/->(?:decimal|unsignedDecimal)\(\s*[\'"](\w+)[\'"]/', $contents);

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
