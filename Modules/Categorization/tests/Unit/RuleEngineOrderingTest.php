<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Modules\Categorization\Internal\Services\RuleEngine;
use Modules\Categorization\Internal\Services\RuleMatchInput;
use Modules\Categorization\Models\CategorizationRule;
use Modules\Core\Models\User;

uses(RefreshDatabase::class);

// match() ordering is a deterministic function of (rule set, input): the
// ORDER BY priority, id runs in SQL, never as a PHP re-sort.

beforeEach(function (): void {
    $this->user = User::query()->create([
        'username' => 'rule-engine-ordering-test',
        'password' => 'fixture-password',
        'period_start_day' => 1,
    ]);

    $this->engine = $this->app->make(RuleEngine::class);
});

function makeOrderingRule(int $userId, int $priority, string $counterpartyValue): CategorizationRule
{
    $rule = CategorizationRule::query()->create([
        'user_id' => $userId,
        'priority' => $priority,
        'active' => true,
        'combinator' => 'all',
        'notes' => null,
        'hits_count' => 0,
    ]);

    $rule->conditions()->create([
        'field' => 'counterparty',
        'op' => 'contains',
        'value_type' => 'string',
        'value' => $counterpartyValue,
        'value2' => null,
    ]);

    $rule->actions()->create([
        'position' => 0,
        'type' => 'category',
        'payload' => ['category_id' => 1],
    ]);

    return $rule;
}

function orderingMatchInput(): RuleMatchInput
{
    return new RuleMatchInput(
        counterpartyName: 'Spotify AB',
        description: 'Music subscription',
        settledAmountMinor: 1000,
        settledCurrency: 'EUR',
        postedAt: CarbonImmutable::parse('2026-02-01'),
    );
}

it('returns matching rules ordered by priority ascending', function (): void {
    $lowPriorityFirst = makeOrderingRule($this->user->id, 10, 'spotify');
    $higherPrioritySecond = makeOrderingRule($this->user->id, 20, 'spotify');

    $result = $this->engine->match(orderingMatchInput(), $this->user);

    expect($result)->toHaveCount(2);
    expect(array_map(fn ($m) => $m->ruleId, $result))
        ->toBe([$lowPriorityFirst->id, $higherPrioritySecond->id]);
});

it('tiebreaks equal-priority matching rules by id ascending', function (): void {
    $first = makeOrderingRule($this->user->id, 10, 'spotify');
    $second = makeOrderingRule($this->user->id, 10, 'spotify');

    expect($first->id)->toBeLessThan($second->id);

    $result = $this->engine->match(orderingMatchInput(), $this->user);

    expect(array_map(fn ($m) => $m->ruleId, $result))
        ->toBe([$first->id, $second->id]);
});

it('tiebreaks same-position rule_actions by id ascending', function (): void {
    $rule = makeOrderingRule($this->user->id, 10, 'spotify');
    // makeOrderingRule() already seeds one position=0 category action —
    // capture its id before adding two more rows at the SAME position.
    $seededActionId = (int) $rule->actions()->firstOrFail()->id;

    // Nothing at the write layer enforces a unique rule_actions.position —
    // only RuleFormModal assigns them sequentially — so a non-UI caller can
    // land two actions sharing position=0, exactly as here.
    $secondActionId = (int) DB::table('rule_actions')->insertGetId([
        'rule_id' => $rule->id,
        'position' => 0,
        'type' => 'note',
        'payload' => json_encode(['text' => 'second', 'mode' => 'set']),
        'created_at' => CarbonImmutable::now(),
        'updated_at' => CarbonImmutable::now(),
    ]);
    $thirdActionId = (int) DB::table('rule_actions')->insertGetId([
        'rule_id' => $rule->id,
        'position' => 0,
        'type' => 'note',
        'payload' => json_encode(['text' => 'third', 'mode' => 'set']),
        'created_at' => CarbonImmutable::now(),
        'updated_at' => CarbonImmutable::now(),
    ]);
    expect($seededActionId)->toBeLessThan($secondActionId)
        ->and($secondActionId)->toBeLessThan($thirdActionId);

    $result = $this->engine->match(orderingMatchInput(), $this->user);

    expect($result)->toHaveCount(1);
    // All three share position=0, so the id tiebreak alone fixes the order;
    // without it the DB is free to return any sequence.
    expect(array_map(fn ($a) => $a->id, $result[0]->actions))
        ->toBe([$seededActionId, $secondActionId, $thirdActionId]);
});

it('returns a byte-identical ordered ruleId list on repeat calls', function (): void {
    $ruleA = makeOrderingRule($this->user->id, 30, 'spotify');
    $ruleB = makeOrderingRule($this->user->id, 10, 'spotify');
    $ruleC = makeOrderingRule($this->user->id, 20, 'spotify');

    $firstCall = array_map(fn ($m) => $m->ruleId, $this->engine->match(orderingMatchInput(), $this->user));
    $secondCall = array_map(fn ($m) => $m->ruleId, $this->engine->match(orderingMatchInput(), $this->user));

    $expected = [$ruleB->id, $ruleC->id, $ruleA->id];

    expect($firstCall)->toBe($expected)
        ->and($secondCall)->toBe($expected)
        ->and($firstCall)->toBe($secondCall);
});
