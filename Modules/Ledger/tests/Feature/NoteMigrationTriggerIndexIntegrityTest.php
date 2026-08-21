<?php

declare(strict_types=1);

use Illuminate\Database\DatabaseManager;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('transactions carries exactly the four check triggers after the note migration', function (): void {
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

it('transactions retains all expected indexes after the note column-add', function (): void {
    /** @var DatabaseManager $db */
    $db = app(DatabaseManager::class);

    $indexes = collect($db->connection()->select(
        "SELECT name FROM sqlite_master WHERE type='index' AND tbl_name='transactions'"
    ))->pluck('name')
        // sqlite_autoindex_* are unnamed and reappear with their constraint.
        ->reject(fn (string $n): bool => str_starts_with($n, 'sqlite_autoindex_'))
        ->values()
        ->all();

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

it('FTS sync triggers are NOT on transactions — they live on transaction_search_docs', function (): void {
    /** @var DatabaseManager $db */
    $db = app(DatabaseManager::class);

    // FTS lives on a separate shadow table, so a transactions rebuild has no
    // FTS trigger to drop.
    $ftsTriggersOnTransactions = $db->connection()->select(
        "SELECT name FROM sqlite_master WHERE type='trigger' AND tbl_name='transactions' AND name LIKE '%fts%'"
    );

    expect($ftsTriggersOnTransactions)->toBe([]);

    $ftsTable = $db->connection()->select(
        "SELECT name FROM sqlite_master WHERE name='transaction_search_fts'"
    );
    expect($ftsTable)->not->toBe([]);
});
