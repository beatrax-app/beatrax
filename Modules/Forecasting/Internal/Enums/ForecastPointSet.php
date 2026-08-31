<?php

declare(strict_types=1);

namespace Modules\Forecasting\Internal\Enums;

// The two balance curves one projection run writes for an account. They share
// every point estimate — a sum is a sum — and differ only in the band: the
// per-series curve combines each day's half-widths in quadrature, while the
// funder curve adds one collapsed line's bounds outright.
enum ForecastPointSet: string
{
    case PerSeries = 'points';

    case ByFunder = 'points_by_funder';

    public static function for(bool $viewByFunder): self
    {
        return $viewByFunder ? self::ByFunder : self::PerSeries;
    }
}
