<?php

declare(strict_types=1);

use Illuminate\Database\DatabaseManager;

it('every domain table has a nullable user_id column (FND-03)', function (): void {
    // RefreshDatabase has already migrated the test schema; introspect it directly.
    $connection = $this->app->make(DatabaseManager::class)->connection();
    $domainTables = [
        'accounts',
        'transactions',
        'categories',
        'merchants',
        'merchant_memories',
        'import_runs',
    ];

    foreach ($domainTables as $table) {
        $columns = $connection->getSchemaBuilder()->getColumns($table);
        $userId = collect($columns)->firstWhere('name', 'user_id');
        expect($userId)->not->toBeNull("Table {$table} is missing user_id column (FND-03)");
        expect($userId['nullable'])->toBeTrue("Table {$table}.user_id must be nullable (FND-03)");
    }
});
