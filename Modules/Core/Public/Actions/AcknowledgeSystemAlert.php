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
 * @link ../../../../.docs/features/core/architecture.md
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
