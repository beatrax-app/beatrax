<?php

declare(strict_types=1);

namespace Modules\Onboarding\Internal\Enums;

// wizard_progress.status stays a string column, constrained by a database
// trigger; this enum is the one canonical spelling callers map through.
enum WizardStepStatus: string
{
    case Pending = 'pending';

    case InProgress = 'in_progress';

    case Done = 'done';

    case Skipped = 'skipped';
}
