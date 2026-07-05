<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Container\Container;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Modules\Categorization\Internal\Http\Livewire\RuleFormModal;
use Modules\Categorization\Internal\Services\RuleEngine;
use Modules\Categorization\Internal\Services\RuleMatchInput;
use Modules\Categorization\Public\Actions\CreateCategorizationRule;
use Modules\Categorization\Public\Actions\UpdateCategorizationRule;
use Modules\Core\Models\User;
use Modules\Counterparties\Models\Counterparty;
use Modules\Ledger\Models\Category;

/*
 * 13.4-08 Task 1: RuleFormModal grown from a single-condition sentence
 * form into a multi-condition/multi-action builder per
 * 13.4-UI-SPEC.md's Component Contract. Saves through the Plan 03
 * Create/UpdateCategorizationRule actions (priority/combinator/
 * conditions[]/actions[]).
 */

beforeEach(function (): void {
    $this->user = User::create([
        'username' => 'rule-form',
        'password' => 'opensesame',
        'period_start_day' => 1,
    ]);
    $this->actingAs($this->user);

    $this->streaming = Category::create([
        'user_id' => null,
        'name' => 'Streaming',
        'slug' => 'streaming-form',
        'kind' => 'expense',
        'display_order' => 100,
    ]);

    $this->music = Category::create([
        'user_id' => null,
        'name' => 'Music',
        'slug' => 'music-form',
        'kind' => 'expense',
        'display_order' => 110,
    ]);

    $this->counterparty = Counterparty::create([
        'user_id' => $this->user->id,
        'type' => 'merchant',
        'slug' => 'rule-form-spotify',
        'display_name' => 'Spotify',
        'merchant_name' => 'SPOTIFY',
    ]);
});

function seedFormRule(User $user, int $priority, string $combinator, array $conditions, array $actions): int
{
    /** @var CreateCategorizationRule $create */
    $create = Container::getInstance()->make(CreateCategorizationRule::class);

    return ($create)($user, $priority, $combinator, true, null, $conditions, $actions);
}

it('opens in create mode when rule-form:open fires without a ruleId', function (): void {
    Livewire::test(RuleFormModal::class)
        ->call('open', ruleId: null)
        ->assertSet('editingRuleId', null)
        ->assertSet('combinator', 'all')
        ->assertCount('conditions', 1)
        ->assertCount('actions', 1)
        // Regression (UAT): open() must surface the Flux modal, not just
        // hydrate state — without this the rule-builder is unreachable from
        // the "New rule" / "Create your first rule" buttons.
        ->assertDispatched('modal-show', name: 'rule-form');
});

it('surfaces the Flux modal on open in edit mode too', function (): void {
    $ruleId = seedFormRule($this->user, 10, 'all', [
        ['field' => 'merchant', 'op' => 'contains', 'value_type' => 'string', 'value' => 'SPOTIFY'],
    ], [
        ['type' => 'category', 'payload' => ['category_id' => $this->streaming->id]],
    ]);

    Livewire::test(RuleFormModal::class)
        ->call('open', ruleId: $ruleId)
        ->assertSet('editingRuleId', $ruleId)
        ->assertDispatched('modal-show', name: 'rule-form');
});

it('defaults priority to (max existing priority) + 10 on create', function (): void {
    seedFormRule($this->user, 30, 'all', [
        ['field' => 'merchant', 'op' => 'contains', 'value_type' => 'string', 'value' => 'EXISTING'],
    ], [
        ['type' => 'category', 'payload' => ['category_id' => $this->streaming->id]],
    ]);

    Livewire::test(RuleFormModal::class)
        ->call('open', ruleId: null)
        ->assertSet('priorityInput', '40');
});

