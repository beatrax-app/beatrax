<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Modules\Categorization\Internal\Http\Livewire\RuleFormModal;
use Modules\Categorization\Public\Actions\CreateCategorizationRule;
use Modules\Categorization\Public\Actions\UpdateCategorizationRule;
use Modules\Core\Models\User;
use Modules\Ledger\Models\Category;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

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
});

function seedFormRule(int $userId, string $field, string $match, string $value, int $categoryId): int
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

it('opens in create mode when rule-form:open fires without a ruleId', function (): void {
    Livewire::test(RuleFormModal::class)
        ->call('open', ruleId: null)
        ->assertSet('editingRuleId', null)
        ->assertSet('field', '')
        ->assertSet('match', '')
        ->assertSet('value', '')
        ->assertSet('categoryId', null);
});

it('hydrates fields in edit mode when rule-form:open carries a ruleId', function (): void {
    $ruleId = seedFormRule($this->user->id, 'merchant', 'contains', 'SPOTIFY', $this->streaming->id);

    Livewire::test(RuleFormModal::class)
        ->call('open', ruleId: $ruleId)
        ->assertSet('editingRuleId', $ruleId)
        ->assertSet('field', 'merchant')
        ->assertSet('match', 'contains')
        ->assertSet('value', 'SPOTIFY')
        ->assertSet('categoryId', $this->streaming->id);
});

it('falls back to create mode when rule-form:open carries a foreign ruleId', function (): void {
    $other = User::create([
        'username' => 'foreign-rule-form',
        'password' => 'opensesame',
        'period_start_day' => 1,
    ]);
    $foreignRuleId = seedFormRule($other->id, 'merchant', 'contains', 'NETFLIX', $this->streaming->id);

    Livewire::test(RuleFormModal::class)
        ->call('open', ruleId: $foreignRuleId)
        ->assertSet('editingRuleId', null)
        ->assertSet('value', '');
});

it('rejects an empty value with the locked UI-SPEC error copy', function (): void {
    Livewire::test(RuleFormModal::class)
        ->set('field', 'merchant')
        ->set('match', 'contains')
        ->set('value', '')
        ->set('categoryId', $this->streaming->id)
        ->call('save')
        ->assertSet('errorValue', 'Value is required.');
});

it('rejects a missing category with the locked UI-SPEC error copy', function (): void {
    Livewire::test(RuleFormModal::class)
        ->set('field', 'merchant')
        ->set('match', 'contains')
        ->set('value', 'SPOTIFY')
        ->set('categoryId', null)
        ->call('save')
        ->assertSet('errorCategory', 'Pick a category to assign.');
});

it('creates a new rule on save and dispatches rule-form:saved + modal-hide', function (): void {
    Livewire::test(RuleFormModal::class)
        ->set('field', 'merchant')
        ->set('match', 'contains')
        ->set('value', 'SPOTIFY')
        ->set('categoryId', $this->streaming->id)
        ->call('save')
        ->assertDispatched('rule-form:saved')
        ->assertDispatched('modal-hide');

    expect(
        DB::table('categorization_rules')
            ->where('user_id', $this->user->id)
            ->where('value', 'SPOTIFY')
            ->exists()
    )->toBeTrue();
});

it('updates an existing rule on save in edit mode', function (): void {
    $ruleId = seedFormRule($this->user->id, 'merchant', 'contains', 'SPOTIFY', $this->streaming->id);

    Livewire::test(RuleFormModal::class)
        ->call('open', ruleId: $ruleId)
        ->set('categoryId', $this->music->id)
        ->call('save')
        ->assertDispatched('rule-form:saved')
        ->assertDispatched('modal-hide');

    $row = DB::table('categorization_rules')->where('id', $ruleId)->first();
    expect($row->category_id)->toBe($this->music->id);
});

it('translates a duplicate-rule UNIQUE violation into the locked error copy', function (): void {
    seedFormRule($this->user->id, 'merchant', 'contains', 'SPOTIFY', $this->streaming->id);

    Livewire::test(RuleFormModal::class)
        ->set('field', 'merchant')
        ->set('match', 'contains')
        ->set('value', 'SPOTIFY')
        ->set('categoryId', $this->music->id)
        ->call('save')
        ->assertSet('errorValue', 'A rule with this field, match, and value already exists. Edit the existing rule instead.');
});

it('refuses to update a foreign user rule', function (): void {
    $other = User::create([
        'username' => 'cross-user-update',
        'password' => 'opensesame',
        'period_start_day' => 1,
    ]);
    $foreignRuleId = seedFormRule($other->id, 'merchant', 'contains', 'NETFLIX', $this->streaming->id);

    // Manually invoke the action — the modal would have refused to hydrate.
    /** @var UpdateCategorizationRule $update */
    $update = $this->app->make(UpdateCategorizationRule::class);

    expect(fn () => $update($this->user, $foreignRuleId, ['value' => 'HACKED']))
        ->toThrow(NotFoundHttpException::class);
});

it('escapes HTML payloads in rendered value field (T-07-06)', function (): void {
    seedFormRule($this->user->id, 'merchant', 'contains', '<script>alert(1)</script>', $this->streaming->id);

    Livewire::test(RuleFormModal::class)
        ->call('open', ruleId: DB::table('categorization_rules')->where('user_id', $this->user->id)->value('id'))
        ->assertDontSee('<script>alert(1)</script>', escape: false);
});

