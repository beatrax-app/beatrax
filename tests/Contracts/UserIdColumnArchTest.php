<?php

declare(strict_types=1);

use Illuminate\Database\DatabaseManager;

// A table that never grew a user_id can never be scoped to an account, and sync
// would carry its rows to a paired device with nobody to attach them to. This
// guard named sixteen tables by hand while fifty-seven went unchecked, so it now
// walks the migrated schema and the list below is the only way out.
/**
 * @return array<string, string> table => the reason it carries no user_id
 */
function userIdExemptTables(): array
{
    return [
        // Laravel's own plumbing. Queue, cache and migration state belong to
        // the installation, not to a person in it. `sessions` was listed here
        // and did not belong: Laravel's own sessions table carries a user_id,
        // so the entry excused nothing and read as a considered decision.
        'cache' => 'framework cache store',
        'cache_locks' => 'framework cache store',
        'failed_jobs' => 'framework queue state',
        'job_batches' => 'framework queue state',
        'jobs' => 'framework queue state',
        'migrations' => 'framework schema ledger',
        'password_reset_tokens' => 'keyed by e-mail before any account is proven',

        // The account table itself, and the reference data every account reads.
        'users' => 'the owner table: its own id IS the user id',
        'currencies' => 'ISO reference data, identical for every user',
        'exchange_rates' => 'published market data, identical for every user',

        // Children of a row that is already scoped. An owner column here would
        // let a child disagree with its parent about who owns it.
        'rule_actions' => 'child of categorization_rules, scoped by its parent',
        'rule_conditions' => 'child of categorization_rules, scoped by its parent',

        // An FTS5 external-content index over transaction_search_docs, which
        // does carry the owner. It addresses rows by content_rowid and holds
        // no column of its own but the indexed text.
        'transaction_search_fts' => 'FTS5 index over transaction_search_docs, scoped by the table it indexes',

        // Deliberately owner-free.
        'relay_mailbox' => 'zero-knowledge relay: device_id routing only, never a user',
        'dev_mode_audit' => 'records what this machine did, not what an account did',

        // Import scratch space, truncated per run and never synced. The run
        // that owns them is identified by the import itself.
        'migration_staging_accounts' => 'per-run import staging, never synced',
        'migration_staging_budget_assignments' => 'per-run import staging, never synced',
        'migration_staging_categories' => 'per-run import staging, never synced',
        'migration_staging_goals' => 'per-run import staging, never synced',
        'migration_staging_payees' => 'per-run import staging, never synced',
        'migration_staging_transactions' => 'per-run import staging, never synced',
        'migration_staging_unmapped_items' => 'per-run import staging, never synced',
    ];
}

// Where a row exists before its owner is known: an importer writes it from a
// file and the account is attached afterwards. Most of the schema instead
// requires an owner at insert, which scopes a row at least as strictly — only
// these must stay nullable, or that ingestion order breaks.
/**
 * @return list<string>
 */
function userIdMustStayNullable(): array
{
    return [
        'accounts',
        'categories',
        'discovered_senders',
        'envelope_moves',
        'goal_contributions',
        'import_runs',
        'inbox_messages',
        'inbox_scan_state',
        'inboxes',
        'known_senders',
        'merchant_memories',
        'merchants',
        'oauth_secrets',
        'transaction_splits',
        'transactions',
        'user_recovery_codes',
    ];
}

/** @return list<string> every table the migrated schema declares */
function userIdSchemaTables(): array
{
    /** @var DatabaseManager $db */
    $db = app(DatabaseManager::class);

    $names = [];
    foreach ($db->connection()->getSchemaBuilder()->getTables() as $table) {
        $name = is_array($table) && isset($table['name']) && is_string($table['name']) ? $table['name'] : null;
        if ($name === null || str_starts_with($name, 'sqlite_')) {
            continue;
        }
        $names[] = $name;
    }

    sort($names);

    return $names;
}

it('gives every table the schema declares a user_id, or a stated reason it has none', function (): void {
    /** @var DatabaseManager $db */
    $db = app(DatabaseManager::class);
    $schema = $db->connection()->getSchemaBuilder();

    $tables = userIdSchemaTables();

    // Sixty-odd tables today. Floored far under: a schema read that answered
    // nothing would report every table as carrying its owner.
    expect(count($tables))->toBeGreaterThan(20, 'the schema read found almost no tables — the connection is wrong, not the schema.');

    $exempt = userIdExemptTables();
    $missing = [];

    foreach ($tables as $table) {
        if (isset($exempt[$table])) {
            continue;
        }
        if (collect($schema->getColumns($table))->firstWhere('name', 'user_id') === null) {
            $missing[] = $table;
        }
    }

    expect($missing)->toBe(
        [],
        "A table with no user_id cannot be scoped to an account and cannot be synced\n".
        "to one. Add the column, or add the table to userIdExemptTables() with the\n".
        'reason it has no owner. Missing:',
    );
});

it('keeps user_id nullable on the tables written before an owner is known', function (): void {
    /** @var DatabaseManager $db */
    $db = app(DatabaseManager::class);
    $schema = $db->connection()->getSchemaBuilder();

    $notNullable = [];

    foreach (userIdMustStayNullable() as $table) {
        $userId = collect($schema->getColumns($table))->firstWhere('name', 'user_id');

        expect($userId)->not->toBeNull("Table {$table} is missing user_id column");

        if ($userId['nullable'] !== true) {
            $notNullable[] = $table;
        }
    }

    expect($notNullable)->toBe(
        [],
        'An importer writes these rows before the account is attached. Not nullable:',
    );
});

it('carries no exemption for a table the schema no longer has', function (): void {
    // An exemption that outlives its table is a reason nobody can check, and
    // the next table to reuse the name inherits the excuse.
    $tables = userIdSchemaTables();

    expect(array_values(array_diff(array_keys(userIdExemptTables()), $tables)))
        ->toBe([], 'Remove the exemption for a table that no longer exists:');
    expect(array_values(array_diff(userIdMustStayNullable(), $tables)))
        ->toBe([], 'Remove the nullable requirement for a table that no longer exists:');
});

it('carries no exemption for a table that grew a user_id after all', function (): void {
    /** @var DatabaseManager $db */
    $db = app(DatabaseManager::class);
    $schema = $db->connection()->getSchemaBuilder();

    $tables = userIdSchemaTables();
    $excusingNothing = [];

    foreach (userIdExemptTables() as $table => $reason) {
        if (! in_array($table, $tables, true)) {
            continue;
        }

        if (collect($schema->getColumns($table))->firstWhere('name', 'user_id') !== null) {
            $excusingNothing[] = $table.' — excused as "'.$reason.'", and carries user_id';
        }
    }

    // The other half of the staleness check above. A table that grew the column
    // is no longer excused by anything; leaving the entry means the day it
    // LOSES the column again, nothing says so. `sessions` sat here for exactly
    // that reason: Laravel's own table has carried a user_id all along.
    expect($excusingNothing)->toBe([], implode("\n  ", [
        'These tables are exempted from needing a user_id and have one. The exemption excuses',
        'nothing, and a reason nobody can trip reads as considered to every reader after it.',
        'Delete the entry:',
        ...$excusingNothing,
    ]));
});
