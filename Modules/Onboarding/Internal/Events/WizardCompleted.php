<?php

declare(strict_types=1);

namespace Modules\Onboarding\Internal\Events;

final readonly class WizardCompleted
{
    public function __construct(public int $userId) {}
}
