<?php

declare(strict_types=1);

namespace Modules\Forecasting\Internal\Exceptions;

use RuntimeException;

// Thrown inside the ForecastRunStateMachine transaction when the
// lockForUpdate row lookup returns null — the forecast_runs row vanished
// mid-flight after the caller handed us the model.
final class ForecastRunNotFoundException extends RuntimeException {}
