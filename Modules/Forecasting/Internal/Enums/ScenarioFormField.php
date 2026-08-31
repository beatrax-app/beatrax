<?php

declare(strict_types=1);

namespace Modules\Forecasting\Internal\Enums;

// The keys the scenario mutation form posts under, and the label each one is
// called by on screen. A missing field used to be reported as "Field 'seriesId'
// is required." — the array key, in English, to every reader.
enum ScenarioFormField: string
{
    case SeriesId = 'seriesId';

    case Date = 'date';

    case Amount = 'amount';

    case Currency = 'currency';

    case Direction = 'direction';

    case Note = 'note';

    case StartDate = 'startDate';

    case Cadence = 'cadence';

    case NewAmount = 'newAmount';

    case NewNextDate = 'newNextDate';

    case Scope = 'scope';

    public function labelKey(): string
    {
        return 'forecasting::scenario.form.'.match ($this) {
            self::SeriesId => 'series',
            self::Date => 'date',
            self::Amount => 'amount',
            self::Currency => 'currency',
            self::Direction => 'direction',
            self::Note => 'note',
            self::StartDate => 'start_date',
            self::Cadence => 'cadence',
            self::NewAmount => 'new_amount',
            self::NewNextDate => 'new_next_date',
            self::Scope => 'scope',
        };
    }
}
