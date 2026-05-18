<?php

declare(strict_types=1);

namespace Modules\Forecasting\Public\Actions;

use Carbon\CarbonImmutable;
use Illuminate\Contracts\Bus\Dispatcher as BusDispatcher;
use Illuminate\Database\DatabaseManager;
use InvalidArgumentException;
use Modules\Core\Models\User;
use Modules\Core\Public\Contracts\Clock;
use Modules\Forecasting\Internal\Jobs\ProjectForecastJob;
use Modules\Forecasting\Public\Exceptions\OpeningBalanceDivergenceWarning;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Public Action that persists the per-account opening balance + as-of
 * date.
 *
 * Mirrors `SetAccountForecastBuffer`:
 *   - Cross-user 404 via `(account_id, user_id)` guard — raises
 *     `NotFoundHttpException` when the account does not belong to the
 *     caller.
 *   - Server-side validation: when an opening balance is provided, the
 *     as-of date is mandatory and must parse to a valid ISO date that
 *     is not in the future.
 *   - Soft-warning: when the entered opening balance diverges from the
 *     sum of imported transactions on the account up to the as-of date
 *     by more than €500 (50000 minor units), the Action raises
 *     `OpeningBalanceDivergenceWarning`. The caller catches the
 *     exception, surfaces the warning UI, and re-invokes with
 *     `$allowDivergence=true` to commit anyway.
 *   - Write happens inside a single DB transaction. On success the
 *     three baseline projection horizons (30 / 60 / 90) are dispatched
 *     so the chart re-renders against the new anchor.
 *
 * `$openingBalanceMinor === null` clears both columns (no opening
 * balance configured for the account); the soft-warning check is
 * skipped in the clear-path.
 */
final class SetAccountOpeningBalance
{
    /**
     * Diff threshold above which the Action raises
     * `OpeningBalanceDivergenceWarning` instead of silently committing.
     * 50_000 minor units = €500.
     */
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
            if ($openingBalanceAsOfDate === null || trim($openingBalanceAsOfDate) === '') {
                throw new InvalidArgumentException('Pick the date this opening balance applies to.');
            }
            $parsed = \DateTimeImmutable::createFromFormat('Y-m-d', $openingBalanceAsOfDate);
            if ($parsed === false || $parsed->format('Y-m-d') !== $openingBalanceAsOfDate) {
                throw new InvalidArgumentException('Opening balance date must be a valid ISO date (YYYY-MM-DD).');
            }
            $today = $this->clock->now()->startOfDay();
            $asOf = CarbonImmutable::parse($openingBalanceAsOfDate)->startOfDay();
            if ($asOf->greaterThan($today)) {
                throw new InvalidArgumentException('Opening balance date cannot be in the future.');
            }

            if (! $allowDivergence) {
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

    /**
     * Compute the sum of imported transactions on the account up to a
     * given as-of date. Exposed publicly so the
     * `OpeningBalanceEditor` Livewire SFC can populate the
     * "Use diederik's number" chip with the computed value without
     * re-invoking the full Action.
     */
    public function sumOfTransactionsForAccount(int $accountId, User $user, string $asOfDate): int
    {
        return (int) $this->db->connection()->table('transactions')
            ->where('user_id', $user->id)
            ->where('account_id', $accountId)
            ->where('booked_at', '<=', CarbonImmutable::parse($asOfDate)->endOfDay()->toDateTimeString())
            ->sum('amount_minor');
    }
}
