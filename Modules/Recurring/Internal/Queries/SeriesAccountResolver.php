<?php

declare(strict_types=1);

namespace Modules\Recurring\Internal\Queries;

use Illuminate\Database\DatabaseManager;
use Modules\Core\Models\User;
use Modules\Core\Public\Concerns\CoercesScalars;
use Modules\Recurring\Internal\Support\NewestOccurrenceFirst;
use Modules\Recurring\Internal\Support\SeriesTables;
use stdClass;

// recurring_series carries no account column, so the originating account is
// derived from the newest occurrence and the transaction it points at.
final readonly class SeriesAccountResolver
{
    use CoercesScalars;

    public function __construct(private DatabaseManager $db) {}

    /**
     * @param  list<int>  $seriesIds  already normalised; callers use SeriesIds::normalize()
     * @return array<int, int>
     */
    public function forSeriesIds(array $seriesIds, User $user): array
    {
        $map = $this->fromLatestOccurrence($seriesIds, $user);

        $missing = array_values(array_diff($seriesIds, array_keys($map)));
        if ($missing === []) {
            return $map;
        }

        return $map + $this->fallbackAccount($missing, $user);
    }

    // One row per series comes back, not the whole occurrence history: the
    // table gains a row per observed payment forever, and the answer is a map
    // of at most one account id per series.
    /**
     * @param  list<int>  $seriesIds
     * @return array<int, int>
     */
    private function fromLatestOccurrence(array $seriesIds, User $user): array
    {
        $connection = $this->db->connection();

        $ranked = $connection->table('recurring_series_occurrences as o')
            ->join(SeriesTables::TRANSACTIONS, 't.id', '=', 'o.transaction_id')
            ->where('o.user_id', $user->id)
            ->where('t.user_id', $user->id)
            ->whereIn('o.recurring_series_id', $seriesIds)
            ->select(['o.recurring_series_id as series_id', 't.account_id as account_id'])
            ->selectRaw(
                'row_number() over ('
                .'partition by o.recurring_series_id order by '.NewestOccurrenceFirst::SQL
                .') as occurrence_rank'
            );

        $rows = $connection->query()
            ->fromSub($ranked, 'latest')
            ->where('latest.occurrence_rank', 1)
            ->get(['latest.series_id', 'latest.account_id']);

        $map = [];
        foreach ($rows as $row) {
            $seriesId = self::toInt($row->series_id);

            if ($seriesId > 0) {
                $map[$seriesId] = self::toInt($row->account_id);
            }
        }

        return $map;
    }

    // Ownership is re-checked here, so a cross-user or deleted id gets no
    // fallback account at all.
    /**
     * @param  list<int>  $seriesIds
     * @return array<int, int>
     */
    private function fallbackAccount(array $seriesIds, User $user): array
    {
        $owned = $this->ownedSeriesIds($seriesIds, $user);
        if ($owned === []) {
            return [];
        }

        $accountId = $this->firstAccountId($user);
        if ($accountId === null) {
            return [];
        }

        return array_fill_keys($owned, $accountId);
    }

    /**
     * @param  list<int>  $seriesIds
     * @return list<int>
     */
    private function ownedSeriesIds(array $seriesIds, User $user): array
    {
        $rows = $this->db->connection()->table('recurring_series')
            ->where('user_id', $user->id)
            ->whereIn('id', $seriesIds)
            ->pluck('id');

        $owned = [];
        foreach ($rows as $id) {
            $i = self::toInt($id);
            if ($i > 0) {
                $owned[] = $i;
            }
        }

        return $owned;
    }

    private function firstAccountId(User $user): ?int
    {
        // The id is a per-device autoincrement, so two accounts sharing a name
        // — a second ASN current account, a second PayPal — were parted by a
        // number the peer hands to a different row. The IBAN is what the
        // account IS, carries unique(user_id, iban), and is never sealed.
        $row = $this->db->connection()->table('accounts')
            ->where('user_id', $user->id)
            ->orderBy('name')
            ->orderBy('iban')
            ->first(['id']);

        if ($row === null) {
            return null;
        }

        /** @var stdClass $row */
        $id = self::toInt($row->id);

        return $id > 0 ? $id : null;
    }
}
