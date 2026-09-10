<?php

declare(strict_types=1);

namespace Modules\Forecasting\Internal\Support;

use Illuminate\Database\DatabaseManager;
use Modules\Core\Models\User;
use Modules\Core\Public\Support\Lang;
use Modules\Forecasting\Internal\Enums\ScenarioTemplate;
use Modules\Forecasting\Public\Dto\ScenarioMutationPayload\CancelSeriesPayload;
use Modules\Forecasting\Public\Dto\ScenarioMutationPayload\ChangeSeriesAmountPayload;
use Modules\Forecasting\Public\Dto\ScenarioMutationPayload\ScenarioMutationPayload;
use Modules\Forecasting\Public\Dto\ScenarioMutationPayload\ShiftSeriesDatePayload;
use stdClass;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

final readonly class ScenarioSeriesResolver
{
    public function __construct(private DatabaseManager $db) {}

    // What a launchpad's second click looks the scenario back up by. The name
    // cannot serve: it is translated, and it never held the figure either, so
    // the mutation the template wrote answers both — the same row on every
    // device in every language, and the one the typed price lives on.
    /**
     * @return array{scenarioId: int, mutationId: int}|null
     */
    public function existingTemplateScenario(User $user, ScenarioTemplate $template, int $seriesId): ?array
    {
        $row = $this->db->connection()->table('forecast_scenarios')
            ->join(
                'forecast_scenario_mutations',
                'forecast_scenario_mutations.forecast_scenario_id',
                '=',
                'forecast_scenarios.id',
            )
            ->where('forecast_scenarios.user_id', $user->id)
            ->where('forecast_scenario_mutations.kind', $template->mutationKind()->value)
            ->where('forecast_scenario_mutations.target_series_id', $seriesId)
            ->orderBy('forecast_scenarios.id')
            ->first([
                'forecast_scenarios.id as scenario_id',
                'forecast_scenario_mutations.id as mutation_id',
            ]);

        if ($row === null) {
            return null;
        }

        /** @var stdClass $row */
        $scenarioId = is_numeric($row->scenario_id ?? null) ? (int) $row->scenario_id : 0;
        $mutationId = is_numeric($row->mutation_id ?? null) ? (int) $row->mutation_id : 0;

        return $scenarioId === 0 ? null : ['scenarioId' => $scenarioId, 'mutationId' => $mutationId];
    }

    public function existingScenarioIdByName(User $user, string $name): ?int
    {
        $value = $this->db->connection()->table('forecast_scenarios')
            ->where('user_id', $user->id)
            ->where('name', $name)
            ->value('id');

        return is_numeric($value) ? (int) $value : null;
    }

    public function resolveSeriesName(stdClass $row): string
    {
        if (isset($row->display_name_override) && is_string($row->display_name_override) && $row->display_name_override !== '') {
            return $row->display_name_override;
        }
        if (isset($row->detected_name) && is_string($row->detected_name) && $row->detected_name !== '') {
            return $row->detected_name;
        }

        return Lang::get('forecasting::scenario.series_name_fallback');
    }

    // Only the three series-targeting payload kinds carry an id; a one-off or
    // a new recurring line targets no existing series.
    public function targetSeriesIdFor(ScenarioMutationPayload $payload): ?int
    {
        return match (true) {
            $payload instanceof CancelSeriesPayload => $payload->seriesId,
            $payload instanceof ChangeSeriesAmountPayload => $payload->seriesId,
            $payload instanceof ShiftSeriesDatePayload => $payload->seriesId,
            default => null,
        };
    }

    // 404, not 403: another user's series must not be distinguishable from
    // one that does not exist.
    public function assertSeriesOwnedByUser(int $seriesId, User $user): void
    {
        $owns = $this->db->connection()->table('recurring_series')
            ->where('id', $seriesId)
            ->where('user_id', $user->id)
            ->exists();
        if (! $owns) {
            throw new NotFoundHttpException('Recurring series not found.');
        }
    }
}
