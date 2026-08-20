<?php

declare(strict_types=1);

namespace Modules\Forecasting\Public\Actions;

use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Database\DatabaseManager;
use Modules\Core\Models\User;
use Modules\Core\Public\Contracts\Clock;
use Modules\Forecasting\Public\Events\ScenarioMutated;
use Modules\Sync\Public\Events\EntityMutated;
use stdClass;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

final class RemoveScenarioMutation
{
    public function __construct(
        private readonly DatabaseManager $db,
        private readonly Clock $clock,
        private readonly Dispatcher $events,
    ) {}

    public function __invoke(int $mutationId, User $user): void
    {
        $row = $this->db->connection()->table('forecast_scenario_mutations')
            ->where('id', $mutationId)
            ->where('user_id', $user->id)
            ->first(['id', 'forecast_scenario_id', 'kind']);
        if ($row === null) {
            throw new NotFoundHttpException('Scenario mutation not found.');
        }
        /** @var stdClass $row */
        $scenarioId = isset($row->forecast_scenario_id) && is_numeric($row->forecast_scenario_id)
            ? (int) $row->forecast_scenario_id
            : 0;
        $kind = isset($row->kind) && is_string($row->kind) ? $row->kind : '';

        $now = $this->clock->now()->toDateTimeString();

        $this->db->connection()->transaction(function () use ($mutationId, $user, $scenarioId, $now): void {
            $this->db->connection()->table('forecast_scenario_mutations')
                ->where('id', $mutationId)
                ->where('user_id', $user->id)
                ->delete();

            if ($scenarioId > 0) {
                $this->db->connection()->table('forecast_scenarios')
                    ->where('id', $scenarioId)
                    ->where('user_id', $user->id)
                    ->update(['updated_at' => $now]);
            }
        });

        // A removed mutation changes what the scenario projects, so leaving
        // the tombstone uncaptured left the peer forecasting against a
        // what-if the user had already taken back.
        $this->events->dispatch(new EntityMutated(
            table: 'forecast_scenario_mutations',
            pk: $mutationId,
            userId: (int) $user->id,
            mutationType: 'delete',
        ));

        $this->events->dispatch(new ScenarioMutated(
            userId: $user->id,
            scenarioId: $scenarioId,
            mutationId: $mutationId,
            kind: $kind,
        ));
    }
}
