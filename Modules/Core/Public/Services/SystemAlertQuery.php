<?php

declare(strict_types=1);

namespace Modules\Core\Public\Services;

use Illuminate\Database\DatabaseManager;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Query\Builder;
use Modules\Core\Models\SystemAlert;
use Modules\Core\Models\User;
use stdClass;

/**
 * Public read API over the `system_alerts` table — the only sanctioned
 * way to surface operational-failure rows to the dashboard banner.
 *
 * `active(?User)` returns the user's own un-acknowledged rows AND the
 * system-wide (user_id IS NULL) un-acknowledged rows in a single
 * Eloquent Collection, ordered critical → warning → info, with
 * chronological tie-break inside each severity tier. The combined
 * per-user-OR-system-wide shape is the load-bearing safety property:
 * the banner MUST surface SQLite-PRAGMA drifts (system-wide) and the
 * user's own corrupt-backup alerts in the same render pass without
 * leaking another user's private rows.
 *
 * Implementation runs through the raw `DatabaseManager::table()` Query
 * Builder so the compound `where(function () { … })` predicate, the
 * `orderByRaw` severity-tier sort, and the row hydration into
 * `SystemAlert` models stay PHPStan-strict-rules clean — Eloquent
 * statics are allowed by the project DI feedback memory, but the
 * `staticMethod.dynamicCall` rule rejects calling instance methods on
 * Eloquent's Builder object after `Model::query()`. Hydrating from the
 * raw row set via `SystemAlert::hydrate()` recovers the model surface
 * the banner expects without tripping the analyser.
 */
final readonly class SystemAlertQuery
{
    public function __construct(
        private DatabaseManager $db,
    ) {}

    /**
     * Active (un-acknowledged) alerts visible to `$user`. NULL `$user`
     * narrows the read to system-wide rows only (used by background
     * probes that need to inspect global state without an auth context).
     *
     * Ordering: severity tier (critical → warning → info) then
     * `created_at` ascending. The Blade banner renders the resulting
     * Collection top-to-bottom, so the user always sees the most
     * critical event first within each cohort.
     *
     * @return Collection<int, SystemAlert>
     */
    public function active(?User $user): Collection
    {
        $rows = $this->scopedActiveQuery($user)
            ->orderByRaw("CASE severity WHEN 'critical' THEN 0 WHEN 'warning' THEN 1 ELSE 2 END")
            ->orderBy('created_at', 'asc')
            ->select(['id', 'user_id', 'kind', 'severity', 'message', 'metadata', 'created_at', 'acknowledged_at'])
            ->get();

        $arrayRows = [];
        foreach ($rows as $row) {
            /** @var stdClass $row */
            $arrayRows[] = (array) $row;
        }

        /** @var Collection<int, SystemAlert> $collection */
        $collection = SystemAlert::hydrate($arrayRows);

        return $collection;
    }

    /**
     * Per-user-OR-system-wide active row count. Uses the raw query
     * builder so the compound `where(function () { … })` predicate
     * stays clean under larastan-strict-rules and avoids the Eloquent
     * `::count()` `staticMethod.dynamicCall` complaint.
     */
    public function count(?User $user): int
    {
        return $this->scopedActiveQuery($user)->count();
    }

    /**
     * Shared per-user-OR-system-wide active-row predicate.
     */
    private function scopedActiveQuery(?User $user): Builder
    {
        $query = $this->db->connection()->table('system_alerts')
            ->whereNull('acknowledged_at');

        if ($user !== null) {
            $userId = $user->id;
            $query->where(function (Builder $q) use ($userId): void {
                $q->where('user_id', $userId)->orWhereNull('user_id');
            });
        } else {
            $query->whereNull('user_id');
        }

        return $query;
    }
}
