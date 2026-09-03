<?php

declare(strict_types=1);

namespace Modules\Forecasting\Public\Actions;

use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Database\DatabaseManager;
use Modules\Core\Models\User;
use Modules\Forecasting\Public\Events\ScenarioDeleted;
use Modules\Sync\Public\Events\EntityMutated;
use Modules\Sync\Public\Services\DependentRowCascade;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

final readonly class DeleteScenario
{
    public function __construct(
        private DatabaseManager $db,
        private Dispatcher $events,
        private DependentRowCascade $cascade,
    ) {}

    public function __invoke(int $scenarioId, User $user): void
    {
        $owns = $this->db->connection()->table('forecast_scenarios')
            ->where('id', $scenarioId)
            ->where('user_id', $user->id)
            ->exists();
        if (! $owns) {
            throw new NotFoundHttpException('Scenario not found.');
        }

        /** @var list<object> $dependents */
        $dependents = [];

        $this->db->connection()->transaction(function () use ($scenarioId, $user, &$dependents): void {
            $dependents = $this->cascade->delete('forecast_scenarios', $scenarioId, $user->id);

            $this->db->connection()->table('forecast_scenarios')
                ->where('id', $scenarioId)
                ->where('user_id', $user->id)
                ->delete();
        });

        $this->events->dispatch(new ScenarioDeleted(
            userId: $user->id,
            scenarioId: $scenarioId,
        ));

        $this->events->dispatch(new EntityMutated(
            table: 'forecast_scenarios',
            pk: $scenarioId,
            userId: $user->id,
            mutationType: 'delete',
        ));

        foreach ($dependents as $event) {
            $this->events->dispatch($event);
        }
    }
}
