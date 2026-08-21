<?php

declare(strict_types=1);

use Illuminate\Database\DatabaseManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Modules\Categorization\Public\Actions\CreateCategorizationRule;
use Modules\Categorization\Public\Actions\UpdateCategorizationRule;
use Modules\Categorization\Public\Dto\RuleInput;
use Modules\Categorization\Public\Services\CategorizationRuleQuery;
use Modules\Core\Models\User;
use Modules\Counterparties\Models\Counterparty;
use Modules\Ledger\Models\Category;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

uses(RefreshDatabase::class);

function ruleAuthoringUser(string $suffix): User
{
    return User::query()->create([
        'username' => 'rule-authoring-'.$suffix.'-'.bin2hex(random_bytes(4)),
        'password' => 'fixture-password',
        'period_start_day' => 1,
    ]);
}

function ruleAuthoringDeductionCategory(DatabaseManager $db, int $userId, string $name): int
{
    $id = $db->connection()->table('tax_deduction_categories')->insertGetId([
        'user_id' => $userId,
        'name' => $name,
        'short_name' => substr($name, 0, 3),
        'status' => 'active',
        'sort_order' => 0,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    return is_numeric($id) ? (int) $id : 0;
}

beforeEach(function (): void {
    $this->user = ruleAuthoringUser('primary');
    $this->otherUser = ruleAuthoringUser('other');

    $this->category = Category::create([
        'user_id' => null,
        'name' => 'Subscriptions',
        'slug' => 'rule-authoring-subscriptions-'.bin2hex(random_bytes(3)),
        'kind' => 'expense',
        'display_order' => 1,
    ]);

    $this->counterparty = Counterparty::create([
        'user_id' => $this->user->id,
        'type' => 'merchant',
        'slug' => 'rule-authoring-spotify-'.bin2hex(random_bytes(3)),
        'display_name' => 'Spotify',
        'merchant_name' => 'SPOTIFY',
    ]);

    $this->foreignCounterparty = Counterparty::create([
        'user_id' => $this->otherUser->id,
        'type' => 'merchant',
        'slug' => 'rule-authoring-foreign-'.bin2hex(random_bytes(3)),
        'display_name' => 'Foreign Merchant',
        'merchant_name' => 'FOREIGN MERCHANT',
    ]);

    $this->create = app(CreateCategorizationRule::class);
    $this->update = app(UpdateCategorizationRule::class);
    $this->query = app(CategorizationRuleQuery::class);
});

it('persists a valid multi-condition/multi-action rule as one parent + N conditions + M actions atomically', function (): void {
    $ruleId = ($this->create)(
        $this->user,
        new RuleInput(
            priority: 10,
            combinator: 'all',
            active: true,
            notes: 'notes here',
            conditions: [
                ['field' => 'merchant', 'op' => 'contains', 'value_type' => 'string', 'value' => 'SPOTIFY'],
                ['op' => '>', 'value_type' => 'amount', 'value' => '-5000'],
            ],
            actions: [
                ['type' => 'category', 'payload' => ['category_id' => $this->category->id]],
                ['type' => 'counterparty', 'payload' => ['counterparty_id' => $this->counterparty->id]],
            ],
        ),
    );

    expect($ruleId)->toBeGreaterThan(0);

    $db = app(DatabaseManager::class);

    expect($db->connection()->table('categorization_rules')->where('id', $ruleId)->count())->toBe(1);
    expect($db->connection()->table('rule_conditions')->where('rule_id', $ruleId)->count())->toBe(2);
    expect($db->connection()->table('rule_actions')->where('rule_id', $ruleId)->count())->toBe(2);

    $rule = $db->connection()->table('categorization_rules')->where('id', $ruleId)->first();
    expect((int) $rule->priority)->toBe(10);
    expect($rule->combinator)->toBe('all');
});

it('CategorizationRuleQuery::forUser round-trips a persisted rule into nested condition/action DTOs', function (): void {
    $ruleId = ($this->create)(
        $this->user,
        new RuleInput(
            priority: 10,
            combinator: 'all',
            active: true,
            notes: null,
            conditions: [
                ['field' => 'merchant', 'op' => 'contains', 'value_type' => 'string', 'value' => 'SPOTIFY'],
            ],
            actions: [
                ['type' => 'category', 'payload' => ['category_id' => $this->category->id]],
                ['type' => 'counterparty', 'payload' => ['counterparty_id' => $this->counterparty->id]],
            ],
        ),
    );

    $dtos = $this->query->forUser($this->user);

    expect($dtos)->toHaveCount(1);
    $dto = $dtos[0];
    expect($dto->id)->toBe($ruleId);
    expect($dto->conditions)->toHaveCount(1);
    expect($dto->actions)->toHaveCount(2);

    $categoryAction = collect($dto->actions)->firstWhere('type', 'category');
    expect($categoryAction)->not->toBeNull();
    expect($categoryAction->categoryPath)->not->toBeNull();

    $counterpartyAction = collect($dto->actions)->firstWhere('type', 'counterparty');
    expect($counterpartyAction)->not->toBeNull();
    expect($counterpartyAction->counterpartyName)->toBe('Spotify');
});

it('CategorizationRuleQuery::forUser returns rules ordered priority asc, then id asc', function (): void {
    $lowPriorityId = ($this->create)($this->user, new RuleInput(priority: 50, combinator: 'all', active: true, notes: null, conditions: [
        ['field' => 'merchant', 'op' => 'contains', 'value_type' => 'string', 'value' => 'FIRST'],
    ], actions: [
        ['type' => 'category', 'payload' => ['category_id' => $this->category->id]],
    ]));

    $highPriorityId = ($this->create)($this->user, new RuleInput(priority: 5, combinator: 'all', active: true, notes: null, conditions: [
        ['field' => 'merchant', 'op' => 'contains', 'value_type' => 'string', 'value' => 'SECOND'],
    ], actions: [
        ['type' => 'category', 'payload' => ['category_id' => $this->category->id]],
    ]));

    $dtos = $this->query->forUser($this->user);

    expect($dtos)->toHaveCount(2);
    expect($dtos[0]->id)->toBe($highPriorityId);
    expect($dtos[1]->id)->toBe($lowPriorityId);
});

it('CategorizationRuleQuery::findForUser returns null for a rule owned by another user', function (): void {
    $ruleId = ($this->create)($this->user, new RuleInput(priority: 10, combinator: 'all', active: true, notes: null, conditions: [
        ['field' => 'merchant', 'op' => 'contains', 'value_type' => 'string', 'value' => 'SPOTIFY'],
    ], actions: [
        ['type' => 'category', 'payload' => ['category_id' => $this->category->id]],
    ]));

    expect($this->query->findForUser($this->otherUser, $ruleId))->toBeNull();
    expect($this->query->findForUser($this->user, $ruleId))->not->toBeNull();
});

it('rejects a condition whose (op, value_type) pair is outside the validity matrix', function (): void {
    expect(fn () => ($this->create)($this->user, new RuleInput(priority: 10, combinator: 'all', active: true, notes: null, conditions: [
        ['op' => 'starts_with', 'value_type' => 'amount', 'value' => '100'],
    ], actions: [
        ['type' => 'category', 'payload' => ['category_id' => $this->category->id]],
    ])))->toThrow(InvalidArgumentException::class);
});

it('rejects a between condition missing value2', function (): void {
    expect(fn () => ($this->create)($this->user, new RuleInput(priority: 10, combinator: 'all', active: true, notes: null, conditions: [
        ['op' => 'between', 'value_type' => 'amount', 'value' => '100'],
    ], actions: [
        ['type' => 'category', 'payload' => ['category_id' => $this->category->id]],
    ])))->toThrow(InvalidArgumentException::class);
});

it('rejects an action payload referencing a counterparty not visible to the user', function (): void {
    expect(fn () => ($this->create)($this->user, new RuleInput(priority: 10, combinator: 'all', active: true, notes: null, conditions: [
        ['field' => 'merchant', 'op' => 'contains', 'value_type' => 'string', 'value' => 'SPOTIFY'],
    ], actions: [
        ['type' => 'counterparty', 'payload' => ['counterparty_id' => $this->foreignCounterparty->id]],
    ])))->toThrow(InvalidArgumentException::class);
});

it('rejects a zero-condition rule', function (): void {
    expect(fn () => ($this->create)($this->user, new RuleInput(priority: 10, combinator: 'all', active: true, notes: null, conditions: [], actions: [
        ['type' => 'category', 'payload' => ['category_id' => $this->category->id]],
    ])))->toThrow(ValidationException::class);
});

it('rejects a zero-action rule', function (): void {
    expect(fn () => ($this->create)($this->user, new RuleInput(priority: 10, combinator: 'all', active: true, notes: null, conditions: [
        ['field' => 'merchant', 'op' => 'contains', 'value_type' => 'string', 'value' => 'SPOTIFY'],
    ], actions: [])))->toThrow(ValidationException::class);
});

it('rejects a tax_tag action referencing a deduction category not visible to the user', function (): void {
    $db = app(DatabaseManager::class);
    $foreignDeductionCategoryId = ruleAuthoringDeductionCategory($db, $this->otherUser->id, 'Foreign Deduction');

    expect(fn () => ($this->create)($this->user, new RuleInput(priority: 10, combinator: 'all', active: true, notes: null, conditions: [
        ['field' => 'merchant', 'op' => 'contains', 'value_type' => 'string', 'value' => 'SPOTIFY'],
    ], actions: [
        ['type' => 'tax_tag', 'payload' => ['deduction_category_id' => $foreignDeductionCategoryId]],
    ])))->toThrow(InvalidArgumentException::class);
});

it('accepts a tax_tag action referencing a deduction category visible to the user', function (): void {
    $db = app(DatabaseManager::class);
    $deductionCategoryId = ruleAuthoringDeductionCategory($db, $this->user->id, 'Home Office');

    $ruleId = ($this->create)($this->user, new RuleInput(priority: 10, combinator: 'all', active: true, notes: null, conditions: [
        ['field' => 'merchant', 'op' => 'contains', 'value_type' => 'string', 'value' => 'SPOTIFY'],
    ], actions: [
        ['type' => 'tax_tag', 'payload' => ['deduction_category_id' => $deductionCategoryId]],
    ]));

    expect($ruleId)->toBeGreaterThan(0);
});

it('updates an existing rule via a PK-preserving diff — surviving conditions keep their id, removed ones are deleted, new ones are inserted', function (): void {
    $db = app(DatabaseManager::class);

    $ruleId = ($this->create)($this->user, new RuleInput(priority: 10, combinator: 'all', active: true, notes: null, conditions: [
        ['field' => 'merchant', 'op' => 'contains', 'value_type' => 'string', 'value' => 'SPOTIFY'],
        ['field' => 'description', 'op' => 'equals', 'value_type' => 'string', 'value' => 'REMOVE ME'],
    ], actions: [
        ['type' => 'category', 'payload' => ['category_id' => $this->category->id]],
    ]));

    $survivingConditionId = $db->connection()->table('rule_conditions')
        ->where('rule_id', $ruleId)
        ->where('value', 'SPOTIFY')
        ->value('id');
    expect($survivingConditionId)->not->toBeNull();

    ($this->update)(
        $this->user,
        $ruleId,
        new RuleInput(
            priority: 20,
            combinator: 'any',
            active: true,
            notes: 'updated notes',
            conditions: [
                ['id' => (int) $survivingConditionId, 'field' => 'merchant', 'op' => 'starts_with', 'value_type' => 'string', 'value' => 'SPOT'],
                ['field' => 'merchant', 'op' => 'contains', 'value_type' => 'string', 'value' => 'NEW CONDITION'],
            ],
            actions: [
                ['type' => 'category', 'payload' => ['category_id' => $this->category->id]],
            ],
        ),
    );

    $conditions = $db->connection()->table('rule_conditions')->where('rule_id', $ruleId)->orderBy('id')->get();
    expect($conditions)->toHaveCount(2);

    $preserved = $conditions->firstWhere('id', $survivingConditionId);
    expect($preserved)->not->toBeNull();
    expect($preserved->op)->toBe('starts_with');
    expect($preserved->value)->toBe('SPOT');

    expect($conditions->pluck('value')->all())->not->toContain('REMOVE ME');
    expect($conditions->pluck('value')->all())->toContain('NEW CONDITION');

    $rule = $db->connection()->table('categorization_rules')->where('id', $ruleId)->first();
    expect((int) $rule->priority)->toBe(20);
    expect($rule->combinator)->toBe('any');
    expect($rule->notes)->toBe('updated notes');
});

it('raises a 404-not-403 NotFoundHttpException when updating a rule owned by another user', function (): void {
    $ruleId = ($this->create)($this->user, new RuleInput(priority: 10, combinator: 'all', active: true, notes: null, conditions: [
        ['field' => 'merchant', 'op' => 'contains', 'value_type' => 'string', 'value' => 'SPOTIFY'],
    ], actions: [
        ['type' => 'category', 'payload' => ['category_id' => $this->category->id]],
    ]));

    expect(fn () => ($this->update)(
        $this->otherUser,
        $ruleId,
        new RuleInput(
            priority: 10,
            combinator: 'all',
            active: true,
            notes: null,
            conditions: [
                ['field' => 'merchant', 'op' => 'contains', 'value_type' => 'string', 'value' => 'SPOTIFY'],
            ],
            actions: [
                ['type' => 'category', 'payload' => ['category_id' => $this->category->id]],
            ],
        ),
    ))->toThrow(NotFoundHttpException::class);
});
