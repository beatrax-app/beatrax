<?php

declare(strict_types=1);

use Illuminate\Database\DatabaseManager;
use Modules\Core\Models\User;
use Modules\Ledger\Models\Category;
use Modules\Ledger\Public\Services\CategoryAncestry;

// One walk now serves the dashboard panel and the report builder. These pin the
// three properties the two copies each got right, so a future merge into either
// caller cannot quietly drop one: the visibility predicate at every level, the
// cycle guard, and the empty-input short circuit.

beforeEach(function (): void {
    $this->seedFixtureUserAndAccount();
    $this->ancestry = $this->app->make(CategoryAncestry::class);
    $this->user = $this->fixtureUser;
});

it('loads a category and its whole parent chain in one map', function (): void {
    $root = Category::create(['user_id' => $this->user->id, 'name' => 'Home', 'slug' => 'ancestry-home', 'kind' => 'expense', 'display_order' => 1]);
    $mid = Category::create(['user_id' => $this->user->id, 'parent_id' => $root->id, 'name' => 'Utilities', 'slug' => 'ancestry-utilities', 'kind' => 'expense', 'display_order' => 2]);
    $leaf = Category::create(['user_id' => $this->user->id, 'parent_id' => $mid->id, 'name' => 'Water', 'slug' => 'ancestry-water', 'kind' => 'expense', 'display_order' => 3]);

    $byId = $this->ancestry->load([$leaf->id], $this->user->id);

    expect(array_keys($byId))->toContain($root->id, $mid->id, $leaf->id)
        ->and($this->ancestry->fullPath($leaf->id, $byId))->toBe('Home / Utilities / Water');
});

// A parent_id pointing at another tenant's row ends the breadcrumb at the
// filtered-out parent rather than printing a foreign category's name.
it('stops the chain at a parent the reader may not see', function (): void {
    $stranger = User::create(['username' => 'ancestry-stranger', 'password' => 'fixture-password-12chars', 'period_start_day' => 1]);
    $foreign = Category::create(['user_id' => $stranger->id, 'name' => 'Secret', 'slug' => 'ancestry-secret', 'kind' => 'expense', 'display_order' => 1]);
    $mine = Category::create(['user_id' => $this->user->id, 'parent_id' => $foreign->id, 'name' => 'Mine', 'slug' => 'ancestry-mine', 'kind' => 'expense', 'display_order' => 2]);

    $byId = $this->ancestry->load([$mine->id], $this->user->id);

    expect($byId)->not->toHaveKey($foreign->id)
        ->and($this->ancestry->fullPath($mine->id, $byId))->toBe('Mine');
});

it('reads a global category with no owner alongside the reader own rows', function (): void {
    $global = Category::create(['user_id' => null, 'name' => 'Groceries', 'slug' => 'ancestry-groceries', 'kind' => 'expense', 'display_order' => 1]);

    $byId = $this->ancestry->load([$global->id], $this->user->id);

    expect($this->ancestry->fullPath($global->id, $byId))->toBe('Groceries');
});

// Only the Reports copy carried this guard. It saves a connection resolve and a
// query the walk would otherwise not run anyway; folding it in is the safe half
// of the merge, so it is pinned rather than left to be re-derived.
it('asks the database nothing for an empty id list', function (): void {
    /** @var DatabaseManager $db */
    $db = $this->app->make(DatabaseManager::class);
    $db->connection()->enableQueryLog();

    expect($this->ancestry->load([], $this->user->id))->toBe([])
        ->and($db->connection()->getQueryLog())->toBe([]);

    $db->connection()->disableQueryLog();
});

// Eloquent does not enforce acyclicity, so a corrupt parent_id pair has to
// terminate rather than spin: the visited set is what stops it.
it('terminates on a parent cycle instead of walking forever', function (): void {
    $a = Category::create(['user_id' => $this->user->id, 'name' => 'A', 'slug' => 'ancestry-a', 'kind' => 'expense', 'display_order' => 1]);
    $b = Category::create(['user_id' => $this->user->id, 'parent_id' => $a->id, 'name' => 'B', 'slug' => 'ancestry-b', 'kind' => 'expense', 'display_order' => 2]);
    Category::query()->whereKey($a->id)->update(['parent_id' => $b->id]);

    $byId = $this->ancestry->load([$b->id], $this->user->id);

    expect($this->ancestry->fullPath($b->id, $byId))->toBe('A / B');
});

// The depth cap is the second half of that guard, and it was a named constant
// in one copy and a bare 16 in the other. A chain longer than the cap is
// truncated at the cap rather than rendered whole.
it('stops the breadcrumb at the depth cap on a chain longer than it', function (): void {
    $parentId = null;
    $ids = [];
    foreach (range(1, 20) as $level) {
        $row = Category::create([
            'user_id' => $this->user->id,
            'parent_id' => $parentId,
            'name' => 'L'.$level,
            'slug' => 'ancestry-l'.$level,
            'kind' => 'expense',
            'display_order' => $level,
        ]);
        $parentId = $row->id;
        $ids[] = $row->id;
    }

    $byId = $this->ancestry->load([$ids[19]], $this->user->id);
    $path = $this->ancestry->fullPath($ids[19], $byId);

    expect(substr_count($path, ' / ') + 1)->toBe(16)
        ->and($path)->toEndWith('L20')
        ->and($path)->toStartWith('L5');
});
