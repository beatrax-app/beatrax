<?php

declare(strict_types=1);

use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\DatabaseManager;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;
use Modules\Core\Models\User;
use Modules\Search\Internal\Services\EntityNameSearch;
use Modules\Search\Public\Dto\SearchFilters;
use Modules\Search\Public\Services\SearchQuery;

// Matching a category by the name on the reader's screen moved into PHP, and
// the SQL bound moved out with it: both sites began materialising every
// category row the user can see, on every keystroke. The bound belongs in SQL,
// and the cases below fix what the two sites answer while it moves.

beforeEach(function (): void {
    /** @var DatabaseManager $manager */
    $manager = app(DatabaseManager::class);
    $this->conn = $manager->connection();
    $this->userId = $this->searchTestUser('csb-reader');
    $this->user = User::findOrFail($this->userId);

    $this->csbCategory = function (?int $userId, string $slug, string $name, bool $nameIsDefault): int {
        return (int) $this->conn->table('categories')->insertGetId([
            'user_id' => $userId,
            'name' => $name,
            'slug' => $slug,
            'kind' => 'expense',
            'name_is_default' => $nameIsDefault,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    };

    $this->csbFiller = function (int $count): void {
        $batch = [];
        for ($i = 0; $i < $count; $i++) {
            $batch[] = [
                'user_id' => $this->userId,
                'name' => 'Filler '.$i,
                'slug' => 'csb-filler-'.$i,
                'kind' => 'expense',
                'name_is_default' => false,
                'created_at' => now(),
                'updated_at' => now(),
            ];
        }
        $this->conn->table('categories')->insert($batch);
    };
});

afterEach(function (): void {
    app()->setLocale('en');
});

// Re-runs each captured statement to count the rows it actually handed back,
// which is the cost this measures — a statement count alone cannot tell a
// bounded read from a whole-table one.
/**
 * @param  callable(): void  $work
 */
$csbCategoryRowsRead = function (ConnectionInterface $conn, callable $work): int {
    /** @var list<array{sql: string, bindings: array<int, mixed>}> $seen */
    $seen = [];
    DB::listen(static function (QueryExecuted $query) use (&$seen): void {
        if (stripos($query->sql, 'categories') !== false) {
            $seen[] = ['sql' => $query->sql, 'bindings' => $query->bindings];
        }
    });

    $work();

    $rows = 0;
    foreach ($seen as $statement) {
        $rows += count($conn->select($statement['sql'], $statement['bindings']));
    }

    return $rows;
};

it('reads a bounded number of category rows for a palette query', function () use ($csbCategoryRowsRead): void {
    ($this->csbCategory)(null, 'groceries', 'Groceries', true);
    ($this->csbFiller)(600);

    $search = app(EntityNameSearch::class);
    $rowsRead = $csbCategoryRowsRead($this->conn, function () use ($search): void {
        $search->query($this->user, 'Groceries');
    });

    expect($rowsRead)->toBe(1);
});

it('reads a bounded number of category rows for a category token', function () use ($csbCategoryRowsRead): void {
    ($this->csbCategory)(null, 'groceries', 'Groceries', true);
    ($this->csbFiller)(600);

    $search = app(SearchQuery::class);
    $rowsRead = $csbCategoryRowsRead($this->conn, function () use ($search): void {
        $search->search($this->user, 'anything category:Groceries', SearchFilters::empty());
    });

    expect($rowsRead)->toBe(3);
});

it('caps the palette at three categories and takes the lowest ids', function (): void {
    $ids = [];
    for ($i = 0; $i < 5; $i++) {
        $ids[] = ($this->csbCategory)($this->userId, 'csb-capped-'.$i, 'Capped '.$i, false);
    }

    $results = app(EntityNameSearch::class)->query($this->user, 'Capped');

    expect(array_column($results, 'id'))->toBe(array_slice($ids, 0, 3));
});

it('hands the palette nothing when no category matches', function (): void {
    ($this->csbCategory)(null, 'groceries', 'Groceries', true);

    expect(app(EntityNameSearch::class)->query($this->user, 'Kattenvoer'))->toBe([]);
});

it('shows the reader their own categories and the global ones, never another readers', function (): void {
    $strangerId = $this->searchTestUser('csb-stranger');
    $global = ($this->csbCategory)(null, 'csb-shared', 'Shared thing', false);
    $own = ($this->csbCategory)($this->userId, 'csb-mine', 'Mine thing', false);
    ($this->csbCategory)($strangerId, 'csb-theirs', 'Theirs thing', false);

    $results = app(EntityNameSearch::class)->query($this->user, 'thing');

    expect(array_column($results, 'id'))->toBe([$global, $own]);
});

it('matches the palette on a substring but the token only on a prefix', function (): void {
    $id = ($this->csbCategory)($this->userId, 'csb-substring', 'Household supplies', false);

    $palette = app(EntityNameSearch::class)->query($this->user, 'supplies');
    $prefix = app(EntityNameSearch::class)->query($this->user, 'Household');

    expect(array_column($palette, 'id'))->toBe([$id])
        ->and(array_column($prefix, 'id'))->toBe([$id]);

    $this->searchTestTransaction($this->userId, [
        'counterparty_name' => 'Kruidvat',
        'description' => 'winkelrun een',
        'category_id' => $id,
    ]);

    $tokenPrefix = app(SearchQuery::class)->search($this->user, 'winkelrun category:Household', SearchFilters::empty());
    $tokenMiddle = app(SearchQuery::class)->search($this->user, 'winkelrun category:supplies', SearchFilters::empty());

    expect($tokenPrefix->totalCount)->toBe(1)
        ->and($tokenMiddle->totalCount)->toBe(0);
});

it('still matches a default row whose slug carries no translation on its stored name', function (): void {
    $id = ($this->csbCategory)(null, 'csb-untranslated-slug', 'Marmalade', true);
    app()->setLocale('nl');

    $results = app(EntityNameSearch::class)->query($this->user, 'Marmalade');

    expect(array_column($results, 'id'))->toBe([$id])
        ->and($results[0]['label'])->toBe('Marmalade');
});

it('matches a default row on the reader s language and labels it that way', function (): void {
    $id = ($this->csbCategory)(null, 'groceries', 'Groceries', true);
    app()->setLocale('nl');

    $results = app(EntityNameSearch::class)->query($this->user, 'Boodschappen');

    expect(array_column($results, 'id'))->toBe([$id])
        ->and($results[0]['label'])->toBe('Boodschappen');
});

it('never matches a renamed row on the translation its slug would have had', function (): void {
    ($this->csbCategory)($this->userId, 'groceries', 'Huishouden', false);
    app()->setLocale('nl');

    expect(app(EntityNameSearch::class)->query($this->user, 'Boodschappen'))->toBe([]);
});

it('resolves a category token to every category that matches it', function (): void {
    $one = ($this->csbCategory)($this->userId, 'csb-shop-one', 'Shopping daily', false);
    $two = ($this->csbCategory)($this->userId, 'csb-shop-two', 'Shopping weekly', false);

    foreach ([$one, $two] as $categoryId) {
        $this->searchTestTransaction($this->userId, [
            'counterparty_name' => 'Kruidvat',
            'description' => 'winkelrun een',
            'category_id' => $categoryId,
        ]);
    }

    $page = app(SearchQuery::class)->search($this->user, 'winkelrun category:Shopping', SearchFilters::empty());

    expect($page->totalCount)->toBe(2);
});
