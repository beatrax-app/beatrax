<?php

declare(strict_types=1);

namespace Modules\Forecasting\Public\Dto\ScenarioMutationPayload;

use InvalidArgumentException;

/**
 * @see ScenarioMutationPayload
 */
final class AddOneOffPayload extends ScenarioMutationPayload
{
    public const ALLOWED_DIRECTIONS = ['expense', 'income'];

    public function __construct(
        public readonly string $date,
        public readonly int $amountMinor,
        public readonly string $currency,
        public readonly string $direction,
        public readonly ?string $note = null,
    ) {
        // The typed JSON cast invokes this constructor on read, so a
        // corrupted DB row raises here rather than silently flipping an
        // income mutation into an expense (ScenarioApplier treats any
        // non-'income' value as expense via a sign flip).
        if (! in_array($direction, self::ALLOWED_DIRECTIONS, true)) {
            throw new InvalidArgumentException(
                "AddOneOffPayload.direction must be one of: 'expense' | 'income'; got '{$direction}'."
            );
        }
    }

    public function kind(): string
    {
        return 'add_one_off';
    }
}
