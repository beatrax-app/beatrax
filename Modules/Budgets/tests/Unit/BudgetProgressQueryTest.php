<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Budgets\Public\Services\BudgetProgressQuery;
use Modules\Core\Models\User;
use Modules\Ledger\Models\Category;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->user = User::create([
        'username' => 'wessel',
        'password' => 'opensesame',
        'period_start_day' => 1,
    ]);
    $this->actingAs($this->user);

    $this->groceries = Category::create([
        'user_id' => null,
        'name' => 'Groceries',
        'slug' => 'groceries',
        'kind' => 'expense',
        'display_order' => 1,
    ]);
});

it('lists expense categories available to budget', function (): void {
    Category::create(['user_id' => null, 'name' => 'Salary', 'slug' => 'salary', 'kind' => 'income', 'display_order' => 9]);

    $options = app(BudgetProgressQuery::class)->expenseCategories($this->user);

    expect($options)->toHaveKey($this->groceries->id, 'Groceries');
    expect($options)->not->toContain('Salary');
});

it('refuses a category that is neither the user\'s own nor global', function (): void {
    $mallory = User::create(['username' => 'mallory-budget-vocabulary', 'password' => 'x', 'period_start_day' => 1]);
    $foreign = Category::create(['user_id' => $mallory->id, 'name' => 'Therapy', 'slug' => 'therapy', 'kind' => 'expense', 'display_order' => 2]);

    $query = app(BudgetProgressQuery::class);

    expect($query->canBudget($this->user, $this->groceries->id))->toBeTrue();
    expect($query->canBudget($this->user, $foreign->id))->toBeFalse();
});
