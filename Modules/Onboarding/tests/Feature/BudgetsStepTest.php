<?php

declare(strict_types=1);

use Livewire\Livewire;
use Modules\Budgets\Models\CategoryBudget;
use Modules\Core\Models\User;
use Modules\Ledger\Models\Category;
use Modules\Ledger\Public\Services\PeriodQuery;
use Modules\Onboarding\Internal\Http\Livewire\Steps\BudgetsStep;

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

it('renders the optional budgets step with the expense categories', function (): void {
    Livewire::test(BudgetsStep::class)
        ->assertOk()
        ->assertSee("Assign this month's money", false)
        ->assertSee('Groceries');
});

it('saves entered amounts as month-1 envelope assignments and advances the wizard on continue', function (): void {
    Livewire::test(BudgetsStep::class)
        ->set("amounts.{$this->groceries->id}", '50,00')
        ->call('continue')
        ->assertDispatched('wizard.step.completed');

    $period = app(PeriodQuery::class)->current();

    $this->assertDatabaseHas('envelope_assignments', [
        'user_id' => $this->user->id,
        'category_id' => $this->groceries->id,
        'assigned_minor' => 5000,
        'period_start' => $period->start->toDateString(),
    ]);
    $this->assertDatabaseMissing('category_budgets', ['category_id' => $this->groceries->id]);
});

it('advances without saving anything on skip', function (): void {
    Livewire::test(BudgetsStep::class)
        ->set("amounts.{$this->groceries->id}", '50')
        ->call('skip')
        ->assertDispatched('wizard.step.skipped');

    $this->assertDatabaseMissing('envelope_assignments', ['category_id' => $this->groceries->id]);
});

it('advances on continue even when no amounts were entered', function (): void {
    Livewire::test(BudgetsStep::class)
        ->call('continue')
        ->assertDispatched('wizard.step.completed');

    expect(CategoryBudget::query()->count())->toBe(0);
});

it('ignores a foreign / non-budgetable category id on continue', function (): void {
    $other = User::create(['username' => 'mallory', 'password' => 'x', 'period_start_day' => 1]);
    $foreign = Category::create(['user_id' => $other->id, 'name' => 'Therapy', 'slug' => 'therapy', 'kind' => 'expense', 'display_order' => 1]);

    Livewire::test(BudgetsStep::class)
        ->set("amounts.{$foreign->id}", '50')
        ->call('continue')
        ->assertDispatched('wizard.step.completed');

    $this->assertDatabaseMissing('envelope_assignments', ['category_id' => $foreign->id]);
});
