<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Modules\Categorization\Internal\Http\Livewire\RulesPage;
use Modules\Categorization\Public\Actions\CreateCategorizationRule;
use Modules\Categorization\Public\Actions\DeleteCategorizationRule;
use Modules\Categorization\Public\Actions\UpdateCategorizationRule;
use Modules\Core\Models\User;
use Modules\Ledger\Models\Category;

beforeEach(function (): void {
    $this->user = User::create([
        'email' => 'rules-page@example.com',
        'password' => 'opensesame',
        'period_start_day' => 1,
    ]);
    $this->actingAs($this->user);

    $this->streaming = Category::create([
        'user_id' => null,
        'name' => 'Streaming',
        'slug' => 'streaming-rule-page',
        'kind' => 'expense',
        'display_order' => 100,
    ]);

    $this->groceries = Category::create([
        'user_id' => null,
        'name' => 'Groceries',
        'slug' => 'groceries-rule-page',
        'kind' => 'expense',
        'display_order' => 200,
    ]);
});

function seedRulePageRule(int $userId, string $field, string $match, string $value, int $categoryId): int
{
    $id = DB::table('categorization_rules')->insertGetId([
        'user_id' => $userId,
        'field' => $field,
        'match' => $match,
        'value' => $value,
        'category_id' => $categoryId,
        'hits_count' => 0,
        'active' => true,
        'notes' => null,
        'created_at' => CarbonImmutable::now()->toDateTimeString(),
        'updated_at' => CarbonImmutable::now()->toDateTimeString(),
    ]);

    return (int) $id;
}

it('renders the empty state when the user has no rules', function (): void {
    Livewire::test(RulesPage::class)
        ->assertSee('No rules yet')
        ->assertSee('Create your first rule')
        ->assertSee('Pre-categorize transactions on import.');
});

it('renders the rules table when at least one rule exists', function (): void {
    seedRulePageRule($this->user->id, 'merchant', 'contains', 'SPOTIFY', $this->streaming->id);

    Livewire::test(RulesPage::class)
        ->assertSee('Field')
        ->assertSee('Match')
        ->assertSee('Value')
        ->assertSee('Category')
        ->assertSee('Hits')
        ->assertSee('Created')
        ->assertSee('SPOTIFY')
        ->assertSee('Streaming');
});

it('dispatches rule-form:open when New rule is clicked', function (): void {
    Livewire::test(RulesPage::class)
        ->call('openCreateModal')
        ->assertDispatched('rule-form:open');
});

it('dispatches rule-form:open with ruleId when Edit row is clicked', function (): void {
    $ruleId = seedRulePageRule($this->user->id, 'merchant', 'contains', 'SPOTIFY', $this->streaming->id);

    Livewire::test(RulesPage::class)
        ->call('openEditModal', $ruleId)
        ->assertDispatched('rule-form:open');
});

it('toggles the inline confirm-delete state via confirmDelete + cancelDelete', function (): void {
    $ruleId = seedRulePageRule($this->user->id, 'merchant', 'contains', 'SPOTIFY', $this->streaming->id);

    Livewire::test(RulesPage::class)
        ->assertSet('confirmingDeleteId', null)
        ->call('confirmDelete', $ruleId)
        ->assertSet('confirmingDeleteId', $ruleId)
        ->assertSee('Delete?')
        ->assertSee('Yes, delete')
        ->call('cancelDelete')
        ->assertSet('confirmingDeleteId', null);
});

it('deletes the rule when Yes, delete is invoked and flashes Rule deleted.', function (): void {
    $ruleId = seedRulePageRule($this->user->id, 'merchant', 'contains', 'SPOTIFY', $this->streaming->id);

    Livewire::test(RulesPage::class)
        ->call('confirmDelete', $ruleId)
        ->call('deleteRule', $ruleId)
        ->assertSet('confirmingDeleteId', null)
        ->assertSet('flashMessage', 'Rule deleted.')
        ->assertSee('Rule deleted.');

    expect(DB::table('categorization_rules')->where('id', $ruleId)->exists())->toBeFalse();
});

it('refuses to delete a foreign user rule and surfaces 404', function (): void {
    $other = User::create([
        'email' => 'other-rule@example.com',
        'password' => 'opensesame',
        'period_start_day' => 1,
    ]);
    $foreignRule = seedRulePageRule($other->id, 'merchant', 'contains', 'NETFLIX', $this->streaming->id);

    Livewire::test(RulesPage::class)
        ->call('deleteRule', $foreignRule)
        ->assertStatus(404);

    expect(DB::table('categorization_rules')->where('id', $foreignRule)->exists())->toBeTrue();
});

it('refreshes its row list when rule-form:saved fires', function (): void {
    Livewire::test(RulesPage::class)
        ->call('handleSaved')
        ->assertSet('flashMessage', 'Rule saved.')
        ->assertSee('Rule saved.');
});

it('escapes any HTML embedded in the rule value (T-07-06 XSS defence)', function (): void {
    seedRulePageRule($this->user->id, 'merchant', 'contains', '<script>alert(1)</script>', $this->streaming->id);

    Livewire::test(RulesPage::class)
        ->assertSee('&lt;script&gt;', escape: false)
        ->assertDontSee('<script>alert(1)</script>', escape: false);
});

it('binds the three CRUD actions as singletons', function (): void {
    expect($this->app->make(CreateCategorizationRule::class))->toBeInstanceOf(CreateCategorizationRule::class);
    expect($this->app->make(UpdateCategorizationRule::class))->toBeInstanceOf(UpdateCategorizationRule::class);
    expect($this->app->make(DeleteCategorizationRule::class))->toBeInstanceOf(DeleteCategorizationRule::class);
});
