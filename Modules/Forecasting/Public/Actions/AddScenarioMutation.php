<?php

declare(strict_types=1);

namespace Modules\Forecasting\Public\Actions;

use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Database\DatabaseManager;
use InvalidArgumentException;
use Modules\Core\Models\User;
use Modules\Core\Public\Contracts\Clock;
use Modules\Forecasting\Models\ForecastScenarioMutation;
use Modules\Forecasting\Public\Dto\ScenarioMutationPayload\CancelSeriesPayload;
use Modules\Forecasting\Public\Dto\ScenarioMutationPayload\ChangeSeriesAmountPayload;
use Modules\Forecasting\Public\Dto\ScenarioMutationPayload\ScenarioMutationPayload;
use Modules\Forecasting\Public\Dto\ScenarioMutationPayload\ShiftSeriesDatePayload;
use Modules\Forecasting\Public\Events\ScenarioMutated;
use Modules\Sync\Public\Events\EntityMutated;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

final class AddScenarioMutation
{
    public function __construct(
        private readonly DatabaseManager $db,
        private readonly Clock $clock,
        private readonly Dispatcher $events,
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

        $targetSeriesId = $this->targetSeriesIdFor($payload);
        if ($targetSeriesId !== null) {
            $this->assertSeriesOwnedByUser($targetSeriesId, $user);
        }

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
            userId: (int) $user->id,
            mutationType: 'create',
            dirtyFields: [
                'user_id' => (int) $user->id,
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

    private function targetSeriesIdFor(ScenarioMutationPayload $payload): ?int
    {
        return match (true) {
            $payload instanceof CancelSeriesPayload => $payload->seriesId,
            $payload instanceof ChangeSeriesAmountPayload => $payload->seriesId,
            $payload instanceof ShiftSeriesDatePayload => $payload->seriesId,
            default => null,
        };
    }

    private function assertSeriesOwnedByUser(int $seriesId, User $user): void
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
