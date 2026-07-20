<?php

declare(strict_types=1);

namespace Modules\Forecasting\Public\Dto\ScenarioMutationPayload;

use Modules\Forecasting\Internal\Casts\ScenarioMutationPayloadCast;
use Spatie\LaravelData\Data;

/**
 * @see ScenarioMutationPayloadCast
 */
abstract class ScenarioMutationPayload extends Data
{
    abstract public function kind(): string;
}
