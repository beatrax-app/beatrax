<?php

declare(strict_types=1);

namespace Modules\Forecasting\Public\Actions;

use Illuminate\Contracts\Bus\Dispatcher as BusDispatcher;
use Illuminate\Database\DatabaseManager;
use InvalidArgumentException;
use Modules\Core\Models\User;
use Modules\Core\Public\Contracts\Clock;
use Modules\Forecasting\Internal\Jobs\ProjectForecastJob;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Public Action that persists the per-account forecast buffer.
 *
 * Mirrors `Modules\DriftAlerts\Public\Actions\AcknowledgeDriftAlert`:
 *   - Cross-user 404 via `(account_id, user_id)` guard — raises
 *     `NotFoundHttpException` when the account does not belong to the
 *     caller.
 *   - Server-side validation rejects negative buffer values with
 *     `InvalidArgumentException` (carrying the UI-SPEC-locked message
 *     "Buffer must be zero or positive.").
 *   - Write happens inside a single DB transaction; on success the
 *     three baseline projection horizons (30 / 60 / 90) are dispatched
 *     so the chart re-renders with the new floor line and any new
 *     shortfall band.
 *
 * `$bufferMinor === null` clears the buffer (effective zero-crossing
 * default).
 */
final class SetAccountForecastBuffer
{
    public function __construct(
        private readonly DatabaseManager $db,
        private readonly Clock $clock,
        private readonly BusDispatcher $bus,
    ) {}

    public function __invoke(int $accountId, User $user, ?int $bufferMinor): void
    {
        $account = $this->db->connection()->table('accounts')
            ->where('id', $accountId)
            ->where('user_id', $user->id)
            ->first(['id']);

        if ($account === null) {
            throw new NotFoundHttpException('Account not found.');
        }

        if ($bufferMinor !== null && $bufferMinor < 0) {
            throw new InvalidArgumentException('Buffer must be zero or positive.');
        }

        $this->db->connection()->transaction(function () use ($accountId, $user, $bufferMinor): void {
            $this->db->connection()->table('accounts')
                ->where('id', $accountId)
                ->where('user_id', $user->id)
                ->update([
                    'forecast_min_buffer_minor' => $bufferMinor,
                    'updated_at' => $this->clock->now()->toDateTimeString(),
                ]);
        });

        // Re-project the baseline across all three horizons so the
        // chart band + shortfall windows reflect the new buffer.
        foreach ([30, 60, 90] as $horizon) {
            $this->bus->dispatch(new ProjectForecastJob(
                userId: $user->id,
                scenarioId: null,
                horizonDays: $horizon,
            ));
        }
    }
}
