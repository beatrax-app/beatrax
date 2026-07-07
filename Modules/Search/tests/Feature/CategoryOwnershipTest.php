<?php

declare(strict_types=1);

use Illuminate\Database\DatabaseManager;
use Modules\Core\Models\User;
use Modules\Search\Public\Dto\SearchFilters;
use Modules\Search\Public\Services\SearchQuery;

/**
 * CategoryOwnershipTest — WR-04 (999.6-persistence review).
 *
 * Proves `SearchQuery::applyFilters()` validates `categories` filter ids
 * against ownership before applying `whereIn`, mirroring the account and
 * counterparty blocks (T-08-06 / T-999.6-04): a foreign (non-owned,
 * non-global) category id must yield the caller's own empty result set,
 * never another user's rows. Categories may also be global (seeded,
 * null-user) rows — those must remain usable as a filter for every user
 * (see `it_filters_by_category` in SearchQueryTest.php, which relies on
 * exactly this behavior and must not regress).
 */
beforeEach(function (): void {
    $this->userAId = $this->searchTestUser('catown-user-a');
    $this->userBId = $this->searchTestUser('catown-user-b');
});

/**
 * Inserts a categories row (optionally user-owned) and returns its id.
 */
function catOwnMakeCategory(?int $userId, string $name): int
{
    $db = app(DatabaseManager::class)->connection();
    $suffix = bin2hex(random_bytes(4));

    return (int) $db->table('categories')->insertGetId([
        'user_id' => $userId,
        'name' => $name,
        'slug' => 'catown-'.$suffix,
        'kind' => 'expense',
        'created_at' => now(),
        'updated_at' => now(),
    ]);
}

// (a) A foreign (another user's) category id returns an empty result, never that user's data.
it('SearchQuery returns empty result for a foreign category id', function (): void {
    $foreignId = catOwnMakeCategory($this->userBId, 'Foreign Category');

    $this->searchTestTransaction($this->userBId, [
        'category_id' => $foreignId,
        'counterparty_name' => 'Foreign Category Vendor',
        'description' => 'Belongs to user B',
    ]);

    // User A has an unrelated transaction that must also not leak through.
    $this->searchTestTransaction($this->userAId, [
        'category_id' => null,
        'counterparty_name' => 'User A Vendor',
        'description' => 'User A own data',
    ]);

    /** @var SearchQuery $searchQuery */
    $searchQuery = app(SearchQuery::class);
    $user = User::findOrFail($this->userAId);

    // User A queries with User B's category id.
    $filters = new SearchFilters(categories: [$foreignId]);
    $page = $searchQuery->search($user, '', $filters);

    expect($page->totalCount)->toBe(0);
});

// (b) A category the user owns still restricts results correctly.
it('SearchQuery restricts results to the owned category', function (): void {
    $ownedId = catOwnMakeCategory($this->userAId, 'Owned Category');

    $matchingTxId = $this->searchTestTransaction($this->userAId, [
        'category_id' => $ownedId,
        'counterparty_name' => 'Owned Category Vendor',
        'description' => 'Matches the category filter',
    ]);

    $this->searchTestTransaction($this->userAId, [
        'category_id' => null,
        'counterparty_name' => 'Unrelated Vendor',
        'description' => 'Should be excluded',
    ]);

    /** @var SearchQuery $searchQuery */
    $searchQuery = app(SearchQuery::class);
    $user = User::findOrFail($this->userAId);

    $filters = new SearchFilters(categories: [$ownedId]);
    $page = $searchQuery->search($user, '', $filters);

    expect($page->totalCount)->toBe(1);
    expect($page->rows[0]->id)->toBe($matchingTxId);
});

// (c) A global (null-user) category still works as a filter for any user —
// must not regress SearchQueryTest's `it_filters_by_category`.
it('SearchQuery still restricts results to a global (null-user) category', function (): void {
    $globalId = catOwnMakeCategory(null, 'Global Category');

    $matchingTxId = $this->searchTestTransaction($this->userAId, [
        'category_id' => $globalId,
        'counterparty_name' => 'Global Category Vendor',
        'description' => 'Matches the global category filter',
    ]);

    $this->searchTestTransaction($this->userAId, [
        'category_id' => null,
        'counterparty_name' => 'Unrelated Vendor',
        'description' => 'Should be excluded',
    ]);

    /** @var SearchQuery $searchQuery */
    $searchQuery = app(SearchQuery::class);
    $user = User::findOrFail($this->userAId);

    $filters = new SearchFilters(categories: [$globalId]);
    $page = $searchQuery->search($user, '', $filters);

    expect($page->totalCount)->toBe(1);
    expect($page->rows[0]->id)->toBe($matchingTxId);
});
