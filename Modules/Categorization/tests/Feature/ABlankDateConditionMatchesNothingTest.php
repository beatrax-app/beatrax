<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Carbon\Exceptions\InvalidFormatException;
use Illuminate\Database\QueryException;
use Modules\Categorization\Internal\Services\RuleEngine;
use Modules\Categorization\Internal\Services\RuleMatchInput;
use Modules\Categorization\Models\CategorizationRule;
use Modules\Core\Models\User;

// `CarbonImmutable::parse('')` is NOW, not an error. A Date condition whose
// stored value is blank therefore stopped being a date test at all and became
// "posted today" — matching a different set of rows every day it ran, and never
// the set its author wrote. normalizeCondition() rejects a blank value on the
// write path, so the way in is a row written around it: a sync, or a restore.

beforeEach(function (): void {
    $this->ruleOwner = User::query()->create([
        'username' => 'blank-date-owner',
        'password' => 'a-genuinely-long-password',
        'period_start_day' => 1,
    ]);

    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-06-15 12:00:00'));
});

afterEach(function (): void {
    CarbonImmutable::setTestNow();
});

function blankDateRule(int $userId, ?string $value, string $op = 'after', ?string $value2 = null): void
{
    $rule = CategorizationRule::query()->create([
        'user_id' => $userId,
        'priority' => 0,
        'active' => true,
        'combinator' => 'all',
        'notes' => null,
        'hits_count' => 0,
    ]);

    $rule->conditions()->create([
        'field' => 'merchant',
        'op' => $op,
        'value_type' => 'date',
        'value' => $value,
        'value2' => $value2,
    ]);

    $rule->actions()->create(['position' => 0, 'type' => 'note', 'payload' => ['note' => 'matched']]);
}

function blankDateTransaction(string $postedAt): RuleMatchInput
{
    return new RuleMatchInput(
        counterpartyName: 'Albert Heijn',
        description: 'Groceries',
        settledAmountMinor: -4990,
        postedAt: CarbonImmutable::parse($postedAt),
    );
}

// The shape of the defect: "after ''" resolved to "after today", so a row posted
// yesterday failed and a row posted tomorrow passed — on today's run only.
it('does not treat a blank date value as today', function (): void {
    blankDateRule($this->ruleOwner->id, '');

    $tomorrow = app(RuleEngine::class)->match(blankDateTransaction('2026-06-16'), $this->ruleOwner);
    $yesterday = app(RuleEngine::class)->match(blankDateTransaction('2026-06-14'), $this->ruleOwner);

    expect($tomorrow)->toBe([])
        ->and($yesterday)->toBe([]);
});

// The empty string is the only way in, and this is why: the column is NOT NULL,
// so the null the read-path coercion also guards against cannot be stored. Pinned
// so that if the column ever turns nullable, the second route is known to be open.
it('cannot store a null date value, leaving the empty string as the only way in', function (): void {
    expect(fn () => blankDateRule($this->ruleOwner->id, null))->toThrow(QueryException::class);
});

// `between` needs both ends. One usable bound is not an open-ended range — it is
// a condition its author did not write.
it('matches nothing when a between range is missing its upper bound', function (): void {
    blankDateRule($this->ruleOwner->id, '2026-01-01', 'between', '');

    expect(app(RuleEngine::class)->match(blankDateTransaction('2026-06-15'), $this->ruleOwner))->toBe([]);
});

// The other half of the contract, and the reason the guard is on emptiness
// rather than on parseability: a value that is present but unreadable still
// throws, so ReapplyRulesJob can count the row as errored and skip it instead
// of silently matching nothing. Swapping the throw for a false would hide a
// malformed rule from the operator entirely.
it('still raises on a value that is present but unreadable', function (): void {
    blankDateRule($this->ruleOwner->id, 'not-a-real-date');

    expect(fn (): array => app(RuleEngine::class)->match(blankDateTransaction('2026-06-15'), $this->ruleOwner))
        ->toThrow(InvalidFormatException::class);
});

// A real date still decides normally — the guard must not have turned every
// Date condition into a non-match.
it('still matches a date condition that carries a real value', function (): void {
    blankDateRule($this->ruleOwner->id, '2026-01-01');

    expect(app(RuleEngine::class)->match(blankDateTransaction('2026-06-15'), $this->ruleOwner))->not->toBe([]);
});
