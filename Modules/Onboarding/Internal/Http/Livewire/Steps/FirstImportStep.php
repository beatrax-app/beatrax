<?php

declare(strict_types=1);

namespace Modules\Onboarding\Internal\Http\Livewire\Steps;

use Illuminate\Contracts\View\Factory as ViewFactory;
use Illuminate\Contracts\View\View;
use Livewire\Component;

/**
 * Wizard step 5 — first import preview. This step is a thin Livewire
 * wrapper that mounts the existing `Modules\Import` `UploadWizard`
 * inline inside the wizard's 1120px-wide card variant (the
 * `<x-onboarding::wiz-card :wide="true">` exception from
 * UI-SPEC §"Density rules"). The user submits a statement just like
 * they would on /imports, except the surrounding chrome is the wizard.
 *
 * Step advancement: the parent UploadWizard's submit() redirects the
 * user to /imports/{id}/preview on success — that's the existing
 * pipeline-shape and is not modified by this plan. When the user
 * confirms the preview the wizard_progress row for `first-import`
 * advances to `done` via the wizard's resume logic the next time the
 * user lands on /setup-wizard. The blade also exposes an "I've
 * imported — continue" manual-advance affordance so a user who has
 * already imported a file (e.g. via the connector steps) can mark this
 * step done without re-uploading.
 *
 * This step is NOT skippable per WizardStepRegistry::SKIPPABLE — the
 * wizard's whole point is to land the user with at least one imported
 * statement. Calling `skip()` here is a no-op at the parent.
 */
final class FirstImportStep extends Component
{
    /**
     * Manual advance affordance. The user clicks "I've imported —
     * continue" once they've returned from /imports/{id}/preview and
     * confirmed at least one row. Dispatches `wizard.step.completed`
     * to the SetupWizard parent so the wizard advances to the done
     * step.
     */
    public function markImported(): void
    {
        $this->dispatch('wizard.step.completed');
    }

    public function render(ViewFactory $views): View
    {
        return $views->make('onboarding::livewire.steps.first-import-step');
    }
}
