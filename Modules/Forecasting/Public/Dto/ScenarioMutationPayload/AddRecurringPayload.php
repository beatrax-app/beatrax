<?php

declare(strict_types=1);

namespace Modules\Forecasting\Public\Dto\ScenarioMutationPayload;

/**
 * Mutation payload: add a hypothetical recurring series for the
 * projection horizon. Carries the start date, minor-unit amount, ISO
 * 4217 currency, direction, cadence (weekly / monthly / quarterly /
 * yearly), and an optional free-text note.
 *
 * Distinct from `add_one_off` because the projector emits multiple
 * occurrences across the horizon — the cadence drives the spacing.
 */
final class AddRecurringPayload extends ScenarioMutationPayload
{
    public function __construct(
        public readonly string $startDate,
        public readonly int $amountMinor,
        public readonly string $currency,
        public readonly string $direction,
        public readonly string $cadence,
        public readonly ?string $note = null,
    ) {}

    public function kind(): string
    {
        return 'add_recurring';
    }
}
