<?php

declare(strict_types=1);

use Livewire\Livewire;
use Modules\Categorization\Internal\Http\Livewire\RuleFormModal;
use Modules\Categorization\Public\Enums\ConditionOperator;
use Modules\Core\Models\User;
use Modules\Core\Public\Support\Lang;

beforeEach(function (): void {
    $this->user = User::create([
        'username' => 'reshaped-repeater',
        'password' => 'opensesame',
        'period_start_day' => 1,
    ]);
    $this->actingAs($this->user);
});

it('renders a condition row that arrived without any of its keys', function (): void {
    Livewire::test(RuleFormModal::class)
        ->set('conditions', [['a' => 'b']])
        ->assertOk();
});

it('renders an action row that arrived without any of its keys', function (): void {
    Livewire::test(RuleFormModal::class)
        ->set('actions', [['a' => 'b']])
        ->assertOk();
});

it('renders a condition repeater whose rows are not arrays at all', function (): void {
    Livewire::test(RuleFormModal::class)
        ->set('conditions', ['x'])
        ->assertOk();
});

it('renders an action repeater whose rows are not arrays at all', function (): void {
    Livewire::test(RuleFormModal::class)
        ->set('actions', ['x'])
        ->assertOk();
});

it('renders an emptied repeater and refuses the save with the add-a-row message', function (): void {
    Livewire::test(RuleFormModal::class)
        ->set('conditions', [])
        ->set('actions', [])
        ->assertOk()
        ->call('save')
        ->assertOk()
        ->assertSet('errorConditions', Lang::get('categorization::rule_form.error_add_condition'))
        ->assertSet('errorActions', Lang::get('categorization::rule_form.error_add_action'))
        ->assertNotDispatched('rule-form:saved');
});

it('replaces an operator the chosen field does not offer', function (): void {
    Livewire::test(RuleFormModal::class)
        ->set('conditions', [['field' => 'amount', 'op' => ConditionOperator::Contains->value, 'value' => '1']])
        ->assertSet('conditions.0.op', ConditionOperator::GreaterThan->value);
});

it('falls back to a known field when the row names one that does not exist', function (): void {
    Livewire::test(RuleFormModal::class)
        ->set('conditions', [['field' => '../../etc/passwd', 'op' => ConditionOperator::Contains->value, 'value' => 'x']])
        ->assertSet('conditions.0.field', 'counterparty');
});

it('saves without a 500 when the action row was replaced wholesale', function (): void {
    Livewire::test(RuleFormModal::class)
        ->set('actions', [['a' => 'b']])
        ->call('save')
        ->assertOk()
        ->assertHasNoErrors();
});
