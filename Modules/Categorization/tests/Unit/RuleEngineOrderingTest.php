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

/*
 * Task 2 (13.4-02): RuleEngine::match() ordering is a deterministic
 * pure function of (rule set, input) — DB-level ORDER BY
 * priority,id, never a PHP re-sort. Asserts priority-asc ordering,
 * id-asc tiebreak on equal priority, and byte-identical repeat calls.
 */

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

it('tiebreaks same-position rule_actions by id ascending (WR-02)', function (): void {
    $rule = makeOrderingRule($this->user->id, 10, 'spotify');
    // makeOrderingRule() already seeds one position=0 category action —
    // capture its id before adding two more rows at the SAME position.
    $seededActionId = (int) $rule->actions()->firstOrFail()->id;

    // rule_actions.position carries no uniqueness enforcement at the write
    // layer (only RuleFormModal's own UI always assigns unique sequential
    // positions) — insert two more actions sharing position=0 directly,
    // exactly as a non-UI caller (direct-action/DevTools call) could reach.
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
    // All three actions share position=0, so the id tiebreak alone
    // determines the order — without it, a DB-incidental order would be
    // free to return them in any sequence.
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
