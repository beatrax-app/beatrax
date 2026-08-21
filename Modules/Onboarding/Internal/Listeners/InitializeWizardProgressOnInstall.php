<?php

declare(strict_types=1);

namespace Modules\Onboarding\Internal\Listeners;

use Modules\Core\Public\Events\UserInstalled;
use Modules\Onboarding\Internal\Services\WizardProgressInitializer;

final readonly class InitializeWizardProgressOnInstall
{
    public function __construct(private WizardProgressInitializer $initializer) {}

    public function handle(UserInstalled $event): void
    {
        $this->initializer->initialize($event->userId);
    }
}
