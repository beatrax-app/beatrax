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

        $this->db->connection()->transaction(static function () use ($alert, $now): void {
            $alert->update(['acknowledged_at' => $now]);
        });

        // Only an owned row travels: the writer drops a system-wide one,
        // because a peer never received that alert and a SET naming a pk it
        // does not hold is an op it can only quarantine.
        $this->alerts->captureAcknowledgement(
            $alertId,
            $alert->user_id,
            $now->toDateTimeString(),
        );

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

        $this->db->connection()->table('system_alert_acknowledgements')->insert([
            'system_alert_id' => $alertId,
            'user_id' => $userId,
            'acknowledged_at' => $now->toDateTimeString(),
        ]);
    }
}
