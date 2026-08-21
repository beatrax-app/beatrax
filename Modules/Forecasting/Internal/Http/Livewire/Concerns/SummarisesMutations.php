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
use Modules\Ledger\Public\ValueObjects\MoneyInput;

// Split out of ScenarioEditorSidebar to keep it under the method ceiling.
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

    // Reads the catalog render() already populated, so no query here.
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
        $amount = MoneyInput::formatAbsMinor($payload->amountMinor);

        return Lang::get('forecasting::scenario.summary.one_off', [
            'amount' => $sign.$amount,
            'currency' => $payload->currency,
            'date' => $payload->date,
        ]);
    }

    private function summariseRecurring(AddRecurringPayload $payload): string
    {
        $sign = $payload->direction === 'income' ? '+' : '−';
        $amount = MoneyInput::formatAbsMinor($payload->amountMinor);

        return Lang::get('forecasting::scenario.summary.recurring', [
            'amount' => $sign.$amount,
            'currency' => $payload->currency,
            'cadence' => $payload->cadence,
            'date' => $payload->startDate,
        ]);
    }

    private function summariseChangeAmount(ChangeSeriesAmountPayload $payload): string
    {
        $amount = MoneyInput::formatMinor($payload->newAmountMinor);

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
