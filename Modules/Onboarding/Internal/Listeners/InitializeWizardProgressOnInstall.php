<?php

declare(strict_types=1);

namespace Modules\Onboarding\Internal\Listeners;

use Modules\Core\Public\Events\UserInstalled;
use Modules\Onboarding\Internal\Services\WizardProgressInitializer;

/**
 * Listens for `UserInstalled` (dispatched by `SignupAction` after the
 * signup transaction commits, and by the `beatrax:install` console
 * command on re-runs) and seeds the new user's six wizard_progress
 * rows so the first `/setup` page request can render the wizard
 * without an explicit bootstrap call.
 *
 * The initializer is idempotent (UNIQUE(user_id, step_key) + the
 * empty-update closure inside `updateOrInsert`), so a duplicate
 * dispatch of UserInstalled — e.g. a re-run of the install command
 * against an already-installed account — is a no-op.
 */
final readonly class InitializeWizardProgressOnInstall
{
    public function __construct(private WizardProgressInitializer $initializer) {}

    public function handle(UserInstalled $event): void
    {
        $this->initializer->initialize($event->userId);
    }
}
