<?php

declare(strict_types=1);

namespace Modules\Forecasting\Public\Dto\ScenarioMutationPayload;

use InvalidArgumentException;
use Modules\Forecasting\Public\Enums\ScenarioMutationKind;
use Modules\Ledger\Public\Enums\Direction;

/**
 * @see ScenarioMutationPayload
 */
final class AddOneOffPayload extends ScenarioMutationPayload
{
    public function __construct(
        public readonly string $date,
        public readonly int $amountMinor,
        public readonly string $currency,
        public readonly string $direction,
        public readonly ?string $note = null,
    ) {
        // ScenarioApplier reads any non-'income' direction as an expense sign flip,
        // so a corrupted row has to raise here rather than change the sign.
        if (Direction::tryFrom($direction) === null) {
            throw new InvalidArgumentException(
                'AddOneOffPayload.direction must be one of: '.implode(' | ', array_map(static fn (Direction $d): string => "'".$d->value."'", Direction::cases()))."; got '{$direction}'."
            );
        }
    }

    public function kind(): string
    {
        return ScenarioMutationKind::AddOneOff->value;
    }
}