it('hydrates the nested conditions/actions arrays in edit mode', function (): void {
    $ruleId = seedFormRule($this->user, 10, 'all', [
        ['field' => 'merchant', 'op' => 'contains', 'value_type' => 'string', 'value' => 'SPOTIFY'],
        ['op' => '>', 'value_type' => 'amount', 'value' => '-5000'],
    ], [
        ['type' => 'category', 'payload' => ['category_id' => $this->streaming->id]],
        ['type' => 'counterparty', 'payload' => ['counterparty_id' => $this->counterparty->id]],
    ]);

    $component = Livewire::test(RuleFormModal::class)
        ->call('open', ruleId: $ruleId)
        ->assertSet('editingRuleId', $ruleId)
        ->assertSet('combinator', 'all')
        ->assertSet('priorityInput', '10')
        ->assertCount('conditions', 2)
        ->assertCount('actions', 2);

    expect($component->get('conditions')[0]['field'])->toBe('merchant');
    expect($component->get('conditions')[0]['value'])->toBe('SPOTIFY');
    expect($component->get('conditions')[1]['field'])->toBe('amount');
    // Stored minor units (-5000 = -50.00 EUR) round-trip to the Dutch-decimal
    // display convention (CR-01) rather than the raw minor-unit string.
    expect($component->get('conditions')[1]['value'])->toBe('-50,00');

    expect($component->get('actions')[0]['type'])->toBe('category');
    expect($component->get('actions')[0]['category_id'])->toBe($this->streaming->id);
    expect($component->get('actions')[1]['type'])->toBe('counterparty');
    expect($component->get('actions')[1]['counterparty_id'])->toBe($this->counterparty->id);
});

it('falls back to create mode when rule-form:open carries a foreign ruleId', function (): void {
    $other = User::create([
        'username' => 'foreign-rule-form',
        'password' => 'opensesame',
        'period_start_day' => 1,
    ]);
    $foreignRuleId = seedFormRule($other, 10, 'all', [
        ['field' => 'merchant', 'op' => 'contains', 'value_type' => 'string', 'value' => 'NETFLIX'],
    ], [
        ['type' => 'category', 'payload' => ['category_id' => $this->streaming->id]],
    ]);

    Livewire::test(RuleFormModal::class)
        ->call('open', ruleId: $foreignRuleId)
        ->assertSet('editingRuleId', null)
        ->assertCount('conditions', 1)
        ->assertCount('actions', 1);
});

it('saves a 2-condition/2-action rule with a priority', function (): void {
    Livewire::test(RuleFormModal::class)
        ->call('open', ruleId: null)
        ->set('priorityInput', '15')
        ->set('conditions.0.field', 'merchant')
        ->set('conditions.0.op', 'contains')
        ->set('conditions.0.value', 'SPOTIFY')
        ->call('addCondition')
        ->set('conditions.1.field', 'amount')
        ->set('conditions.1.op', 'between')
        ->set('conditions.1.value', '-50,00')
        ->set('conditions.1.value2', '-1,00')
        ->set('actions.0.type', 'category')
        ->set('actions.0.category_id', $this->streaming->id)
        ->call('addAction')
        ->set('actions.1.type', 'note')
        ->set('actions.1.note_text', 'Recurring subscription')
        ->call('save')
        ->assertDispatched('rule-form:saved')
        ->assertDispatched('modal-close');

    $ruleId = DB::table('categorization_rules')->where('user_id', $this->user->id)->value('id');
    expect($ruleId)->not->toBeNull();
    expect((int) DB::table('categorization_rules')->where('id', $ruleId)->value('priority'))->toBe(15);
    expect(DB::table('rule_conditions')->where('rule_id', $ruleId)->count())->toBe(2);
    expect(DB::table('rule_actions')->where('rule_id', $ruleId)->count())->toBe(2);

    // Human-entered Euro decimals scale into signed integer minor units
    // (CR-01): "-50,00" -> -5000, "-1,00" -> -100.
    $betweenCondition = DB::table('rule_conditions')->where('rule_id', $ruleId)->where('op', 'between')->first();
    expect($betweenCondition)->not->toBeNull();
    expect($betweenCondition->value)->toBe('-5000');
    expect($betweenCondition->value2)->toBe('-100');
});

