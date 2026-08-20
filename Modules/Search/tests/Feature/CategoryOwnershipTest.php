<?php

declare(strict_types=1);

use Illuminate\Database\DatabaseManager;
use Modules\Core\Models\User;
use Modules\Search\Public\Dto\SearchFilters;
use Modules\Search\Public\Services\SearchQuery;

beforeEach(function (): void {
    $this->userAId = $this->searchTestUser('catown-user-a');
    $this->userBId = $this->searchTestUser('catown-user-b');
});

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

it('SearchQuery returns empty result for a foreign category id', function (): void {
    $foreignId = catOwnMakeCategory($this->userBId, 'Foreign Category');

    $this->searchTestTransaction($this->userBId, [
        'category_id' => $foreignId,
        'counterparty_name' => 'Foreign Category Vendor',
        'description' => 'Belongs to user B',
    ]);

    $this->searchTestTransaction($this->userAId, [
        'category_id' => null,
        'counterparty_name' => 'User A Vendor',
        'description' => 'User A own data',
    ]);

    /** @var SearchQuery $searchQuery */
    $searchQuery = app(SearchQuery::class);
    $user = User::findOrFail($this->userAId);

    $filters = new SearchFilters(categories: [$foreignId]);
    $page = $searchQuery->search($user, '', $filters);

    expect($page->totalCount)->toBe(0);
});

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

// Ownership validation must not reject the seeded global categories, which
// every user is allowed to filter on.
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
