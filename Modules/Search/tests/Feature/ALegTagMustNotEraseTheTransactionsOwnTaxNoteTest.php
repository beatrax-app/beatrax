<?php

declare(strict_types=1);

use Illuminate\Database\DatabaseManager;
use Illuminate\Support\Facades\Artisan;
use Modules\Core\Models\User;
use Modules\Search\Public\Dto\SearchFilters;
use Modules\Search\Public\Services\SearchQuery;
use Modules\Tax\Public\Actions\TagTransaction;

// A tax note lives on the whole-transaction tag; a split leg carries a tag of
// its own and the only writer of one passes no note at all. Both index writers
// read "the tags of this transaction" without saying which one they mean, and
// they disagreed about the answer: the incremental writer took the first row it
// scanned, the reindex the last one it looped over. A rebuild dropped the note
// the app had indexed and the row stopped being findable by the words on it.

function legTagSearchCount(int $userId, string $needle): int
{
    /** @var User $user */
    $user = User::query()->findOrFail($userId);

    return app(SearchQuery::class)->search($user, $needle, SearchFilters::empty())->totalCount;
}

// ->call($this, ...) at the call sites: searchTestUser/searchTestTransaction are
// protected on the module TestCase, so the fixture has to run bound to it.
$legTagFixture = function (bool $legFirst): int {
    /** @var DatabaseManager $db */
    $db = app(DatabaseManager::class);
    $connection = $db->connection();
    $suffix = bin2hex(random_bytes(4));

    $userId = $this->searchTestUser('leg-tag-'.$suffix);
    $txId = $this->searchTestTransaction($userId, [
        'counterparty_name' => 'Furniture Depot',
        'counterparty_normalized' => 'furniture depot',
    ]);

    $categoryId = (int) $connection->table('categories')->insertGetId([
        'user_id' => null,
        'name' => 'Office '.$suffix,
        'slug' => 'leg-tag-office-'.$suffix,
        'kind' => 'expense',
        'display_order' => 1,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $legId = (int) $connection->table('transaction_splits')->insertGetId([
        'user_id' => $userId,
        'transaction_id' => $txId,
        'category_id' => $categoryId,
        'settled_amount_minor' => -4990,
        'settled_currency' => 'EUR',
        'sort_order' => 0,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    // The two production writers: the tax picker tags the whole transaction
    // with a note, the split editor tags the leg with none. Either order is
    // reachable, and the tag rows land in the order they were written, so the
    // order decides which row an unordered `first()` returns.
    $tag = app(TagTransaction::class);
    if ($legFirst) {
        $tag->execute($userId, $txId, null, null, null, $legId);
    }
    $tag->execute($userId, $txId, null, 'Ergonomische bureaustoel', null);
    if (! $legFirst) {
        $tag->execute($userId, $txId, null, null, null, $legId);
    }

    return $userId;
};

it('keeps the transaction note findable whichever tag was written first', function (bool $legFirst) use ($legTagFixture): void {
    $userId = $legTagFixture->call($this, $legFirst);

    expect(legTagSearchCount($userId, 'bureaustoel'))->toBe(1);
})->with([
    'transaction tagged first' => [false],
    'leg tagged first' => [true],
]);

it('still finds it after a full reindex', function (bool $legFirst) use ($legTagFixture): void {
    $userId = $legTagFixture->call($this, $legFirst);

    Artisan::call('search:reindex', ['--force' => true]);

    expect(legTagSearchCount($userId, 'bureaustoel'))->toBe(1);
})->with([
    'transaction tagged first' => [false],
    'leg tagged first' => [true],
]);
