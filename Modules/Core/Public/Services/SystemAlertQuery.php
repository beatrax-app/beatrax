<?php

declare(strict_types=1);

namespace Modules\Core\Public\Services;

use Illuminate\Database\DatabaseManager;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Query\Builder;
use Modules\Core\Models\SystemAlert;
use Modules\Core\Models\User;
use stdClass;

final readonly class SystemAlertQuery
{
    public function __construct(
        private DatabaseManager $db,
    ) {}

    // NULL `$user` narrows the read to system-wide rows only (used by
    // background probes inspecting global state without an auth context).
    /**
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

        return SystemAlert::hydrate($arrayRows);
    }

    public function count(?User $user): int
    {
        return $this->scopedActiveQuery($user)->count();
    }

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
