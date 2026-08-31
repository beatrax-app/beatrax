<?php

declare(strict_types=1);

namespace Modules\Anomaly\Database\Seeders\Demo;

use Illuminate\Contracts\Bus\Dispatcher;
use Illuminate\Database\DatabaseManager;
use Modules\Anomaly\Internal\Jobs\BackfillAnomaliesJob;
use Modules\Anomaly\Public\Actions\AcknowledgeAnomalyAlert;
use Modules\Anomaly\Public\Actions\DismissAnomalyAlert;
use Modules\Core\Models\User;

// Runs the real backfill rather than hand-writing alert rows, so the demo
// shows what the detectors actually flag. Synchronous because a demo
// install has no queue worker attached.
final class DemoAnomalyAlertsSeeder
{
    public function __construct(
        private readonly DatabaseManager $db,
        private readonly Dispatcher $bus,
        private readonly AcknowledgeAnomalyAlert $acknowledge,
        private readonly DismissAnomalyAlert $dismiss,
    ) {}

    /**
     * @param  array<string, User>  $users
     */
    public function run(array $users): int
    {
        $userIds = array_map(static fn (User $u): int => $u->id, $users);

        foreach ($users as $user) {
            // The job no-ops once anomaly_backfilled_at is stamped, so a
            // re-seed has to clear it to walk the new transactions.
            $this->db->connection()
                ->table('users')
                ->where('id', $user->id)
                ->update(['anomaly_backfilled_at' => null]);

            $this->bus->dispatchSync(new BackfillAnomaliesJob($user->id));
        }

        $primary = $users['demo-1'] ?? null;
        if ($primary !== null) {
            $this->walkTwoAlertsThroughTheirLifecycle($primary);
        }

        return $this->db->connection()
            ->table('anomaly_alerts')
            ->whereIn('user_id', $userIds)
            ->count();
    }

    // The detectors emit nothing but `open`, so the History and Dismissed tabs
    // had no row to show. Through the public actions, which write the
    // transitions a hand-set state column would leave missing.
    private function walkTwoAlertsThroughTheirLifecycle(User $user): void
    {
        // Chosen by position rather than by state, so a re-seed picks the same
        // two rows; both actions no-op on a state that no longer allows the move.
        $rows = $this->db->connection()
            ->table('anomaly_alerts')
            ->where('user_id', $user->id)
            ->orderBy('detected_at')
            ->orderBy('id')
            ->limit(2)
            ->pluck('id')
            ->all();

        $ids = [];
        foreach ($rows as $id) {
            if (is_numeric($id)) {
                $ids[] = (int) $id;
            }
        }

        if (count($ids) < 2) {
            return;
        }

        ($this->acknowledge)($ids[0], $user);
        ($this->dismiss)($ids[1], $user);
    }
}
