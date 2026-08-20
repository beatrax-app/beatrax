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
use Modules\Forecasting\Public\Events\ScenarioCreated;
use Modules\Sync\Public\Events\EntityMutated;

final class CreateScenario
{
    public function __construct(
        private readonly DatabaseManager $db,
        private readonly Clock $clock,
        private readonly Dispatcher $events,
    ) {}

    public function __invoke(User $user, string $name, ?string $description = null): int
    {
        $trimmed = trim($name);
        if ($trimmed === '') {
            throw new InvalidArgumentException(Lang::get('forecasting::scenario.errors.name_empty'));
        }
        if (mb_strlen($trimmed) > ForecastScenario::MAX_NAME_LENGTH) {
            throw new InvalidArgumentException(Lang::get('forecasting::scenario.errors.name_too_long', ['max' => ForecastScenario::MAX_NAME_LENGTH]));
        }

        $now = $this->clock->now()->toDateTimeString();

        try {
            $newId = $this->db->connection()->transaction(function () use ($user, $trimmed, $description, $now): int {
                return $this->db->connection()->table('forecast_scenarios')->insertGetId([
                    'user_id' => $user->id,
                    'name' => $trimmed,
                    'description' => $description,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            });
        } catch (QueryException $e) {
            if (self::looksLikeUniqueViolation($e)) {
                throw new InvalidArgumentException(Lang::get('forecasting::scenario.errors.name_taken'), 0, $e);
            }
            throw $e;
        }

        $this->events->dispatch(new ScenarioCreated(
            userId: $user->id,
            scenarioId: $newId,
            name: $trimmed,
        ));

        // Scenarios had merge rules and no capture, so one created after
        // pairing stayed on the device that named it. The timestamps ride
        // along because the scenario picker orders by created_at.
        $this->events->dispatch(new EntityMutated(
            table: 'forecast_scenarios',
            pk: $newId,
            userId: (int) $user->id,
            mutationType: 'create',
            dirtyFields: [
                'user_id' => (int) $user->id,
                'name' => $trimmed,
                'description' => $description,
                'created_at' => $now,
                'updated_at' => $now,
            ],
        ));

        return $newId;
    }

    private static function looksLikeUniqueViolation(QueryException $e): bool
    {
        $msg = $e->getMessage();

        return str_contains($msg, 'UNIQUE constraint failed')
            || str_contains($msg, 'Integrity constraint violation')
            || str_contains($msg, 'Duplicate entry');
    }
}
