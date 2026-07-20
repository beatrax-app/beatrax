<?php

declare(strict_types=1);

namespace Modules\Forecasting\Internal\StateMachines;

use Illuminate\Database\DatabaseManager;
use Modules\Core\Public\Contracts\Clock;
use Modules\Forecasting\Internal\Exceptions\InvalidForecastRunTransitionException;
use Modules\Forecasting\Models\ForecastRun;
use RuntimeException;

/**
 * @link ../../../../.docs/features/forecasting/architecture.md
 */
final readonly class ForecastRunStateMachine
{
    /**
     * @return array<string, list<string>>
     */
    public static function transitionMap(): array
    {
        return [
            'pending' => ['running', 'failed'],
            'running' => ['complete', 'failed'],
            'complete' => [],
            'failed' => [],
        ];
    }

    public function __construct(
        private DatabaseManager $db,
        private Clock $clock,
    ) {}

    public function start(ForecastRun $run): void
    {
        $now = $this->clock->now()->toDateTimeString();
        $this->transition($run, 'running', ['started_at' => $now, 'updated_at' => $now]);
    }

    public function complete(ForecastRun $run): void
    {
        $now = $this->clock->now()->toDateTimeString();
        $this->transition($run, 'complete', ['completed_at' => $now, 'updated_at' => $now]);
    }

    public function fail(ForecastRun $run): void
    {
        $now = $this->clock->now()->toDateTimeString();
        $this->transition($run, 'failed', ['completed_at' => $now, 'updated_at' => $now]);
    }

    /**
     * @param  array<string, string|int|null>  $extraColumns
     */
    private function transition(ForecastRun $run, string $toStatus, array $extraColumns): void
    {
        $runId = self::toInt($run->id);

        $this->db->connection()->transaction(function () use ($runId, $toStatus, $extraColumns, $run): void {
            $connection = $this->db->connection();
            $connection->statement('PRAGMA busy_timeout = 5000');

            $row = $connection->table('forecast_runs')
                ->where('id', $runId)
                ->lockForUpdate()
                ->first();

            if ($row === null) {
                throw new RuntimeException(
                    "ForecastRunStateMachine: forecast_runs row {$runId} not found.",
                );
            }

            $currentStatus = self::toString($row->status);
            $this->guardTransition($runId, $currentStatus, $toStatus);

            $connection->table('forecast_runs')
                ->where('id', $runId)
                ->update(array_merge($extraColumns, ['status' => $toStatus]));

            // Refresh the caller's Eloquent model so the caller sees
            // the updated status + lifecycle stamps without needing to
            // call ->fresh() themselves.
            $run->refresh();
        });
    }

    private function guardTransition(int $runId, string $currentStatus, string $toStatus): void
    {
        $allowed = self::transitionMap()[$currentStatus] ?? [];
        if (! in_array($toStatus, $allowed, strict: true)) {
            throw InvalidForecastRunTransitionException::forTransition($runId, $currentStatus, $toStatus);
        }
    }

    private static function toInt(mixed $value): int
    {
        return is_numeric($value) ? (int) $value : 0;
    }

    private static function toString(mixed $value): string
    {
        return is_string($value) ? $value : (is_scalar($value) ? (string) $value : '');
    }
}
