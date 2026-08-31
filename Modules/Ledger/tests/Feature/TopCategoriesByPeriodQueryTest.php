<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Database\DatabaseManager;
use Modules\Ledger\Models\Account;
use Modules\Ledger\Models\Category;
use Modules\Ledger\Public\Services\PeriodQuery;
use Modules\Ledger\Public\Services\TopCategoriesByPeriodQuery;

beforeEach(function (): void {
    $this->seedFixtureUserAndAccount();
    $this->actingAs($this->fixtureUser);
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-05-15 12:00:00'));

    $this->query = $this->app->make(TopCategoriesByPeriodQuery::class);
    $this->periods = $this->app->make(PeriodQuery::class);
    $this->user = $this->fixtureUser;
    /** @var Account $account */
    $account = Account::query()->where('iban', 'NL57ASNB0123456789')->firstOrFail();
    $this->account = $account;
    $this->run = $this->makeImportRun($this->user);
});

afterEach(function (): void {
    CarbonImmutable::setTestNow();
});

it('returns an empty list when the user has no categorised spend', function (): void {
    $period = $this->periods->current();

    expect($this->query->for($this->user, $period)->rows)->toBe([]);
});

it('ranks categories by spend descending, unsplit transactions unchanged', function (): void {
    /** @var Category $groceries */
    $groceries = Category::create(['user_id' => $this->user->id, 'name' => 'Groceries', 'slug' => 'tcbp-groceries', 'kind' => 'expense', 'display_order' => 1]);
    /** @var Category $transport */
    $transport = Category::create(['user_id' => $this->user->id, 'name' => 'Transport', 'slug' => 'tcbp-transport', 'kind' => 'expense', 'display_order' => 2]);

    $this->makeTransaction($this->user, $this->account, $this->run, [
        'amount_minor' => -5000,
        'posted_at' => '2026-05-05',
        'booked_at' => '2026-05-05 12:00:00',
        'category_id' => $groceries->id,
    ]);
    $this->makeTransaction($this->user, $this->account, $this->run, [
        'amount_minor' => -1000,
        'posted_at' => '2026-05-06',
        'booked_at' => '2026-05-06 12:00:00',
        'category_id' => $transport->id,
    ]);

    $period = $this->periods->current();
    $rows = $this->query->for($this->user, $period)->rows;

    expect($rows)->toHaveCount(2);
    expect($rows[0]->name)->toBe('Groceries');
    expect($rows[0]->spend->toMinor())->toBe(5000);
    expect($rows[1]->name)->toBe('Transport');
    expect($rows[1]->spend->toMinor())->toBe(1000);
});

it('counts a split transaction\'s legs individually, never the parent', function (): void {
    /** @var DatabaseManager $db */
    $db = $this->app->make(DatabaseManager::class);

    /** @var Category $groceries */
    $groceries = Category::create(['user_id' => $this->user->id, 'name' => 'Groceries', 'slug' => 'tcbp-split-groceries', 'kind' => 'expense', 'display_order' => 1]);
    /** @var Category $household */
    $household = Category::create(['user_id' => $this->user->id, 'name' => 'Household', 'slug' => 'tcbp-split-household', 'kind' => 'expense', 'display_order' => 2]);

    // The parent keeps a vestigial category_id that must not add to its legs.
    $tx = $this->makeTransaction($this->user, $this->account, $this->run, [
        'amount_minor' => -8000,
        'posted_at' => '2026-05-05',
        'booked_at' => '2026-05-05 12:00:00',
        'category_id' => $groceries->id,
    ]);
    $db->connection()->table('transaction_splits')->insert([
        ['user_id' => $this->user->id, 'transaction_id' => $tx->id, 'category_id' => $groceries->id, 'settled_amount_minor' => -6000, 'settled_currency' => 'EUR', 'note' => null, 'sort_order' => 0, 'created_at' => '2026-05-05 12:00:00', 'updated_at' => '2026-05-05 12:00:00'],
        ['user_id' => $this->user->id, 'transaction_id' => $tx->id, 'category_id' => $household->id, 'settled_amount_minor' => -2000, 'settled_currency' => 'EUR', 'note' => null, 'sort_order' => 1, 'created_at' => '2026-05-05 12:00:00', 'updated_at' => '2026-05-05 12:00:00'],
    ]);

    $period = $this->periods->current();
    $rows = $this->query->for($this->user, $period)->rows;

    $byName = [];
    foreach ($rows as $row) {
        $byName[$row->name] = $row;
    }

    expect($byName['Groceries']->spend->toMinor())->toBe(6000);
    expect($byName['Household']->spend->toMinor())->toBe(2000);
    expect($byName['Groceries']->spend->toMinor() + $byName['Household']->spend->toMinor())->toBe(8000); // not 16000

    $total = array_sum(array_map(fn ($row) => $row->percentageOfTotal, $rows));
    expect(abs($total - 1.0))->toBeLessThan(0.0001);
});