it('throws InvalidArgumentException via CreateCategorizationRule for an invalid field', function (): void {
    /** @var CreateCategorizationRule $create */
    $create = $this->app->make(CreateCategorizationRule::class);

    expect(fn () => $create($this->user, 'bogus', 'contains', 'SPOTIFY', $this->streaming->id))
        ->toThrow(InvalidArgumentException::class);
});

it('refuses to CREATE a rule with a foreign-user category id (cross-user authorization)', function (): void {
    // Seed another user + a category they own. The category exists
    // (FK accepts the id) but is NOT visible to $this->user, so the
    // create-action MUST reject the request — even though the UI
    // picker funnel would never surface this id, a Livewire-DevTools
    // payload tamper or a direct Tinker call could.
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

    /** @var CreateCategorizationRule $create */
    $create = $this->app->make(CreateCategorizationRule::class);

    expect(fn () => $create($this->user, 'merchant', 'contains', 'TARGET', $foreignCategoryId))
        ->toThrow(InvalidArgumentException::class);

    // And the rule MUST NOT exist after the rejection.
    expect(
        DB::table('categorization_rules')
            ->where('user_id', $this->user->id)
            ->where('category_id', $foreignCategoryId)
            ->exists()
    )->toBeFalse();
});

it('refuses to UPDATE a rule to point at a foreign-user category id', function (): void {
    $ruleId = seedFormRule($this->user->id, 'merchant', 'contains', 'SPOTIFY', $this->streaming->id);

    $other = User::create([
        'username' => 'foreign-category-owner-2',
        'password' => 'opensesame',
        'period_start_day' => 1,
    ]);
    $foreignCategoryId = Category::create([
        'user_id' => $other->id,
        'name' => 'Private 2',
        'slug' => 'private-foreign-2',
        'kind' => 'expense',
        'display_order' => 999,
    ])->id;

    /** @var UpdateCategorizationRule $update */
    $update = $this->app->make(UpdateCategorizationRule::class);

    expect(fn () => $update($this->user, $ruleId, ['category_id' => $foreignCategoryId]))
        ->toThrow(InvalidArgumentException::class);

    // The original category_id MUST remain unchanged after the
    // rejection — the action must not partially apply.
    $row = DB::table('categorization_rules')->where('id', $ruleId)->first();
    expect($row->category_id)->toBe($this->streaming->id);
});

it('accepts a GLOBAL (user_id=NULL) category id for both Create and Update', function (): void {
    // Sanity-check the visibility predicate: global-default categories
    // (categories.user_id IS NULL) must still pass the new guard so
    // the seeded chart-of-accounts continues to work for every user.
    /** @var CreateCategorizationRule $create */
    $create = $this->app->make(CreateCategorizationRule::class);
    $ruleId = $create($this->user, 'merchant', 'contains', 'GLOBAL-OK', $this->streaming->id);
    expect($ruleId)->toBeGreaterThan(0);

    /** @var UpdateCategorizationRule $update */
    $update = $this->app->make(UpdateCategorizationRule::class);
    $affected = $update($this->user, $ruleId, ['category_id' => $this->music->id]);
    expect($affected)->toBe(1);
});

it('save() catches InvalidArgumentException from a tampered foreign category id and surfaces a calm error', function (): void {
    // WR-02: a Livewire request that smuggles a foreign-category id
    // past the picker funnel will hit the WR-01 ownership guard,
    // which throws InvalidArgumentException. The component must
    // catch it and render a calm errorValue instead of a 500.
    $other = User::create([
        'username' => 'tamper-foreign-cat',
        'password' => 'opensesame',
        'period_start_day' => 1,
    ]);
    $foreignCategoryId = Category::create([
        'user_id' => $other->id,
        'name' => 'Secret',
        'slug' => 'secret-foreign-cat',
        'kind' => 'expense',
        'display_order' => 999,
    ])->id;

    Livewire::test(RuleFormModal::class)
        ->set('field', 'merchant')
        ->set('match', 'contains')
        ->set('value', 'TARGET')
        ->set('categoryId', $foreignCategoryId)
        ->call('save')
        ->assertSet('errorValue', 'Invalid field, match, or category — pick from the dropdowns and try again.')
        ->assertNotDispatched('rule-form:saved');

    expect(
        DB::table('categorization_rules')
            ->where('user_id', $this->user->id)
            ->where('value', 'TARGET')
            ->exists()
    )->toBeFalse();
});

it('save() catches NotFoundHttpException from a foreign editingRuleId and hides the modal calmly', function (): void {
    // WR-02: a tampered editingRuleId that points to another user's
    // rule is rejected by UpdateCategorizationRule with
    // NotFoundHttpException. The component must catch it, surface a
    // calm message, and dispatch modal-hide instead of a 500.
    $other = User::create([
        'username' => 'tamper-foreign-rule',
        'password' => 'opensesame',
        'period_start_day' => 1,
    ]);
    $foreignRuleId = seedFormRule($other->id, 'merchant', 'contains', 'NETFLIX', $this->streaming->id);

    Livewire::test(RuleFormModal::class)
        ->set('editingRuleId', $foreignRuleId) // bypass open() which would have masked it
        ->set('field', 'merchant')
        ->set('match', 'contains')
        ->set('value', 'NEW')
        ->set('categoryId', $this->streaming->id)
        ->call('save')
        ->assertSet('errorValue', 'That rule is no longer available.')
        ->assertDispatched('modal-hide');

    // The foreign rule MUST remain untouched.
    $row = DB::table('categorization_rules')->where('id', $foreignRuleId)->first();
    expect($row->value)->toBe('NETFLIX');
});
