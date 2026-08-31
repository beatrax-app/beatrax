<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Categorization\Internal\Services\RuleEngine;
use Modules\Categorization\Internal\Services\RuleMatchInput;
use Modules\Categorization\Models\CategorizationRule;
use Modules\Core\Models\User;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->user = User::query()->create([
        'username' => 'rule-engine-test',
        'password' => 'fixture-password',
        'period_start_day' => 1,
    ]);

    $this->engine = $this->app->make(RuleEngine::class);
});

/**
 * @param  list<array{field: string, op: string, value_type: string, value: string, value2?: string|null}>  $conditions
 */
function makeMatchingRule(int $userId, string $combinator, array $conditions, int $priority = 0): CategorizationRule
{
    $rule = CategorizationRule::query()->create([
        'user_id' => $userId,
        'priority' => $priority,
        'active' => true,
        'combinator' => $combinator,
        'notes' => null,
        'hits_count' => 0,
    ]);

    foreach ($conditions as $condition) {
        $rule->conditions()->create([
            'field' => $condition['field'],
            'op' => $condition['op'],
            'value_type' => $condition['value_type'],
            'value' => $condition['value'],
            'value2' => $condition['value2'] ?? null,
        ]);
    }

    $rule->actions()->create([
        'position' => 0,
        'type' => 'category',
        'payload' => ['category_id' => 1],
    ]);

    return $rule;
}

function matchInput(
    ?string $counterpartyName = 'Spotify AB',
    ?string $description = 'Music subscription',
    int $settledAmountMinor = 1000,
    ?CarbonImmutable $postedAt = null,
    string $settledCurrency = 'EUR',
): RuleMatchInput {
    return new RuleMatchInput(
        counterpartyName: $counterpartyName,
        description: $description,
        settledAmountMinor: $settledAmountMinor,
        settledCurrency: $settledCurrency,
        postedAt: $postedAt ?? CarbonImmutable::parse('2026-02-01'),
    );
}

it('fires a string equals condition on exact case-insensitive match', function (): void {
    makeMatchingRule($this->user->id, 'all', [
        ['field' => 'counterparty', 'op' => 'equals', 'value_type' => 'string', 'value' => 'spotify ab'],
    ]);

    $result = $this->engine->match(matchInput(counterpartyName: 'Spotify AB'), $this->user);

    expect($result)->toHaveCount(1);
});

it('does not fire a string equals condition when target differs', function (): void {
    makeMatchingRule($this->user->id, 'all', [
        ['field' => 'counterparty', 'op' => 'equals', 'value_type' => 'string', 'value' => 'netflix'],
    ]);

    $result = $this->engine->match(matchInput(counterpartyName: 'Spotify AB'), $this->user);

    expect($result)->toHaveCount(0);
});

it('fires a string contains condition on a substring match', function (): void {
    makeMatchingRule($this->user->id, 'all', [
        ['field' => 'counterparty', 'op' => 'contains', 'value_type' => 'string', 'value' => 'potify'],
    ]);

    $result = $this->engine->match(matchInput(counterpartyName: 'Spotify AB'), $this->user);

    expect($result)->toHaveCount(1);
});

it('fires a string starts_with condition on a prefix match', function (): void {
    makeMatchingRule($this->user->id, 'all', [
        ['field' => 'counterparty', 'op' => 'starts_with', 'value_type' => 'string', 'value' => 'Spotify'],
    ]);

    $result = $this->engine->match(matchInput(counterpartyName: 'Spotify AB'), $this->user);

    expect($result)->toHaveCount(1);
});

it('does not fire a string starts_with condition when value is not a prefix', function (): void {
    makeMatchingRule($this->user->id, 'all', [
        ['field' => 'counterparty', 'op' => 'starts_with', 'value_type' => 'string', 'value' => 'potify'],
    ]);

    $result = $this->engine->match(matchInput(counterpartyName: 'Spotify AB'), $this->user);

    expect($result)->toHaveCount(0);
});

it('does not fire a string condition when the target field is null', function (): void {
    makeMatchingRule($this->user->id, 'all', [
        ['field' => 'description', 'op' => 'contains', 'value_type' => 'string', 'value' => 'anything'],
    ]);

    $result = $this->engine->match(matchInput(description: null), $this->user);

    expect($result)->toHaveCount(0);
});

it('matches unicode merchant names case-insensitively via mb_* comparisons', function (): void {
    makeMatchingRule($this->user->id, 'all', [
        ['field' => 'counterparty', 'op' => 'contains', 'value_type' => 'string', 'value' => 'café'],
    ]);

    $result = $this->engine->match(matchInput(counterpartyName: 'Le CAFÉ Noir'), $this->user);

    expect($result)->toHaveCount(1);
});

