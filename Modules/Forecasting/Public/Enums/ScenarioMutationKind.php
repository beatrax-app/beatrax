<?php

declare(strict_types=1);

namespace Modules\Forecasting\Public\Enums;

use Modules\Forecasting\Public\Dto\ScenarioMutationPayload\AddOneOffPayload;
use Modules\Forecasting\Public\Dto\ScenarioMutationPayload\AddRecurringPayload;
use Modules\Forecasting\Public\Dto\ScenarioMutationPayload\CancelSeriesPayload;
use Modules\Forecasting\Public\Dto\ScenarioMutationPayload\ChangeSeriesAmountPayload;
use Modules\Forecasting\Public\Dto\ScenarioMutationPayload\ScenarioMutationPayload;
use Modules\Forecasting\Public\Dto\ScenarioMutationPayload\ShiftSeriesDatePayload;

// The five shapes a forecast scenario mutation can take. The `kind` column,
// its DTOs and the payload cast stay string-keyed; this enum is the one
// canonical spelling callers map through, and it owns the kind -> payload
// class projection the polymorphic cast reads back through.
enum ScenarioMutationKind: string
{
    case CancelSeries = 'cancel_series';

    case AddOneOff = 'add_one_off';

    case AddRecurring = 'add_recurring';

    case ChangeSeriesAmount = 'change_series_amount';

    case ShiftSeriesDate = 'shift_series_date';

    /** @return class-string<ScenarioMutationPayload> */
    public function payloadClass(): string
    {
        return match ($this) {
            self::CancelSeries => CancelSeriesPayload::class,
            self::AddOneOff => AddOneOffPayload::class,
            self::AddRecurring => AddRecurringPayload::class,
            self::ChangeSeriesAmount => ChangeSeriesAmountPayload::class,
            self::ShiftSeriesDate => ShiftSeriesDatePayload::class,
        };
    }
}
