<?php

declare(strict_types=1);

use Illuminate\Database\DatabaseManager;

it('it_rebuilds_the_fts_index', function (): void {
    $userId = $this->searchTestUser('reindex-user-a');

    // seedFts: false leaves the index empty, so the rebuild has to be the
    // thing that fills it.
    for ($i = 1; $i <= 3; $i++) {
        $this->searchTestTransaction($userId, [
            'counterparty_name' => "Vendor {$i}",
        ], seedFts: false);
    }

    /** @var DatabaseManager $db */
    $db = app(DatabaseManager::class);

    expect($db->connection()->table('transaction_search_docs')->count())->toBe(0);

    $this->artisan('search:reindex')->assertExitCode(0);

    $docsCount = $db->connection()->table('transaction_search_docs')->count();
    $txCount = $db->connection()->table('transactions')
        ->where('user_id', $userId)
        ->count();

    expect($docsCount)->toBeGreaterThanOrEqual($txCount);
});