it('reads a description-field condition against the description property', function (): void {
    makeMatchingRule($this->user->id, 'all', [
        ['field' => 'description', 'op' => 'contains', 'value_type' => 'string', 'value' => 'IDEAL BETALING'],
    ]);

    $result = $this->engine->match(matchInput(description: 'IDEAL BETALING, DANK U'), $this->user);

    expect($result)->toHaveCount(1);
});

it('fires an amount > condition when the target exceeds the value', function (): void {
    makeMatchingRule($this->user->id, 'all', [
        ['field' => 'merchant', 'op' => '>', 'value_type' => 'amount', 'value' => '1000'],
    ]);

    $result = $this->engine->match(matchInput(settledAmountMinor: 1500), $this->user);

    expect($result)->toHaveCount(1);
});

it('does not fire an amount > condition when the target does not exceed the value', function (): void {
    makeMatchingRule($this->user->id, 'all', [
        ['field' => 'merchant', 'op' => '>', 'value_type' => 'amount', 'value' => '1000'],
    ]);

    $result = $this->engine->match(matchInput(settledAmountMinor: 900), $this->user);

    expect($result)->toHaveCount(0);
});

it('fires an amount < condition when the target is below the value', function (): void {
    makeMatchingRule($this->user->id, 'all', [
        ['field' => 'merchant', 'op' => '<', 'value_type' => 'amount', 'value' => '1000'],
    ]);

    $result = $this->engine->match(matchInput(settledAmountMinor: 500), $this->user);

    expect($result)->toHaveCount(1);
});

it('fires an amount between condition when the target is within the inclusive range', function (): void {
    makeMatchingRule($this->user->id, 'all', [
        ['field' => 'merchant', 'op' => 'between', 'value_type' => 'amount', 'value' => '1000', 'value2' => '1500'],
    ]);

    $result = $this->engine->match(matchInput(settledAmountMinor: 1200), $this->user);

    expect($result)->toHaveCount(1);
});

it('does not fire an amount between condition when the target is outside the range', function (): void {
    makeMatchingRule($this->user->id, 'all', [
        ['field' => 'merchant', 'op' => 'between', 'value_type' => 'amount', 'value' => '1000', 'value2' => '1500'],
    ]);

    $result = $this->engine->match(matchInput(settledAmountMinor: 1600), $this->user);

    expect($result)->toHaveCount(0);
});

it('fires an amount equals condition on an exact match', function (): void {
    makeMatchingRule($this->user->id, 'all', [
        ['field' => 'merchant', 'op' => 'equals', 'value_type' => 'amount', 'value' => '1000'],
    ]);

    $result = $this->engine->match(matchInput(settledAmountMinor: 1000), $this->user);

    expect($result)->toHaveCount(1);
});

it('fires a date after condition when postedAt is later than the value', function (): void {
    makeMatchingRule($this->user->id, 'all', [
        ['field' => 'merchant', 'op' => 'after', 'value_type' => 'date', 'value' => '2026-01-01'],
    ]);

    $result = $this->engine->match(matchInput(postedAt: CarbonImmutable::parse('2026-03-01')), $this->user);

    expect($result)->toHaveCount(1);
});

it('does not fire a date after condition when postedAt is earlier than the value', function (): void {
    makeMatchingRule($this->user->id, 'all', [
        ['field' => 'merchant', 'op' => 'after', 'value_type' => 'date', 'value' => '2026-01-01'],
    ]);

    $result = $this->engine->match(matchInput(postedAt: CarbonImmutable::parse('2025-12-01')), $this->user);

    expect($result)->toHaveCount(0);
});

it('fires a date before condition when postedAt is earlier than the value', function (): void {
    makeMatchingRule($this->user->id, 'all', [
        ['field' => 'merchant', 'op' => 'before', 'value_type' => 'date', 'value' => '2026-03-01'],
    ]);

    $result = $this->engine->match(matchInput(postedAt: CarbonImmutable::parse('2026-01-01')), $this->user);

    expect($result)->toHaveCount(1);
});

it('fires a date between condition inclusively', function (): void {
    makeMatchingRule($this->user->id, 'all', [
        ['field' => 'merchant', 'op' => 'between', 'value_type' => 'date', 'value' => '2026-01-01', 'value2' => '2026-03-01'],
    ]);

    $result = $this->engine->match(matchInput(postedAt: CarbonImmutable::parse('2026-01-01')), $this->user);

    expect($result)->toHaveCount(1);
});

it('does not fire a date between condition when postedAt is outside the range', function (): void {
    makeMatchingRule($this->user->id, 'all', [
        ['field' => 'merchant', 'op' => 'between', 'value_type' => 'date', 'value' => '2026-01-01', 'value2' => '2026-03-01'],
    ]);

    $result = $this->engine->match(matchInput(postedAt: CarbonImmutable::parse('2026-04-01')), $this->user);

    expect($result)->toHaveCount(0);
});

