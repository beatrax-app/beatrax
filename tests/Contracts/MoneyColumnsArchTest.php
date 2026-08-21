<?php

declare(strict_types=1);

use Illuminate\Database\DatabaseManager;
use Illuminate\Database\Schema\Blueprint;

// A minor-unit integer does not say which currency it counts. This guard named
// one table by hand while nineteen others went unchecked, which is long enough
// for two of them to ship an amount with no currency beside it at all.
/**
 * @return array<string, array{minor: list<string>, currency: list<string>}>
 *                                                                          every table the migrated schema declares that carries a *_minor column
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
            'currency' => array_values(array_filter($columns, static fn (string $c): bool => str_contains($c, 'currency'))),
        ];
    }

    ksort($tables);

    return $tables;
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

    expect($columns)->toContain('amount_minor');
    expect($columns)->toContain('currency');
    expect($columns)->toContain('settled_amount_minor');
    expect($columns)->toContain('settled_currency');
});

it('stores every minor-unit amount the schema declares beside a currency code', function (): void {
    $tables = moneyColumnsByTable();
    expect($tables)->not->toBeEmpty('the probe found no money columns at all, which cannot be right');

    expect(moneyTablesMissingCurrency($tables))->toBe(
        [],
        "An amount stored without a currency forces every read site to hardcode one,\n".
        "which stays right only while every row happens to agree. Store the code the\n".
        'amount was denominated in. Offenders:',
    );
});

it('sees a table whose amount has no currency beside it', function (): void {
    // Without this the probe above passes on a schema it cannot read at all,
    // which is indistinguishable from a schema that is clean.
    /** @var DatabaseManager $db */
    $db = app(DatabaseManager::class);
    $schema = $db->connection()->getSchemaBuilder();

    $schema->create('money_columns_probe', static function (Blueprint $table): void {
        $table->id();
        $table->bigInteger('amount_minor');
    });

    try {
        $offenders = moneyTablesMissingCurrency(moneyColumnsByTable());
    } finally {
        $schema->drop('money_columns_probe');
    }

    expect($offenders)->toContain('money_columns_probe (amount_minor)');
});
