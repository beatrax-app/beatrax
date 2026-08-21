<?php

declare(strict_types=1);

use Illuminate\Contracts\Console\Kernel as ConsoleKernel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Categorization\Database\Seeders\DefaultCategorizationRuleSeeder;
use Modules\Categorization\Database\Seeders\DefaultCategoryTreeSeeder;
use Modules\Categorization\Models\CategorizationRule;
use Modules\Core\Models\User;
use Modules\Ledger\Models\Category;

uses(RefreshDatabase::class);

// Read from the canonical fixture so a shrink to 79 rows cannot silently pass
// a hardcoded `>= 80` floor.
function fixtureRuleCount(): int
{
    /** @var list<array{category: string, field: string, match: string, value: string}> $fixture */
    $fixture = require base_path('Modules/Categorization/Database/Seeders/default-categorization-rules.php');

    return count($fixture);
}

// field/match/value live on the child row, so the lookup joins through it.
function findSeededRule(int $userId, string $field, string $match, string $value): ?CategorizationRule
{
    return CategorizationRule::withoutGlobalScopes()
        ->where('user_id', $userId)
        ->whereHas('conditions', function ($query) use ($field, $match, $value): void {
            $query->where('field', $field)->where('op', $match)->where('value', $value);
        })
        ->first();
}

beforeEach(function (): void {
    // The rule seeder resolves category_id by slug from the global
    // category tree, so the tree seeder must run first in every test.
    $this->app->make(DefaultCategoryTreeSeeder::class)->run();

    $this->user = User::query()->create([
        'username' => 'rule-seeder-test',
        'password' => 'fixture-password',
        'period_start_day' => 1,
    ]);
});

it('seeds at least the fixture-defined rule count scoped to the user', function (): void {
    $this->app->make(DefaultCategorizationRuleSeeder::class)->run($this->user);

    $expectedCount = fixtureRuleCount();

    $actualCount = CategorizationRule::withoutGlobalScopes()
        ->where('user_id', $this->user->id)
        ->count();

    expect($actualCount)->toBe($expectedCount)
        ->and($expectedCount)->toBeGreaterThanOrEqual(80);
});

it('gives every seeded rule exactly one condition and one category action', function (): void {
    $this->app->make(DefaultCategorizationRuleSeeder::class)->run($this->user);

    $rules = CategorizationRule::withoutGlobalScopes()->where('user_id', $this->user->id)->get();

    foreach ($rules as $rule) {
        expect($rule->conditions()->count())->toBe(1);
        expect($rule->actions()->count())->toBe(1);

        $action = $rule->actions()->firstOrFail();
        expect($action->type)->toBe('category');
        expect($action->payload)->toHaveKey('category_id');
        expect($action->payload['category_id'])->not->toBeNull();
    }
});

it('locks the create-only semantics — re-running preserves hits_count and active', function (): void {
    $seeder = $this->app->make(DefaultCategorizationRuleSeeder::class);
    $seeder->run($this->user);

    $countAfterFirstRun = CategorizationRule::withoutGlobalScopes()
        ->where('user_id', $this->user->id)
        ->count();

    // Mutate one row to the sentinel state a naive re-seed would overwrite:
    // hits_count=42, active=false.
    $sampleRule = findSeededRule($this->user->id, 'counterparty', 'contains', 'Netflix');
    expect($sampleRule)->not->toBeNull();

    $sampleRule->update([
        'hits_count' => 42,
        'active' => false,
    ]);

    // Re-run — must NOT touch the mutated row, nor create a duplicate.
    $seeder->run($this->user);

    $countAfterSecondRun = CategorizationRule::withoutGlobalScopes()
        ->where('user_id', $this->user->id)
        ->count();

    expect($countAfterSecondRun)->toBe($countAfterFirstRun);

    $sampleRule->refresh();
    expect($sampleRule->hits_count)->toBe(42)
        ->and($sampleRule->active)->toBeFalse();
});

it('produces a per-user rule set scoped to the second user when re-run', function (): void {
    $secondUser = User::query()->create([
        'username' => 'second-user',
        'password' => 'fixture-password',
        'period_start_day' => 1,
    ]);

    $seeder = $this->app->make(DefaultCategorizationRuleSeeder::class);
    $seeder->run($this->user);
    $seeder->run($secondUser);

    $firstUserCount = CategorizationRule::withoutGlobalScopes()
        ->where('user_id', $this->user->id)
        ->count();
    $secondUserCount = CategorizationRule::withoutGlobalScopes()
        ->where('user_id', $secondUser->id)
        ->count();

    expect($firstUserCount)->toBe(fixtureRuleCount())
        ->and($secondUserCount)->toBe($firstUserCount);
});

it('seeds the Netflix -> subscriptions-streaming anchor rule', function (): void {
    $this->app->make(DefaultCategorizationRuleSeeder::class)->run($this->user);

    $rule = findSeededRule($this->user->id, 'counterparty', 'contains', 'Netflix');
    expect($rule)->not->toBeNull();

    $categoryId = $rule->actions()->firstOrFail()->payload['category_id'];
    $categorySlug = Category::withoutGlobalScopes()->where('id', $categoryId)->value('slug');

    expect($categorySlug)->toBe('subscriptions-streaming');
});

it('seeds the cash-withdrawal anchor rule with the starts_with operator', function (): void {
    $this->app->make(DefaultCategorizationRuleSeeder::class)->run($this->user);

    $rule = findSeededRule($this->user->id, 'counterparty', 'starts_with', 'KOSTEN KASOPNAME');
    expect($rule)->not->toBeNull();

    $categoryId = $rule->actions()->firstOrFail()->payload['category_id'];
    $categorySlug = Category::withoutGlobalScopes()->where('id', $categoryId)->value('slug');

    expect($categorySlug)->toBe('cash-withdrawal');
});

it('seeds the transfers-internal anchor rule against the description field', function (): void {
    $this->app->make(DefaultCategorizationRuleSeeder::class)->run($this->user);

    $rule = findSeededRule($this->user->id, 'description', 'contains', 'IDEAL BETALING, DANK U');
    expect($rule)->not->toBeNull();

    $categoryId = $rule->actions()->firstOrFail()->payload['category_id'];
    $categorySlug = Category::withoutGlobalScopes()->where('id', $categoryId)->value('slug');

    expect($categorySlug)->toBe('transfers-internal');
});

it('runs from the beatrax:install command via the UserInstalled listener end-to-end', function (): void {
    // Both listeners on UserInstalled must fire, so drive the real command.
    $exit = $this->app->make(ConsoleKernel::class)->call('beatrax:install', [
        '--username' => 'install-rules',
        '--password' => 'opensesame',
        '--period-start-day' => 1,
    ]);

    expect($exit)->toBe(0);

    $ruleCount = CategorizationRule::withoutGlobalScopes()->count();
    expect($ruleCount)->toBe(fixtureRuleCount());
});
