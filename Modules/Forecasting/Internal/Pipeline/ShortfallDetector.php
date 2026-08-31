<?php

declare(strict_types=1);

namespace Modules\Forecasting\Internal\Pipeline;

use Carbon\CarbonImmutable;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Database\DatabaseManager;
use Modules\Core\Models\User;
use Modules\Core\Public\Contracts\Clock;
use Modules\Forecasting\Internal\Support\BufferFloor;
use Modules\Forecasting\Public\Events\ForecastShortfallDetected;

final readonly class ShortfallDetector
{
    public function __construct(
        private DatabaseManager $db,
        private Dispatcher $events,
        private Clock $clock,
    ) {}

    /**
     * @param  list<array{date: string, low_minor: int, point_minor: int, high_minor: int, currency: string}>  $dailyPoints
     * @param  int|null  $effectiveBufferMinor  the floor to judge against, or
     *                                          null where no floor is in force
     * @return list<array{starts_at: string, ends_at: string, lowest_balance_minor: int, buffer_used_minor: int, currency: string}>
     */
    public function detect(
        array $dailyPoints,
        int $accountId,
        ?int $scenarioId,
        int $horizonDays,
        ?int $effectiveBufferMinor,
        string $currency,
        User $user,
    ): array {
        // Null is not zero: it is "no floor is in force here", and the rows
        // still have to be cleared so a floor the reader has just taken away
        // does not leave its last run's windows standing.
        $buffer = $effectiveBufferMinor ?? BufferFloor::ZERO_CROSSING;
        $windows = $effectiveBufferMinor === null ? [] : $this->buildWindows($dailyPoints, $buffer);
        $written = $this->persistWindows($windows, $accountId, $scenarioId, $horizonDays, $buffer, $currency, $user);

        // A what-if raises nothing. Every read outside this module — the inbox
        // above all — is the baseline's, and a scenario that reached one would
        // be telling the reader a dip they never chose to have is coming.
        if ($scenarioId !== null) {
            return $written;
        }

        foreach ($written as $row) {
            $this->events->dispatch(new ForecastShortfallDetected(
                userId: $user->id,
                accountId: $accountId,
                scenarioId: $scenarioId,
                startsAt: CarbonImmutable::parse($row['starts_at']),
                endsAt: CarbonImmutable::parse($row['ends_at']),
                lowestBalanceMinor: $row['lowest_balance_minor'],
                currency: $row['currency'],
                bufferUsedMinor: $row['buffer_used_minor'],
            ));
        }

        return $written;
    }

    /**
     * @param  list<array{date: string, low_minor: int, point_minor: int, high_minor: int, currency: string}>  $dailyPoints
     * @return list<array{starts_at: string, ends_at: string, lowest_balance_minor: int}>
     */
    private function buildWindows(array $dailyPoints, int $buffer): array
    {
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
                } elseif ($point < $lowestBalance) {
                    $lowestBalance = $point;
                }
                $previousDate = $date;

                continue;
            }

            // Closes on the previous day: the recovery day itself is not in
            // shortfall, so including it would overstate the window by one.
            if ($startsAt !== null) {
                $windows[] = [
                    'starts_at' => $startsAt,
                    'ends_at' => $previousDate ?? $startsAt,
                    'lowest_balance_minor' => $lowestBalance,
                ];
                $startsAt = null;
                $lowestBalance = 0;
            }
            $previousDate = $date;
        }

        if ($startsAt !== null && $previousDate !== null) {
            $windows[] = [
                'starts_at' => $startsAt,
                'ends_at' => $previousDate,
                'lowest_balance_minor' => $lowestBalance,
            ];
        }

        return $windows;
    }

    /**
     * @param  list<array{starts_at: string, ends_at: string, lowest_balance_minor: int}>  $windows
     * @return list<array{starts_at: string, ends_at: string, lowest_balance_minor: int, buffer_used_minor: int, currency: string}>
     */
    private function persistWindows(array $windows, int $accountId, ?int $scenarioId, int $horizonDays, int $buffer, string $currency, User $user): array
    {
        // Delete-then-write inside one transaction, scoped to this run's own
        // horizon: the five horizons all project the same account, and a delete
        // that ignored the horizon left whichever finished last speaking for
        // all of them.
        $written = [];
        $this->db->connection()->transaction(function () use (
            $user,
            $accountId,
            $scenarioId,
            $horizonDays,
            $buffer,
            $currency,
            $windows,
            &$written,
        ): void {
            $delete = $this->db->connection()->table('forecast_shortfall_windows')
                ->where('user_id', $user->id)
                ->where('account_id', $accountId)
                ->where('horizon_days', $horizonDays);
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
                        'horizon_days' => $horizonDays,
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

        return $written;
    }
}
