<?php

declare(strict_types=1);

namespace Modules\Ledger\Public\Services;

use Carbon\CarbonImmutable;
use Illuminate\Database\DatabaseManager;
use Illuminate\Database\Query\Builder;
use Modules\Core\Models\User;
use Modules\Core\Public\Concerns\CoercesScalars;
use Modules\Core\Public\Support\SafeDate;
use stdClass;

/**
 * @link ../../../../.docs/features/ledger/architecture.md#accountstartingbalancequery--the-baseline-every-balance-starts-from
 */
final readonly class AccountStartingBalanceQuery
{
    use CoercesScalars;

    // The balance the reader typed in Settings outranks the one an import
    // detected: it is the only number they entered deliberately, and an
    // override that diverges warns rather than yields. Amount and date move
    // together, so a row is answered by one pair or the other, never a mix.
    private const string EFFECTIVE_MINOR_SQL = 'case when accounts.opening_balance_minor is not null then accounts.opening_balance_minor else accounts.starting_balance_minor end';

    private const string EFFECTIVE_DATE_SQL = 'case when accounts.opening_balance_minor is not null then accounts.opening_balance_as_of_date else accounts.starting_balance_date end';

    // The one spelling of the lower bound for a grouped, multi-account sum
    // that cannot reach a per-account date in PHP. Both sides must be joined
    // in under these exact table names; a NULL date bounds nothing. date()
    // on both, because the two columns are stored in two different shapes.

    // The currency branch mirrors AccountBalanceQuery::sumFromBaseline(): a row
    // settled outside the account's own denomination has no baseline covering
    // it, and bounding it dropped a -USD221.00 holding off the calendar's
    // past-day line. coalesce, or a NULL currency would bound every row.
    /**
     * @link ../../../../.docs/features/ledger/reconcile-needs-an-anchor.md#two-columns-two-shapes
     */
    public const string AT_OR_AFTER_BASELINE_SQL = '('.self::EFFECTIVE_DATE_SQL.' is null'
        ." or transactions.settled_currency <> coalesce(accounts.default_currency, '')"
        .' or date(transactions.posted_at) >= date('.self::EFFECTIVE_DATE_SQL.'))';

    public function __construct(
        private DatabaseManager $db,
    ) {}

    /**
     * @return array{minorUnits: int, currency: string, date: CarbonImmutable|null}
     */
    public function forAccount(int $accountId, User $user): array
    {
        $row = $this->db->connection()
            ->table('accounts')
            ->where('id', $accountId)
            ->where('user_id', $user->id)
            ->first([
                'starting_balance_minor', 'starting_balance_date',
                'opening_balance_minor', 'opening_balance_as_of_date',
                'default_currency',
            ]);

        if ($row === null) {
            return self::zeroIn('');
        }

        /** @var stdClass $row */
        $override = is_numeric($row->opening_balance_minor);
        $minor = $override ? $row->opening_balance_minor : $row->starting_balance_minor;

        // A date without an amount is not a baseline: honouring its lower
        // bound would drop every earlier row and add nothing back. The account
        // still names the currency the zero is denominated in.
        if (! is_numeric($minor)) {
            return self::zeroIn(self::toString($row->default_currency));
        }

        $rawDate = self::toStringOrNull($override ? $row->opening_balance_as_of_date : $row->starting_balance_date);

        return [
            'minorUnits' => self::toInt($minor),
            'currency' => self::toString($row->default_currency),
            'date' => $rawDate === null ? null : SafeDate::normalisedDayOrNull($rawDate),
        ];
    }

    /**
     * @param  list<int>  $accountIds
     * @return array<string, int>
     */
    public function bucketedByDefaultCurrency(array $accountIds, User $user): array
    {
        if ($accountIds === []) {
            return [];
        }

        $rows = $this->db->connection()
            ->table('accounts')
            ->where('user_id', $user->id)
            ->whereIn('id', $accountIds)
            ->where(static function (Builder $either): void {
                $either->whereNotNull('opening_balance_minor')
                    ->orWhereNotNull('starting_balance_minor');
            })
            ->groupBy('default_currency')
            ->selectRaw('default_currency, SUM('.self::EFFECTIVE_MINOR_SQL.') as sum_minor')
            ->get();

        $byCurrency = [];
        foreach ($rows as $row) {
            /** @var stdClass $row */
            $byCurrency[self::toString($row->default_currency)] = self::toInt($row->sum_minor);
        }

        return $byCurrency;
    }

    /**
     * @return array{minorUnits: int, currency: string, date: CarbonImmutable|null}
     */
    private static function zeroIn(string $currency): array
    {
        return ['minorUnits' => 0, 'currency' => $currency, 'date' => null];
    }
}
