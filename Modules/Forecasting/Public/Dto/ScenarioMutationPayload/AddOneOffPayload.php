<?php

declare(strict_types=1);

namespace Modules\Forecasting\Public\Dto\ScenarioMutationPayload;

use InvalidArgumentException;

/**
 * Mutation payload: add a single hypothetical charge or credit at a
 * specific date inside the projection horizon. Carries the date,
 * minor-unit amount, ISO 4217 currency, direction (expense or income),
 * and an optional free-text note for the mutation summary.
 *
 * The constructor validates `direction` is one of the two allowed
 * literals; the typed JSON cast on read invokes the constructor, so a
 * corrupted DB row surfaces as a loud `InvalidArgumentException`
 * rather than silently flipping an income mutation into an expense
 * (the downstream ScenarioApplier treats any non-'income' value as
 * expense via a sign flip).
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
