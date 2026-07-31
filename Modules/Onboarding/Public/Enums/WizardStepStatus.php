<?php

declare(strict_types=1);

namespace Modules\Onboarding\Public\Enums;

// The per-step state of a wizard_progress row: `pending`, `in_progress`,
// `done`, or `skipped`. The column stays string (enforced by a trigger);
// this enum is the one canonical spelling callers map through.
/**
 * @link ../../../../.docs/features/onboarding/architecture.md
 */
enum WizardStepStatus: string
{
    case Pending = 'pending';

    case InProgress = 'in_progress';

    case Done = 'done';

    case Skipped = 'skipped';
}
