<?php

declare(strict_types=1);

namespace Modules\Forecasting\Public\Actions;

use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Database\DatabaseManager;
use Illuminate\Database\QueryException;
use InvalidArgumentException;
use Modules\Core\Models\User;
use Modules\Core\Public\Contracts\Clock;
use Modules\Forecasting\Public\Events\ScenarioCreated;

/**
 * @link ../../../../.docs/features/forecasting/architecture.md
 */
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
            throw new InvalidArgumentException('Scenario name cannot be empty.');
        }
        if (mb_strlen($trimmed) > 120) {
            throw new InvalidArgumentException('Scenario name must be 120 characters or fewer.');
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
                throw new InvalidArgumentException('A scenario with that name already exists.', 0, $e);
            }
            throw $e;
        }

        $this->events->dispatch(new ScenarioCreated(
            userId: $user->id,
            scenarioId: $newId,
            name: $trimmed,
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