it('fires an "all" combinator rule only when both string conditions match', function (): void {
    makeMatchingRule($this->user->id, 'all', [
        ['field' => 'counterparty', 'op' => 'contains', 'value_type' => 'string', 'value' => 'spotify'],
        ['field' => 'description', 'op' => 'contains', 'value_type' => 'string', 'value' => 'music'],
    ]);

    $bothMatch = $this->engine->match(
        matchInput(counterpartyName: 'Spotify AB', description: 'Music subscription'),
        $this->user,
    );
    $onlyOneMatches = $this->engine->match(
        matchInput(counterpartyName: 'Spotify AB', description: 'Something else'),
        $this->user,
    );

    expect($bothMatch)->toHaveCount(1)
        ->and($onlyOneMatches)->toHaveCount(0);
});

it('fires an "any" combinator rule when either string condition matches', function (): void {
    makeMatchingRule($this->user->id, 'any', [
        ['field' => 'counterparty', 'op' => 'contains', 'value_type' => 'string', 'value' => 'spotify'],
        ['field' => 'description', 'op' => 'contains', 'value_type' => 'string', 'value' => 'music'],
    ]);

    $onlyCounterpartyMatches = $this->engine->match(
        matchInput(counterpartyName: 'Spotify AB', description: 'Something else'),
        $this->user,
    );
    $onlyDescriptionMatches = $this->engine->match(
        matchInput(counterpartyName: 'Someone else', description: 'Music subscription'),
        $this->user,
    );
    $neitherMatches = $this->engine->match(
        matchInput(counterpartyName: 'Someone else', description: 'Something else'),
        $this->user,
    );

    expect($onlyCounterpartyMatches)->toHaveCount(1)
        ->and($onlyDescriptionMatches)->toHaveCount(1)
        ->and($neitherMatches)->toHaveCount(0);
});

it('fires a mixed amount-between and date-after condition set under "all"', function (): void {
    makeMatchingRule($this->user->id, 'all', [
        ['field' => 'merchant', 'op' => 'between', 'value_type' => 'amount', 'value' => '1000', 'value2' => '1500'],
        ['field' => 'merchant', 'op' => 'after', 'value_type' => 'date', 'value' => '2026-01-01'],
    ]);

    $bothConditionsHold = $this->engine->match(
        matchInput(settledAmountMinor: 1200, postedAt: CarbonImmutable::parse('2026-03-01')),
        $this->user,
    );
    $amountOutOfRange = $this->engine->match(
        matchInput(settledAmountMinor: 1600, postedAt: CarbonImmutable::parse('2026-03-01')),
        $this->user,
    );
    $dateTooEarly = $this->engine->match(
        matchInput(settledAmountMinor: 1200, postedAt: CarbonImmutable::parse('2025-12-01')),
        $this->user,
    );

    expect($bothConditionsHold)->toHaveCount(1)
        ->and($amountOutOfRange)->toHaveCount(0)
        ->and($dateTooEarly)->toHaveCount(0);
});

it('never matches an inactive rule', function (): void {
    $rule = makeMatchingRule($this->user->id, 'all', [
        ['field' => 'counterparty', 'op' => 'contains', 'value_type' => 'string', 'value' => 'spotify'],
    ]);
    $rule->update(['active' => false]);

    $result = $this->engine->match(matchInput(counterpartyName: 'Spotify AB'), $this->user);

    expect($result)->toHaveCount(0);
});

it('never matches a rule scoped to a different user', function (): void {
    $otherUser = User::query()->create([
        'username' => 'other-user',
        'password' => 'fixture-password',
        'period_start_day' => 1,
    ]);
    makeMatchingRule($otherUser->id, 'all', [
        ['field' => 'counterparty', 'op' => 'contains', 'value_type' => 'string', 'value' => 'spotify'],
    ]);

    $result = $this->engine->match(matchInput(counterpartyName: 'Spotify AB'), $this->user);

    expect($result)->toHaveCount(0);
});

it('returns MatchedRule instances carrying the ruleId, priority, and ordered actions', function (): void {
    $rule = makeMatchingRule($this->user->id, 'all', [
        ['field' => 'counterparty', 'op' => 'contains', 'value_type' => 'string', 'value' => 'spotify'],
    ], priority: 42);

    $result = $this->engine->match(matchInput(counterpartyName: 'Spotify AB'), $this->user);

    expect($result)->toHaveCount(1);
    $matchedRule = $result[0];
    expect($matchedRule->ruleId)->toBe($rule->id)
        ->and($matchedRule->priority)->toBe(42)
        ->and($matchedRule->actions)->toHaveCount(1)
        ->and($matchedRule->actions[0]->type)->toBe('category');
});

it('never fires a rule with an empty condition set', function (): void {
    CategorizationRule::query()->create([
        'user_id' => $this->user->id,
        'priority' => 0,
        'active' => true,
        'combinator' => 'all',
        'notes' => null,
        'hits_count' => 0,
    ]);

    $result = $this->engine->match(matchInput(), $this->user);

    expect($result)->toHaveCount(0);
});
