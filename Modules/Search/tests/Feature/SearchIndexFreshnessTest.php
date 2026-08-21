<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Modules\Core\Models\User;
use Modules\Search\Public\Contracts\SearchIndexWriterContract;
use Modules\Search\Public\Dto\SearchFilters;
use Modules\Search\Public\Dto\SearchResultPage;
use Modules\Search\Public\Services\SearchQuery;

// Indexing happens on the same write a transaction arrives on: searchable
// immediately, never after a queue run.

it('it_indexes_new_transactions_synchronously', function (): void {
    $userId = $this->searchTestUser('freshness-user-a');
    $user = User::findOrFail($userId);

    $txId = $this->searchTestTransaction($userId, [
        'counterparty_name' => 'Aldi Nederland',
        'description' => 'Groceries friday',
    ]);

    // The fixture insert never fires TransactionImported, so drive the writer
    // the listener would have called.
    $writer = app(SearchIndexWriterContract::class);
    $writer->upsertForTransaction($txId, $userId);

    /** @var SearchQuery $searchQuery */
    $searchQuery = app(SearchQuery::class);

    /** @var SearchResultPage $page */
    $page = $searchQuery->search($user, 'Aldi', SearchFilters::empty());

    expect($page->totalCount)->toBeGreaterThan(0);
});

it('it_reindexes_when_tax_note_is_updated', function (): void {
    $userId = $this->searchTestUser('freshness-user-b');
    $user = User::findOrFail($userId);

    $txId = $this->searchTestTransaction($userId, [
        'counterparty_name' => 'Office Depot',
        'description' => 'Stationery purchase',
    ]);

    $writer = app(SearchIndexWriterContract::class);
    $writer->upsertForTransaction($txId, $userId);

    // Stands in for a TagTransaction call.
    DB::table('tax_transaction_tags')->insert([
        'transaction_id' => $txId,
        'user_id' => $userId,
        'note' => 'ergonomic keyboard deductible',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $writer->upsertForTransaction($txId, $userId);

    /** @var SearchQuery $searchQuery */
    $searchQuery = app(SearchQuery::class);

    /** @var SearchResultPage $page */
    $page = $searchQuery->search($user, 'keyboard', SearchFilters::empty());

    expect($page->totalCount)->toBeGreaterThan(0);
});

// The writer issues the FTS delete whenever a docs row existed at all, not
// only when the old body was non-empty, which is what keeps a second upsert
// from stacking a stale posting behind the new one.
it('it_does_not_duplicate_fts_postings_on_reindex', function (): void {
    $userId = $this->searchTestUser('reindex-dedup-user');
    $user = User::findOrFail($userId);

    // Skip the harness FTS seed so the writer alone owns the index.
    $txId = $this->searchTestTransaction($userId, [
        'counterparty_name' => 'Coolblue',
        'description' => 'original widget purchase',
    ], seedFts: false);

    $writer = app(SearchIndexWriterContract::class);

    $writer->upsertForTransaction($txId, $userId);

    DB::table('transactions')->where('id', $txId)->update([
        'description' => 'replacement gadget purchase',
    ]);
    $writer->upsertForTransaction($txId, $userId);

    /** @var SearchQuery $searchQuery */
    $searchQuery = app(SearchQuery::class);

    /** @var SearchResultPage $page */
    $page = $searchQuery->search($user, 'gadget', SearchFilters::empty());
    expect($page->totalCount)->toBe(1)
        ->and($page->rows)->toHaveCount(1);

    /** @var SearchResultPage $stale */
    $stale = $searchQuery->search($user, 'widget', SearchFilters::empty());
    expect($stale->totalCount)->toBe(0);

    $docCount = DB::table('transaction_search_docs')->where('transaction_id', $txId)->count();
    expect($docCount)->toBe(1);
});

it('it_refuses_to_index_a_transaction_owned_by_another_user', function (): void {
    $ownerId = $this->searchTestUser('writer-owner');
    $attackerId = $this->searchTestUser('writer-attacker');

    $txId = $this->searchTestTransaction($ownerId, [
        'counterparty_name' => 'Private Merchant',
        'description' => 'confidential note',
    ], seedFts: false);

    $writer = app(SearchIndexWriterContract::class);

    $writer->upsertForTransaction($txId, $attackerId);
    expect(DB::table('transaction_search_docs')->where('transaction_id', $txId)->count())->toBe(0);

    $writer->upsertForTransaction($txId, $ownerId);
    expect(DB::table('transaction_search_docs')->where('transaction_id', $txId)->count())->toBe(1);

    $writer->deleteForTransaction($txId, $attackerId);
    expect(DB::table('transaction_search_docs')->where('transaction_id', $txId)->count())->toBe(1);

    $writer->deleteForTransaction($txId, $ownerId);
    expect(DB::table('transaction_search_docs')->where('transaction_id', $txId)->count())->toBe(0);
});
