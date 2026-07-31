<?php

declare(strict_types=1);

namespace Modules\Forecasting\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Modules\Forecasting\Models\ForecastScenarioMutation;
use Modules\Forecasting\Public\Dto\ScenarioMutationPayload\AddOneOffPayload;
use Modules\Forecasting\Public\Dto\ScenarioMutationPayload\AddRecurringPayload;
use Modules\Forecasting\Public\Dto\ScenarioMutationPayload\CancelSeriesPayload;
use Modules\Forecasting\Public\Dto\ScenarioMutationPayload\ChangeSeriesAmountPayload;
use Modules\Forecasting\Public\Dto\ScenarioMutationPayload\ShiftSeriesDatePayload;
use Modules\Forecasting\Public\Enums\ScenarioMutationKind;

/**
 * @extends Factory<ForecastScenarioMutation>
 */
final class ForecastScenarioMutationFactory extends Factory
{
    /** @var class-string<ForecastScenarioMutation> */
    protected $model = ForecastScenarioMutation::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'user_id' => null,
            'forecast_scenario_id' => null,
            'kind' => ScenarioMutationKind::CancelSeries->value,
            'target_series_id' => 42,
            'payload' => new CancelSeriesPayload(seriesId: 42),
        ];
    }

    public function cancelSeries(int $seriesId): self
    {
        return $this->state(fn (array $attributes): array => [
            'kind' => ScenarioMutationKind::CancelSeries->value,
            'target_series_id' => $seriesId,
            'payload' => new CancelSeriesPayload(seriesId: $seriesId),
        ]);
    }

    public function addOneOff(string $date, int $amountMinor, string $currency, string $direction): self
    {
        return $this->state(fn (array $attributes): array => [
            'kind' => ScenarioMutationKind::AddOneOff->value,
            'target_series_id' => null,
            'payload' => new AddOneOffPayload(
                date: $date,
                amountMinor: $amountMinor,
                currency: $currency,
                direction: $direction,
            ),
        ]);
    }

    public function addRecurring(
        string $startDate,
        int $amountMinor,
        string $currency,
        string $direction,
        string $cadence,
    ): self {
        return $this->state(fn (array $attributes): array => [
            'kind' => ScenarioMutationKind::AddRecurring->value,
            'target_series_id' => null,
            'payload' => new AddRecurringPayload(
                startDate: $startDate,
                amountMinor: $amountMinor,
                currency: $currency,
                direction: $direction,
                cadence: $cadence,
            ),
        ]);
    }

    public function changeSeriesAmount(int $seriesId, int $newAmountMinor): self
    {
        return $this->state(fn (array $attributes): array => [
            'kind' => ScenarioMutationKind::ChangeSeriesAmount->value,
            'target_series_id' => $seriesId,
            'payload' => new ChangeSeriesAmountPayload(
                seriesId: $seriesId,
                newAmountMinor: $newAmountMinor,
            ),
        ]);
    }

    public function shiftSeriesDate(int $seriesId, string $newNextDate, string $scope): self
    {
        return $this->state(fn (array $attributes): array => [
            'kind' => ScenarioMutationKind::ShiftSeriesDate->value,
            'target_series_id' => $seriesId,
            'payload' => new ShiftSeriesDatePayload(
                seriesId: $seriesId,
                newNextDate: $newNextDate,
                scope: $scope,
            ),
        ]);
    }
}
