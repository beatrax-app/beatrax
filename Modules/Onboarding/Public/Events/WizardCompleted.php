<?php

declare(strict_types=1);

namespace Modules\Onboarding\Public\Events;

final class WizardCompleted
{
    public function __construct(public readonly int $userId) {}
}
