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
use Modules\Core\Public\Support\SafeDate;
use Modules\Forecasting\Internal\Exceptions\OpeningBalanceDivergenceWarning;
use Modules\Forecasting\Internal\Jobs\ProjectForecastJob;
use Modules\Forecasting\Public\Enums\ForecastHorizon;
use Modules\Ledger\Public\Services\AccountWriter;
use stdClass;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * @see SetAccountForecastBuffer
 */
final readonly class SetAccountOpeningBalance
{
    public const int DIVERGENCE_WARNING_THRESHOLD_MINOR = 50_000;

    public function __construct(
        private DatabaseManager $db,
        private Clock $clock,
        private AccountWriter $accounts,
        private BusDispatcher $bus,
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

        $this->accounts->write($user->id, $accountId, [
            'opening_balance_minor' => $openingBalanceMinor,
            'opening_balance_as_of_date' => $openingBalanceMinor === null ? null : $openingBalanceAsOfDate,
        ]);

        foreach (ForecastHorizon::days() as $horizon) {
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
        $asOf = SafeDate::dayOrNull($openingBalanceAsOfDate);
        if ($asOf === null) {
            throw new InvalidArgumentException(Lang::get('forecasting::opening_balance.errors.date_invalid'));
        }
        $today = $this->clock->now()->startOfDay();
        if ($asOf->greaterThan($today)) {
            throw new InvalidArgumentException(Lang::get('forecasting::opening_balance.errors.date_future'));
        }

        if ($allowDivergence) {
            return;
        }

        // No figure to compare against means no divergence to warn about. The
        // sum that used to stand in for one was not this account's position.
        $sum = $this->positionOn($accountId, $user, $asOf);
        if ($sum === null) {
            return;
        }

        $diff = $openingBalanceMinor - $sum;
        if (abs($diff) > self::DIVERGENCE_WARNING_THRESHOLD_MINOR) {
            throw new OpeningBalanceDivergenceWarning(
                diffMinor: $diff,
                sumOfTransactionsMinor: $sum,
                userValueMinor: $openingBalanceMinor,
            );
        }
    }

    // Derived WITHOUT the override being validated, so the check cannot agree
    // with whatever was last saved. Null where the account names no currency:
    // the column is denominated in the account's own, and a one-click button
    // must withhold a figure rather than offer a guessed one.
    /**
     * @link ../../../../.docs/features/forecasting/opening-balance-suggestion.md
     */
    public function positionOn(int $accountId, User $user, CarbonImmutable $asOf): ?int
    {
        $account = $this->db->connection()->table('accounts')
            ->where('id', $accountId)
            ->where('user_id', $user->id)
            ->first(['default_currency', 'starting_balance_minor', 'starting_balance_date']);

        if ($account === null) {
            return null;
        }

        /** @var stdClass $account */
        $currency = is_string($account->default_currency ?? null) ? $account->default_currency : '';
        if ($currency === '') {
            return null;
        }

        $baselineMinor = is_numeric($account->starting_balance_minor ?? null)
            ? (int) $account->starting_balance_minor
            : 0;
        $baselineDate = is_string($account->starting_balance_date ?? null) && $account->starting_balance_date !== ''
            ? SafeDate::normalisedDayOrNull($account->starting_balance_date)
            : null;

        // settled_amount_minor in the account's own denomination and bounded on
        // posted_at, the same pair every balance in this app sums. amount_minor
        // is the NATIVE figure and summing it across currencies added dollars
        // to euros: EUR6,604.64 was offered back as EUR3,612.14.
        $rows = $this->db->connection()->table('transactions')
            ->where('user_id', $user->id)
            ->where('account_id', $accountId)
            ->where('settled_currency', $currency)
            ->where('posted_at', '<=', $asOf->toDateString());

        if ($baselineDate instanceof CarbonImmutable) {
            $rows->where('posted_at', '>=', $baselineDate->toDateString());
        }

        return $baselineMinor + (int) $rows->sum('settled_amount_minor');
    }
}
