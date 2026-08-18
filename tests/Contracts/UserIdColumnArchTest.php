<?php

declare(strict_types=1);

use Illuminate\Database\DatabaseManager;

it('every domain table has a nullable user_id column for multi-user readiness', function (): void {
    // RefreshDatabase has already migrated the test schema; introspect it directly.
    $connection = $this->app->make(DatabaseManager::class)->connection();
    $domainTables = [
        'accounts',
        'transactions',
        'categories',
        'discovered_senders',
        'inbox_messages',
        'inbox_scan_state',
        'inboxes',
        'known_senders',
        'merchants',
        'merchant_memories',
        'import_runs',
        'oauth_secrets',
        'user_recovery_codes',
        'transaction_splits',
        'envelope_moves',
        'goal_contributions',
    ];

    foreach ($domainTables as $table) {
        $columns = $connection->getSchemaBuilder()->getColumns($table);
        $userId = collect($columns)->firstWhere('name', 'user_id');
        expect($userId)->not->toBeNull("Table {$table} is missing user_id column");
        expect($userId['nullable'])->toBeTrue("Table {$table}.user_id must be nullable");
    }
});
