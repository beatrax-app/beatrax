<?php

declare(strict_types=1);

namespace Modules\Core\Public\Actions;

use Illuminate\Database\DatabaseManager;
use Modules\Core\Models\SystemAlert;
use Modules\Core\Models\User;
use Modules\Core\Public\Contracts\Clock;
use Modules\Core\Public\Services\SystemAlertQuery;
use Modules\Core\Public\Services\SystemAlertWriter;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

final class AcknowledgeSystemAlert
{
    public function __construct(
        private readonly DatabaseManager $db,
        private readonly Clock $clock,
        private readonly SystemAlertWriter $alerts,
        private readonly SystemAlertQuery $alertQuery,
    ) {}

    public function __invoke(int $alertId, User $user): SystemAlert
    {
        $alert = $this->alertQuery->visibleTo($alertId, $user);

        if ($alert === null) {
            throw new NotFoundHttpException('System alert not found.');
        }

        if ($alert->acknowledged_at !== null) {
            return $alert;
        }

        $now = $this->clock->now();

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
}
