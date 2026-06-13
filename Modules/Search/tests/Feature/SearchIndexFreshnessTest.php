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
    $writer->upsertForTransaction($txId, $userId);

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
    $writer->upsertForTransaction($txId, $userId);

    // Add a tax note — simulates TagTransaction call
    DB::table('tax_transaction_tags')->insert([
        'transaction_id' => $txId,
        'user_id' => $userId,
        'note' => 'ergonomic keyboard deductible',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    // Re-index with the note
    $writer->upsertForTransaction($txId, $userId);

    /** @var SearchQuery $searchQuery */
    $searchQuery = app(SearchQuery::class);

    /** @var SearchResultPage $page */
    $page = $searchQuery->search($user, 'keyboard', SearchFilters::empty());

    expect($page->totalCount)->toBeGreaterThan(0);
});

// CR-03: re-indexing after a description change must not leave duplicate FTS
// postings. The writer issues the FTS 'delete' whenever a docs row previously
// existed (not only when the old body was non-empty), inside one transaction,
// so a second upsert replaces — never stacks — the posting.
it('it_does_not_duplicate_fts_postings_on_reindex', function (): void {
    $userId = $this->searchTestUser('reindex-dedup-user');
    $user = User::findOrFail($userId);

    // Seed WITHOUT the test-harness FTS seed so the writer owns the index.
    $txId = $this->searchTestTransaction($userId, [
        'counterparty_name' => 'Coolblue',
        'description' => 'original widget purchase',
    ], seedFts: false);

    $writer = app(SearchIndexWriterContract::class);

    // First index.
    $writer->upsertForTransaction($txId, $userId);

    // Change the description, then re-index.
    DB::table('transactions')->where('id', $txId)->update([
        'description' => 'replacement gadget purchase',
    ]);
    $writer->upsertForTransaction($txId, $userId);

    /** @var SearchQuery $searchQuery */
    $searchQuery = app(SearchQuery::class);

    // The new term resolves to exactly one row (no stale duplicate posting).
    /** @var SearchResultPage $page */
    $page = $searchQuery->search($user, 'gadget', SearchFilters::empty());
    expect($page->totalCount)->toBe(1)
        ->and($page->rows)->toHaveCount(1);

    // The old term no longer matches at all (the stale posting was removed).
    /** @var SearchResultPage $stale */
    $stale = $searchQuery->search($user, 'widget', SearchFilters::empty());
    expect($stale->totalCount)->toBe(0);

    // And exactly one docs row exists for the transaction.
    $docCount = DB::table('transaction_search_docs')->where('transaction_id', $txId)->count();
    expect($docCount)->toBe(1);
});

// CR-02: the writer refuses to (re)index a transaction the actor does not own.
it('it_refuses_to_index_a_transaction_owned_by_another_user', function (): void {
    $ownerId = $this->searchTestUser('writer-owner');
    $attackerId = $this->searchTestUser('writer-attacker');

    // Owner's transaction, not yet indexed.
    $txId = $this->searchTestTransaction($ownerId, [
        'counterparty_name' => 'Private Merchant',
        'description' => 'confidential note',
    ], seedFts: false);

    $writer = app(SearchIndexWriterContract::class);

    // Attacker attempts to index the owner's transaction → no-op.
    $writer->upsertForTransaction($txId, $attackerId);
    expect(DB::table('transaction_search_docs')->where('transaction_id', $txId)->count())->toBe(0);

    // Owner indexes their own transaction → succeeds.
    $writer->upsertForTransaction($txId, $ownerId);
    expect(DB::table('transaction_search_docs')->where('transaction_id', $txId)->count())->toBe(1);

    // Attacker attempts to delete the owner's doc → no-op.
    $writer->deleteForTransaction($txId, $attackerId);
    expect(DB::table('transaction_search_docs')->where('transaction_id', $txId)->count())->toBe(1);

    // Owner deletes their own doc → removed.
    $writer->deleteForTransaction($txId, $ownerId);
    expect(DB::table('transaction_search_docs')->where('transaction_id', $txId)->count())->toBe(0);
});
