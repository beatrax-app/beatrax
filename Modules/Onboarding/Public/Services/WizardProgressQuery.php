<?php

declare(strict_types=1);

namespace Modules\Onboarding\Public\Services;

use Illuminate\Database\DatabaseManager;
use Modules\Onboarding\Internal\Services\WizardStepRegistry;

/**
 * Public read-only view over a user's wizard_progress rows. Returns a
 * deterministic 6-entry array keyed by step_key in registry order so
 * the SetupWizard's progress-dots strip can render without re-querying
 * or re-sorting per dot. Missing rows fall back to a `pending` /
 * `null` payload so a partially-seeded user still renders a coherent
 * progress strip rather than rendering nothing.
 *
 * Query explicitly filters by `user_id` per the multi-user-readiness
 * rule; never trusts the BelongsToUser global scope, which falls
 * through to "no scope" under non-HTTP contexts (queue workers,
 * artisan commands, listener tests).
 */
final readonly class WizardProgressQuery
{
    public function __construct(
        private DatabaseManager $db,
        private WizardStepRegistry $registry,
    ) {}

    /**
     * @return array<string, array{status: string, completed_at: ?string}>
     */
    public function list(int $userId): array
    {
        $rows = $this->db->connection()
            ->table('wizard_progress')
            ->where('user_id', $userId)
            ->get(['step_key', 'status', 'completed_at']);

        $byStep = [];
        foreach ($rows as $row) {
            if (is_string($row->step_key) && is_string($row->status)) {
                $byStep[$row->step_key] = [
                    'status' => $row->status,
                    'completed_at' => is_string($row->completed_at) ? $row->completed_at : null,
                ];
            }
        }

        $progress = [];
        foreach ($this->registry->steps() as $stepKey) {
            $progress[$stepKey] = $byStep[$stepKey] ?? [
                'status' => 'pending',
                'completed_at' => null,
            ];
        }

        return $progress;
    }
}
