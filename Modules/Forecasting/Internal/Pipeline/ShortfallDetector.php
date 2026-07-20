<?php

declare(strict_types=1);

namespace Modules\Forecasting\Internal\Pipeline;

use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Database\DatabaseManager;
use Illuminate\Support\Carbon;
use Modules\Core\Models\User;
use Modules\Core\Public\Contracts\Clock;
use Modules\Forecasting\Public\Events\ForecastShortfallDetected;

/**
 * @link ../../../../.docs/features/forecasting/architecture.md
 */
final readonly class ShortfallDetector
{
    public function __construct(
        private DatabaseManager $db,
        private Dispatcher $events,
        private Clock $clock,
    ) {}

    /**
     * @param  list<array{date: string, low_minor: int, point_minor: int, high_minor: int, currency: string}>  $dailyPoints
     * @return list<array{starts_at: string, ends_at: string, lowest_balance_minor: int, buffer_used_minor: int, currency: string}>
     */
    public function detect(
        array $dailyPoints,
        int $accountId,
        ?int $scenarioId,
        ?int $effectiveBufferMinor,
        string $currency,
        User $user,
    ): array {
        $buffer = $effectiveBufferMinor ?? 0;

        // Walk the points with a simple state machine. Track the start
        // of the current shortfall (if any) plus the lowest point so
        // far. Emit a window when the balance recovers above the
        // buffer or at end-of-horizon.
        /** @var list<array{starts_at: string, ends_at: string, lowest_balance_minor: int}> $windows */
        $windows = [];
        $startsAt = null;
        $lowestBalance = 0;
        $previousDate = null;

        foreach ($dailyPoints as $day) {
            $point = $day['point_minor'];
            $date = $day['date'];

            if ($point < $buffer) {
                if ($startsAt === null) {
                    $startsAt = $date;
                    $lowestBalance = $point;
                } else {
                    if ($point < $lowestBalance) {
                        $lowestBalance = $point;
                    }
                }
                $previousDate = $date;

                continue;
            }

            // Balance >= buffer — close any open window the day BEFORE
            // this one (the recovery day is not itself in shortfall).
            if ($startsAt !== null) {
                $endsAt = $previousDate ?? $startsAt;
                $windows[] = [
                    'starts_at' => $startsAt,
                    'ends_at' => $endsAt,
                    'lowest_balance_minor' => $lowestBalance,
                ];
                $startsAt = null;
                $lowestBalance = 0;
            }
            $previousDate = $date;
        }

        // End-of-horizon: emit any still-open window with ends_at = last
        // observed day.
        if ($startsAt !== null && $previousDate !== null) {
            $windows[] = [
                'starts_at' => $startsAt,
                'ends_at' => $previousDate,
                'lowest_balance_minor' => $lowestBalance,
            ];
        }

        // Persist + emit events inside a single transaction. Pre-write
        // cleanup is the canonical "the shortfall picture is fully
        // replaced on each projection run" semantic.
        $written = [];
        $this->db->connection()->transaction(function () use (
            $user,
            $accountId,
            $scenarioId,
            $buffer,
            $currency,
            $windows,
            &$written,
        ): void {
            $delete = $this->db->connection()->table('forecast_shortfall_windows')
                ->where('user_id', $user->id)
                ->where('account_id', $accountId);
            if ($scenarioId === null) {
                $delete->whereNull('scenario_id');
            } else {
                $delete->where('scenario_id', $scenarioId);
            }
            $delete->delete();

            $now = $this->clock->now()->toDateTimeString();

            foreach ($windows as $window) {
                $this->db->connection()->table('forecast_shortfall_windows')
                    ->insert([
                        'user_id' => $user->id,
                        'account_id' => $accountId,
                        'scenario_id' => $scenarioId,
                        'starts_at' => $window['starts_at'],
                        'ends_at' => $window['ends_at'],
                        'lowest_balance_minor' => $window['lowest_balance_minor'],
                        'currency' => $currency,
                        'buffer_used_minor' => $buffer,
                        'created_at' => $now,
                        'updated_at' => $now,
                    ]);

                $written[] = [
                    'starts_at' => $window['starts_at'],
                    'ends_at' => $window['ends_at'],
                    'lowest_balance_minor' => $window['lowest_balance_minor'],
                    'buffer_used_minor' => $buffer,
                    'currency' => $currency,
                ];
            }
        });

        foreach ($written as $row) {
            $this->events->dispatch(new ForecastShortfallDetected(
                userId: $user->id,
                accountId: $accountId,
                scenarioId: $scenarioId,
                startsAt: Carbon::parse($row['starts_at']),
                endsAt: Carbon::parse($row['ends_at']),
                lowestBalanceMinor: $row['lowest_balance_minor'],
                currency: $row['currency'],
                bufferUsedMinor: $row['buffer_used_minor'],
            ));
        }

        return $written;
    }
}
