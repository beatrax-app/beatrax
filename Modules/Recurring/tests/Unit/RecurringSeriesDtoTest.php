<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Modules\Ledger\Public\ValueObjects\Money;
use Modules\Recurring\Public\Contracts\SeriesDetector;
use Modules\Recurring\Public\Dto\RecurringOccurrenceDto;
use Modules\Recurring\Public\Dto\RecurringSeriesAmountTrendDto;
use Modules\Recurring\Public\Dto\RecurringSeriesDto;
use Modules\Recurring\Public\Enums\SeriesCadence;
use Modules\Recurring\Public\Events\RecurringSeriesApproved;
use Modules\Recurring\Public\Events\RecurringSeriesCadenceFlipped;
use Modules\Recurring\Public\Events\RecurringSeriesDetected;
use Modules\Recurring\Public\Events\RecurringSeriesRejected;

it('constructs RecurringSeriesDto with Money and CarbonImmutable props and the displayName helper falls back', function (): void {
    $dto = new RecurringSeriesDto(
        seriesId: 42,
        direction: 'expense',
        detectedName: 'Netflix',
        displayNameOverride: null,
        state: 'approved',
        cadence: SeriesCadence::Monthly,
        latestAmount: Money::ofMinor(-1099, 'EUR'),
        eurEquivalent: null,
        monthlyEquivalent: Money::ofMinor(-1099, 'EUR'),
        latestFundingChainLinkId: null,
        nextExpectedAt: CarbonImmutable::parse('2026-06-05'),
        nextExpectedConfidenceLow: false,
        varianceTolerancePercent: 25,
        snoozedUntil: null,
    );

    expect($dto->seriesId)->toBe(42);
    expect($dto->latestAmount->toMinor())->toBe(-1099);
    expect($dto->latestAmount->currency())->toBe('EUR');
    expect($dto->displayName())->toBe('Netflix');
});

it('returns displayNameOverride from displayName when present', function (): void {
    $dto = new RecurringSeriesDto(
        seriesId: 1,
        direction: 'expense',
        detectedName: 'Some merchant',
        displayNameOverride: 'Netflix',
        state: 'approved',
        cadence: SeriesCadence::Monthly,
        latestAmount: Money::ofMinor(-1099, 'EUR'),
        eurEquivalent: null,
        monthlyEquivalent: Money::ofMinor(-1099, 'EUR'),
        latestFundingChainLinkId: null,
        nextExpectedAt: null,
        nextExpectedConfidenceLow: false,
        varianceTolerancePercent: 25,
        snoozedUntil: null,
    );

    expect($dto->displayName())->toBe('Netflix');
});

it('carries an EUR equivalent when the original currency is not EUR', function (): void {
    $dto = new RecurringSeriesDto(
        seriesId: 7,
        direction: 'expense',
        detectedName: 'Google Play',
        displayNameOverride: null,
        state: 'approved',
        cadence: SeriesCadence::Monthly,
        latestAmount: Money::ofMinor(-499, 'USD'),
        eurEquivalent: Money::ofMinor(-460, 'EUR'),
        monthlyEquivalent: Money::ofMinor(-460, 'EUR'),
        latestFundingChainLinkId: 12,
        nextExpectedAt: CarbonImmutable::parse('2026-06-12'),
        nextExpectedConfidenceLow: true,
        varianceTolerancePercent: 25,
        snoozedUntil: null,
    );

    expect($dto->latestAmount->currency())->toBe('USD');
    expect($dto->eurEquivalent?->currency())->toBe('EUR');
    expect($dto->eurEquivalent?->toMinor())->toBe(-460);
    expect($dto->latestFundingChainLinkId)->toBe(12);
    expect($dto->nextExpectedConfidenceLow)->toBeTrue();
});

it('constructs RecurringOccurrenceDto with the documented props', function (): void {
    $dto = new RecurringOccurrenceDto(
        occurrenceId: 9,
        recurringSeriesId: 42,
        transactionId: 200,
        observedAt: CarbonImmutable::parse('2026-05-15'),
        observedAmount: Money::ofMinor(-1099, 'EUR'),
        observedCurrency: 'EUR',
    );

    expect($dto->occurrenceId)->toBe(9);
    expect($dto->recurringSeriesId)->toBe(42);
    expect($dto->observedAmount->toMinor())->toBe(-1099);
    expect($dto->observedAt->toDateString())->toBe('2026-05-15');
});

it('constructs RecurringSeriesAmountTrendDto with a typed points list', function (): void {
    $dto = new RecurringSeriesAmountTrendDto(
        seriesId: 42,
        currency: 'EUR',
        points: [
            ['date' => '2026-01-15', 'amount_minor' => -999, 'eur_amount_minor' => null],
            ['date' => '2026-02-15', 'amount_minor' => -1099, 'eur_amount_minor' => null],
        ],
        maxPoints: 24,
    );

    expect($dto->points)->toHaveCount(2);
    expect($dto->currency)->toBe('EUR');
    expect($dto->maxPoints)->toBe(24);
    expect($dto->points[0]['amount_minor'])->toBe(-999);
});

it('declares SeriesDetector as a Public interface', function (): void {
    $reflection = new ReflectionClass(SeriesDetector::class);

    expect($reflection->isInterface())->toBeTrue();
    expect($reflection->hasMethod('detectForUser'))->toBeTrue();
});

it('declares the four Public events as final readonly classes', function (): void {
    foreach ([
        RecurringSeriesDetected::class,
        RecurringSeriesApproved::class,
        RecurringSeriesRejected::class,
        RecurringSeriesCadenceFlipped::class,
    ] as $eventClass) {
        $reflection = new ReflectionClass($eventClass);
        expect($reflection->isFinal())->toBeTrue();
        expect($reflection->isReadOnly())->toBeTrue();
    }
});

it('carries the locked event payloads', function (): void {
    $detected = new RecurringSeriesDetected(
        seriesId: 1,
        userId: 5,
        direction: 'expense',
        detectedName: 'Netflix',
        cadence: 'monthly',
    );
    $approved = new RecurringSeriesApproved(seriesId: 2, userId: 5);
    $rejected = new RecurringSeriesRejected(seriesId: 3, userId: 5);
    $flipped = new RecurringSeriesCadenceFlipped(
        seriesId: 4,
        userId: 5,
        oldCadence: 'monthly',
        newCadence: 'irregular',
    );

    expect($detected->detectedName)->toBe('Netflix');
    expect($detected->cadence)->toBe('monthly');
    expect($approved->userId)->toBe(5);
    expect($rejected->seriesId)->toBe(3);
    expect($flipped->oldCadence)->toBe('monthly');
    expect($flipped->newCadence)->toBe('irregular');
});
