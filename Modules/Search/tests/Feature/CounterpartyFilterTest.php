<?php

declare(strict_types=1);

use Illuminate\Database\DatabaseManager;
use Modules\Core\Models\User;
use Modules\Search\Public\Dto\SearchFilters;
use Modules\Search\Public\Services\SearchQuery;

/**
 * CounterpartyFilterTest — Req 12 / D-04 (999.6-02).
 *
 * Proves SearchFilters/SearchQuery carry counterparty as a first-class,
 * ownership-validated filter dimension (T-999.6-04), and that the real
 * /transactions search-mode surface (TransactionsList) wires a
 * `?counterparty[]=` URL param through to it (T-999.6-05).
 */
beforeEach(function (): void {
    $this->userAId = $this->searchTestUser('cpfilter-user-a');
    $this->userBId = $this->searchTestUser('cpfilter-user-b');
});

/**
 * Inserts a counterparties row for the given user and returns its id.
 */
function cpFilterMakeCounterparty(int $userId, string $displayName): int
{
    $db = app(DatabaseManager::class)->connection();
    $suffix = bin2hex(random_bytes(4));

    return (int) $db->table('counterparties')->insertGetId([
        'user_id' => $userId,
        'type' => 'merchant',
        'slug' => 'cpfilter-'.$suffix,
        'display_name' => $displayName,
        'created_at' => now(),
        'updated_at' => now(),
    ]);
}

// (a) SearchFilters with an owned counterparty id restricts to that counterparty.
it('SearchQuery restricts results to the owned counterparty', function (): void {
    $ownedId = cpFilterMakeCounterparty($this->userAId, 'Owned Counterparty');

    $matchingTxId = $this->searchTestTransaction($this->userAId, [
        'counterparty_id' => $ownedId,
        'counterparty_name' => 'Owned Counterparty',
        'description' => 'Matches the counterparty filter',
    ]);

    // A second transaction with no counterparty_id — must not appear.
    $this->searchTestTransaction($this->userAId, [
        'counterparty_id' => null,
        'counterparty_name' => 'Unrelated Vendor',
        'description' => 'Should be excluded',
    ]);

    /** @var SearchQuery $searchQuery */
    $searchQuery = app(SearchQuery::class);
    $user = User::findOrFail($this->userAId);

    $filters = new SearchFilters(counterparties: [$ownedId]);
    $page = $searchQuery->search($user, '', $filters);

    expect($page->totalCount)->toBe(1);
    expect($page->rows[0]->id)->toBe($matchingTxId);
});

// (b) A foreign counterparty id returns an empty result, never another user's data.
it('SearchQuery returns empty result for a foreign counterparty id', function (): void {
    $foreignId = cpFilterMakeCounterparty($this->userBId, 'Foreign Counterparty');

    $this->searchTestTransaction($this->userBId, [
        'counterparty_id' => $foreignId,
        'counterparty_name' => 'Foreign Counterparty',
        'description' => 'Belongs to user B',
    ]);

    // User A has an unrelated transaction that must also not leak through.
    $this->searchTestTransaction($this->userAId, [
        'counterparty_id' => null,
        'counterparty_name' => 'User A Vendor',
        'description' => 'User A own data',
    ]);

    /** @var SearchQuery $searchQuery */
    $searchQuery = app(SearchQuery::class);
    $user = User::findOrFail($this->userAId);

    // User A queries with User B's counterparty id.
    $filters = new SearchFilters(counterparties: [$foreignId]);
    $page = $searchQuery->search($user, '', $filters);

    expect($page->totalCount)->toBe(0);
});

// (c) isActive() returns true when only counterparties is set.
it('SearchFilters isActive is true when only counterparties is set', function (): void {
    $filters = new SearchFilters(counterparties: [123]);

    expect($filters->isActive())->toBeTrue();
});

it('SearchFilters isActive is false when empty', function (): void {
    $filters = SearchFilters::empty();

    expect($filters->isActive())->toBeFalse();
});
