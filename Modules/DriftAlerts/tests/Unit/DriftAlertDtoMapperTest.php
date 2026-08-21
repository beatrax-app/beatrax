<?php

declare(strict_types=1);

use Modules\DriftAlerts\Internal\Mapping\DriftAlertDtoMapper;

// The schema guarantees detected_at is non-null, so these rows can only come
// from corruption; the mapper must name the row id rather than let
// CarbonImmutable::parse('') raise an anonymous InvalidFormatException.

function damtRow(array $overrides = []): stdClass
{
    $defaults = [
        'id' => 42,
        'recurring_series_id' => 1,
        'direction' => 'expense',
        'state' => 'open',
        'baseline_amount_minor' => -999,
        'latest_amount_minor' => -1149,
        'currency' => 'EUR',
        'delta_minor' => -150,
        'annualized_impact_minor' => -1800,
        'threshold_percent_used' => 5,
        'threshold_source' => 'default',
        'detected_at' => '2026-05-19 12:00:00',
        'snoozed_until' => null,
        'actioned_at' => null,
    ];

    return (object) array_merge($defaults, $overrides);
}

it('raises InvalidArgumentException with the row id when detected_at is null', function (): void {
    DriftAlertDtoMapper::hydrate(damtRow(['detected_at' => null]));
})->throws(InvalidArgumentException::class, 'drift_alerts row 42 has missing or non-string detected_at');

it('raises InvalidArgumentException with the row id when detected_at is empty string', function (): void {
    DriftAlertDtoMapper::hydrate(damtRow(['detected_at' => '']));
})->throws(InvalidArgumentException::class, 'drift_alerts row 42 has missing or non-string detected_at');

it('hydrates successfully when detected_at is a valid timestamp string', function (): void {
    $dto = DriftAlertDtoMapper::hydrate(damtRow());

    expect($dto->driftAlertId)->toBe(42);
    expect($dto->detectedAt->toDateTimeString())->toBe('2026-05-19 12:00:00');
});
