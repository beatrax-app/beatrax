<?php

declare(strict_types=1);

namespace Modules\Forecasting\Internal\Http\Livewire\Concerns;

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
/**
 * @link ../../../../../../.docs/features/forecasting/architecture.md
 */
trait SummarisesMutations
{
    private function summaryFor(string $kind, ScenarioMutationPayload $payload): string
    {
        return match (true) {
            $payload instanceof CancelSeriesPayload => 'Cancel '.$this->resolveSeriesName($payload->seriesId),
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

        return 'series #'.$seriesId;
    }

    private function summariseOneOff(AddOneOffPayload $payload): string
    {
        $sign = $payload->direction === 'income' ? '+' : '−';
        $amount = number_format($payload->amountMinor / 100, 2, ',', '.');

        return "{$sign}{$amount} {$payload->currency} on {$payload->date}";
    }

    private function summariseRecurring(AddRecurringPayload $payload): string
    {
        $sign = $payload->direction === 'income' ? '+' : '−';
        $amount = number_format($payload->amountMinor / 100, 2, ',', '.');

        return "{$sign}{$amount} {$payload->currency} {$payload->cadence} from {$payload->startDate}";
    }

    private function summariseChangeAmount(ChangeSeriesAmountPayload $payload): string
    {
        $amount = number_format($payload->newAmountMinor / 100, 2, ',', '.');

        return $this->resolveSeriesName($payload->seriesId).": new amount {$amount}";
    }

    private function summariseShiftDate(ShiftSeriesDatePayload $payload): string
    {
        $scope = $payload->scope === ShiftScope::AllSubsequent->value ? 'all subsequent' : 'next';

        return $this->resolveSeriesName($payload->seriesId).": shift {$scope} to {$payload->newNextDate}";
    }
}
