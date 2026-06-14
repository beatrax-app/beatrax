<?php

declare(strict_types=1);

use Illuminate\Database\DatabaseManager;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/*
 * WR-07: the note migration (2026_06_15_000004_add_note_to_transactions) is the
 * new "last toucher" of the transactions table. SQLite rebuilds a table on some
 * column operations and drops ALL its triggers (and, for a full rebuild, its
 * indexes). This test asserts that after the full migration set runs, the
 * transactions table carries:
 *
 *   - exactly the four enumerated transactions_*_check_* triggers (the complete
 *     set per database/schema/sqlite-schema.sql — there are no FTS triggers on
 *     transactions; FTS lives on the separate transaction_search_docs table), and
 *   - all of its expected indexes (none silently lost by the column-add).
 *
 * If a future ALTER on transactions drops a trigger or index without restoring
 * it, this test fails loudly.
 */

it('transactions carries exactly the four check triggers after the note migration (WR-07)', function (): void {
    /** @var DatabaseManager $db */
    $db = app(DatabaseManager::class);

    $triggers = collect($db->connection()->select(
        "SELECT name FROM sqlite_master WHERE type='trigger' AND tbl_name='transactions' ORDER BY name"
    ))->pluck('name')->all();

    expect($triggers)->toEqualCanonicalizing([
        'transactions_payment_type_check_insert',
        'transactions_payment_type_check_update',
        'transactions_type_check_insert',
        'transactions_type_check_update',
    ]);
});

it('transactions retains all expected indexes after the note column-add (WR-07)', function (): void {
    /** @var DatabaseManager $db */
    $db = app(DatabaseManager::class);

    $indexes = collect($db->connection()->select(
        "SELECT name FROM sqlite_master WHERE type='index' AND tbl_name='transactions'"
    ))->pluck('name')
        // Drop auto-created UNIQUE/PK indexes (sqlite_autoindex_*) — they are not
        // named in the schema and are recreated automatically with the constraint.
        ->reject(fn (string $n): bool => str_starts_with($n, 'sqlite_autoindex_'))
        ->values()
        ->all();

    // The named indexes that the schema snapshot declares on transactions.
    $expected = [
        'transactions_account_id_posted_at_index',
        'transactions_category_id_posted_at_index',
        'transactions_fingerprint_sha_uq',
        'transactions_fingerprint_uq',
        'transactions_uncategorized_idx',
        'transactions_user_id_posted_at_index',
        'transactions_unpaired_transfer_idx',
        'transactions_user_id_payment_type_index',
        'transactions_user_id_counterparty_id_index',
    ];

    foreach ($expected as $name) {
        expect($indexes)->toContain($name);
    }
});

it('FTS sync triggers are NOT on transactions — they live on transaction_search_docs (WR-07)', function (): void {
    /** @var DatabaseManager $db */
    $db = app(DatabaseManager::class);

    // No FTS triggers on transactions itself, so the note migration's table
    // rebuild cannot have dropped any. (Documents the WR-07 finding: FTS is a
    // separate shadow table, unaffected by a transactions column-add.)
    $ftsTriggersOnTransactions = $db->connection()->select(
        "SELECT name FROM sqlite_master WHERE type='trigger' AND tbl_name='transactions' AND name LIKE '%fts%'"
    );

    expect($ftsTriggersOnTransactions)->toBe([]);

    // The FTS virtual table itself still exists and is queryable.
    $ftsTable = $db->connection()->select(
        "SELECT name FROM sqlite_master WHERE name='transaction_search_fts'"
    );
    expect($ftsTable)->not->toBe([]);
});
