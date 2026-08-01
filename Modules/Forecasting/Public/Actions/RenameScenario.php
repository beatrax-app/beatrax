<?php

declare(strict_types=1);

namespace Modules\Forecasting\Public\Actions;

use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Database\DatabaseManager;
use Illuminate\Database\QueryException;
use InvalidArgumentException;
use Modules\Core\Models\User;
use Modules\Core\Public\Contracts\Clock;
use Modules\Core\Public\Support\Lang;
use Modules\Forecasting\Models\ForecastScenario;
use Modules\Forecasting\Public\Events\ScenarioMutated;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * @link ../../../../.docs/features/forecasting/architecture.md
 */
final class RenameScenario
{
    public function __construct(
        private readonly DatabaseManager $db,
        private readonly Clock $clock,
        private readonly Dispatcher $events,
    ) {}

    public function __invoke(int $scenarioId, User $user, string $newName): void
    {
        $trimmed = trim($newName);
        if ($trimmed === '') {
            throw new InvalidArgumentException(Lang::get('forecasting::scenario.errors.name_empty'));
        }
        if (mb_strlen($trimmed) > ForecastScenario::MAX_NAME_LENGTH) {
            throw new InvalidArgumentException(Lang::get('forecasting::scenario.errors.name_too_long', ['max' => ForecastScenario::MAX_NAME_LENGTH]));
        }

        $owns = $this->db->connection()->table('forecast_scenarios')
            ->where('id', $scenarioId)
            ->where('user_id', $user->id)
            ->exists();
        if (! $owns) {
            throw new NotFoundHttpException('Scenario not found.');
        }

        $now = $this->clock->now()->toDateTimeString();

        try {
            $this->db->connection()->transaction(function () use ($scenarioId, $user, $trimmed, $now): void {
                $this->db->connection()->table('forecast_scenarios')
                    ->where('id', $scenarioId)
                    ->where('user_id', $user->id)
                    ->update([
                        'name' => $trimmed,
                        'updated_at' => $now,
                    ]);
            });
        } catch (QueryException $e) {
            if (self::looksLikeUniqueViolation($e)) {
                throw new InvalidArgumentException(Lang::get('forecasting::scenario.errors.name_taken'), 0, $e);
            }
            throw $e;
        }

        $this->events->dispatch(new ScenarioMutated(
            userId: $user->id,
            scenarioId: $scenarioId,
            mutationId: 0,
            kind: 'rename',
        ));
    }

    private static function looksLikeUniqueViolation(QueryException $e): bool
    {
        $msg = $e->getMessage();

        return str_contains($msg, 'UNIQUE constraint failed')
            || str_contains($msg, 'Integrity constraint violation')
            || str_contains($msg, 'Duplicate entry');
    }
}
