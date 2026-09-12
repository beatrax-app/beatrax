<?php

declare(strict_types=1);

namespace Modules\Forecasting\Internal\Mapping;

use Carbon\CarbonImmutable;
use InvalidArgumentException;
use Modules\Core\Public\Concerns\CoercesScalars;
use Modules\Core\Public\Contracts\Clock;
use Modules\Forecasting\Internal\Enums\ForecastPointSet;
use Modules\Forecasting\Public\Dto\ForecastDto;
use Modules\Forecasting\Public\Dto\ForecastPointDto;
use Modules\Forecasting\Public\Dto\ScenarioDto;
use Modules\Forecasting\Public\Dto\SeriesConfidenceDto;
use Modules\Forecasting\Public\Dto\ShortfallWindowDto;
use Modules\FX\Public\Dto\ConversionDisclosure;
use stdClass;

final readonly class ForecastDtoMapper
{
    use CoercesScalars;

    // A stored rate's staleness is a fact about the day it is read, not the
    // day the run wrote it, so the mapper is the stage that needs today.
    public function __construct(private Clock $clock) {}

    /**
     * @param  array<array-key, mixed>  $accountResult
     * @param  list<SeriesConfidenceDto>  $seriesConfidence
     */
    public function mapForecast(
        array $accountResult,
        ForecastWindow $window,
        bool $isComputing,
        array $seriesConfidence = [],
        ForecastPointSet $pointSet = ForecastPointSet::PerSeries,
        bool $isStale = false,
    ): ForecastDto {
        // A run written before the funder curve existed carries only the
        // per-series one, and falling back to it beats drawing nothing.
        $pointsRaw = $accountResult[$pointSet->value]
            ?? $accountResult[ForecastPointSet::PerSeries->value]
            ?? [];
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

        $currency = self::toString($accountResult['default_currency'] ?? null);
        $unconverted = self::stringList($accountResult['unconverted_currencies'] ?? null);

        return new ForecastDto(
            accountId: self::toInt($accountResult['account_id'] ?? null),
            accountName: self::toString($accountResult['account_name'] ?? null),
            defaultCurrency: $currency,
            horizonDays: $window->horizonDays,
            scenarioId: $window->scenarioId,
            asOf: $window->asOf,
            todayBalanceMinor: self::toInt($accountResult['today_balance_minor'] ?? null),
            points: $points,
            seriesConfidence: $seriesConfidence,
            isComputing: $isComputing,
            isStale: $isStale,
            unconvertedCurrencies: $unconverted,
            conversion: $this->disclosureOf($accountResult, $currency, $unconverted),
        );
    }

    // A run written before result_json carried its rates cannot be read as
    // having converted nothing, so it says what is true instead: whatever it
    // converted at is not on record. The next projection replaces the row and
    // the line goes with it.
    /**
     * @param  array<array-key, mixed>  $accountResult
     * @param  list<string>  $unconverted
     */
    private function disclosureOf(array $accountResult, string $currency, array $unconverted): ConversionDisclosure
    {
        $rates = StoredRateSet::decode($accountResult[StoredRateSet::KEY] ?? null, $currency, $this->clock->now());

        return $rates === null
            ? ConversionDisclosure::unrecorded($currency, $unconverted)
            : ConversionDisclosure::of($rates, $unconverted);
    }

    /**
     * @return list<string>
     */
    private static function stringList(mixed $raw): array
    {
        if (! is_array($raw)) {
            return [];
        }

        $codes = [];
        foreach ($raw as $code) {
            if (is_string($code) && $code !== '') {
                $codes[] = $code;
            }
        }

        return $codes;
    }

    /**
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
}
