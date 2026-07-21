<?php

declare(strict_types=1);

namespace Modules\Onboarding\Internal\Services;

use Illuminate\Database\DatabaseManager;
use Modules\Core\Public\Contracts\Clock;

/**
 * @link ../../../../.docs/features/onboarding/architecture.md
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
            $hasPending = false;
            foreach ($connection->table('wizard_progress')->where('user_id', $userId)->get(['step_key', 'status']) as $row) {
                if (is_string($row->step_key)) {
                    $existing[$row->step_key] = true;
                }
                if ($row->status !== 'done' && $row->status !== 'skipped') {
                    $hasPending = true;
                }
            }

            // See architecture.md for why a newly-registered step seeds as
            // skipped (not pending) for an already-finished user.
            $seedStatus = ($existing !== [] && ! $hasPending) ? 'skipped' : 'pending';

            foreach ($this->registry->steps() as $stepKey) {
                if (isset($existing[$stepKey])) {
                    continue;
                }

                $connection->table('wizard_progress')->insert([
                    'user_id' => $userId,
                    'step_key' => $stepKey,
                    'status' => $seedStatus,
                    'data' => null,
                    'completed_at' => null,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            }
        });
    }
}
