<?php

declare(strict_types=1);

use Illuminate\Container\Container;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Modules\Categorization\Internal\Http\Livewire\RuleFormModal;
use Modules\Categorization\Internal\Http\Livewire\RulesPage;
use Modules\Categorization\Public\Actions\CreateCategorizationRule;
use Modules\Categorization\Public\Dto\RuleConditionDto;
use Modules\Categorization\Public\Dto\RuleInput;
use Modules\Core\Models\User;
use Modules\Ledger\Models\Category;
use Modules\Ledger\Public\Enums\Currency;

beforeEach(function (): void {
    App::setLocale('en');
    $this->user = User::create([
        'username' => 'yen-rules',
        'password' => 'opensesame',
        'period_start_day' => 1,
        'base_currency' => Currency::Jpy->value,
    ]);
    $this->actingAs($this->user);

    $this->transit = Category::create([
        'user_id' => null,
        'name' => 'Transit',
        'slug' => 'yen-rules-transit',
        'kind' => 'expense',
        'display_order' => 100,
    ]);
});

it('stores a yen amount condition in whole yen', function (): void {
    Livewire::test(RuleFormModal::class)
        ->call('open', ruleId: null)
        ->set('conditions.0.field', 'amount')
        ->set('conditions.0.op', '>')
        ->set('conditions.0.value', '1250')
        ->set('actions.0.type', 'category')
        ->set('actions.0.category_id', $this->transit->id)
        ->call('save')
        ->assertDispatched('rule-form:saved');

    $ruleId = DB::table('categorization_rules')->where('user_id', $this->user->id)->value('id');

    expect(DB::table('rule_conditions')->where('rule_id', $ruleId)->value('value'))->toBe('1250');
});

it('reads a stored yen amount condition back into the form as whole yen', function (): void {
    /** @var CreateCategorizationRule $create */
    $create = Container::getInstance()->make(CreateCategorizationRule::class);
    $ruleId = ($create)($this->user, new RuleInput(
        priority: 10,
        combinator: 'all',
        active: true,
        notes: null,
        conditions: [['field' => 'merchant', 'op' => '>', 'value_type' => 'amount', 'value' => '1250', 'value2' => null]],
        actions: [['type' => 'category', 'payload' => ['category_id' => $this->transit->id]]],
    ));

    Livewire::test(RuleFormModal::class)
        ->call('open', ruleId: $ruleId)
        ->assertSet('conditions.0.value', '1,250');
});

it('prints a yen amount condition on the rules list in whole yen', function (): void {
    $fragment = RulesPage::conditionFragment(new RuleConditionDto(
        id: 1,
        field: 'merchant',
        op: '>',
        valueType: 'amount',
        value: '1250',
        value2: null,
    ));

    expect($fragment)->toContain('1,250')->not->toContain('12.50');
});
