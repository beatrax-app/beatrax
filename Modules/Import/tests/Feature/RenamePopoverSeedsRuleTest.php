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
 * merchant_aliases row AND seeds a categorization_rules row (+ its
 * rule_conditions/rule_actions children, D-01/D-02/D-03) via the
 * existing CreateCategorizationRule action. Leaving the category
 * hint blank persists only the alias.
 *
 * The seeded rule keys on a single `field='description'`,
 * `op='contains'`, `value_type='string'`, `value=$generalized_pattern`
 * condition plus a single `category` action so future imports of the
 * same merchant code auto-categorize without the user touching the
 * rules surface.
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

    $ruleId = DB::table('categorization_rules')
        ->where('user_id', $this->user->id)
        ->value('id');
    expect($ruleId)->not->toBeNull();

    $condition = DB::table('rule_conditions')->where('rule_id', $ruleId)->first();
    expect($condition)->not->toBeNull();
    expect($condition->field)->toBe('description');
    expect($condition->op)->toBe('contains');
    expect(mb_strtolower((string) $condition->value))->toContain('shell');

    $action = DB::table('rule_actions')->where('rule_id', $ruleId)->where('type', 'category')->first();
    expect($action)->not->toBeNull();
    /** @var array<string, mixed> $payload */
    $payload = json_decode((string) $action->payload, true);
    expect((int) $payload['category_id'])->toBe($this->category->id);
});

it('saves the alias and closes calmly when categoryHint is a foreign/tampered category id (WR-04)', function (): void {
    $other = User::create([
        'username' => 'rename-popover-foreign-owner',
        'password' => 'opensesame',
        'period_start_day' => 1,
    ]);
    $foreignCategory = Category::create([
        'user_id' => $other->id,
        'name' => 'Foreign Private',
        'slug' => 'foreign-private-rename-popover',
        'kind' => 'expense',
        'display_order' => 999,
    ]);

    // A stale/foreign/tampered categoryHint — CreateCategorizationRule's
    // assertCategoryVisible() throws InvalidArgumentException for it.
    // Before the fix, save() only caught ValidationException, so this
    // would surface as an uncaught 500 AFTER the alias already persisted.
    Livewire::test(RenameCounterpartyPopover::class)
        ->dispatch('rename-counterparty:open', raw: 'BCK*SHELL Z', rowIndex: 0)
        ->set('friendly', 'Shell')
        ->set('categoryHint', $foreignCategory->id)
        ->call('save')
        ->assertDispatched('rename-counterparty:saved');

    // The alias still persisted — the popover closed calmly rather than
    // leaving the user with a half-completed action.
    $alias = DB::table('merchant_aliases')
        ->where('user_id', $this->user->id)
        ->where('pattern', 'BCK*SHELL Z')
        ->first();
    expect($alias)->not->toBeNull();

    // No rule was created (the rule-authoring attempt was rejected and
    // swallowed, not silently succeeded with the wrong category).
    expect(DB::table('categorization_rules')->where('user_id', $this->user->id)->exists())->toBeFalse();
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