it('leaves a category out of the ranking once its refunds outrun its spending, and names what came back', function (): void {
    /** @var Category $groceries */
    $groceries = Category::create(['user_id' => $this->user->id, 'name' => 'Groceries', 'slug' => 'tcbp-refund-groceries', 'kind' => 'expense', 'display_order' => 1]);
    /** @var Category $electronics */
    $electronics = Category::create(['user_id' => $this->user->id, 'name' => 'Electronics', 'slug' => 'tcbp-refund-electronics', 'kind' => 'expense', 'display_order' => 2]);

    $this->makeTransaction($this->user, $this->account, $this->run, [
        'amount_minor' => -8000, 'posted_at' => '2026-05-03', 'booked_at' => '2026-05-03 12:00:00', 'category_id' => $groceries->id,
    ]);
    $this->makeTransaction($this->user, $this->account, $this->run, [
        'amount_minor' => -5000, 'posted_at' => '2026-05-04', 'booked_at' => '2026-05-04 12:00:00', 'category_id' => $electronics->id,
    ]);
    $this->makeTransaction($this->user, $this->account, $this->run, [
        'amount_minor' => 40000, 'type' => 'refund', 'posted_at' => '2026-05-05', 'booked_at' => '2026-05-05 12:00:00', 'category_id' => $electronics->id,
    ]);

    $top = $this->query->for($this->user, $this->periods->current());

    expect($top->rows)->toHaveCount(1);
    expect($top->rows[0]->name)->toBe('Groceries');
    expect($top->rows[0]->spend->toMinor())->toBe(8000);
    expect($top->rows[0]->percentageOfTotal)->toBe(1.0);
    expect($top->refunded->toMinor())->toBe(35000);
    expect($top->refundedCategoryCount)->toBe(1);
    expect($top->isEmpty())->toBeFalse();
});

it('keeps the share under one when a refund shrinks the total it is cut from', function (): void {
    /** @var Category $groceries */
    $groceries = Category::create(['user_id' => $this->user->id, 'name' => 'Groceries', 'slug' => 'tcbp-share-groceries', 'kind' => 'expense', 'display_order' => 1]);
    /** @var Category $electronics */
    $electronics = Category::create(['user_id' => $this->user->id, 'name' => 'Electronics', 'slug' => 'tcbp-share-electronics', 'kind' => 'expense', 'display_order' => 2]);

    $this->makeTransaction($this->user, $this->account, $this->run, [
        'amount_minor' => -8000, 'posted_at' => '2026-05-03', 'booked_at' => '2026-05-03 12:00:00', 'category_id' => $groceries->id,
    ]);
    $this->makeTransaction($this->user, $this->account, $this->run, [
        'amount_minor' => -5000, 'posted_at' => '2026-05-04', 'booked_at' => '2026-05-04 12:00:00', 'category_id' => $electronics->id,
    ]);
    $this->makeTransaction($this->user, $this->account, $this->run, [
        'amount_minor' => 12500, 'type' => 'refund', 'posted_at' => '2026-05-05', 'booked_at' => '2026-05-05 12:00:00', 'category_id' => $electronics->id,
    ]);

    $top = $this->query->for($this->user, $this->periods->current());

    expect($top->rows)->toHaveCount(1);
    expect($top->rows[0]->percentageOfTotal)->toBe(1.0);
    expect($top->rows[0]->barWidth())->toBe(100);
    expect($top->refunded->toMinor())->toBe(7500);
});

it('answers an empty ranking with nothing returned when the period is genuinely empty', function (): void {
    $top = $this->query->for($this->user, $this->periods->current());

    expect($top->isEmpty())->toBeTrue();
    expect($top->hasRefundedCategories())->toBeFalse();
    expect($top->refunded->toMinor())->toBe(0);
});
