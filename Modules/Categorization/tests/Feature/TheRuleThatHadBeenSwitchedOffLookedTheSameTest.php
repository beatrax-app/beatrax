<?php

declare(strict_types=1);

use Illuminate\Container\Container;
use Livewire\Livewire;
use Modules\Categorization\Internal\Http\Livewire\RulesPage;
use Modules\Categorization\Public\Actions\CreateCategorizationRule;
use Modules\Categorization\Public\Dto\RuleInput;
use Modules\Categorization\Public\Enums\ActionType;
use Modules\Categorization\Public\Enums\ConditionOperator;
use Modules\Categorization\Public\Enums\ConditionValueType;
use Modules\Core\Models\User;
use Modules\Core\Public\Support\Lang;
use Modules\Ledger\Models\Category;

// RuleEngine only matches active rules, and deleting a category switches every
// rule that pointed at it off — without asking, and without a word anywhere.
// The list then printed the dead rule exactly like the live ones above it, so
// the page said "Description contains X → Category Y" about a rule that had
// not run since.

beforeEach(function (): void {
    $this->user = User::create([
        'username' => 'rule-switched-off',
        'password' => 'opensesame-12chars',
        'period_start_day' => 1,
    ]);
    $this->actingAs($this->user);

    $this->category = Category::create([
        'user_id' => null,
        'name' => 'Streaming',
        'slug' => 'switched-off-streaming',
        'kind' => 'expense',
        'display_order' => 100,
    ]);
});

function switchedOffRule(User $user, bool $active, int $priority, int $categoryId): void
{
    /** @var CreateCategorizationRule $create */
    $create = Container::getInstance()->make(CreateCategorizationRule::class);

    ($create)($user, new RuleInput(
        priority: $priority,
        combinator: 'all',
        active: $active,
        notes: null,
        conditions: [['field' => 'description', 'op' => ConditionOperator::Contains->value, 'value' => 'Netflix', 'value2' => null, 'value_type' => ConditionValueType::Text->value]],
        actions: [['type' => ActionType::Category->value, 'payload' => ['category_id' => $categoryId]]],
    ));
}

it('says on the list that a switched-off rule does not run', function (): void {
    switchedOffRule($this->user, false, 10, $this->category->id);

    Livewire::test(RulesPage::class)
        ->assertSee(Lang::get('categorization::rules.inactive_badge'));
});

it('says nothing of the sort about a rule that does run', function (): void {
    switchedOffRule($this->user, true, 20, $this->category->id);

    Livewire::test(RulesPage::class)
        ->assertDontSee(Lang::get('categorization::rules.inactive_badge'));
});
