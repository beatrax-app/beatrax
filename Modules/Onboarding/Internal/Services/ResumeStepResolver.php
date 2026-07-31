<?php

declare(strict_types=1);

namespace Modules\Onboarding\Internal\Services;

use Illuminate\Database\DatabaseManager;
use Modules\Onboarding\Public\Enums\WizardStepStatus;

/**
 * @link ../../../../.docs/features/onboarding/architecture.md
 */
final readonly class ResumeStepResolver
{
    public function __construct(
        private WizardStepRegistry $registry,
        private DatabaseManager $db,
    ) {}

    public function resolve(int $userId): string
    {
        $rows = $this->db->connection()
            ->table('wizard_progress')
            ->where('user_id', $userId)
            ->get(['step_key', 'status']);

        $statusByStep = [];
        foreach ($rows as $row) {
            if (is_string($row->step_key) && is_string($row->status)) {
                $statusByStep[$row->step_key] = $row->status;
            }
        }

        foreach ($this->registry->steps() as $stepKey) {
            if (($statusByStep[$stepKey] ?? null) === WizardStepStatus::InProgress->value) {
                return $stepKey;
            }
        }

        foreach ($this->registry->steps() as $stepKey) {
            if (($statusByStep[$stepKey] ?? null) === WizardStepStatus::Pending->value) {
                return $stepKey;
            }
        }

        return '';
    }
}
