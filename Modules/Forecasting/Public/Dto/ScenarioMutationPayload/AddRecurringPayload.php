<?php

declare(strict_types=1);

namespace Modules\Forecasting\Public\Dto\ScenarioMutationPayload;

use InvalidArgumentException;

/**
 * @see ScenarioMutationPayload
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
        // A tampered or mistyped value here would otherwise silently
        // produce zero occurrences (unknown cadence) or flip the sign
        // (typo'd direction) rather than raising loudly.
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
