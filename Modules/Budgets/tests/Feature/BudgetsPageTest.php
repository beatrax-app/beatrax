<?php

declare(strict_types=1);

use Livewire\Livewire;
use Modules\Budgets\Internal\Http\Livewire\BudgetsPage;
use Modules\Budgets\Models\CategoryBudget;
use Modules\Core\Models\User;
use Modules\Ledger\Models\Category;

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

it('renders the budgets page', function (): void {
    Livewire::test(BudgetsPage::class)
        ->assertOk()
        ->assertSee('Budgets')
        ->assertSee('Add a budget');
});

it('sets a budget for a category', function (): void {
    Livewire::test(BudgetsPage::class)
        ->set('newCategoryId', (string) $this->groceries->id)
        ->set('newAmount', '50.00')
        ->call('setBudget')
        ->assertDispatched('toast');

    $this->assertDatabaseHas('category_budgets', [
        'user_id' => $this->user->id,
        'category_id' => $this->groceries->id,
        'budget_minor' => 5000,
        'currency' => 'EUR',
        'period_type' => 'monthly',
    ]);
});

it('rejects an invalid amount without writing a budget', function (): void {
    Livewire::test(BudgetsPage::class)
        ->set('newCategoryId', (string) $this->groceries->id)
        ->set('newAmount', 'not-a-number')
        ->call('setBudget')
        ->assertDispatched('toast');

    $this->assertDatabaseMissing('category_budgets', [
        'category_id' => $this->groceries->id,
    ]);
});

it('updates an existing budget via the inline editor', function (): void {
    CategoryBudget::create([
        'user_id' => $this->user->id,
        'category_id' => $this->groceries->id,
        'budget_minor' => 5000,
        'currency' => 'EUR',
        'period_type' => 'monthly',
    ]);

    Livewire::test(BudgetsPage::class)
        ->set("amounts.{$this->groceries->id}", '75')
        ->call('updateBudget', $this->groceries->id);

    $this->assertDatabaseHas('category_budgets', [
        'category_id' => $this->groceries->id,
        'budget_minor' => 7500,
    ]);
});

it('removes a budget', function (): void {
    CategoryBudget::create([
        'user_id' => $this->user->id,
        'category_id' => $this->groceries->id,
        'budget_minor' => 5000,
        'currency' => 'EUR',
        'period_type' => 'monthly',
    ]);

    Livewire::test(BudgetsPage::class)
        ->call('removeBudget', $this->groceries->id);

    $this->assertDatabaseMissing('category_budgets', [
        'category_id' => $this->groceries->id,
    ]);
});

it('upserts rather than duplicating when the same category is set twice', function (): void {
    Livewire::test(BudgetsPage::class)
        ->set('newCategoryId', (string) $this->groceries->id)
        ->set('newAmount', '50')
        ->call('setBudget')
        ->set('newCategoryId', (string) $this->groceries->id)
        ->set('newAmount', '60')
        ->call('setBudget');

    expect(CategoryBudget::query()->where('category_id', $this->groceries->id)->count())->toBe(1);
    $this->assertDatabaseHas('category_budgets', [
        'category_id' => $this->groceries->id,
        'budget_minor' => 6000,
    ]);
});

it('refuses to budget a category that is not the user’s own or global expense category', function (): void {
    $other = User::create(['username' => 'mallory', 'password' => 'x', 'period_start_day' => 1]);
    $foreign = Category::create(['user_id' => $other->id, 'name' => 'Therapy', 'slug' => 'therapy', 'kind' => 'expense', 'display_order' => 1]);
    $income = Category::create(['user_id' => null, 'name' => 'Salary', 'slug' => 'salary', 'kind' => 'income', 'display_order' => 2]);

    // Another user's private category id (client-supplied) is rejected.
    Livewire::test(BudgetsPage::class)
        ->set('newCategoryId', (string) $foreign->id)
        ->set('newAmount', '50')
        ->call('setBudget')
        ->assertDispatched('toast');
    $this->assertDatabaseMissing('category_budgets', ['category_id' => $foreign->id]);

    // A non-expense (income) category is rejected too — including via the
    // inline-editor path, which also takes a client-supplied id.
    Livewire::test(BudgetsPage::class)
        ->set("amounts.{$income->id}", '50')
        ->call('updateBudget', $income->id);
    $this->assertDatabaseMissing('category_budgets', ['category_id' => $income->id]);
});

it('parses the Dutch grouped amount format', function (): void {
    Livewire::test(BudgetsPage::class)
        ->set('newCategoryId', (string) $this->groceries->id)
        ->set('newAmount', '1.234,56')
        ->call('setBudget');

    $this->assertDatabaseHas('category_budgets', [
        'category_id' => $this->groceries->id,
        'budget_minor' => 123456,
    ]);
});
