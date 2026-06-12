<?php

declare(strict_types=1);

use Illuminate\Database\DatabaseManager;

// Wave 0 RED — implemented in Plan 02 (ReindexSearchCommand + SearchIndexWriter)

/**
 * ReindexCommandTest — D-24 full-index rebuild test.
 *
 * RED in Wave 0.  Requires `search:reindex` artisan command (Plan 02) to
 * be registered.  Once Plan 02 lands, the command rebuilds the entire
 * transaction_search_docs + FTS index from the transactions table and the
 * doctor probe row count matches.
 */

// D-24: reindex command rebuilds the FTS index
it('it_rebuilds_the_fts_index', function (): void {
    // Wave 0 RED — implemented in Plan 02
    $userId = $this->searchTestUser('reindex-user-a');

    // Seed transactions BEFORE reindex (no prior index entries exist)
    for ($i = 1; $i <= 3; $i++) {
        $this->searchTestTransaction($userId, [
            'counterparty_name' => "Vendor {$i}",
        ]);
    }

    /** @var DatabaseManager $db */
    $db = app(DatabaseManager::class);

    // Precondition: index is empty (no writer ran yet)
    expect($db->connection()->table('transaction_search_docs')->count())->toBe(0);

    // Run the reindex command
    $this->artisan('search:reindex')->assertExitCode(0);

    // Postcondition: all transactions now indexed
    $docsCount = $db->connection()->table('transaction_search_docs')->count();
    $txCount = $db->connection()->table('transactions')
        ->where('user_id', $userId)
        ->count();

    expect($docsCount)->toBeGreaterThanOrEqual($txCount);
});