it('scales a human-entered Dutch-decimal amount condition to minor units and matches only the correct transaction (CR-01)', function (): void {
    Livewire::test(RuleFormModal::class)
        ->call('open', ruleId: null)
        ->set('conditions.0.field', 'amount')
        ->set('conditions.0.op', 'equals')
        ->set('conditions.0.value', '12,50')
        ->set('actions.0.type', 'category')
        ->set('actions.0.category_id', $this->streaming->id)
        ->call('save')
        ->assertDispatched('rule-form:saved');

    $ruleId = DB::table('categorization_rules')->where('user_id', $this->user->id)->value('id');
    expect($ruleId)->not->toBeNull();

    $condition = DB::table('rule_conditions')->where('rule_id', $ruleId)->first();
    // "12,50" (Dutch comma-decimal Euro input) MUST scale to 1250 minor
    // units, not silently stay a non-numeric string (evaluating to 0) and
    // not truncate to 12.
    expect($condition->value)->toBe('1250');

    /** @var RuleEngine $engine */
    $engine = app(RuleEngine::class);

    $matchingInput = new RuleMatchInput(
        counterpartyName: 'Spotify',
        description: null,
        settledAmountMinor: 1250,
        postedAt: CarbonImmutable::parse('2026-01-01'),
    );
    $wrongAmountInput = new RuleMatchInput(
        counterpartyName: 'Spotify',
        description: null,
        settledAmountMinor: 1200,
        postedAt: CarbonImmutable::parse('2026-01-01'),
    );

    expect($engine->match($matchingInput, $this->user))->toHaveCount(1);
    expect($engine->match($wrongAmountInput, $this->user))->toHaveCount(0);
});

it('scales a human-entered dot-decimal amount condition to minor units and matches only the correct transaction (CR-01)', function (): void {
    Livewire::test(RuleFormModal::class)
        ->call('open', ruleId: null)
        ->set('conditions.0.field', 'amount')
        ->set('conditions.0.op', 'equals')
        ->set('conditions.0.value', '12.50')
        ->set('actions.0.type', 'category')
        ->set('actions.0.category_id', $this->streaming->id)
        ->call('save')
        ->assertDispatched('rule-form:saved');

    $ruleId = DB::table('categorization_rules')->where('user_id', $this->user->id)->value('id');
    expect($ruleId)->not->toBeNull();

    $condition = DB::table('rule_conditions')->where('rule_id', $ruleId)->first();
    // "12.50" (dot-decimal Euro input) MUST scale to 1250 minor units, not
    // truncate to 12 via a bare (int) cast.
    expect($condition->value)->toBe('1250');

    /** @var RuleEngine $engine */
    $engine = app(RuleEngine::class);

    $matchingInput = new RuleMatchInput(
        counterpartyName: 'Spotify',
        description: null,
        settledAmountMinor: 1250,
        postedAt: CarbonImmutable::parse('2026-01-01'),
    );
    $wrongAmountInput = new RuleMatchInput(
        counterpartyName: 'Spotify',
        description: null,
        settledAmountMinor: 1200,
        postedAt: CarbonImmutable::parse('2026-01-01'),
    );

    expect($engine->match($matchingInput, $this->user))->toHaveCount(1);
    expect($engine->match($wrongAmountInput, $this->user))->toHaveCount(0);
});

it('rejects saving an unparsable amount condition value instead of silently matching zero (CR-01)', function (): void {
    Livewire::test(RuleFormModal::class)
        ->call('open', ruleId: null)
        ->set('conditions.0.field', 'amount')
        ->set('conditions.0.op', 'equals')
        ->set('conditions.0.value', 'not-a-number')
        ->set('actions.0.type', 'category')
        ->set('actions.0.category_id', $this->streaming->id)
        ->call('save')
        ->assertSet('conditionErrors.0', 'Enter a valid amount for condition 1.')
        ->assertNotDispatched('rule-form:saved');

    expect(DB::table('categorization_rules')->where('user_id', $this->user->id)->exists())->toBeFalse();
});

it('the operator select options are gated by the selected field value_type', function (): void {
    expect(RuleFormModal::operatorOptionsFor('merchant'))->toHaveKeys(['contains', 'equals', 'starts_with']);
    expect(RuleFormModal::operatorOptionsFor('amount'))->toHaveKeys(['>', '<', 'between', 'equals']);
    expect(RuleFormModal::operatorOptionsFor('date'))->toHaveKeys(['before', 'after', 'between']);
});

it('rejects saving with zero conditions', function (): void {
    Livewire::test(RuleFormModal::class)
        ->call('open', ruleId: null)
        ->set('conditions', [])
        ->set('actions.0.type', 'category')
        ->set('actions.0.category_id', $this->streaming->id)
        ->call('save')
        ->assertSet('errorConditions', 'Add at least one condition.')
        ->assertNotDispatched('rule-form:saved');
});

