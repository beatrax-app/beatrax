<?php

declare(strict_types=1);

use Modules\Counterparties\Internal\Enums\CounterpartyTypeFilter;
use Modules\Counterparties\Public\Enums\CounterpartyType;

it('matches every row for the all chip rather than any stored value', function (): void {
    expect(CounterpartyTypeFilter::All->toColumnValue())->toBeNull();
});

it('spells the self chip self and the column it filters self_account', function (): void {
    expect(CounterpartyTypeFilter::SelfAccount->value)->toBe('self');
    expect(CounterpartyTypeFilter::SelfAccount->toColumnValue())->toBe(CounterpartyType::SelfAccount);
    expect(CounterpartyType::SelfAccount->value)->toBe('self_account');
});

it('carries one chip per stored type', function (): void {
    $columns = array_values(array_filter(array_map(
        static fn (CounterpartyTypeFilter $filter): ?CounterpartyType => $filter->toColumnValue(),
        CounterpartyTypeFilter::cases(),
    )));

    expect($columns)->toBe(CounterpartyType::cases());
});

it('round-trips every stored type back to the chip that shows it', function (): void {
    foreach (CounterpartyType::cases() as $type) {
        expect(CounterpartyTypeFilter::forColumnValue($type)->toColumnValue())->toBe($type);
    }
});

it('reads back every chip spelling the index page emits', function (): void {
    $values = array_map(
        static fn (CounterpartyTypeFilter $filter): string => $filter->value,
        CounterpartyTypeFilter::cases(),
    );

    expect($values)->toBe(['all', 'merchant', 'personal', 'bank', 'government', 'self', 'unknown']);
});
