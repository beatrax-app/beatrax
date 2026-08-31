<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Categorization\Internal\Services\RuleEngine;
use Modules\Categorization\Internal\Services\RuleMatchInput;
use Modules\Categorization\Models\CategorizationRule;
use Modules\Core\Models\User;
use Modules\Ledger\Public\Enums\Currency;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->user = User::query()->create([
        'username' => 'amount-rule-currency',
        'password' => 'fixture-password',
        'period_start_day' => 1,
        'base_currency' => Currency::Eur->value,
    ]);

    $this->engine = $this->app->make(RuleEngine::class);

    $rule = CategorizationRule::query()->create([
        'user_id' => $this->user->id,
        'priority' => 0,
        'active' => true,
        'combinator' => 'all',
        'notes' => null,
        'hits_count' => 0,
    ]);
    $rule->conditions()->create([
        'field' => 'merchant',
        'op' => '>',
        'value_type' => 'amount',
        'value' => '5000',
        'value2' => null,
    ]);
    $rule->actions()->create(['position' => 0, 'type' => 'category', 'payload' => ['category_id' => 1]]);
});

function amountRuleInput(int $settledAmountMinor, string $settledCurrency): RuleMatchInput
{
    return new RuleMatchInput(
        counterpartyName: 'JR East',
        description: 'JR EAST TOKYO STATION',
        settledAmountMinor: $settledAmountMinor,
        settledCurrency: $settledCurrency,
        postedAt: CarbonImmutable::parse('2026-02-01'),
    );
}

it('fires an amount rule on a row in the currency it was written in', function (): void {
    expect($this->engine->match(amountRuleInput(6000, Currency::Eur->value), $this->user))->toHaveCount(1);
});

it('leaves an amount rule alone on a row denominated in another currency', function (): void {
    // ¥13,840 is about €87, but 13840 minor yen against a €50.00 bound is a
    // comparison of two different quantities.
    expect($this->engine->match(amountRuleInput(13840, Currency::Jpy->value), $this->user))->toHaveCount(0);
});
