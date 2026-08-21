<?php

declare(strict_types=1);

use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\DatabaseManager;
use Modules\Core\Models\User;
use Modules\Search\Internal\Services\EntityNameSearch;
use Modules\Search\Public\Dto\SearchFilters;
use Modules\Search\Public\Services\SearchQuery;

// An untouched default category stores canonical English and renders its
// slug's translation, so `category:` and the palette have to match the word
// the reader is looking at — while the stored English and a rename keep
// working for anyone who types one of those instead.

function catSearchDb(): ConnectionInterface
{
    return app(DatabaseManager::class)->connection();
}

function catSearchCategory(?int $userId, string $slug, string $name, bool $nameIsDefault): int
{
    return (int) catSearchDb()->table('categories')->insertGetId([
        'user_id' => $userId,
        'name' => $name,
        'slug' => $slug,
        'kind' => 'expense',
        'name_is_default' => $nameIsDefault,
        'created_at' => now(),
        'updated_at' => now(),
    ]);
}

// Two transactions the text query matches equally, one per category, so a
// token that matches the wrong category — or none — is visible in the count
// rather than hidden behind a single row that would have come back anyway.
/**
 * @return array{User, int, int}
 */
function catSearchFixture(string $username, bool $renameFirst = false): array
{
    $userId = test()->searchTestUser($username);
    $user = User::findOrFail($userId);

    $groceries = $renameFirst
        ? catSearchCategory($userId, 'groceries', 'Huishouden', false)
        : catSearchCategory(null, 'groceries', 'Groceries', true);
    $transport = catSearchCategory(null, 'transport', 'Transport', true);

    test()->searchTestTransaction($userId, [
        'counterparty_name' => 'Albert Heijn',
        'description' => 'winkelrun een',
        'category_id' => $groceries,
    ]);
    test()->searchTestTransaction($userId, [
        'counterparty_name' => 'Nederlandse Spoorwegen',
        'description' => 'winkelrun twee',
        'category_id' => $transport,
    ]);

    return [$user, $groceries, $transport];
}

afterEach(function (): void {
    app()->setLocale('en');
});

it('narrows on a category token written in the reader s language', function (): void {
    [$user] = catSearchFixture('cat-token-nl');
    app()->setLocale('nl');

    $page = app(SearchQuery::class)->search($user, 'winkelrun category:Boodschappen', SearchFilters::empty());

    expect($page->totalCount)->toBe(1)
        ->and($page->rows[0]->counterpartyName)->toBe('Albert Heijn');
});

it('still narrows on a category token written in the stored English', function (): void {
    [$user] = catSearchFixture('cat-token-en');
    app()->setLocale('nl');

    $page = app(SearchQuery::class)->search($user, 'winkelrun category:Groceries', SearchFilters::empty());

    expect($page->totalCount)->toBe(1)
        ->and($page->rows[0]->counterpartyName)->toBe('Albert Heijn');
});

it('narrows a renamed category on the rename and a default one on its translation', function (): void {
    [$user] = catSearchFixture('cat-token-renamed', renameFirst: true);
    app()->setLocale('nl');

    $renamed = app(SearchQuery::class)->search($user, 'winkelrun category:Huishouden', SearchFilters::empty());
    $translated = app(SearchQuery::class)->search($user, 'winkelrun category:Vervoer', SearchFilters::empty());

    expect($renamed->totalCount)->toBe(1)
        ->and($renamed->rows[0]->counterpartyName)->toBe('Albert Heijn')
        ->and($translated->totalCount)->toBe(1)
        ->and($translated->rows[0]->counterpartyName)->toBe('Nederlandse Spoorwegen');
});

it('finds a default category in the palette by its translated name', function (): void {
    [$user, $groceries] = catSearchFixture('cat-palette-nl');
    app()->setLocale('nl');

    $results = app(EntityNameSearch::class)->query($user, 'Boodschappen');

    expect($results)->toHaveCount(1)
        ->and($results[0]['id'])->toBe($groceries)
        ->and($results[0]['type'])->toBe('category')
        ->and($results[0]['label'])->toBe('Boodschappen');
});

it('finds a default category in the palette by its stored English name', function (): void {
    [$user, $groceries] = catSearchFixture('cat-palette-en');
    app()->setLocale('nl');

    $results = app(EntityNameSearch::class)->query($user, 'Groceries');

    expect($results)->toHaveCount(1)
        ->and($results[0]['id'])->toBe($groceries)
        ->and($results[0]['label'])->toBe('Boodschappen');
});

// The worst of the three outcomes: a term that matched nothing used to drop
// the filter, so the reader got their WHOLE history back under a query that
// looks like it worked. Empty is honest; unfiltered is not.
it('narrows to nothing when a category token matches no category', function (): void {
    [$user] = catSearchFixture('cat-token-unresolvable');
    app()->setLocale('nl');

    $page = app(SearchQuery::class)->search($user, 'winkelrun category:Kattenvoer', SearchFilters::empty());

    expect($page->totalCount)->toBe(0);
});

it('narrows to nothing when an account token matches no account', function (): void {
    [$user] = catSearchFixture('acct-token-unresolvable');

    $page = app(SearchQuery::class)->search($user, 'winkelrun account:Spaarrekening', SearchFilters::empty());

    expect($page->totalCount)->toBe(0);
});
