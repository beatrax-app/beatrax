<?php

declare(strict_types=1);

namespace Modules\Onboarding\Internal\Services;

use Modules\Onboarding\Internal\Enums\WizardStepStatus;

final readonly class ResumeStepResolver
{
    public function __construct(
        private WizardStepRegistry $registry,
        private WizardProgressQuery $progress,
    ) {}

    public function resolve(int $userId): string
    {
        $progress = $this->progress->list($userId);

        foreach ($this->registry->steps() as $stepKey) {
            if ($this->isResumable($stepKey, WizardStepStatus::InProgress, $progress)) {
                return $stepKey;
            }
        }

        return $this->earliestReachablePending($progress);
    }

    /**
     * @param  array<string, array{status: string, completed_at: ?string}>  $progress
     */
    private function earliestReachablePending(array $progress): string
    {
        foreach ($this->registry->steps() as $stepKey) {
            if ($this->isResumable($stepKey, WizardStepStatus::Pending, $progress)) {
                return $stepKey;
            }
        }

        return '';
    }

    // A step behind a prior the reader cannot clear is one the jump guard would
    // refuse, and resuming onto it would strand them there. That is reachable
    // now that a step can be reopened: inserting a step ahead of the one someone
    // left in progress puts a pending row in front of it.
    /**
     * @param  array<string, array{status: string, completed_at: ?string}>  $progress
     */
    private function isResumable(string $stepKey, WizardStepStatus $status, array $progress): bool
    {
        return ($progress[$stepKey]['status'] ?? null) === $status->value
            && $this->registry->isReachable($stepKey, $progress);
    }
}
