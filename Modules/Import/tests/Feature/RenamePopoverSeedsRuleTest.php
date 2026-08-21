<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Modules\Core\Models\User;
use Modules\Import\Internal\Http\Livewire\RenameCounterpartyPopover;
use Modules\Ledger\Models\Category;

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

    // assertCategoryVisible() throws InvalidArgumentException here. save() used
    // to catch only ValidationException, so a foreign id surfaced as a 500
    // AFTER the alias had already persisted.
    Livewire::test(RenameCounterpartyPopover::class)
        ->dispatch('rename-counterparty:open', raw: 'BCK*SHELL Z', rowIndex: 0)
        ->set('friendly', 'Shell')
        ->set('categoryHint', $foreignCategory->id)
        ->call('save')
        ->assertDispatched('rename-counterparty:saved');

    $alias = DB::table('merchant_aliases')
        ->where('user_id', $this->user->id)
        ->where('pattern', 'BCK*SHELL Z')
        ->first();
    expect($alias)->not->toBeNull();

    // Rejected and swallowed, not quietly written against the wrong category.
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
