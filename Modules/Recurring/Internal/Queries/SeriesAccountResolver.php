<?php

declare(strict_types=1);

namespace Modules\Recurring\Internal\Queries;

use Illuminate\Database\DatabaseManager;
use Modules\Core\Models\User;
use Modules\Core\Public\Concerns\CoercesScalars;
use stdClass;

// recurring_series carries no account column, so the originating account is
// derived from the newest occurrence and the transaction it points at.
final readonly class SeriesAccountResolver
{
    use CoercesScalars;

    public function __construct(private DatabaseManager $db) {}

    /**
     * @param  list<int>  $seriesIds  already normalised; callers use SeriesIds::normalise()
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

    /**
     * @param  list<int>  $seriesIds
     * @return array<int, int>
     */
    private function fromLatestOccurrence(array $seriesIds, User $user): array
    {
        $rows = $this->db->connection()->table('recurring_series_occurrences as rso')
            ->join('transactions as t', 't.id', '=', 'rso.transaction_id')
            ->where('rso.user_id', $user->id)
            ->where('t.user_id', $user->id)
            ->whereIn('rso.recurring_series_id', $seriesIds)
            ->orderByDesc('rso.observed_at')
            ->orderByDesc('rso.id')
            ->get(['rso.recurring_series_id as series_id', 't.account_id as account_id']);

        $map = [];
        foreach ($rows as $row) {
            /** @var stdClass $row */
            $seriesId = self::toInt($row->series_id);

            // First row per series wins: the ORDER BY puts the newest first.
            if ($seriesId > 0 && ! isset($map[$seriesId])) {
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
        $row = $this->db->connection()->table('accounts')
            ->where('user_id', $user->id)
            ->orderBy('name')
            ->orderBy('id')
            ->first(['id']);

        if ($row === null) {
            return null;
        }

        /** @var stdClass $row */
        $id = self::toInt($row->id);

        return $id > 0 ? $id : null;
    }
}
