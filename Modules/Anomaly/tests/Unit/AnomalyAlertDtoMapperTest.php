<?php

declare(strict_types=1);

use Modules\Anomaly\Internal\Enums\AnomalyDetector;
use Modules\Anomaly\Internal\Enums\DismissedAs;
use Modules\Anomaly\Internal\Mapping\AnomalyAlertDtoMapper;
use Modules\Anomaly\Public\Dto\AnomalyAlertDto;

function anomalyRow(array $overrides = []): stdClass
{
    return (object) array_merge([
        'id' => 42,
        'transaction_id' => 7,
        'state' => 'open',
        'direction' => 'expense',
        'reasons' => json_encode(['large', 'first_time']),
        'dismissed_as' => null,
        'baseline_amount_minor' => -999,
        'latest_amount_minor' => -2349,
        'currency' => 'EUR',
        'sensitivity_percent_used' => 50,
        'snoozed_until' => null,
        'detected_at' => '2026-06-13 12:00:00',
        'actioned_at' => null,
    ], $overrides);
}

it('hydrates reasons json into a list and Money fields in the settled currency', function (): void {
    $dto = AnomalyAlertDtoMapper::hydrate(anomalyRow(), 'Spotify', 'EUR');

    expect($dto)->toBeInstanceOf(AnomalyAlertDto::class)
        ->and($dto->reasons)->toBe([AnomalyDetector::Large, AnomalyDetector::FirstTime])
        ->and($dto->displayName)->toBe('Spotify')
        ->and($dto->transactionId)->toBe(7)
        ->and($dto->currency)->toBe('EUR')
        ->and($dto->baselineAmount->toMinor())->toBe(-999)
        ->and($dto->baselineAmount->currency())->toBe('EUR')
        ->and($dto->latestAmount->toMinor())->toBe(-2349)
        ->and($dto->latestAmount->currency())->toBe('EUR')
        ->and($dto->sensitivityPercentUsed)->toBe(50)
        ->and($dto->dismissedAs)->toBeNull()
        ->and($dto->actionedAt)->toBeNull()
        ->and($dto->snoozedUntil)->toBeNull();
});

it('uses the row currency for Money even when non-EUR', function (): void {
    $dto = AnomalyAlertDtoMapper::hydrate(
        anomalyRow(['currency' => 'USD', 'latest_amount_minor' => -1299]),
        'Google Play',
        'EUR',
    );

    expect($dto->currency)->toBe('USD')
        ->and($dto->latestAmount->currency())->toBe('USD')
        ->and($dto->latestAmount->toMinor())->toBe(-1299);
});

// Zero is an amount, and hydrating the absent baseline to one let the row
// state a EUR 0.00 comparison the detector never made.
it('keeps a null baseline null rather than reading it as a zero amount', function (): void {
    $dto = AnomalyAlertDtoMapper::hydrate(
        anomalyRow(['baseline_amount_minor' => null, 'reasons' => json_encode(['first_time', 'large'])]),
        'New Merchant',
        'EUR',
    );

    expect($dto->baselineAmount)->toBeNull()
        ->and($dto->reasons)->toBe([AnomalyDetector::FirstTime, AnomalyDetector::Large]);
});

it('carries dismissedAs when the alert was dismissed as expected', function (): void {
    $dto = AnomalyAlertDtoMapper::hydrate(
        anomalyRow(['state' => 'dismissed', 'dismissed_as' => 'expected', 'actioned_at' => '2026-06-13 13:00:00']),
        'Spotify',
        'EUR',
    );

    expect($dto->state)->toBe('dismissed')
        ->and($dto->dismissedAs)->toBe(DismissedAs::Expected)
        ->and($dto->actionedAt)->not->toBeNull();
});

it('degrades a malformed reasons payload to an empty list', function (): void {
    $dto = AnomalyAlertDtoMapper::hydrate(anomalyRow(['reasons' => 'not-json']), 'Spotify', 'EUR');

    expect($dto->reasons)->toBe([]);
});

it('fails loud when detected_at is missing', function (): void {
    expect(fn () => AnomalyAlertDtoMapper::hydrate(anomalyRow(['detected_at' => null]), null, 'EUR'))
        ->toThrow(InvalidArgumentException::class);
});

it('maps each dismissal word to its case and refuses one outside the vocabulary', function (): void {
    $plain = AnomalyAlertDtoMapper::hydrate(
        anomalyRow(['state' => 'dismissed', 'dismissed_as' => 'dismissed']),
        'Spotify',
        'EUR',
    );
    $stray = AnomalyAlertDtoMapper::hydrate(
        anomalyRow(['state' => 'dismissed', 'dismissed_as' => 'not_unusual']),
        'Spotify',
        'EUR',
    );

    expect($plain->dismissedAs)->toBe(DismissedAs::Dismissed)
        ->and($stray->dismissedAs)->toBeNull();
});
