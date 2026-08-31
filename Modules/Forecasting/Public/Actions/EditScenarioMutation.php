<?php

declare(strict_types=1);

namespace Modules\Forecasting\Public\Actions;

use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Database\DatabaseManager;
use InvalidArgumentException;
use Modules\Core\Models\User;
use Modules\Core\Public\Contracts\Clock;
use Modules\Forecasting\Internal\Support\ScenarioHorizonBounds;
use Modules\Forecasting\Internal\Support\ScenarioSeriesResolver;
use Modules\Forecasting\Models\ForecastScenarioMutation;
use Modules\Forecasting\Public\Dto\ScenarioMutationPayload\ScenarioMutationPayload;
use Modules\Forecasting\Public\Events\ScenarioMutated;
use Modules\Sync\Public\Events\EntityMutated;
use stdClass;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

final readonly class EditScenarioMutation
{
    public function __construct(
        private DatabaseManager $db,
        private Clock $clock,
        private Dispatcher $events,
        private ScenarioSeriesResolver $seriesResolver,
        private ScenarioHorizonBounds $horizonBounds,
    ) {}

    public function __invoke(int $mutationId, User $user, ScenarioMutationPayload $newPayload): void
    {
        $row = $this->db->connection()->table('forecast_scenario_mutations')
            ->where('id', $mutationId)
            ->where('user_id', $user->id)
            ->first(['id', 'forecast_scenario_id', 'kind']);
        if ($row === null) {
            throw new NotFoundHttpException('Scenario mutation not found.');
        }
        /** @var stdClass $row */
        $existingKind = isset($row->kind) && is_string($row->kind) ? $row->kind : '';
        if ($newPayload->kind() !== $existingKind) {
            throw new InvalidArgumentException(
                "EditScenarioMutation: payload kind '{$newPayload->kind()}' cannot replace existing kind '{$existingKind}'. Remove + re-add to change kind.",
            );
        }
        $scenarioId = isset($row->forecast_scenario_id) && is_numeric($row->forecast_scenario_id)
            ? (int) $row->forecast_scenario_id
            : 0;

        $targetSeriesId = $this->seriesResolver->targetSeriesIdFor($newPayload);
        if ($targetSeriesId !== null) {
            $this->seriesResolver->assertSeriesOwnedByUser($targetSeriesId, $user);
        }

        $this->horizonBounds->assertReachable($newPayload);

        $now = $this->clock->now();

        $edited = $this->db->connection()->transaction(function () use ($mutationId, $user, $newPayload, $targetSeriesId, $scenarioId, $now): ForecastScenarioMutation {
            $mutation = ForecastScenarioMutation::query()
                ->where('id', $mutationId)
                ->where('user_id', $user->id)
                ->first();
            if ($mutation === null) {
                throw new NotFoundHttpException('Scenario mutation not found.');
            }
            $mutation->target_series_id = $targetSeriesId;
            $mutation->payload = $newPayload;
            $mutation->updated_at = $now;
            $mutation->save();

            if ($scenarioId > 0) {
                $this->db->connection()->table('forecast_scenarios')
                    ->where('id', $scenarioId)
                    ->where('user_id', $user->id)
                    ->update(['updated_at' => $now->toDateTimeString()]);
            }

            return $mutation;
        });

        // Only the two columns an edit can move. `kind` is refused above, so
        // it is never dirty here and a peer's copy keeps the kind its own
        // create op already gave it.
        $this->events->dispatch(new EntityMutated(
            table: 'forecast_scenario_mutations',
            pk: $mutationId,
            userId: $user->id,
            mutationType: 'edit',
            dirtyFields: [
                'target_series_id' => $targetSeriesId,
                'payload' => $edited->getAttributes()['payload'] ?? null,
            ],
        ));

        $this->events->dispatch(new ScenarioMutated(
            userId: $user->id,
            scenarioId: $scenarioId,
            mutationId: $mutationId,
            kind: $existingKind,
        ));
    }
}
