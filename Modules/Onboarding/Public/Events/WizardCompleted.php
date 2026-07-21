<?php

declare(strict_types=1);

namespace Modules\Onboarding\Public\Events;

// Dispatched from DoneStep::finish(). Carries no state beyond $userId —
// the wizard's own wizard_progress rows hold the per-step completion data.
final class WizardCompleted
{
    public function __construct(public readonly int $userId) {}
}
