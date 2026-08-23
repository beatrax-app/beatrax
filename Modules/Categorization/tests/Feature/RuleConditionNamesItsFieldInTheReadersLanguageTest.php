<?php

declare(strict_types=1);

use Modules\Categorization\Internal\Http\Livewire\RulesPage;
use Modules\Categorization\Public\Dto\RuleConditionDto;
use Modules\Categorization\Public\Enums\ConditionOperator;
use Modules\Categorization\Public\Enums\ConditionValueType;
use Modules\Core\Public\Support\Lang;

// The /rules list prints each rule as a sentence. The operator was translated
// and the field beside it was not, so a Dutch reader got `counterparty bevat
// "Netflix"` — the column name out of the database, mid-sentence.

function conditionOn(string $field, string $valueType, string $value): RuleConditionDto
{
    return new RuleConditionDto(
        id: 1,
        field: $field,
        op: ConditionOperator::Contains->value,
        valueType: $valueType,
        value: $value,
        value2: null,
    );
}

it('names a text field in the reader language', function (string $locale): void {
    app()->setLocale($locale);

    $fragment = RulesPage::conditionFragment(
        conditionOn('counterparty', ConditionValueType::Text->value, 'Netflix'),
    );

    expect($fragment)->toStartWith(Lang::get('categorization::rule_form.field_counterparty'));
})->with(['en', 'nl', 'de']);

it('never prints the raw column name to a reader who does not read English', function (): void {
    app()->setLocale('nl');

    $fragment = RulesPage::conditionFragment(
        conditionOn('counterparty', ConditionValueType::Text->value, 'Netflix'),
    );

    expect($fragment)->not->toContain('counterparty');
});

it('names an amount condition in the reader language too', function (): void {
    app()->setLocale('nl');

    $fragment = RulesPage::conditionFragment(
        conditionOn('amount', ConditionValueType::Amount->value, '1000'),
    );

    expect($fragment)->toStartWith(Lang::get('categorization::rule_form.field_amount'))
        ->and($fragment)->not->toStartWith('amount');
});
