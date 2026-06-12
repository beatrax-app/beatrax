<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Modules\Core\Models\User;
use Modules\Search\Public\Contracts\SearchIndexWriterContract;
use Modules\Search\Public\Dto\SearchFilters;
use Modules\Search\Public\Dto\SearchResultPage;
use Modules\Search\Public\Services\SearchQuery;

// Wave 0 RED — implemented in Plan 02 (SearchIndexWriter) and Plan 03 (SearchQuery)

/**
 * SearchIndexFreshnessTest — SRCH-01 index freshness tests.
 *
 * Both tests in this file are RED in Wave 0.  They require:
 *   - SearchIndexWriter (Plan 02) to be registered so the TransactionImported
 *     listener and the TagTransaction call path can upsert the FTS index.
 *   - SearchQuery (Plan 03) to be registered for result assertions.
 *
 * D-23: "Searchable immediately, same write." Index updates are synchronous
 * and never use a queue.
 */

// SRCH-01: import freshness
it('it_indexes_new_transactions_synchronously', function (): void {
    // Wave 0 RED — implemented in Plan 02
    $userId = $this->searchTestUser('freshness-user-a');
    $user = User::findOrFail($userId);

    // Seed transaction (this would normally fire TransactionImported which
    // triggers IndexTransactionOnImport — once Plan 02 lands)
    $txId = $this->searchTestTransaction($userId, [
        'counterparty_name' => 'Aldi Nederland',
        'description' => 'Groceries friday',
    ]);

    // Manually trigger the writer as Plan 02 will do it via the event listener
    $writer = app(SearchIndexWriterContract::class);
    $writer->upsertForTransaction($txId);

    /** @var SearchQuery $searchQuery */
    $searchQuery = app(SearchQuery::class);

    /** @var SearchResultPage $page */
    $page = $searchQuery->search($user, 'Aldi', SearchFilters::empty());

    expect($page->totalCount)->toBeGreaterThan(0);
});

// SRCH-01: tax note freshness
it('it_reindexes_when_tax_note_is_updated', function (): void {
    // Wave 0 RED — implemented in Plan 02
    $userId = $this->searchTestUser('freshness-user-b');
    $user = User::findOrFail($userId);

    $txId = $this->searchTestTransaction($userId, [
        'counterparty_name' => 'Office Depot',
        'description' => 'Stationery purchase',
    ]);

    // Initial index (no note)
    $writer = app(SearchIndexWriterContract::class);
    $writer->upsertForTransaction($txId);

    // Add a tax note — simulates TagTransaction call
    DB::table('tax_transaction_tags')->insert([
        'transaction_id' => $txId,
        'user_id' => $userId,
        'note' => 'ergonomic keyboard deductible',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    // Re-index with the note
    $writer->upsertForTransaction($txId);

    /** @var SearchQuery $searchQuery */
    $searchQuery = app(SearchQuery::class);

    /** @var SearchResultPage $page */
    $page = $searchQuery->search($user, 'keyboard', SearchFilters::empty());

    expect($page->totalCount)->toBeGreaterThan(0);
});
