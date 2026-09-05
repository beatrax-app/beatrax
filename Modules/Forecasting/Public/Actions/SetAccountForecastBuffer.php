<?php

declare(strict_types=1);

namespace Modules\Forecasting\Public\Actions;

use Illuminate\Contracts\Bus\Dispatcher as BusDispatcher;
use Illuminate\Database\DatabaseManager;
use InvalidArgumentException;
use Modules\Core\Models\User;
use Modules\Core\Public\Support\Lang;
use Modules\DriftAlerts\Public\Actions\AcknowledgeDriftAlert;
use Modules\Forecasting\Internal\Jobs\ProjectForecastJob;
use Modules\Forecasting\Public\Enums\ForecastHorizon;
use Modules\Ledger\Public\Services\AccountWriter;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * @see AcknowledgeDriftAlert
 */
final readonly class SetAccountForecastBuffer
{
    public function __construct(
        private DatabaseManager $db,
        private AccountWriter $accounts,
        private BusDispatcher $bus,
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

        $this->accounts->write($user->id, $accountId, ['forecast_min_buffer_minor' => $bufferMinor]);

        foreach (ForecastHorizon::days() as $horizon) {
            $this->bus->dispatch(new ProjectForecastJob(
                userId: $user->id,
                scenarioId: null,
                horizonDays: $horizon,
            ));
        }
    }
}
