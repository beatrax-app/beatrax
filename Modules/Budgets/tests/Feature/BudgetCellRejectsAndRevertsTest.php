<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Modules\Budgets\Internal\Http\Livewire\BudgetsPage;
use Modules\Core\Models\User;
use Modules\Ledger\Models\Category;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    /** @var User $user */
    $user = User::query()->create([
        'username' => 'budget-revert',
        'password' => 'fixture-password',
        'period_start_day' => 1,
        'default_currency_view' => 'eur_only',
    ]);

    $this->user = $user;
    $this->categoryId = (int) Category::create([
        'user_id' => null,
        'name' => 'Car maintenance',
        'slug' => 'revert-car-'.bin2hex(random_bytes(3)),
        'kind' => 'expense',
        'display_order' => 1,
    ])->id;

    $this->actingAs($user);
});

// A negative assignment is refused — an envelope holds money, it does not owe
// it. The refusal was already correct; what the cell did afterwards was not.
it('leaves no trace of a rejected amount in the cell', function (): void {
    $component = Livewire::test(BudgetsPage::class)
        ->set('assignedInputs.'.$this->categoryId, '-50')
        ->call('setAssigned', $this->categoryId);

    // The screen showed "-50" in a cell nothing had stored, next to a total
    // and a summary card that both correctly said zero — three readings of one
    // number, two of them right.
    expect($component->get('assignedInputs')[$this->categoryId] ?? '')->not->toBe('-50');

    $component->assertDispatched('toast');

    expect(DB::table('envelope_assignments')->where('user_id', $this->user->id)->count())->toBe(0);
});

it('keeps an accepted amount in the cell', function (): void {
    $component = Livewire::test(BudgetsPage::class)
        ->set('assignedInputs.'.$this->categoryId, '50')
        ->call('setAssigned', $this->categoryId);

    expect($component->get('assignedInputs')[$this->categoryId] ?? '')->not->toBe('');
});

// The zero-recogniser used to be a hand-rolled copy of MoneyInput's parse that
// stripped a plain and a non-breaking space but not French's narrow one, so a
// French reader's cleared cell read as junk. It routes through the seam now.
it('accepts a narrow-no-break-space grouped zero as a tombstone', function (): void {
    Livewire::test(BudgetsPage::class)
        ->set('assignedInputs.'.$this->categoryId, '50')
        ->call('setAssigned', $this->categoryId);

    $component = Livewire::test(BudgetsPage::class)
        ->set('assignedInputs.'.$this->categoryId, "\u{202F}0,00")
        ->call('setAssigned', $this->categoryId);

    $component->assertNotDispatched('toast');
    expect($component->get('assignedInputs')[$this->categoryId] ?? '')->toBe('');
    expect((int) DB::table('envelope_assignments')
        ->where('user_id', $this->user->id)
        ->where('category_id', $this->categoryId)
        ->sum('assigned_minor'))->toBe(0);
});

// The other side of the same seam: a third decimal is not an amount, and it was
// only ever accepted here because is_numeric() reads "0,000" as the float zero.
it('rejects a zero written with three decimals, as the parser beside it does', function (): void {
    $component = Livewire::test(BudgetsPage::class)
        ->set('assignedInputs.'.$this->categoryId, '0,000')
        ->call('setAssigned', $this->categoryId);

    $component->assertDispatched('toast');
});
