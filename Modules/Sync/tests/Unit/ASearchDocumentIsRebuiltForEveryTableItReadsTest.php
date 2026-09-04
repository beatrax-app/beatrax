<?php

declare(strict_types=1);

use Illuminate\Database\DatabaseManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Sync\Internal\Merge\SearchDocumentRows;

uses(RefreshDatabase::class);

// A replay that rebuilt the index only for `transactions` left 17 of 147
// bodies on a receiving device without their tax note: the tag synced, the
// document did not. The list below is derived from the writer rather than
// copied, so a third source table breaks this instead of going unindexed.

/**
 * @return list<string>
 */
function tablesTheSearchWriterComposesFrom(): array
{
    $source = file_get_contents(base_path('Modules/Search/Internal/Services/SearchIndexWriter.php'));

    expect($source)->toBeString();

    preg_match_all("/->table\('([a-z_]+)'\)/", (string) $source, $matches);

    // The destination is not a source: the writer reads it only to compare the
    // body it is about to store against the one already there.
    $tables = array_diff(array_unique($matches[1]), ['transaction_search_docs']);

    sort($tables);

    return array_values($tables);
}

it('tracks freshness for every table the document is composed from', function (): void {
    $tracked = SearchDocumentRows::sourceTables();
    sort($tracked);

    expect($tracked)->toBe(tablesTheSearchWriterComposesFrom());
});

it('resolves a tag to the transaction whose document it belongs to', function (): void {
    /** @var DatabaseManager $db */
    $db = app(DatabaseManager::class);
    $connection = $db->connection();

    $userId = $connection->table('users')->insertGetId([
        'username' => 'fts-rows-u1',
        'password' => 'fixture-password-12chars',
        'period_start_day' => 1,
        'default_currency_view' => 'eur_only',
    ]);

    $accountId = $connection->table('accounts')->insertGetId([
        'user_id' => $userId,
        'name' => 'FTS rows test',
        'slug' => 'fts-rows-account',
        'kind' => 'bank',
        'iban' => 'NL00ASNB0000000001',
        'default_currency' => 'EUR',
        'created_at' => '2026-07-01 00:00:00',
        'updated_at' => '2026-07-01 00:00:00',
    ]);

    $runId = $connection->table('import_runs')->insertGetId([
        'user_id' => $userId,
        'source_format' => 'asn-csv',
        'raw_file_path' => '/tmp/fts-rows.csv',
        'sha256' => hash('sha256', 'fts-rows-run'),
        'uploaded_at' => '2026-07-01 00:00:00',
        'status' => 'previewed',
        'created_at' => '2026-07-01 00:00:00',
        'updated_at' => '2026-07-01 00:00:00',
    ]);

    $transactionId = $connection->table('transactions')->insertGetId([
        'user_id' => $userId,
        'account_id' => $accountId,
        'import_run_id' => $runId,
        'fingerprint' => hash('sha256', 'fts-rows-tx'),
        'posted_at' => '2026-07-01',
        'booked_at' => '2026-07-01 10:00:00',
        'value_date' => '2026-07-01',
        'amount_minor' => -1500,
        'currency' => 'EUR',
        'settled_amount_minor' => -1500,
        'settled_currency' => 'EUR',
        'counterparty_normalized' => 'bol com',
        'counterparty_name' => 'Bol.com',
        'normalization_version' => 3,
        'description' => 'Bol.com via PayPal',
        'type' => 'expense',
        'source_format' => 'asn-csv',
        'source_row_index' => 1,
        'fingerprint_version' => 3,
        'created_at' => '2026-07-01 00:00:00',
        'updated_at' => '2026-07-01 00:00:00',
    ]);

    $tagId = $connection->table('tax_transaction_tags')->insertGetId([
        'user_id' => $userId,
        'transaction_id' => $transactionId,
        'note' => 'Vakliteratuur',
    ]);

    $rows = new SearchDocumentRows($db);

    expect($rows->documentsOf('tax_transaction_tags', $tagId, $userId))->toBe([$transactionId]);
    expect($rows->documentsOf('transactions', $transactionId, $userId))->toBe([$transactionId]);
});

it('rebuilds a transaction whose tag was removed instead of dropping its document', function (): void {
    /** @var DatabaseManager $db */
    $db = app(DatabaseManager::class);

    $rows = new SearchDocumentRows($db);

    // Deleting the tag leaves the transaction behind, so its document is
    // rebuilt without the note. Deleting the transaction takes the document
    // with it. Getting these two the same way loses a row from search.
    $rows->rowDeleted('tax_transaction_tags', [4242]);
    $rows->rowDeleted('transactions', [99]);

    expect($rows->touched())->toBe([4242]);
    expect($rows->tombstoned())->toBe([99]);
});
