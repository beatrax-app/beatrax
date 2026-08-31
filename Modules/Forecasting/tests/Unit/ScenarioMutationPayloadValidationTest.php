<?php

declare(strict_types=1);

use Modules\Forecasting\Public\Dto\ScenarioMutationPayload\AddOneOffPayload;
use Modules\Forecasting\Public\Dto\ScenarioMutationPayload\AddRecurringPayload;
use Modules\Forecasting\Public\Dto\ScenarioMutationPayload\ShiftSeriesDatePayload;

// Validation sits in the constructor so the typed JSON cast, which calls it on
// read, catches a corrupt row rather than mis-rendering it.

it('AddOneOffPayload accepts a valid direction', function (string $direction): void {
    $payload = new AddOneOffPayload(
        date: '2026-06-01',
        amountMinor: -1000,
        currency: 'EUR',
        direction: $direction,
    );
    expect($payload->direction)->toBe($direction);
})->with(['expense', 'income']);

it('AddOneOffPayload rejects an unknown direction', function (): void {
    expect(fn () => new AddOneOffPayload(
        date: '2026-06-01',
        amountMinor: -1000,
        currency: 'EUR',
        direction: 'Income', // capitalized — would silently mean 'expense' downstream
    ))->toThrow(InvalidArgumentException::class, "must be one of: 'expense' | 'income'");
});

it('AddOneOffPayload rejects an empty direction', function (): void {
    expect(fn () => new AddOneOffPayload(
        date: '2026-06-01',
        amountMinor: -1000,
        currency: 'EUR',
        direction: '',
    ))->toThrow(InvalidArgumentException::class);
});

it('AddRecurringPayload accepts every documented cadence', function (string $cadence): void {
    $payload = new AddRecurringPayload(
        startDate: '2026-06-01',
        amountMinor: -1000,
        currency: 'EUR',
        direction: 'expense',
        cadence: $cadence,
    );
    expect($payload->cadence)->toBe($cadence);
})->with(['weekly', 'monthly', 'quarterly', 'yearly']);

it('AddRecurringPayload rejects an unknown cadence', function (): void {
    expect(fn () => new AddRecurringPayload(
        startDate: '2026-06-01',
        amountMinor: -1000,
        currency: 'EUR',
        direction: 'expense',
        cadence: 'biweekly',
    ))->toThrow(InvalidArgumentException::class, "must be one of: 'weekly' | 'monthly' | 'quarterly' | 'yearly'");
});

it('AddRecurringPayload rejects an unknown direction even with a valid cadence', function (): void {
    expect(fn () => new AddRecurringPayload(
        startDate: '2026-06-01',
        amountMinor: -1000,
        currency: 'EUR',
        direction: 'credit',
        cadence: 'monthly',
    ))->toThrow(InvalidArgumentException::class, "must be one of: 'expense' | 'income'");
});

it('ShiftSeriesDatePayload accepts a valid scope', function (string $scope): void {
    $payload = new ShiftSeriesDatePayload(
        seriesId: 7,
        newNextDate: '2026-06-15',
        scope: $scope,
    );
    expect($payload->scope)->toBe($scope);
})->with(['next', 'all_subsequent']);

it('ShiftSeriesDatePayload rejects an unknown scope', function (): void {
    expect(fn () => new ShiftSeriesDatePayload(
        seriesId: 7,
        newNextDate: '2026-06-15',
        scope: 'next_only', // typo — would silently fall through to 'next' downstream
    ))->toThrow(InvalidArgumentException::class, "must be one of: 'next' | 'all_subsequent'");
});

it('AddOneOffPayload folds a lower-case currency to its ISO-4217 spelling', function (): void {
    $payload = new AddOneOffPayload(
        date: '2026-06-01',
        amountMinor: -1000,
        currency: 'usd',
        direction: 'expense',
    );

    expect($payload->currency)->toBe('USD');
});

it('AddOneOffPayload rejects a currency code that is not a currency', function (): void {
    // Unchecked this reached DailyFold, which raises on a code it cannot
    // convert — and the run it raises in is the whole projection.
    expect(fn () => new AddOneOffPayload(
        date: '2026-06-01',
        amountMinor: -1000,
        currency: 'ZZZ',
        direction: 'expense',
    ))->toThrow(InvalidArgumentException::class, 'must be an ISO-4217 code');
});

it('AddRecurringPayload folds a lower-case currency to its ISO-4217 spelling', function (): void {
    $payload = new AddRecurringPayload(
        startDate: '2026-06-01',
        amountMinor: -1000,
        currency: 'gbp',
        direction: 'expense',
        cadence: 'monthly',
    );

    expect($payload->currency)->toBe('GBP');
});

it('AddRecurringPayload rejects a currency code that is not a currency', function (): void {
    expect(fn () => new AddRecurringPayload(
        startDate: '2026-06-01',
        amountMinor: -1000,
        currency: 'ZZZ',
        direction: 'expense',
        cadence: 'monthly',
    ))->toThrow(InvalidArgumentException::class, 'must be an ISO-4217 code');
});
