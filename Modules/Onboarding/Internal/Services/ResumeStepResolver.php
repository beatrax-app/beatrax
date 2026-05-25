<?php

declare(strict_types=1);

namespace Modules\Onboarding\Internal\Services;

use Illuminate\Database\DatabaseManager;

/**
 * Decides which step the user lands on when they re-enter `/setup` mid-
 * flow. Walks the registry's canonical step order against the user's
 * own `wizard_progress` rows and returns the step_key to mount, or the
 * empty-string sentinel when every step is done or skipped (callers
 * read the sentinel as "redirect to /").
 *
 * Decision order:
 *  1. The single `in_progress` step wins. This drops the user back
 *     exactly where they were when the app closed mid-step — the
 *     "Welcome back" path.
 *  2. Else the first `pending` step in registry order. This keeps the
 *     wizard moving forward even when the user has completed or
 *     skipped earlier steps and then re-entered.
 *  3. Else the empty-string sentinel — every step is done or skipped.
 *
 * The query explicitly filters by `user_id` so the resolver remains
 * correct under unauthenticated CLI / queue / test contexts where the
 * BelongsToUser global scope falls through to "no scope".
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
            if (($statusByStep[$stepKey] ?? null) === 'in_progress') {
                return $stepKey;
            }
        }

        foreach ($this->registry->steps() as $stepKey) {
            if (($statusByStep[$stepKey] ?? null) === 'pending') {
                return $stepKey;
            }
        }

        return '';
    }
}
