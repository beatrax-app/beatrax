<?php

declare(strict_types=1);

use Illuminate\Database\DatabaseManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Categorization\Public\Actions\CreateCategorizationRule;
use Modules\Categorization\Public\Dto\RuleInput;
use Modules\Core\Models\User;
use Modules\Counterparties\Models\Counterparty;
use Modules\Ledger\Models\Category;

uses(RefreshDatabase::class);

/*
 * 13.4-03 Task 3: DeactivateRulesOnReferentDelete — the app-level
 * referential-integrity guard that replaces the FK cascade lost by
 * D-03's JSON-payload rule_actions design (RESEARCH.md Pitfall 2 /
 * T-13.4-09). Deleting a category/counterparty a rule's action
 * payload references must deactivate the owning rule so no active
 * rule is ever left pointing at a dangling id.
 */

function rriUser(string $suffix): User
{
    return User::query()->create([
        'username' => 'rule-referential-'.$suffix.'-'.bin2hex(random_bytes(4)),
        'password' => 'fixture-password',
        'period_start_day' => 1,
    ]);
}

beforeEach(function (): void {
    $this->user = rriUser('primary');
    $this->create = app(CreateCategorizationRule::class);
});

it('deactivates the owning rule when its referenced category is deleted', function (): void {
    $category = Category::create([
        'user_id' => $this->user->id,
        'name' => 'Streaming',
        'slug' => 'rri-streaming-'.bin2hex(random_bytes(3)),
        'kind' => 'expense',
        'display_order' => 1,
    ]);

    $ruleId = ($this->create)($this->user, new RuleInput(priority: 10, combinator: 'all', active: true, notes: null, conditions: [
        ['field' => 'merchant', 'op' => 'contains', 'value_type' => 'string', 'value' => 'SPOTIFY'],
    ], actions: [
        ['type' => 'category', 'payload' => ['category_id' => $category->id]],
    ]));

    $db = app(DatabaseManager::class);
    expect((bool) $db->connection()->table('categorization_rules')->where('id', $ruleId)->value('active'))->toBeTrue();

    $category->delete();

    expect((bool) $db->connection()->table('categorization_rules')->where('id', $ruleId)->value('active'))->toBeFalse();
});

it('deactivates the owning rule when its referenced counterparty is deleted', function (): void {
    $counterparty = Counterparty::create([
        'user_id' => $this->user->id,
        'type' => 'merchant',
        'slug' => 'rri-netflix-'.bin2hex(random_bytes(3)),
        'display_name' => 'Netflix',
        'merchant_name' => 'NETFLIX',
    ]);

    $ruleId = ($this->create)($this->user, new RuleInput(priority: 10, combinator: 'all', active: true, notes: null, conditions: [
        ['field' => 'merchant', 'op' => 'contains', 'value_type' => 'string', 'value' => 'NETFLIX'],
    ], actions: [
        ['type' => 'counterparty', 'payload' => ['counterparty_id' => $counterparty->id]],
    ]));

    $db = app(DatabaseManager::class);
    expect((bool) $db->connection()->table('categorization_rules')->where('id', $ruleId)->value('active'))->toBeTrue();

    $counterparty->delete();

    expect((bool) $db->connection()->table('categorization_rules')->where('id', $ruleId)->value('active'))->toBeFalse();
});

it('scopes the deactivation UPDATE by user_id — does not touch a different user\'s rule referencing an unrelated category with the same id shape', function (): void {
    $otherUser = rriUser('other');

    $category = Category::create([
        'user_id' => $this->user->id,
        'name' => 'Groceries',
        'slug' => 'rri-groceries-'.bin2hex(random_bytes(3)),
        'kind' => 'expense',
        'display_order' => 1,
    ]);

    $otherCategory = Category::create([
        'user_id' => $otherUser->id,
        'name' => 'Groceries Other',
        'slug' => 'rri-groceries-other-'.bin2hex(random_bytes(3)),
        'kind' => 'expense',
        'display_order' => 1,
    ]);

    $otherRuleId = ($this->create)($otherUser, new RuleInput(priority: 10, combinator: 'all', active: true, notes: null, conditions: [
        ['field' => 'merchant', 'op' => 'contains', 'value_type' => 'string', 'value' => 'AH'],
    ], actions: [
        ['type' => 'category', 'payload' => ['category_id' => $otherCategory->id]],
    ]));

    $category->delete();

    $db = app(DatabaseManager::class);
    expect((bool) $db->connection()->table('categorization_rules')->where('id', $otherRuleId)->value('active'))->toBeTrue();
});
