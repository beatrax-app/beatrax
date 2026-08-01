<?php

declare(strict_types=1);

namespace Modules\Forecasting\Public\Actions;

use Carbon\CarbonImmutable;
use Illuminate\Contracts\Bus\Dispatcher as BusDispatcher;
use Illuminate\Database\DatabaseManager;
use InvalidArgumentException;
use Modules\Core\Models\User;
use Modules\Core\Public\Contracts\Clock;
use Modules\Core\Public\Support\Lang;
use Modules\Forecasting\Internal\Jobs\ProjectForecastJob;
use Modules\Forecasting\Public\Exceptions\OpeningBalanceDivergenceWarning;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * @see SetAccountForecastBuffer
 */
final class SetAccountOpeningBalance
{
    // 50_000 minor units = €500 — the diff threshold above which this
    // Action raises OpeningBalanceDivergenceWarning instead of silently
    // committing the user-entered opening balance.
    public const DIVERGENCE_WARNING_THRESHOLD_MINOR = 50_000;

    public function __construct(
        private readonly DatabaseManager $db,
        private readonly Clock $clock,
        private readonly BusDispatcher $bus,
    ) {}

    public function __invoke(
        int $accountId,
        User $user,
        ?int $openingBalanceMinor,
        ?string $openingBalanceAsOfDate,
        bool $allowDivergence = false,
    ): void {
        $account = $this->db->connection()->table('accounts')
            ->where('id', $accountId)
            ->where('user_id', $user->id)
            ->first(['id']);

        if ($account === null) {
            throw new NotFoundHttpException('Account not found.');
        }

        if ($openingBalanceMinor !== null) {
            $this->validateOpeningBalance($accountId, $user, $openingBalanceMinor, $openingBalanceAsOfDate, $allowDivergence);
        }

        $this->db->connection()->transaction(function () use ($accountId, $user, $openingBalanceMinor, $openingBalanceAsOfDate): void {
            $this->db->connection()->table('accounts')
                ->where('id', $accountId)
                ->where('user_id', $user->id)
                ->update([
                    'opening_balance_minor' => $openingBalanceMinor,
                    'opening_balance_as_of_date' => $openingBalanceMinor === null ? null : $openingBalanceAsOfDate,
                    'updated_at' => $this->clock->now()->toDateTimeString(),
                ]);
        });

        foreach (ProjectForecastJob::HORIZON_DAYS as $horizon) {
            $this->bus->dispatch(new ProjectForecastJob(
                userId: $user->id,
                scenarioId: null,
                horizonDays: $horizon,
            ));
        }
    }

    private function validateOpeningBalance(
        int $accountId,
        User $user,
        int $openingBalanceMinor,
        ?string $openingBalanceAsOfDate,
        bool $allowDivergence,
    ): void {
        if ($openingBalanceAsOfDate === null || trim($openingBalanceAsOfDate) === '') {
            throw new InvalidArgumentException(Lang::get('forecasting::opening_balance.errors.date_required'));
        }
        $parsed = \DateTimeImmutable::createFromFormat('Y-m-d', $openingBalanceAsOfDate);
        if ($parsed === false || $parsed->format('Y-m-d') !== $openingBalanceAsOfDate) {
            throw new InvalidArgumentException(Lang::get('forecasting::opening_balance.errors.date_invalid'));
        }
        $today = $this->clock->now()->startOfDay();
        $asOf = CarbonImmutable::parse($openingBalanceAsOfDate)->startOfDay();
        if ($asOf->greaterThan($today)) {
            throw new InvalidArgumentException(Lang::get('forecasting::opening_balance.errors.date_future'));
        }

        if ($allowDivergence) {
            return;
        }

        $sum = (int) $this->db->connection()->table('transactions')
            ->where('user_id', $user->id)
            ->where('account_id', $accountId)
            ->where('booked_at', '<=', $asOf->endOfDay()->toDateTimeString())
            ->sum('amount_minor');
        $diff = $openingBalanceMinor - $sum;
        if (abs($diff) > self::DIVERGENCE_WARNING_THRESHOLD_MINOR) {
            throw new OpeningBalanceDivergenceWarning(
                diffMinor: $diff,
                sumOfTransactionsMinor: $sum,
                userValueMinor: $openingBalanceMinor,
            );
        }
    }

    // Exposed publicly so OpeningBalanceEditor can populate the "Use
    // beatrax's number" chip without re-invoking the full Action.
    public function sumOfTransactionsForAccount(int $accountId, User $user, string $asOfDate): int
    {
        return (int) $this->db->connection()->table('transactions')
            ->where('user_id', $user->id)
            ->where('account_id', $accountId)
            ->where('booked_at', '<=', CarbonImmutable::parse($asOfDate)->endOfDay()->toDateTimeString())
            ->sum('amount_minor');
    }
}
