<?php

declare(strict_types=1);

namespace Modules\Forecasting\Public\Actions;

use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Database\DatabaseManager;
use Modules\Core\Models\User;
use Modules\Forecasting\Public\Events\ScenarioDeleted;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Hard-deletes a saved scenario. The Wave 1 schema's FK
 * cascade-on-delete wipes the scenario's mutations, runs, and
 * shortfall-window rows atomically — this Action therefore only
 * deletes the parent row + dispatches the event.
 *
 * Cross-user invocation raises `NotFoundHttpException` via the
 * `(id, user_id)` guard. Idempotent re-invocation against a missing
 * row also raises `NotFoundHttpException` (no silent no-op — the
 * caller is expected to refresh its view of the world before
 * deleting).
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