it('rejects saving with an empty condition value', function (): void {
    Livewire::test(RuleFormModal::class)
        ->call('open', ruleId: null)
        ->set('conditions.0.field', 'merchant')
        ->set('conditions.0.value', '')
        ->set('actions.0.type', 'category')
        ->set('actions.0.category_id', $this->streaming->id)
        ->call('save')
        ->assertSet('conditionErrors.0', 'Enter a value for condition 1.')
        ->assertNotDispatched('rule-form:saved');
});

it('rejects saving a between condition missing the upper bound', function (): void {
    Livewire::test(RuleFormModal::class)
        ->call('open', ruleId: null)
        ->set('conditions.0.field', 'amount')
        ->set('conditions.0.op', 'between')
        ->set('conditions.0.value', '-5000')
        ->set('conditions.0.value2', '')
        ->set('actions.0.type', 'category')
        ->set('actions.0.category_id', $this->streaming->id)
        ->call('save')
        ->assertSet('conditionErrors.0', 'Pick a lower and upper bound for condition 1.')
        ->assertNotDispatched('rule-form:saved');
});

it('rejects saving with zero actions', function (): void {
    Livewire::test(RuleFormModal::class)
        ->call('open', ruleId: null)
        ->set('conditions.0.field', 'merchant')
        ->set('conditions.0.value', 'SPOTIFY')
        ->set('actions', [])
        ->call('save')
        ->assertSet('errorActions', 'Add at least one action.')
        ->assertNotDispatched('rule-form:saved');
});

it('rejects saving a category action with no category picked', function (): void {
    Livewire::test(RuleFormModal::class)
        ->call('open', ruleId: null)
        ->set('conditions.0.field', 'merchant')
        ->set('conditions.0.value', 'SPOTIFY')
        ->set('actions.0.type', 'category')
        ->set('actions.0.category_id', null)
        ->call('save')
        ->assertSet('actionErrors.0', 'Pick a category for this action.')
        ->assertNotDispatched('rule-form:saved');
});

it('rejects saving a counterparty action with no counterparty picked', function (): void {
    Livewire::test(RuleFormModal::class)
        ->call('open', ruleId: null)
        ->set('conditions.0.field', 'merchant')
        ->set('conditions.0.value', 'SPOTIFY')
        ->set('actions.0.type', 'counterparty')
        ->set('actions.0.counterparty_id', null)
        ->call('save')
        ->assertSet('actionErrors.0', 'Pick a counterparty to reassign to.')
        ->assertNotDispatched('rule-form:saved');
});

it('rejects saving a note action with empty text', function (): void {
    Livewire::test(RuleFormModal::class)
        ->call('open', ruleId: null)
        ->set('conditions.0.field', 'merchant')
        ->set('conditions.0.value', 'SPOTIFY')
        ->set('actions.0.type', 'note')
        ->set('actions.0.note_text', '')
        ->call('save')
        ->assertSet('actionErrors.0', 'Enter note text.')
        ->assertNotDispatched('rule-form:saved');
});

it('rejects saving a tax_tag action with no deduction category picked', function (): void {
    Livewire::test(RuleFormModal::class)
        ->call('open', ruleId: null)
        ->set('conditions.0.field', 'merchant')
        ->set('conditions.0.value', 'SPOTIFY')
        ->set('actions.0.type', 'tax_tag')
        ->set('actions.0.deduction_category_id', null)
        ->call('save')
        ->assertSet('actionErrors.0', 'Pick a deduction category for the tax tag.')
        ->assertNotDispatched('rule-form:saved');
});

it('rejects a non-numeric priority with the locked copy', function (): void {
    Livewire::test(RuleFormModal::class)
        ->call('open', ruleId: null)
        ->set('priorityInput', 'abc')
        ->set('conditions.0.field', 'merchant')
        ->set('conditions.0.value', 'SPOTIFY')
        ->set('actions.0.type', 'category')
        ->set('actions.0.category_id', $this->streaming->id)
        ->call('save')
        ->assertSet('errorPriority', 'Priority must be a whole number.')
        ->assertNotDispatched('rule-form:saved');
});

