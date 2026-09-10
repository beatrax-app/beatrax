<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Modules\Categorization\Internal\Services\RuleEngine;
use Modules\Categorization\Internal\Services\RuleMatchInput;
use Modules\Categorization\Models\CategorizationRule;
use Modules\Core\Models\User;

// `(int) ''` is 0, so an Amount condition whose stored bound is blank stopped
// being an amount test and became a comparison against nothing: "> ''" fired on
// every income row the reader owns. The sibling branches already refuse theirs
// — a blank Text value never matches, and a blank Date value matches nothing —
// and this one did not. normalizeCondition() rejects a blank on the write path,
// so the way in is a row written around it: a sync, or a restore.

beforeEach(function (): void {
    $this->amountRuleOwner = User::query()->create([
        'username' => 'blank-amount-owner',
        'password' => 'a-genuinely-long-password',
        'period_start_day' => 1,
    ]);
});

function blankAmountRule(int $userId, string $value, string $op = '>', ?string $value2 = null): void
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
        'value_type' => 'amount',
        'value' => $value,
        'value2' => $value2,
    ]);

    $rule->actions()->create(['position' => 0, 'type' => 'note', 'payload' => ['note' => 'matched']]);
}

function blankAmountTransaction(int $minor): RuleMatchInput
{
    return new RuleMatchInput(
        counterpartyName: 'Albert Heijn',
        description: 'Groceries',
        settledAmountMinor: $minor,
        settledCurrency: 'EUR',
        postedAt: CarbonImmutable::parse('2026-06-15'),
    );
}

// The shape of the defect: a bound of nothing became a bound of zero, so the
// rule claimed every row on the credit side of it.
it('does not treat a blank amount bound as zero', function (string $op): void {
    blankAmountRule($this->amountRuleOwner->id, '', $op);

    $income = app(RuleEngine::class)->match(blankAmountTransaction(120_00), $this->amountRuleOwner);
    $expense = app(RuleEngine::class)->match(blankAmountTransaction(-49_90), $this->amountRuleOwner);

    expect($income)->toBe([])
        ->and($expense)->toBe([]);
})->with(['>', '<', 'equals']);

// The coercion was never about emptiness: any stored value the parser cannot
// read as a number reached the comparison as 0, and a rule form is not the only
// writer of this column.
it('does not treat a bound that is not a number as zero', function (string $stored): void {
    blankAmountRule($this->amountRuleOwner->id, $stored);

    expect(app(RuleEngine::class)->match(blankAmountTransaction(120_00), $this->amountRuleOwner))->toBe([]);
})->with(['not-a-number', '12,50', 'EUR 50', ' ']);

// `between` needs both ends, the same way the date branch does. One usable
// bound is not an open-ended range — it is a condition its author did not write.
it('matches nothing when a between range is missing an end', function (string $lo, string $hi): void {
    blankAmountRule($this->amountRuleOwner->id, $lo, 'between', $hi);

    expect(app(RuleEngine::class)->match(blankAmountTransaction(50_00), $this->amountRuleOwner))->toBe([]);
})->with([['', '100'], ['10', ''], ['10', 'not-a-number']]);

// A real bound still decides normally — the guard must not have turned every
// Amount condition into a non-match.
it('still matches an amount condition that carries a real bound', function (): void {
    blankAmountRule($this->amountRuleOwner->id, '1000');

    expect(app(RuleEngine::class)->match(blankAmountTransaction(120_00), $this->amountRuleOwner))->not->toBe([]);
});

it('still matches a real between range', function (): void {
    blankAmountRule($this->amountRuleOwner->id, '1000', 'between', '10000');

    expect(app(RuleEngine::class)->match(blankAmountTransaction(50_00), $this->amountRuleOwner))->not->toBe([]);
});
