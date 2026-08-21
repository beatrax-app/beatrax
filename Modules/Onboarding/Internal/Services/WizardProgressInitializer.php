<?php

declare(strict_types=1);

namespace Modules\Onboarding\Internal\Services;

use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\DatabaseManager;
use Modules\Core\Public\Contracts\Clock;
use Modules\Onboarding\Internal\Enums\WizardStepStatus;

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

        $connection->transaction(fn () => $this->seedSteps($connection, $userId, $now));
    }

    private function seedSteps(ConnectionInterface $connection, int $userId, string $now): void
    {
        [$existing, $hasPending] = $this->existingState($connection, $userId);

        // A step added after a user finished the wizard seeds as skipped, not
        // pending, so shipping a new step does not re-open a closed wizard.
        $seedStatus = ($existing !== [] && ! $hasPending) ? WizardStepStatus::Skipped->value : WizardStepStatus::Pending->value;

        foreach ($this->registry->steps() as $stepKey) {
            if (! isset($existing[$stepKey])) {
                $this->insertStep($connection, $userId, $stepKey, $seedStatus, $now);
            }
        }
    }

    /**
     * @return array{0: array<string, true>, 1: bool}
     */
    private function existingState(ConnectionInterface $connection, int $userId): array
    {
        $existing = [];
        $hasPending = false;
        foreach ($connection->table('wizard_progress')->where('user_id', $userId)->get(['step_key', 'status']) as $row) {
            if (is_string($row->step_key)) {
                $existing[$row->step_key] = true;
            }
            if ($row->status !== WizardStepStatus::Done->value && $row->status !== WizardStepStatus::Skipped->value) {
                $hasPending = true;
            }
        }

        return [$existing, $hasPending];
    }

    private function insertStep(ConnectionInterface $connection, int $userId, string $stepKey, string $seedStatus, string $now): void
    {
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
}
