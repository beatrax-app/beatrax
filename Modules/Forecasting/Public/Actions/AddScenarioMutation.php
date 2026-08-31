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
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

final readonly class AddScenarioMutation
{
    public function __construct(
        private DatabaseManager $db,
        private Clock $clock,
        private Dispatcher $events,
        private ScenarioSeriesResolver $seriesResolver,
        private ScenarioHorizonBounds $horizonBounds,
    ) {}

    public function __invoke(int $scenarioId, User $user, string $kind, ScenarioMutationPayload $payload): int
    {
        if ($payload->kind() !== $kind) {
            throw new InvalidArgumentException(
                "AddScenarioMutation: payload kind '{$payload->kind()}' does not match kind argument '{$kind}'.",
            );
        }

        $owns = $this->db->connection()->table('forecast_scenarios')
            ->where('id', $scenarioId)
            ->where('user_id', $user->id)
            ->exists();
        if (! $owns) {
            throw new NotFoundHttpException('Scenario not found.');
        }

        $targetSeriesId = $this->seriesResolver->targetSeriesIdFor($payload);
        if ($targetSeriesId !== null) {
            $this->seriesResolver->assertSeriesOwnedByUser($targetSeriesId, $user);
        }

        $this->horizonBounds->assertReachable($payload);

        $now = $this->clock->now();

        $mutation = $this->db->connection()->transaction(function () use ($scenarioId, $user, $kind, $payload, $targetSeriesId, $now): ForecastScenarioMutation {
            $mutation = new ForecastScenarioMutation;
            $mutation->user_id = $user->id;
            $mutation->forecast_scenario_id = $scenarioId;
            $mutation->kind = $kind;
            $mutation->target_series_id = $targetSeriesId;
            $mutation->payload = $payload;
            $mutation->created_at = $now;
            $mutation->updated_at = $now;
            $mutation->save();

            $this->db->connection()->table('forecast_scenarios')
                ->where('id', $scenarioId)
                ->where('user_id', $user->id)
                ->update(['updated_at' => $now->toDateTimeString()]);

            return $mutation;
        });

        $id = $mutation->getAttribute('id');
        $newId = is_numeric($id) ? (int) $id : 0;

        // The scenario container synced without its contents, so a peer
        // showed an empty named what-if. `payload` travels as the STORED
        // JSON text — the typed cast is a read-side concern, and re-encoding
        // the DTO here would be a second copy of that encoding to keep true.
        $this->events->dispatch(new EntityMutated(
            table: 'forecast_scenario_mutations',
            pk: $newId,
            userId: $user->id,
            mutationType: 'create',
            dirtyFields: [
                'user_id' => $user->id,
                'forecast_scenario_id' => $scenarioId,
                'kind' => $kind,
                'target_series_id' => $targetSeriesId,
                'payload' => $mutation->getAttributes()['payload'] ?? null,
                'created_at' => $now->toDateTimeString(),
                'updated_at' => $now->toDateTimeString(),
            ],
        ));

        $this->events->dispatch(new ScenarioMutated(
            userId: $user->id,
            scenarioId: $scenarioId,
            mutationId: $newId,
            kind: $kind,
        ));

        return $newId;
    }
}
