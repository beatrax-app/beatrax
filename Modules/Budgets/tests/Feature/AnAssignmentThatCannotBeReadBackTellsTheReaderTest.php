<?php

declare(strict_types=1);

use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Modules\Budgets\Internal\Http\Livewire\BudgetsPage;
use Modules\Core\Models\User;
use Modules\Ledger\Models\Category;
use Modules\Ledger\Models\Currency;

// The grid keeps the reader's own figure on a refusal, so a write that cannot
// name the row it just made answers in the same place every other refusal does
// rather than throwing raw SQL at the page.

uses(RefreshDatabase::class);

beforeEach(function (): void {
    Currency::query()->updateOrInsert(['code' => 'EUR'], ['name' => 'Euro', 'minor_unit' => 2]);

    $this->user = User::query()->create([
        'username' => 'unreadable-assignment',
        'password' => 'fixture-password-12chars',
        'period_start_day' => 1,
        'default_currency_view' => 'eur_only',
        'base_currency' => 'EUR',
    ]);

    $this->categoryId = (int) Category::create([
        'user_id' => null,
        'name' => 'Car maintenance',
        'slug' => 'unreadable-car-'.bin2hex(random_bytes(3)),
        'kind' => 'expense',
        'display_order' => 1,
    ])->id;

    $this->actingAs($this->user);

    // The row is gone by the time the write reads its id back, which is the one
    // outcome the read-back sites disagreed about.
    DB::listen(static function (QueryExecuted $query): void {
        if (str_starts_with(ltrim($query->sql), 'insert into "envelope_assignments"')) {
            DB::table('envelope_assignments')->delete();
        }
    });
});

it('tells the reader the budget was not saved instead of throwing at them', function (): void {
    Livewire::test(BudgetsPage::class)
        ->set('assignedInputs.'.$this->categoryId, '50')
        ->call('setAssigned', $this->categoryId)
        ->assertDispatched('toast');

    expect(DB::table('envelope_assignments')->where('user_id', $this->user->id)->count())->toBe(0);
});
