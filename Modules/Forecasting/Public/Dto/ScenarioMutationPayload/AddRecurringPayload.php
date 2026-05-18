<?php

declare(strict_types=1);

namespace Modules\Forecasting\Public\Dto\ScenarioMutationPayload;

use InvalidArgumentException;

/**
 * Mutation payload: add a hypothetical recurring series for the
 * projection horizon. Carries the start date, minor-unit amount, ISO
 * 4217 currency, direction, cadence (weekly / monthly / quarterly /
 * yearly), and an optional free-text note.
 *
 * Distinct from `add_one_off` because the projector emits multiple
 * occurrences across the horizon — the cadence drives the spacing.
 *
 * The constructor validates `direction` and `cadence` against the
 * allowed string sets so a tampered or mistyped value raises a loud
 * `InvalidArgumentException` instead of silently producing zero
 * occurrences (as ScenarioApplier::applyAddRecurring did when given
 * an unknown cadence) or flipping the sign (as ::applyAddOneOff did
 * when given a typo'd direction like 'Income').
 */
final class AddRecurringPayload extends ScenarioMutationPayload
{
    public const ALLOWED_DIRECTIONS = ['expense', 'income'];

    public const ALLOWED_CADENCES = ['weekly', 'monthly', 'quarterly', 'yearly'];

    public function __construct(
        public readonly string $startDate,
        public readonly int $amountMinor,
        public readonly string $currency,
        public readonly string $direction,
        public readonly string $cadence,
        public readonly ?string $note = null,
    ) {
        if (! in_array($direction, self::ALLOWED_DIRECTIONS, true)) {
            throw new InvalidArgumentException(
                "AddRecurringPayload.direction must be one of: 'expense' | 'income'; got '{$direction}'."
            );
        }
        if (! in_array($cadence, self::ALLOWED_CADENCES, true)) {
            throw new InvalidArgumentException(
                "AddRecurringPayload.cadence must be one of: 'weekly' | 'monthly' | 'quarterly' | 'yearly'; got '{$cadence}'."
            );
        }
    }

    public function kind(): string
    {
        return 'add_recurring';
    }
}
