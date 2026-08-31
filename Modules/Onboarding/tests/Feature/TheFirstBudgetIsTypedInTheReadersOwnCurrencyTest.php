<?php

declare(strict_types=1);

use Livewire\Livewire;
use Modules\Core\Models\User;
use Modules\Ledger\Models\Category;
use Modules\Ledger\Public\Enums\Currency;
use Modules\Ledger\Public\Services\PeriodQuery;
use Modules\Onboarding\Internal\Http\Livewire\Steps\BudgetsStep;

beforeEach(function (): void {
    $this->user = User::create([
        'username' => 'yen-onboarding',
        'password' => 'opensesame',
        'period_start_day' => 1,
        'base_currency' => Currency::Jpy->value,
    ]);
    $this->actingAs($this->user);

    $this->groceries = Category::create([
        'user_id' => null,
        'name' => 'Groceries',
        'slug' => 'yen-onboarding-groceries',
        'kind' => 'expense',
        'display_order' => 1,
    ]);
});

it('stores a first yen budget as whole yen', function (): void {
    Livewire::test(BudgetsStep::class)
        ->set("amounts.{$this->groceries->id}", '50000')
        ->call('continue')
        ->assertDispatched('wizard.step.completed');

    $this->assertDatabaseHas('envelope_assignments', [
        'user_id' => $this->user->id,
        'category_id' => $this->groceries->id,
        'assigned_minor' => 50_000,
        'period_start' => app(PeriodQuery::class)->current()->start->toDateString(),
    ]);
});

it('marks the amount box with the currency the figure is stored in', function (): void {
    Livewire::test(BudgetsStep::class)
        ->assertSee('¥')
        ->assertDontSee('€');
});
