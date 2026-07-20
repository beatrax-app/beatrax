<?php

declare(strict_types=1);

namespace Modules\Core\Public\Actions;

use Illuminate\Database\DatabaseManager;
use Illuminate\Database\Query\Builder;
use Modules\Core\Models\SystemAlert;
use Modules\Core\Models\User;
use Modules\Core\Public\Contracts\Clock;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Acknowledges a `system_alerts` row by stamping `acknowledged_at`
 * with the current clock value, removing the row from the dashboard
 * banner's active stack without deleting it (the audit trail of
 * operational incidents accumulates forever).
 *
 * Lookup is scoped to the caller user OR a system-wide row
 * (`user_id IS NULL`), so a user can dismiss a SQLite-PRAGMA banner
 * but not silence another user's private corrupt-backup alert. Any
 * cross-user attempt raises `NotFoundHttpException` to avoid disclosing
 * row existence, and the failed read leaves the original row's
 * `acknowledged_at` unchanged.
 *
 * Idempotent: an already-acknowledged row returns the existing model
 * with no write, so a double-click or a stale Livewire batched
 * request never bumps the stamp to a fresh value.
 *
 * The mutation runs inside `connection()->transaction(...)` so the
 * status flip is atomic on the row level; SQLite's single-writer
 * model already serialises writers, but the wrapper documents intent
 * and gives a hook for the future addition of a transitions audit
 * table.
 *
 * The lookup uses the raw `DatabaseManager::table()` Query Builder
 * for the compound `where(function () { … })` predicate so the call
 * stays clean under larastan-strict-rules; the resolved row id is
 * then re-hydrated through `SystemAlert::query()->findOrFail()` so
 * the rest of the action operates on a real Eloquent model with all
 * casts (immutable_datetime on acknowledged_at, array on metadata)
 * applied.
 */
final class AcknowledgeSystemAlert
{
    public function __construct(
        private readonly DatabaseManager $db,
        private readonly Clock $clock,
    ) {}

    public function __invoke(int $alertId, User $user): SystemAlert
    {
        $userId = $user->id;

        $row = $this->db->connection()->table('system_alerts')
            ->where('id', $alertId)
            ->where(function (Builder $q) use ($userId): void {
                $q->where('user_id', $userId)->orWhereNull('user_id');
            })
            ->first();

        if ($row === null) {
            throw new NotFoundHttpException('System alert not found.');
        }

        // Bypass the BelongsToUser global UserScope so system-wide rows
        // (`user_id IS NULL`) are reachable — the raw `table()` predicate
        // above already enforced "owned-by-user OR system-wide" access, so
        // this is a query-builder concern, not a security carve-out.
        /** @var SystemAlert $alert */
        $alert = SystemAlert::withoutGlobalScopes()->findOrFail($alertId);

        if ($alert->acknowledged_at !== null) {
            return $alert;
        }

        $now = $this->clock->now();

        $this->db->connection()->transaction(static function () use ($alert, $now): void {
            $alert->update(['acknowledged_at' => $now]);
        });

        return $alert->refresh();
    }
}
