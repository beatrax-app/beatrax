<?php

declare(strict_types=1);

namespace Modules\Forecasting\Public\Actions;

use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Database\DatabaseManager;
use Modules\Core\Models\User;
use Modules\Forecasting\Public\Events\ScenarioDeleted;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * @link ../../../../.docs/features/forecasting/architecture.md
 */
final class DeleteScenario
{
    public function __construct(
        private readonly DatabaseManager $db,
        private readonly Dispatcher $events,
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

        $this->db->connection()->transaction(function () use ($scenarioId, $user): void {
            $this->db->connection()->table('forecast_scenarios')
                ->where('id', $scenarioId)
                ->where('user_id', $user->id)
                ->delete();
        });

        $this->events->dispatch(new ScenarioDeleted(
            userId: $user->id,
            scenarioId: $scenarioId,
        ));
    }
}
