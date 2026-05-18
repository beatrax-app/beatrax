<?php

declare(strict_types=1);

namespace Modules\Forecasting\Public\Dto\ScenarioMutationPayload;

/**
 * Mutation payload: add a single hypothetical charge or credit at a
 * specific date inside the projection horizon. Carries the date,
 * minor-unit amount, ISO 4217 currency, direction (expense or income),
 * and an optional free-text note for the mutation summary.
 */
final class AddOneOffPayload extends ScenarioMutationPayload
{
    public function __construct(
        public readonly string $date,
        public readonly int $amountMinor,
        public readonly string $currency,
        public readonly string $direction,
        public readonly ?string $note = null,
    ) {}

    public function kind(): string
    {
        return 'add_one_off';
    }
}
