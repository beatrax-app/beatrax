<?php

declare(strict_types=1);

namespace Modules\Onboarding\Internal\Services;

use Illuminate\Database\DatabaseManager;
use Modules\Core\Public\Contracts\Clock;

/**
 * Seeds the six per-user `wizard_progress` rows for a fresh install. One
 * row per (user_id, step_key) — the schema's UNIQUE(user_id, step_key)
 * constraint makes the existence-checked insert idempotent so a re-fire
 * from a duplicated `UserInstalled` dispatch never duplicates rows AND
 * never overwrites a row that has already progressed past `pending`.
 *
 * The insert-only behaviour matters because the same `UserInstalled`
 * event the wizard initializer subscribes to is also re-dispatched by
 * the `beatrax:install` command when it re-runs against an already-
 * installed account. A user who is mid-wizard (or has finished it)
 * when the install command re-fires must NOT have their progress
 * demoted; the listener / initializer pairing is the safety net that
 * preserves their in-flight state.
 */
final readonly class WizardProgressInitializer
{
    public function __construct(
        private WizardStepRegistry $registry,
        private DatabaseManager $db,
        private Clock $clock,
    ) {}

    public function initialize(int $userId): void
    {
        $connection = $this->db->connection();
        $now = $this->clock->now()->toDateTimeString();

        $connection->transaction(function () use ($connection, $userId, $now): void {
            $existing = [];
            foreach ($connection->table('wizard_progress')->where('user_id', $userId)->get(['step_key']) as $row) {
                if (is_string($row->step_key)) {
                    $existing[$row->step_key] = true;
                }
            }

            foreach ($this->registry->steps() as $stepKey) {
                if (isset($existing[$stepKey])) {
                    continue;
                }

                $connection->table('wizard_progress')->insert([
                    'user_id' => $userId,
                    'step_key' => $stepKey,
                    'status' => 'pending',
                    'data' => null,
                    'completed_at' => null,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            }
        });
    }
}
