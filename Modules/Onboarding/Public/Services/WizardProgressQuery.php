<?php

declare(strict_types=1);

namespace Modules\Onboarding\Public\Services;

use Illuminate\Database\DatabaseManager;
use Modules\Onboarding\Internal\Services\WizardStepRegistry;

// Returns one entry per registry step keyed by step_key in registry
// order, falling back to pending/null for a missing row so a
// partially-seeded user still renders a coherent progress strip.
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
