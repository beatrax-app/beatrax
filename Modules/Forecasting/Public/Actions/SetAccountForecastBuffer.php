<?php

declare(strict_types=1);

namespace Modules\Forecasting\Public\Actions;

use Illuminate\Contracts\Bus\Dispatcher as BusDispatcher;
use Illuminate\Database\DatabaseManager;
use InvalidArgumentException;
use Modules\Core\Models\User;
use Modules\Core\Public\Contracts\Clock;
use Modules\Core\Public\Support\Lang;
use Modules\DriftAlerts\Public\Actions\AcknowledgeDriftAlert;
use Modules\Forecasting\Internal\Jobs\ProjectForecastJob;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * @see AcknowledgeDriftAlert
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
            throw new InvalidArgumentException(Lang::get('forecasting::buffer.errors.non_negative'));
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
