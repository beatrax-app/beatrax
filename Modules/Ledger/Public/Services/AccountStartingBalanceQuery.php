<?php

declare(strict_types=1);

namespace Modules\Ledger\Public\Services;

use Carbon\CarbonImmutable;
use Illuminate\Database\DatabaseManager;
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

    // The one spelling of the lower bound for a grouped, multi-account sum
    // that cannot reach a per-account date in PHP. Both sides must be joined
    // in under these exact table names; a NULL date bounds nothing.
    public const string AT_OR_AFTER_BASELINE_SQL = '(accounts.starting_balance_date is null or transactions.posted_at >= accounts.starting_balance_date)';

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
            ->first(['starting_balance_minor', 'starting_balance_date', 'default_currency']);

        if ($row === null) {
            return self::absent();
        }

        /** @var stdClass $row */
        // A date without an amount is not a baseline: honouring its lower
        // bound would drop every earlier row and add nothing back.
        if (! is_numeric($row->starting_balance_minor)) {
            return self::absent();
        }

        $rawDate = self::toStringOrNull($row->starting_balance_date);

        return [
            'minorUnits' => self::toInt($row->starting_balance_minor),
            'currency' => self::toString($row->default_currency),
            'date' => $rawDate === null ? null : SafeDate::parseDayOrNull($rawDate),
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
            ->whereNotNull('starting_balance_minor')
            ->groupBy('default_currency')
            ->selectRaw('default_currency, SUM(starting_balance_minor) as sum_minor')
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
    private static function absent(): array
    {
        return ['minorUnits' => 0, 'currency' => '', 'date' => null];
    }
}
