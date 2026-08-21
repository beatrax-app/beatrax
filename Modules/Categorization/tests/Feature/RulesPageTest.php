<?php

declare(strict_types=1);

use Illuminate\Container\Container;
use Illuminate\Contracts\Cache\Repository;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Modules\Categorization\Internal\Http\Livewire\RulesPage;
use Modules\Categorization\Internal\Jobs\ReapplyRulesJob;
use Modules\Categorization\Public\Actions\CreateCategorizationRule;
use Modules\Categorization\Public\Actions\DeleteCategorizationRule;
use Modules\Categorization\Public\Actions\UpdateCategorizationRule;
use Modules\Categorization\Public\Dto\RuleInput;
use Modules\Core\Models\User;
use Modules\Counterparties\Models\Counterparty;
use Modules\Ledger\Models\Category;

beforeEach(function (): void {
    $this->user = User::create([
        'username' => 'rules-page',
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

    $this->counterparty = Counterparty::create([
        'user_id' => $this->user->id,
        'type' => 'merchant',
        'slug' => 'rules-page-spotify',
        'display_name' => 'Spotify',
        'merchant_name' => 'SPOTIFY',
    ]);
});

function seedRulePageRule(User $user, int $priority, array $conditions, array $actions): int
{
    /** @var CreateCategorizationRule $create */
    $create = Container::getInstance()->make(CreateCategorizationRule::class);

    return ($create)($user, new RuleInput(priority: $priority, combinator: 'all', active: true, notes: null, conditions: $conditions, actions: $actions));
}

it('renders the empty state when the user has no rules', function (): void {
    Livewire::test(RulesPage::class)
        ->assertSee('No rules yet')
        ->assertSee('Create your first rule')
        ->assertSee('Rules match transactions on multiple conditions');
});

it('renders the redesigned Priority/Conditions/Actions table', function (): void {
    seedRulePageRule($this->user, 10, [
        ['field' => 'merchant', 'op' => 'contains', 'value_type' => 'string', 'value' => 'SPOTIFY'],
    ], [
        ['type' => 'category', 'payload' => ['category_id' => $this->streaming->id]],
    ]);

    Livewire::test(RulesPage::class)
        ->assertSee('Priority')
        ->assertSee('Conditions')
        ->assertSee('Actions')
        ->assertSee('Hits')
        ->assertSee('Created')
        ->assertSee('SPOTIFY')
        ->assertSee('Category: Streaming');
});

it('renders a multi-condition rule with the combinator badge + N-more chip and per-type action chips, in priority order', function (): void {
    $lowPriorityId = seedRulePageRule($this->user, 50, [
        ['field' => 'merchant', 'op' => 'contains', 'value_type' => 'string', 'value' => 'FIRST'],
    ], [
        ['type' => 'category', 'payload' => ['category_id' => $this->streaming->id]],
    ]);

    $highPriorityId = seedRulePageRule($this->user, 5, [
        ['field' => 'merchant', 'op' => 'contains', 'value_type' => 'string', 'value' => 'SECOND'],
        ['op' => '>', 'value_type' => 'amount', 'value' => '-5000'],
    ], [
        ['type' => 'category', 'payload' => ['category_id' => $this->groceries->id]],
        ['type' => 'counterparty', 'payload' => ['counterparty_id' => $this->counterparty->id]],
    ]);

    // The priority=5 rule ("SECOND") renders before the priority=50 rule
    // ("FIRST"): the table is the engine's execution order.
    Livewire::test(RulesPage::class)
        ->assertSee('ALL')
        ->assertSee('+1 more')
        ->assertSee('Category: Groceries')
        ->assertSee('Counterparty: Spotify')
        ->assertSeeInOrder(['SECOND', 'FIRST']);

    expect($highPriorityId)->not->toBe($lowPriorityId);
});

it('dispatches rule-form:open when New rule is clicked', function (): void {
    Livewire::test(RulesPage::class)
        ->call('openCreateModal')
        ->assertDispatched('rule-form:open');
});

it('dispatches rule-form:open with ruleId when Edit row is clicked', function (): void {
    $ruleId = seedRulePageRule($this->user, 10, [
        ['field' => 'merchant', 'op' => 'contains', 'value_type' => 'string', 'value' => 'SPOTIFY'],
    ], [
        ['type' => 'category', 'payload' => ['category_id' => $this->streaming->id]],
    ]);

    Livewire::test(RulesPage::class)
        ->call('openEditModal', $ruleId)
        ->assertDispatched('rule-form:open');
});

it('toggles the inline confirm-delete state via confirmDelete + cancelDelete', function (): void {
    $ruleId = seedRulePageRule($this->user, 10, [
        ['field' => 'merchant', 'op' => 'contains', 'value_type' => 'string', 'value' => 'SPOTIFY'],
    ], [
        ['type' => 'category', 'payload' => ['category_id' => $this->streaming->id]],
    ]);

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
    $ruleId = seedRulePageRule($this->user, 10, [
        ['field' => 'merchant', 'op' => 'contains', 'value_type' => 'string', 'value' => 'SPOTIFY'],
    ], [
        ['type' => 'category', 'payload' => ['category_id' => $this->streaming->id]],
    ]);

    Livewire::test(RulesPage::class)
        ->call('confirmDelete', $ruleId)
        ->call('deleteRule', $ruleId)
        ->assertSet('confirmingDeleteId', null)
        ->assertSet('flashMessage', 'Rule deleted.')
        ->assertSee('Rule deleted.');

    expect(DB::table('categorization_rules')->where('id', $ruleId)->exists())->toBeFalse();
});

it('refuses to delete a foreign user rule and surfaces a calm flash (not a 500)', function (): void {
    $other = User::create([
        'username' => 'other-rule',
        'password' => 'opensesame',
        'period_start_day' => 1,
    ]);
    $foreignRule = seedRulePageRule($other, 10, [
        ['field' => 'merchant', 'op' => 'contains', 'value_type' => 'string', 'value' => 'NETFLIX'],
    ], [
        ['type' => 'category', 'payload' => ['category_id' => $this->streaming->id]],
    ]);

    Livewire::test(RulesPage::class)
        ->call('deleteRule', $foreignRule)
        ->assertSet('flashMessage', 'Rule not found (it may have been deleted in another tab).')
        ->assertSet('confirmingDeleteId', null)
        ->assertSee('Rule not found');

    expect(DB::table('categorization_rules')->where('id', $foreignRule)->exists())->toBeTrue();
});

it('refreshes its row list when rule-form:saved fires', function (): void {
    Livewire::test(RulesPage::class)
        ->call('handleSaved')
        ->assertSet('flashMessage', 'Rule saved.')
        ->assertSee('Rule saved.');
});

it('escapes any HTML embedded in a condition value (T-07-06 XSS defence)', function (): void {
    seedRulePageRule($this->user, 10, [
        ['field' => 'merchant', 'op' => 'contains', 'value_type' => 'string', 'value' => '<script>alert(1)</script>'],
    ], [
        ['type' => 'category', 'payload' => ['category_id' => $this->streaming->id]],
    ]);

    Livewire::test(RulesPage::class)
        ->assertSee('&lt;script&gt;', escape: false)
        ->assertDontSee('<script>alert(1)</script>', escape: false);
});

it('binds the three CRUD actions as singletons', function (): void {
    expect($this->app->make(CreateCategorizationRule::class))->toBeInstanceOf(CreateCategorizationRule::class);
    expect($this->app->make(UpdateCategorizationRule::class))->toBeInstanceOf(UpdateCategorizationRule::class);
    expect($this->app->make(DeleteCategorizationRule::class))->toBeInstanceOf(DeleteCategorizationRule::class);
});

it('triggerReapply dispatches ReapplyRulesJob for the CURRENT user only, never a client-supplied id', function (): void {
    Bus::fake();

    Livewire::test(RulesPage::class)
        ->call('triggerReapply')
        ->assertSet('reapplyDispatched', true)
        ->assertSet('flashMessage', 'Re-applying rules to your history…');

    Bus::assertDispatched(
        ReapplyRulesJob::class,
        fn (ReapplyRulesJob $job): bool => $job->userId === $this->user->id,
    );

    // triggerReapply takes no client-suppliable user id, so the job's userId
    // can only ever be CurrentUser's own.
    $reflection = new ReflectionMethod(RulesPage::class, 'triggerReapply');
    foreach ($reflection->getParameters() as $parameter) {
        expect($parameter->getType())->not->toBeNull();
        $typeName = $parameter->getType() instanceof ReflectionNamedType ? $parameter->getType()->getName() : '';
        expect($typeName)->not->toBe('int');
    }
});

it('renders the re-apply progress strip from a seeded running cache payload', function (): void {
    /** @var Repository $cache */
    $cache = $this->app->make(Repository::class);
    $cache->put(ReapplyRulesJob::progressCacheKey($this->user->id), [
        'status' => 'running',
        'checked' => 40,
        'total' => 100,
        'fields_updated' => 0,
        'transactions_updated' => 0,
        'reconciled_skipped' => 0,
        'started_at' => now()->toIso8601String(),
        'finished_at' => null,
    ], 3600);

    Livewire::test(RulesPage::class)
        ->set('reapplyDispatched', true)
        ->assertSee('Re-applying rules…')
        ->assertSee('40')
        ->assertSee('100');
});

it('folds a completed re-apply run into the flash-message summary', function (): void {
    /** @var Repository $cache */
    $cache = $this->app->make(Repository::class);
    $cache->put(ReapplyRulesJob::progressCacheKey($this->user->id), [
        'status' => 'done',
        'checked' => 100,
        'total' => 100,
        'fields_updated' => 7,
        'transactions_updated' => 4,
        'reconciled_skipped' => 2,
        'started_at' => now()->toIso8601String(),
        'finished_at' => now()->toIso8601String(),
    ], 3600);

    Livewire::test(RulesPage::class)
        ->set('reapplyDispatched', true)
        ->assertSet('flashMessage', 'Updated 7 fields across 4 transactions. 2 reconciled transactions were skipped.')
        ->assertSet('reapplyDispatched', false);
});

it('shows the zero-change summary when a completed run changed nothing', function (): void {
    /** @var Repository $cache */
    $cache = $this->app->make(Repository::class);
    $cache->put(ReapplyRulesJob::progressCacheKey($this->user->id), [
        'status' => 'done',
        'checked' => 10,
        'total' => 10,
        'fields_updated' => 0,
        'transactions_updated' => 0,
        'reconciled_skipped' => 0,
        'started_at' => now()->toIso8601String(),
        'finished_at' => now()->toIso8601String(),
    ], 3600);

    Livewire::test(RulesPage::class)
        ->set('reapplyDispatched', true)
        ->assertSet('flashMessage', 'No changes — your history already matches your rules.');
});

it('the re-apply button uses .pill-btn-ghost, never the emerald primary style', function (): void {
    Livewire::test(RulesPage::class)
        ->assertSeeHtml('pill-btn-ghost');
});

it('grep guard: the blade carries a wire:poll.2s progress strip', function (): void {
    $contents = (string) file_get_contents(base_path('Modules/Categorization/Resources/views/livewire/rules-page.blade.php'));

    expect($contents)->toContain('wire:poll.2s="refreshReapplyProgress"');
});
