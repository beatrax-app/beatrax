<?php

declare(strict_types=1);

use Illuminate\Database\DatabaseManager;
use Illuminate\Database\Schema\Blueprint;

// A minor-unit integer does not say which currency it counts. This guard named
// one table by hand while nineteen others went unchecked, which is long enough
// for two of them to ship an amount with no currency beside it at all.
/**
 * @return array<string, array{minor: list<string>, currency: list<string>}>
 *                                                                           every table the migrated schema declares that carries a *_minor column
 */
function moneyColumnsByTable(): array
{
    /** @var DatabaseManager $db */
    $db = app(DatabaseManager::class);
    $schema = $db->connection()->getSchemaBuilder();

    $tables = [];

    foreach ($schema->getTables() as $table) {
        $name = is_array($table) && isset($table['name']) && is_string($table['name']) ? $table['name'] : null;
        if ($name === null || str_starts_with($name, 'sqlite_')) {
            continue;
        }

        $columns = array_map(
            static fn (array $column): string => is_string($column['name']) ? $column['name'] : '',
            $schema->getColumns($name),
        );

        $minor = array_values(array_filter($columns, static fn (string $c): bool => str_ends_with($c, '_minor')));
        if ($minor === []) {
            continue;
        }

        $tables[$name] = [
            'minor' => $minor,
            'currency' => array_values(array_filter($columns, moneyColumnNamesACurrency(...))),
        ];
    }

    ksort($tables);

    return $tables;
}

// The column has to BE a currency, not merely contain the word. `str_contains`
// counted users.default_currency_view — a view preference holding 'eur_only' —
// as the currency beside two minor-unit columns, which is the shape of an
// amount answered for by a column that does not denominate it.
function moneyColumnNamesACurrency(string $column): bool
{
    return $column === 'currency' || str_ends_with($column, '_currency');
}

/**
 * @param  array<string, array{minor: list<string>, currency: list<string>}>  $tables
 * @return list<string> one entry per table storing an amount with no currency
 */
function moneyTablesMissingCurrency(array $tables): array
{
    $offenders = [];

    foreach ($tables as $table => $columns) {
        if ($columns['currency'] === []) {
            $offenders[] = $table.' ('.implode(', ', $columns['minor']).')';
        }
    }

    return $offenders;
}

it('transactions table has both native and settled money columns', function (): void {
    // RefreshDatabase has already migrated the test schema; introspect it directly.
    $connection = $this->app->make(DatabaseManager::class)->connection();
    $columns = collect($connection->getSchemaBuilder()->getColumns('transactions'))->pluck('name');

    expect($columns->count())->toBeGreaterThan(
        10,
        'the transactions table introspected to '.$columns->count().' columns, which is too few to have been migrated.'
    );

    expect($columns)
        ->toContain('amount_minor')
        ->toContain('currency')
        ->toContain('settled_amount_minor')
        ->toContain('settled_currency');
});

it('stores every minor-unit amount the schema declares beside a currency code', function (): void {
    $tables = moneyColumnsByTable();

    // Read before the verdict: the floor sits far under today's 21, so a probe
    // that read no schema at all fails here rather than reporting a clean one.
    expect(count($tables))->toBeGreaterThan(
        10,
        'the probe found '.count($tables).' tables carrying a minor-unit column, which cannot be right.'
    );

    expect(moneyTablesMissingCurrency($tables))->toBe(
        [],
        "An amount stored without a currency forces every read site to hardcode one,\n".
        "which stays right only while every row happens to agree. Store the code the\n".
        'amount was denominated in. Offenders:',
    );
});

it('sees a table whose amount has no currency beside it, and one answered for by a column that is not one', function (): void {
    // Without this the probe above passes on a schema it cannot read at all,
    // which is indistinguishable from a schema that is clean.
    /** @var DatabaseManager $db */
    $db = app(DatabaseManager::class);
    $schema = $db->connection()->getSchemaBuilder();

    $schema->create('money_columns_probe', static function (Blueprint $table): void {
        $table->id();
        $table->bigInteger('amount_minor');
    });

    // The second probe is the narrowing: a column merely holding the word is
    // not the code the amount is denominated in.
    $schema->create('money_columns_near_miss_probe', static function (Blueprint $table): void {
        $table->id();
        $table->bigInteger('amount_minor');
        $table->string('default_currency_view');
    });

    $schema->create('money_columns_answered_probe', static function (Blueprint $table): void {
        $table->id();
        $table->bigInteger('amount_minor');
        $table->string('settled_currency');
    });

    try {
        $offenders = moneyTablesMissingCurrency(moneyColumnsByTable());
    } finally {
        $schema->drop('money_columns_probe');
        $schema->drop('money_columns_near_miss_probe');
        $schema->drop('money_columns_answered_probe');
    }

    expect($offenders)
        ->toContain('money_columns_probe (amount_minor)')
        ->toContain('money_columns_near_miss_probe (amount_minor)');

    expect(in_array('money_columns_answered_probe (amount_minor)', $offenders, true))->toBeFalse(
        'a table whose amount sits beside a *_currency column is answered for, and reporting it is the rule being wrong.'
    );
});

it('tells a currency column from a column that merely names one', function (): void {
    expect(moneyColumnNamesACurrency('currency'))->toBeTrue()
        ->and(moneyColumnNamesACurrency('settled_currency'))->toBeTrue()
        ->and(moneyColumnNamesACurrency('base_currency'))->toBeTrue()
        ->and(moneyColumnNamesACurrency('default_currency_view'))->toBeFalse()
        ->and(moneyColumnNamesACurrency('currency_id'))->toBeFalse()
        ->and(moneyColumnNamesACurrency('amount_minor'))->toBeFalse();
});