it('updates an existing rule on save in edit mode', function (): void {
    $ruleId = seedFormRule($this->user, 10, 'all', [
        ['field' => 'merchant', 'op' => 'contains', 'value_type' => 'string', 'value' => 'SPOTIFY'],
    ], [
        ['type' => 'category', 'payload' => ['category_id' => $this->streaming->id]],
    ]);

    Livewire::test(RuleFormModal::class)
        ->call('open', ruleId: $ruleId)
        ->set('actions.0.category_id', $this->music->id)
        ->call('save')
        ->assertDispatched('rule-form:saved')
        ->assertDispatched('modal-close');

    $action = DB::table('rule_actions')->where('rule_id', $ruleId)->where('type', 'category')->first();
    $payload = json_decode((string) $action->payload, true);
    expect((int) $payload['category_id'])->toBe($this->music->id);
});

it('disables (never removes) the last remaining condition/action row', function (): void {
    Livewire::test(RuleFormModal::class)
        ->call('open', ruleId: null)
        ->call('removeCondition', 0)
        ->assertCount('conditions', 1)
        ->call('removeAction', 0)
        ->assertCount('actions', 1);
});

it('addCondition/addAction grow the repeaters', function (): void {
    Livewire::test(RuleFormModal::class)
        ->call('open', ruleId: null)
        ->call('addCondition')
        ->assertCount('conditions', 2)
        ->call('addAction')
        ->assertCount('actions', 2);
});

it('save() catches NotFoundHttpException from a foreign editingRuleId and hides the modal calmly', function (): void {
    $other = User::create([
        'username' => 'tamper-foreign-rule',
        'password' => 'opensesame',
        'period_start_day' => 1,
    ]);
    $foreignRuleId = seedFormRule($other, 10, 'all', [
        ['field' => 'merchant', 'op' => 'contains', 'value_type' => 'string', 'value' => 'NETFLIX'],
    ], [
        ['type' => 'category', 'payload' => ['category_id' => $this->streaming->id]],
    ]);

    Livewire::test(RuleFormModal::class)
        ->set('editingRuleId', $foreignRuleId) // bypass open() which would have masked it
        ->set('conditions.0.field', 'merchant')
        ->set('conditions.0.value', 'NEW')
        ->set('actions.0.type', 'category')
        ->set('actions.0.category_id', $this->streaming->id)
        ->call('save')
        ->assertSet('errorGeneral', 'That rule is no longer available.')
        ->assertDispatched('modal-close');

    // The foreign rule MUST remain untouched.
    $condition = DB::table('rule_conditions')
        ->where('rule_id', $foreignRuleId)
        ->first();
    expect($condition->value)->toBe('NETFLIX');
});

it('refuses to save a category action with a foreign-user category id (cross-user authorization)', function (): void {
    $other = User::create([
        'username' => 'foreign-category-owner',
        'password' => 'opensesame',
        'period_start_day' => 1,
    ]);
    $foreignCategoryId = Category::create([
        'user_id' => $other->id,
        'name' => 'Private',
        'slug' => 'private-foreign',
        'kind' => 'expense',
        'display_order' => 999,
    ])->id;

    Livewire::test(RuleFormModal::class)
        ->call('open', ruleId: null)
        ->set('conditions.0.field', 'merchant')
        ->set('conditions.0.value', 'TARGET')
        ->set('actions.0.type', 'category')
        ->set('actions.0.category_id', $foreignCategoryId)
        ->call('save')
        ->assertSet('errorGeneral', 'Invalid rule data — pick from the dropdowns and try again.')
        ->assertNotDispatched('rule-form:saved');

    expect(
        DB::table('categorization_rules')
            ->where('user_id', $this->user->id)
            ->exists()
    )->toBeFalse();
});

it('grep guard: RuleFormModal never references ReapplyRulesJob', function (): void {
    $contents = (string) file_get_contents(base_path('Modules/Categorization/Internal/Http/Livewire/RuleFormModal.php'));

    expect($contents)->not->toContain('ReapplyRulesJob');
    expect(mb_strtolower($contents))->not->toContain('reapply');
});

it('escapes HTML payloads in rendered condition value (T-07-06)', function (): void {
    seedFormRule($this->user, 10, 'all', [
        ['field' => 'merchant', 'op' => 'contains', 'value_type' => 'string', 'value' => '<script>alert(1)</script>'],
    ], [
        ['type' => 'category', 'payload' => ['category_id' => $this->streaming->id]],
    ]);
    $ruleId = DB::table('categorization_rules')->where('user_id', $this->user->id)->value('id');

    Livewire::test(RuleFormModal::class)
        ->call('open', ruleId: $ruleId)
        ->assertDontSee('<script>alert(1)</script>', escape: false);
});
