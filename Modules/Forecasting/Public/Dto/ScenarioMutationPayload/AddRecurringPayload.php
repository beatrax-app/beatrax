<?php

declare(strict_types=1);

namespace Modules\Forecasting\Public\Dto\ScenarioMutationPayload;

use InvalidArgumentException;
use Modules\Forecasting\Public\Enums\ScenarioMutationKind;
use Modules\Ledger\Public\Enums\Direction;
use Modules\Recurring\Public\Enums\SeriesCadence;

/**
 * @see ScenarioMutationPayload
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
    ) {
        // A tampered or mistyped value here would otherwise silently
        // produce zero occurrences (unknown cadence) or flip the sign
        // (typo'd direction) rather than raising loudly.
        if (Direction::tryFrom($direction) === null) {
            throw new InvalidArgumentException(
                'AddRecurringPayload.direction must be one of: '.implode(' | ', array_map(static fn (Direction $d): string => "'".$d->value."'", Direction::cases()))."; got '{$direction}'."
            );
        }
        $cadenceEnum = SeriesCadence::tryFrom($cadence);
        if ($cadenceEnum === null || $cadenceEnum === SeriesCadence::Irregular) {
            // A scenario add-recurring is a regular series; Irregular (which
            // SeriesCadence carries for detected series) is not a choice here.
            $valid = array_map(
                static fn (SeriesCadence $c): string => "'".$c->value."'",
                array_filter(SeriesCadence::cases(), static fn (SeriesCadence $c): bool => $c !== SeriesCadence::Irregular),
            );
            throw new InvalidArgumentException(
                'AddRecurringPayload.cadence must be one of: '.implode(' | ', $valid)."; got '{$cadence}'."
            );
        }
    }

    public function kind(): string
    {
        return ScenarioMutationKind::AddRecurring->value;
    }
}
