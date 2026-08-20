<?php

declare(strict_types=1);

namespace Modules\Forecasting\Internal\Http\Livewire\Concerns;

use Modules\Core\Public\Support\Lang;
use Modules\Forecasting\Public\Dto\ScenarioMutationPayload\AddOneOffPayload;
use Modules\Forecasting\Public\Dto\ScenarioMutationPayload\AddRecurringPayload;
use Modules\Forecasting\Public\Dto\ScenarioMutationPayload\CancelSeriesPayload;
use Modules\Forecasting\Public\Dto\ScenarioMutationPayload\ChangeSeriesAmountPayload;
use Modules\Forecasting\Public\Dto\ScenarioMutationPayload\ScenarioMutationPayload;
use Modules\Forecasting\Public\Dto\ScenarioMutationPayload\ShiftSeriesDatePayload;
use Modules\Forecasting\Public\Enums\ShiftScope;

// Human-readable one-line summaries for each scenario mutation kind, shown
// in the sidebar's mutation list. Kept beside ScenarioEditorSidebar rather
// than inside it so the component stays under the method ceiling.
trait SummarisesMutations
{
    private function summaryFor(string $kind, ScenarioMutationPayload $payload): string
    {
        return match (true) {
            $payload instanceof CancelSeriesPayload => Lang::get('forecasting::scenario.summary.cancel', ['name' => $this->resolveSeriesName($payload->seriesId)]),
            $payload instanceof AddOneOffPayload => $this->summariseOneOff($payload),
            $payload instanceof AddRecurringPayload => $this->summariseRecurring($payload),
            $payload instanceof ChangeSeriesAmountPayload => $this->summariseChangeAmount($payload),
            $payload instanceof ShiftSeriesDatePayload => $this->summariseShiftDate($payload),
            default => $kind,
        };
    }

    // Resolve the series display name from the catalog already populated by
    // render(); fall back to "series #N" only when the series is missing
    // from the catalog (e.g. deleted or filtered).
    private function resolveSeriesName(int $seriesId): string
    {
        foreach ($this->availableSeries as $entry) {
            if ($entry['id'] === $seriesId && $entry['name'] !== '') {
                return $entry['name'];
            }
        }

        return Lang::get('forecasting::scenario.summary.series_fallback', ['id' => $seriesId]);
    }

    private function summariseOneOff(AddOneOffPayload $payload): string
    {
        $sign = $payload->direction === 'income' ? '+' : '−';
        $amount = number_format($payload->amountMinor / 100, 2, ',', '.');

        return Lang::get('forecasting::scenario.summary.one_off', [
            'amount' => $sign.$amount,
            'currency' => $payload->currency,
            'date' => $payload->date,
        ]);
    }

    private function summariseRecurring(AddRecurringPayload $payload): string
    {
        $sign = $payload->direction === 'income' ? '+' : '−';
        $amount = number_format($payload->amountMinor / 100, 2, ',', '.');

        return Lang::get('forecasting::scenario.summary.recurring', [
            'amount' => $sign.$amount,
            'currency' => $payload->currency,
            'cadence' => $payload->cadence,
            'date' => $payload->startDate,
        ]);
    }

    private function summariseChangeAmount(ChangeSeriesAmountPayload $payload): string
    {
        $amount = number_format($payload->newAmountMinor / 100, 2, ',', '.');

        return Lang::get('forecasting::scenario.summary.change_amount', [
            'name' => $this->resolveSeriesName($payload->seriesId),
            'amount' => $amount,
        ]);
    }

    private function summariseShiftDate(ShiftSeriesDatePayload $payload): string
    {
        $scope = $payload->scope === ShiftScope::AllSubsequent->value
            ? Lang::get('forecasting::scenario.summary.scope_all')
            : Lang::get('forecasting::scenario.summary.scope_next');

        return Lang::get('forecasting::scenario.summary.shift', [
            'name' => $this->resolveSeriesName($payload->seriesId),
            'scope' => $scope,
            'date' => $payload->newNextDate,
        ]);
    }
}
