<?php

declare(strict_types=1);

namespace Modules\Forecasting\Internal\Enums;

use Modules\Forecasting\Public\Dto\ScenarioMutationPayload\CancelSeriesPayload;
use Modules\Forecasting\Public\Dto\ScenarioMutationPayload\ChangeSeriesAmountPayload;
use Modules\Forecasting\Public\Dto\ScenarioMutationPayload\ScenarioMutationPayload;
use Modules\Forecasting\Public\Enums\ScenarioMutationKind;

// The two what-ifs a screen can launch in one click. The scenario's NAME is
// translated; its IDENTITY is the mutation kind plus the series it targets,
// which is the same in every language. Keying the second on the first is what
// broke the double-click recovery the moment the name stopped being English.
enum ScenarioTemplate: string
{
    case Cancel = 'cancel';

    case ChangeAmount = 'change_amount';

    public function nameKey(): string
    {
        return 'forecasting::scenario.template.'.$this->value;
    }

    public function mutationKind(): ScenarioMutationKind
    {
        return match ($this) {
            self::Cancel => ScenarioMutationKind::CancelSeries,
            self::ChangeAmount => ScenarioMutationKind::ChangeSeriesAmount,
        };
    }

    // $newAmountMinor is read only by ChangeAmount; a null there is a caller
    // that asked for a price change without naming a price.
    public function payloadFor(int $seriesId, ?int $newAmountMinor): ScenarioMutationPayload
    {
        return match ($this) {
            self::Cancel => new CancelSeriesPayload(seriesId: $seriesId),
            self::ChangeAmount => new ChangeSeriesAmountPayload(
                seriesId: $seriesId,
                newAmountMinor: $newAmountMinor ?? 0,
            ),
        };
    }
}
