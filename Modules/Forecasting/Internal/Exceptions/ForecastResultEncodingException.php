<?php

declare(strict_types=1);

namespace Modules\Forecasting\Internal\Exceptions;

use RuntimeException;

// Thrown when the computed projection cannot be encoded to result_json —
// json_encode returned false, so completing the run would otherwise leave
// forecast_runs with an empty or invalid payload the readers cannot parse.
/**
 * @link ../../../../.docs/features/forecasting/architecture.md
 */
final class ForecastResultEncodingException extends RuntimeException {}
