<?php

declare(strict_types=1);

namespace Modules\Anomaly\Database\Seeders\Demo;

use Illuminate\Contracts\Bus\Dispatcher;
use Illuminate\Database\DatabaseManager;
use Modules\Anomaly\Internal\Jobs\BackfillAnomaliesJob;
use Modules\Core\Models\User;

// Runs the real backfill rather than hand-writing alert rows, so the demo
// shows what the detectors actually flag. Synchronous because a demo
// install has no queue worker attached.
final class DemoAnomalyAlertsSeeder
{
    public function __construct(
        private readonly DatabaseManager $db,
        private readonly Dispatcher $bus,
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

        return $this->db->connection()
            ->table('anomaly_alerts')
            ->whereIn('user_id', $userIds)
            ->count();
    }
}
