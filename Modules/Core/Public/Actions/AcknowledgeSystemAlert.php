<?php

declare(strict_types=1);

namespace Modules\Core\Public\Actions;

use Carbon\CarbonImmutable;
use Illuminate\Database\DatabaseManager;
use Modules\Core\Models\SystemAlert;
use Modules\Core\Models\User;
use Modules\Core\Public\Contracts\Clock;
use Modules\Core\Public\Services\SystemAlertQuery;
use Modules\Core\Public\Services\SystemAlertWriter;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

final readonly class AcknowledgeSystemAlert
{
    public function __construct(
        private DatabaseManager $db,
        private Clock $clock,
        private SystemAlertWriter $alerts,
        private SystemAlertQuery $alertQuery,
    ) {}

    public function __invoke(int $alertId, User $user): SystemAlert
    {
        $alert = $this->alertQuery->visibleTo($alertId, $user);

        if ($alert === null) {
            throw new NotFoundHttpException('System alert not found.');
        }

        $now = $this->clock->now();

        // A system-wide row is addressed to everybody on this install, so one
        // member acknowledging it is one member's answer and not the row's:
        // stamping the shared row took a WAL-mode or PRAGMA-drift warning off
        // the other member's screen for good.
        if ($alert->user_id === null) {
            $this->acknowledgeForReader($alertId, $user->id, $now);

            return $alert;
        }

        if ($alert->acknowledged_at !== null) {
            return $alert;
        }

        // Through the writer, never beside it: the stamp and the op that
        // carries it are one operation there. `refresh()` reads the row back
        // afterwards, so the returned model holds neither a stale key — a
        // trigger releases `dedup_key` off this column — nor a stale stamp.
        $this->alerts->acknowledgeForUser($alertId, $alert->user_id, $now);

        return $alert->refresh();
    }

    // Nothing is put on the op log: a system-wide row is about the machine
    // that noticed the fault, the peer never received it, and this dismissal
    // names a primary key the peer does not hold.
    private function acknowledgeForReader(int $alertId, int $userId, CarbonImmutable $now): void
    {
        if ($this->alertQuery->acknowledgedBy($alertId, $userId)) {
            return;
        }

        // insertOrIgnore, not insert: the read above and this write are two
        // statements, and a double tap on a phone sends two requests that both
        // pass it. The unique index then made the second one a 500 on a button
        // whose whole job is to make a warning go away.
        $this->db->connection()->table('system_alert_acknowledgements')->insertOrIgnore([
            'system_alert_id' => $alertId,
            'user_id' => $userId,
            'acknowledged_at' => $now->toDateTimeString(),
        ]);
    }
}
