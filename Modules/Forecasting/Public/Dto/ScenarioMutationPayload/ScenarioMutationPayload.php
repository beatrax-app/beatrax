<?php

declare(strict_types=1);

namespace Modules\Forecasting\Public\Dto\ScenarioMutationPayload;

use Spatie\LaravelData\Data;

/**
 * Abstract base for the five typed `ScenarioMutationPayload` subclasses.
 * Each subclass exposes only the fields its kind needs — cross-kind
 * property access is therefore caught at static-analysis time when
 * code carries the abstract base type (Larastan level 10 strict reports
 * `Access to undefined property` on `$payload->newAmountMinor` when the
 * payload variable is typed `ScenarioMutationPayload`).
 *
 * Each subclass returns its enum-string `kind()`. The
 * `ScenarioMutationPayloadCast` routes a JSON row into the correct
 * subclass via `match($kind)`, and validates `$payload->kind() ===
 * $row['kind']` on set.
 */
abstract class ScenarioMutationPayload extends Data
{
    abstract public function kind(): string;
}
