<?php

declare(strict_types=1);

namespace Modules\Core\Public\Services;

use Illuminate\Database\DatabaseManager;
use Illuminate\Database\Eloquent\Builder as EloquentBuilder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Query\Builder;
use Modules\Core\Models\SystemAlert;
use Modules\Core\Models\User;
use Modules\Core\Public\Enums\SystemAlertSeverity;
use stdClass;

final readonly class SystemAlertQuery
{
    // The severity spellings arrive as bindings, not spliced into the statement:
    // the sort the reader actually sees then follows the enum wherever a case
    // value goes, and orderByRaw still receives a literal it can check.
    private const string SEVERITY_RANK_SQL = 'CASE severity WHEN ? THEN 0 WHEN ? THEN 1 ELSE 2 END';

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
            ->orderByRaw(self::SEVERITY_RANK_SQL, [
                SystemAlertSeverity::Critical->value,
                SystemAlertSeverity::Warning->value,
            ])
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

    // The row a user is allowed to act on: their own, or a system-wide one
    // addressed to everybody. Every handler that takes an alert id off the
    // wire reads it through here, so an id alone never reaches a foreign row.
    public function visibleTo(int $alertId, User $user): ?SystemAlert
    {
        $userId = $user->id;

        /** @var SystemAlert|null $alert */
        $alert = SystemAlert::withoutGlobalScopes()
            ->where('id', $alertId)
            ->where(function (EloquentBuilder $owned) use ($userId): void {
                $owned->where('user_id', $userId)->orWhere('user_id', null);
            })
            ->first();

        return $alert;
    }

    public function count(?User $user): int
    {
        return $this->scopedActiveQuery($user)->count();
    }

    // Whether THIS reader has dismissed a system-wide row, which is a
    // different question from whether the row itself is acknowledged. The
    // column answers the second; only an owned row ever carries it.
    public function acknowledgedBy(int $alertId, int $userId): bool
    {
        return $this->db->connection()->table('system_alert_acknowledgements')
            ->where('system_alert_id', $alertId)
            ->where('user_id', $userId)
            ->exists();
    }

    private function scopedActiveQuery(?User $user): Builder
    {
        $connection = $this->db->connection();

        $query = $connection->table('system_alerts')
            ->whereNull('acknowledged_at');

        if ($user !== null) {
            $userId = $user->id;
            $query->where(function (Builder $q) use ($userId): void {
                $q->where('user_id', $userId)->orWhereNull('user_id');
            });

            // The per-reader half of the same question. A background probe
            // passes no user and asks only whether the fault is still open,
            // which nobody's dismissal answers.
            $query->whereNotExists(static function (Builder $ack) use ($connection, $userId): void {
                $ack->select($connection->raw(1))
                    ->from('system_alert_acknowledgements')
                    ->whereColumn('system_alert_acknowledgements.system_alert_id', 'system_alerts.id')
                    ->where('system_alert_acknowledgements.user_id', $userId);
            });
        } else {
            $query->whereNull('user_id');
        }

        return $query;
    }
}
