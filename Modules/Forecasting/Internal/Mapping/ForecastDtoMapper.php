<?php

declare(strict_types=1);

namespace Modules\Forecasting\Internal\Mapping;

use Carbon\CarbonImmutable;
use InvalidArgumentException;
use Modules\Forecasting\Public\Dto\ForecastDto;
use Modules\Forecasting\Public\Dto\ForecastPointDto;
use Modules\Forecasting\Public\Dto\ScenarioDto;
use Modules\Forecasting\Public\Dto\ShortfallWindowDto;
use stdClass;

/**
 * Pure-data transformer from raw row shapes (`stdClass` rows for
 * `forecast_scenarios` / `forecast_shortfall_windows`, decoded
 * `forecast_runs.result_json` sub-blocks for the per-account
 * projection) into the Public Spatie LaravelData DTOs the read API
 * surface returns.
 *
 * Static-only: no constructor dependencies. The mapper does not read
 * services or touch the DB; the read query class is responsible for
 * resolving cross-module joins (display names, mutation counts) and
 * handing them to the mapper alongside the row.
 *
 * Mirrors `Modules\DriftAlerts\Internal\Mapping\DriftAlertDtoMapper`.
 */
final readonly class ForecastDtoMapper
{
    /**
     * Hydrate a single ForecastDto from the per-account sub-block of
     * a decoded `forecast_runs.result_json` payload.
     *
     * `$seriesConfidence` is supplied by the caller (the read service
     * resolves it via its own query path; the mapper stays static-only
     * and side-effect-free).
     *
     * @param  array<array-key, mixed>             $accountResult
     * @param  list<\Modules\Forecasting\Public\Dto\SeriesConfidenceDto> $seriesConfidence
     */
    public function mapForecast(
        array $accountResult,
        int $horizonDays,
        ?int $scenarioId,
        CarbonImmutable $asOf,
        bool $isComputing,
        array $seriesConfidence = [],
    ): ForecastDto {
        $pointsRaw = $accountResult['points'] ?? [];
        if (! is_array($pointsRaw)) {
            $pointsRaw = [];
        }

        $points = [];
        foreach ($pointsRaw as $day) {
            if (! is_array($day)) {
                continue;
            }
            $points[] = $this->mapPoint($day);
        }

        return new ForecastDto(
            accountId: self::toInt($accountResult['account_id'] ?? null),
            accountName: self::toString($accountResult['account_name'] ?? null),
            defaultCurrency: self::toString($accountResult['default_currency'] ?? null),
            horizonDays: $horizonDays,
            scenarioId: $scenarioId,
            asOf: $asOf,
            todayBalanceMinor: self::toInt($accountResult['today_balance_minor'] ?? null),
            points: $points,
            seriesConfidence: $seriesConfidence,
            isComputing: $isComputing,
        );
    }

    /**
     * Hydrate a single ForecastPointDto from one per-day array row in
     * the decoded result_json payload.
     *
     * @param  array<array-key, mixed>  $day
     */
    public function mapPoint(array $day): ForecastPointDto
    {
        return new ForecastPointDto(
            date: self::toString($day['date'] ?? null),
            lowMinor: self::toInt($day['low_minor'] ?? null),
            pointMinor: self::toInt($day['point_minor'] ?? null),
            highMinor: self::toInt($day['high_minor'] ?? null),
            currency: self::toString($day['currency'] ?? null),
        );
    }

    /**
     * Hydrate a single ScenarioDto from a raw `forecast_scenarios` row
     * plus the externally-resolved mutation count.
     */
    public function mapScenario(stdClass $row, int $mutationCount): ScenarioDto
    {
        $description = $row->description ?? null;
        $description = is_string($description) && $description !== '' ? $description : null;

        return new ScenarioDto(
            id: self::toInt($row->id ?? null),
            userId: self::toInt($row->user_id ?? null),
            name: self::toString($row->name ?? null),
            description: $description,
            createdAt: self::parseCarbon($row->created_at ?? null, 'created_at'),
            updatedAt: self::parseCarbon($row->updated_at ?? null, 'updated_at'),
            mutationCount: $mutationCount,
        );
    }

    /**
     * Hydrate a single ShortfallWindowDto from a raw
     * `forecast_shortfall_windows` row. Wave 3 will populate the
     * underlying table; the mapper lands now so the Public read API's
     * type surface is forward-complete.
     */
    public function mapShortfallWindow(stdClass $row): ShortfallWindowDto
    {
        return new ShortfallWindowDto(
            id: self::toInt($row->id ?? null),
            userId: self::toInt($row->user_id ?? null),
            accountId: self::toInt($row->account_id ?? null),
            scenarioId: isset($row->scenario_id) && is_numeric($row->scenario_id) ? (int) $row->scenario_id : null,
            startsAt: self::parseCarbon($row->starts_at ?? null, 'starts_at'),
            endsAt: self::parseCarbon($row->ends_at ?? null, 'ends_at'),
            lowestBalanceMinor: self::toInt($row->lowest_balance_minor ?? null),
            currency: self::toString($row->currency ?? null),
            bufferUsedMinor: self::toInt($row->buffer_used_minor ?? null),
        );
    }

    private static function parseCarbon(mixed $raw, string $column): CarbonImmutable
    {
        if ($raw instanceof CarbonImmutable) {
            return $raw;
        }
        if ($raw instanceof \DateTimeInterface) {
            return CarbonImmutable::parse($raw->format(\DateTimeInterface::ATOM));
        }
        if (is_string($raw) && $raw !== '') {
            return CarbonImmutable::parse($raw);
        }

        throw new InvalidArgumentException(sprintf(
            'ForecastDtoMapper: missing or non-string %s.',
            $column,
        ));
    }

    private static function toInt(mixed $value): int
    {
        return is_numeric($value) ? (int) $value : 0;
    }

    private static function toString(mixed $value): string
    {
        if (is_string($value)) {
            return $value;
        }

        return is_scalar($value) ? (string) $value : '';
    }
}
