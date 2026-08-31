<?php

declare(strict_types=1);

namespace Modules\Forecasting\Public\Dto\ScenarioMutationPayload;

use InvalidArgumentException;
use Modules\Core\Public\Support\Lang;
use Modules\Forecasting\Public\Enums\ScenarioMutationKind;

/**
 * @see ScenarioMutationPayload
 */
final class ChangeSeriesAmountPayload extends ScenarioMutationPayload
{
    public function __construct(
        public readonly int $seriesId,
        public readonly int $newAmountMinor,
    ) {
        // The form collects a magnitude and ScenarioApplier takes abs() of it,
        // so a negative reached the curve as a POSITIVE charge under a line
        // still reading −€50.00, and a zero became a cancellation. Only the
        // dropdown checked; the sidebar form did not, so the type checks.
        if ($this->newAmountMinor <= 0) {
            throw new InvalidArgumentException(Lang::get('forecasting::scenario.errors.amount_positive'));
        }
    }

    public function kind(): string
    {
        return ScenarioMutationKind::ChangeSeriesAmount->value;
    }
}
