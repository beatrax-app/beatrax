<?php

declare(strict_types=1);

namespace Modules\Forecasting\Internal\Casts;

use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;
use Modules\Forecasting\Public\Dto\ScenarioMutationPayload\AddOneOffPayload;
use Modules\Forecasting\Public\Dto\ScenarioMutationPayload\AddRecurringPayload;
use Modules\Forecasting\Public\Dto\ScenarioMutationPayload\CancelSeriesPayload;
use Modules\Forecasting\Public\Dto\ScenarioMutationPayload\ChangeSeriesAmountPayload;
use Modules\Forecasting\Public\Dto\ScenarioMutationPayload\ScenarioMutationPayload;
use Modules\Forecasting\Public\Dto\ScenarioMutationPayload\ShiftSeriesDatePayload;

/**
 * @implements CastsAttributes<ScenarioMutationPayload, ScenarioMutationPayload>
 */
final class ScenarioMutationPayloadCast implements CastsAttributes
{
    /**
     * @param  array<string, mixed>  $attributes
     */
    public function get(Model $model, string $key, mixed $value, array $attributes): ScenarioMutationPayload
    {
        if (! is_string($value) || $value === '') {
            throw new InvalidArgumentException(
                'ScenarioMutationPayloadCast: payload column must be a non-empty JSON string; got '.get_debug_type($value),
            );
        }

        /** @var mixed $decoded */
        $decoded = json_decode($value, true);
        if (! is_array($decoded)) {
            throw new InvalidArgumentException(
                'ScenarioMutationPayloadCast: payload JSON did not decode to an array.',
            );
        }

        $kind = $attributes['kind'] ?? null;
        if (! is_string($kind) || $kind === '') {
            throw new InvalidArgumentException(
                'ScenarioMutationPayloadCast: kind column must be a non-empty string at read time.',
            );
        }

        return match ($kind) {
            'cancel_series' => CancelSeriesPayload::from($decoded),
            'add_one_off' => AddOneOffPayload::from($decoded),
            'add_recurring' => AddRecurringPayload::from($decoded),
            'change_series_amount' => ChangeSeriesAmountPayload::from($decoded),
            'shift_series_date' => ShiftSeriesDatePayload::from($decoded),
            default => throw new InvalidArgumentException(
                "Unknown scenario mutation kind: {$kind}",
            ),
        };
    }

    /**
     * @param  array<string, mixed>  $attributes
     * @return array<string, string>
     */
    public function set(Model $model, string $key, mixed $value, array $attributes): array
    {
        if (! $value instanceof ScenarioMutationPayload) {
            throw new InvalidArgumentException(
                'ScenarioMutationPayloadCast: payload must be a '.ScenarioMutationPayload::class.'; got '.get_debug_type($value),
            );
        }

        // The about-to-be-set kind lives in $attributes (Laravel passes the
        // full pending-attribute set, INCLUDING freshly assigned values, to
        // set()). The caller must therefore set the kind column before the
        // payload column, or this throws below.
        $kind = $attributes['kind'] ?? null;
        if (! is_string($kind) || $kind === '') {
            throw new InvalidArgumentException(
                'ScenarioMutationPayloadCast: kind column must be set before payload; got '.get_debug_type($kind),
            );
        }

        if ($value->kind() !== $kind) {
            throw new InvalidArgumentException(
                "ScenarioMutationPayloadCast: payload kind '{$value->kind()}' does not match row kind '{$kind}'.",
            );
        }

        return [
            $key => json_encode($value->toArray(), JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        ];
    }
}
