<?php

declare(strict_types=1);

namespace Modules\Forecasting\Public\Services;

use Carbon\CarbonImmutable;
use Illuminate\Database\DatabaseManager;
use Modules\Core\Models\User;
use Modules\Forecasting\Internal\Mapping\ForecastDtoMapper;
use Modules\Forecasting\Models\ForecastScenarioMutation;
use Modules\Forecasting\Public\Dto\ScenarioDto;
use Modules\Forecasting\Public\Dto\ScenarioMutationDto;
use stdClass;

/**
 * @link ../../../../.docs/features/forecasting/architecture.md
 */
final readonly class ScenarioQuery
{
    public function __construct(
        private DatabaseManager $db,
        private ForecastDtoMapper $mapper,
    ) {}

    /**
     * @return list<ScenarioDto>
     */
    public function forUser(User $user): array
    {
        $rows = $this->db->connection()->table('forecast_scenarios as fs')
            ->leftJoin(
                $this->db->connection()->raw(
                    '(SELECT forecast_scenario_id, COUNT(*) AS mutation_count FROM forecast_scenario_mutations GROUP BY forecast_scenario_id) as mc'
                ),
                'mc.forecast_scenario_id',
                '=',
                'fs.id',
            )
            ->where('fs.user_id', $user->id)
            ->orderByDesc('fs.updated_at')
            ->orderByDesc('fs.id')
            ->get([
                'fs.id',
                'fs.user_id',
                'fs.name',
                'fs.description',
                'fs.created_at',
                'fs.updated_at',
                'mc.mutation_count',
            ]);

        $result = [];
        foreach ($rows as $row) {
            /** @var stdClass $row */
            $count = isset($row->mutation_count) && is_numeric($row->mutation_count)
                ? (int) $row->mutation_count
                : 0;
            $result[] = $this->mapper->mapScenario($row, $count);
        }

        return $result;
    }

    public function find(int $scenarioId, User $user): ?ScenarioDto
    {
        $row = $this->db->connection()->table('forecast_scenarios')
            ->where('id', $scenarioId)
            ->where('user_id', $user->id)
            ->first();
        if ($row === null) {
            return null;
        }
        /** @var stdClass $row */
        $count = $this->db->connection()->table('forecast_scenario_mutations')
            ->where('forecast_scenario_id', $scenarioId)
            ->where('user_id', $user->id)
            ->count();

        return $this->mapper->mapScenario($row, $count);
    }

    /**
     * @return list<ScenarioMutationDto>
     */
    public function mutationsFor(int $scenarioId, User $user): array
    {
        $owns = $this->db->connection()->table('forecast_scenarios')
            ->where('id', $scenarioId)
            ->where('user_id', $user->id)
            ->exists();
        if (! $owns) {
            return [];
        }

        // Load row ids via raw query builder (Eloquent's static `orderBy`
        // chain is flagged by larastan strict as a dynamic call), then
        // hydrate each row via the model so the typed cast runs.
        $ids = $this->db->connection()->table('forecast_scenario_mutations')
            ->where('forecast_scenario_id', $scenarioId)
            ->where('user_id', $user->id)
            ->orderBy('id')
            ->pluck('id');

        $result = [];
        foreach ($ids as $idValue) {
            $id = is_numeric($idValue) ? (int) $idValue : 0;
            if ($id === 0) {
                continue;
            }
            $mutation = ForecastScenarioMutation::query()->find($id);
            if ($mutation === null) {
                continue;
            }
            // The model casts created_at to immutable_datetime, so the
            // attribute is always a CarbonImmutable. A guard cast keeps
            // Larastan-level-10 happy without a runtime null branch.
            $rawCreated = $mutation->getAttribute('created_at');
            $createdAt = $rawCreated instanceof CarbonImmutable
                ? $rawCreated
                : CarbonImmutable::now();
            $result[] = new ScenarioMutationDto(
                id: self::toInt($mutation->getAttribute('id')),
                userId: self::toInt($mutation->getAttribute('user_id')),
                forecastScenarioId: self::toInt($mutation->getAttribute('forecast_scenario_id')),
                kind: self::toString($mutation->getAttribute('kind')),
                targetSeriesId: self::nullableInt($mutation->getAttribute('target_series_id')),
                payload: $mutation->payload,
                createdAt: $createdAt,
            );
        }

        return $result;
    }

    private static function nullableInt(mixed $value): ?int
    {
        return is_numeric($value) ? (int) $value : null;
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
