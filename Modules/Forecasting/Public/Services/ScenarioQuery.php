<?php

declare(strict_types=1);

namespace Modules\Forecasting\Public\Services;

use Carbon\CarbonImmutable;
use Illuminate\Database\DatabaseManager;
use Modules\Core\Models\User;
use Modules\Core\Public\Contracts\Clock;
use Modules\Forecasting\Internal\Mapping\ForecastDtoMapper;
use Modules\Forecasting\Models\ForecastScenarioMutation;
use Modules\Forecasting\Public\Dto\ScenarioDto;
use Modules\Forecasting\Public\Dto\ScenarioMutationDto;
use stdClass;

/**
 * Public read API over `forecast_scenarios` and
 * `forecast_scenario_mutations`. Every method scopes by `user_id` and
 * returns Spatie LaravelData DTOs so the /forecast scenario picker
 * and Wave 4's CRUD panel read a single canonical shape.
 *
 * `forUser` LEFT JOINs the mutations table to populate the per-row
 * `mutationCount` field. This JOIN is between two Forecasting-owned
 * tables only — it does NOT violate the
 * `noScenarioMutationsJoinedToTransactionQueries` invariant, which
 * guards joins onto the transaction substrate
 * (`transactions`, `recurring_series_occurrences`, `chain_links`,
 * `card_statements`).
 *
 * `mutationsFor` loads through the Eloquent model so the typed
 * `ScenarioMutationPayloadCast` runs and the per-kind payload subclass
 * is hydrated correctly.
 */
final readonly class ScenarioQuery
{
    public function __construct(
        private DatabaseManager $db,
        private Clock $clock,
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

        $now = $this->clock->now();

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
            $rawCreated = $mutation->getAttribute('created_at');
            $createdAt = $rawCreated instanceof CarbonImmutable ? $rawCreated : $now;
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
