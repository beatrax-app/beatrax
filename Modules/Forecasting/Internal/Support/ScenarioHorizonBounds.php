<?php

declare(strict_types=1);

namespace Modules\Forecasting\Internal\Support;

use InvalidArgumentException;
use Modules\Core\Public\Contracts\Clock;
use Modules\Core\Public\Support\Lang;
use Modules\Core\Public\Support\SafeDate;
use Modules\Forecasting\Public\Dto\ScenarioMutationPayload\AddOneOffPayload;
use Modules\Forecasting\Public\Dto\ScenarioMutationPayload\AddRecurringPayload;
use Modules\Forecasting\Public\Dto\ScenarioMutationPayload\ScenarioMutationPayload;
use Modules\Forecasting\Public\Dto\ScenarioMutationPayload\ShiftSeriesDatePayload;
use Modules\Forecasting\Public\Enums\ForecastHorizon;

// A mutation whose date no horizon reaches is saved, listed beside the ones
// that work, and changes nothing at 30, 60, 90, 180 or 365 days. Refusing it at
// write time is the only outcome that tells the reader why.
final readonly class ScenarioHorizonBounds
{
    public function __construct(private Clock $clock) {}

    /** @throws InvalidArgumentException */
    public function assertReachable(ScenarioMutationPayload $payload): void
    {
        $reach = self::reachOf($payload);
        if ($reach === null) {
            return;
        }
        [$raw, $boundedBelow] = $reach;

        $date = SafeDate::dayOrNull($raw);
        if ($date === null) {
            return;
        }

        $today = $this->clock->now()->startOfDay();
        $lastDay = ForecastHorizon::longestDays();

        if ($date->greaterThan($today->addDays($lastDay)) || ($boundedBelow && $date->lessThan($today))) {
            throw new InvalidArgumentException(
                Lang::choice('forecasting::scenario.errors.date_out_of_range', $lastDay, ['days' => $lastDay]),
            );
        }
    }

    // The date the mutation places money on, and whether today bounds it from
    // below. A recurring START may sit behind today — the occurrence walk steps
    // over the past ones and the later ones still land on the curve.
    /**
     * @return array{0: string, 1: bool}|null null for a kind that places no date
     */
    private static function reachOf(ScenarioMutationPayload $payload): ?array
    {
        return match (true) {
            $payload instanceof AddOneOffPayload => [$payload->date, true],
            $payload instanceof AddRecurringPayload => [$payload->startDate, false],
            $payload instanceof ShiftSeriesDatePayload => [$payload->newNextDate, true],
            default => null,
        };
    }
}
