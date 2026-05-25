<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Modules\Core\Models\User;
use Modules\Import\Internal\Http\Livewire\RenameCounterpartyPopover;
use Modules\Ledger\Models\Category;

/*
 * RENAME-03 coverage: when the user picks an optional category hint
 * inside the rename popover, saving the popover both writes the
 * merchant_aliases row AND seeds a categorization_rules row via the
 * existing CreateCategorizationRule action. Leaving the category
 * hint blank persists only the alias.
 *
 * The seeded rule keys on `field='description'`, `match='contains'`,
 * `value=$generalized_pattern` so future imports of the same merchant
 * code auto-categorize without the user touching the rules surface.
 */

beforeEach(function (): void {
    $this->user = User::create([
        'username' => 'rename-popover-rule',
        'password' => 'opensesame',
        'period_start_day' => 1,
    ]);
    $this->actingAs($this->user);

    $this->category = Category::create([
        'user_id' => null,
        'name' => 'Fuel',
        'slug' => 'fuel-rename-popover',
        'kind' => 'expense',
        'display_order' => 200,
    ]);
});

it('seeds a categorization rule when the optional category hint is set', function (): void {
    Livewire::test(RenameCounterpartyPopover::class)
        ->dispatch('rename-counterparty:open', raw: 'BCK*SHELL X', rowIndex: 0)
        ->set('friendly', 'Shell')
        ->set('categoryHint', $this->category->id)
        ->call('save')
        ->assertDispatched('rename-counterparty:saved');

    $alias = DB::table('merchant_aliases')
        ->where('user_id', $this->user->id)
        ->where('pattern', 'BCK*SHELL X')
        ->first();
    expect($alias)->not->toBeNull();

    $rule = DB::table('categorization_rules')
        ->where('user_id', $this->user->id)
        ->where('category_id', $this->category->id)
        ->first();
    expect($rule)->not->toBeNull();
    expect(mb_strtolower((string) $rule->value))->toContain('shell');
});

it('does not seed a categorization rule when the category hint is null', function (): void {
    Livewire::test(RenameCounterpartyPopover::class)
        ->dispatch('rename-counterparty:open', raw: 'BCK*SHELL Y', rowIndex: 0)
        ->set('friendly', 'Shell')
        ->call('save')
        ->assertDispatched('rename-counterparty:saved');

    $alias = DB::table('merchant_aliases')
        ->where('user_id', $this->user->id)
        ->where('pattern', 'BCK*SHELL Y')
        ->first();
    expect($alias)->not->toBeNull();

    $ruleCount = DB::table('categorization_rules')
        ->where('user_id', $this->user->id)
        ->count();
    expect($ruleCount)->toBe(0);
});
