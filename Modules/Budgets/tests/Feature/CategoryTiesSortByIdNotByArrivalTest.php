<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Modules\Budgets\Public\Services\BudgetProgressQuery;
use Modules\Core\Models\User;
use Modules\Ledger\Models\Category;

uses(RefreshDatabase::class);

// Two categories can share a display name — two slugs whose translation
// collides, or a user category named like a default one. The comparator
// returns 0 for that pair, and forCurrentPeriod is driven by a JOIN with no
// ORDER BY, so before the id tiebreak the surviving order was whatever the
// query plan happened to emit: budget insertion order today, something else
// after an ANALYZE or a new index.

it('orders two equally-named categories by id, not by the order their budgets were written', function (): void {
    /** @var User $user */
    $user = User::query()->create([
        'username' => 'budget-tie-order',
        'password' => 'fixture-password-12chars',
        'period_start_day' => 1,
        'default_currency_view' => 'eur_only',
    ]);

    $first = (int) Category::create([
        'user_id' => null,
        'name' => 'Subscriptions',
        'slug' => 'tie-order-a-'.bin2hex(random_bytes(3)),
        'kind' => 'expense',
        'display_order' => 1,
    ])->id;

    $second = (int) Category::create([
        'user_id' => null,
        'name' => 'Subscriptions',
        'slug' => 'tie-order-b-'.bin2hex(random_bytes(3)),
        'kind' => 'expense',
        'display_order' => 2,
    ])->id;

    $this->actingAs($user);

    expect($second)->toBeGreaterThan($first);

    // Written highest id first, so the join's natural row order is the
    // reverse of the id order the reader should get.
    foreach ([$second, $first] as $categoryId) {
        DB::table('category_budgets')->insert([
            'user_id' => $user->id,
            'category_id' => $categoryId,
            'budget_minor' => 1000,
            'currency' => 'EUR',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    // Without the tiebreak this passes anyway, for a reason that is an
    // accident: SQLite drives the join through category_budgets_user_category_uniq,
    // whose (user_id, category_id) order happens to be the order the reader
    // should get. Drop that index and the same query emits budget-insertion
    // order — the plan-dependence the tiebreak exists to remove. Any new index,
    // an ANALYZE, or another driver reshuffles it the same way.
    DB::statement('DROP INDEX category_budgets_user_category_uniq');

    /** @var BudgetProgressQuery $query */
    $query = app(BudgetProgressQuery::class);

    $ids = array_map(
        static fn (object $row): int => $row->categoryId,
        array_values(array_filter(
            $query->forCurrentPeriod($user),
            static fn (object $row): bool => $row->name === 'Subscriptions',
        )),
    );

    expect($ids)->toBe([$first, $second]);
});
