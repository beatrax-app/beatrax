<?php

declare(strict_types=1);

use Modules\Forecasting\Public\Dto\ScenarioMutationPayload\ChangeSeriesAmountPayload;

it('refuses a negative new amount, which the curve would have drawn as a charge', function (): void {
    expect(fn (): ChangeSeriesAmountPayload => new ChangeSeriesAmountPayload(seriesId: 7, newAmountMinor: -5000))
        ->toThrow(InvalidArgumentException::class);
});

it('refuses a zero new amount rather than letting it read as a cancellation', function (): void {
    expect(fn (): ChangeSeriesAmountPayload => new ChangeSeriesAmountPayload(seriesId: 7, newAmountMinor: 0))
        ->toThrow(InvalidArgumentException::class);
});

it('accepts the magnitude the form is asking for', function (): void {
    expect((new ChangeSeriesAmountPayload(seriesId: 7, newAmountMinor: 5000))->newAmountMinor)->toBe(5000);
});
