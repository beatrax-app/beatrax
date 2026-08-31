<?php

declare(strict_types=1);

namespace Modules\Ledger\Public\Services;

use Illuminate\Database\DatabaseManager;
use Modules\Core\Models\User;
use Modules\Core\Public\Concerns\CoercesScalars;
use Modules\Ledger\Public\Dto\Period;

/**
 * @link ../../../../.docs/features/ledger/architecture.md#populatedperiodquery--where-the-records-actually-are
 */
final readonly class PopulatedPeriodQuery
{
    use CoercesScalars;

    public function __construct(
        private DatabaseManager $db,
        private PeriodQuery $periods,
    ) {}

    // Null means there is nowhere to send the reader: either the period in view
    // already holds records, or the ledger holds none at all -- and that second
    // reader needs the import path, not a jump to nothing.
    public function latestWithRecords(User $user, Period $inView): ?Period
    {
        if ($this->hasRecordsIn($user, $inView)) {
            return null;
        }

        // One MAX over the (user_id, posted_at) index rather than stepping a
        // period at a time until something answers: a reader whose last import
        // was two years ago would pay two dozen round-trips for that walk.
        $latest = $this->db->connection()
            ->table('transactions')
            ->where('user_id', $user->id)
            ->max('posted_at');

        $postedAt = self::toString($latest);

        // The reader's OWN start day, not the guard's and not the first of the
        // month: on a 25th-to-24th calendar, 17 April belongs to the period
        // that opened in March, and a month-based answer would land the reader
        // on the empty period beside their records.
        return $postedAt === ''
            ? null
            : $this->periods->containingDateForDay($postedAt, $user->period_start_day);
    }

    // Whether the ledger holds anything a given period could be compared with:
    // a row on or before its last day. A comparison drawn against a period the
    // ledger never reaches is not a small comparison, it is a fabricated one —
    // the trend card read "+EUR 250,00" against a month with no ledger behind it.
    public function reachesBackInto(User $user, Period $period): bool
    {
        // posted_at is a DATE column, so the bound is a bare Y-m-d: compared
        // against a datetime, SQLite's string comparison drops the boundary day.
        return $this->db->connection()
            ->table('transactions')
            ->where('user_id', $user->id)
            ->where('posted_at', '<', $period->endExclusive->toDateString())
            ->exists();
    }

    private function hasRecordsIn(User $user, Period $period): bool
    {
        return $this->db->connection()
            ->table('transactions')
            ->where('user_id', $user->id)
            ->where('posted_at', '>=', $period->start->toDateString())
            ->where('posted_at', '<', $period->endExclusive->toDateString())
            ->exists();
    }
}
