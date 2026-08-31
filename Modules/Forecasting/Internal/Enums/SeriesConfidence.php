<?php

declare(strict_types=1);

namespace Modules\Forecasting\Internal\Enums;

// How wide a series' band is relative to its point estimate, as the legend
// chip states it. The backing value is the vocabulary the DTO carried as a bare
// string, which the chip then printed verbatim: an English 'low' inside
// otherwise fully Dutch copy.
enum SeriesConfidence: string
{
    case High = 'high';

    case Medium = 'medium';

    case Low = 'low';

    // Bounds on the band ratio: full band width as a percentage of the point,
    // which is twice the series' variance tolerance.
    private const int HIGH_MAX_BAND_RATIO = 10;

    private const int MEDIUM_MAX_BAND_RATIO = 25;

    public static function forBandRatio(int $bandRatioPercent): self
    {
        return match (true) {
            $bandRatioPercent <= self::HIGH_MAX_BAND_RATIO => self::High,
            $bandRatioPercent <= self::MEDIUM_MAX_BAND_RATIO => self::Medium,
            default => self::Low,
        };
    }

    public function labelKey(): string
    {
        return 'forecasting::forecast.confidence.'.$this->value;
    }
}
