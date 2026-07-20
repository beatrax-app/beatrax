<?php

declare(strict_types=1);

namespace Modules\Forecasting\Internal\Exceptions;

use RuntimeException;

final class InvalidForecastRunTransitionException extends RuntimeException
{
    public static function forTransition(int $runId, string $from, string $to): self
    {
        return new self(
            "Illegal forecast_runs transition for id={$runId}: {$from} -> {$to}",
        );
    }
}
