<?php

declare(strict_types=1);

namespace Modules\Onboarding\Internal\Http\Livewire\Steps;

use Illuminate\Contracts\View\Factory as ViewFactory;
use Illuminate\Contracts\View\View;
use Livewire\Component;

/**
 * The wizard's first step — the static welcome page that introduces the
 * setup flow. Renders the UI-SPEC §"Wizard welcome step" copy block
 * (eyebrow + H1 + lede + three glyph rows) and exposes a single
 * `continue` action that bubbles `wizard.step.completed` up to the
 * SetupWizard parent. The parent owns the wizard_progress mutation +
 * the advance to the next step.
 *
 * No user input, no validation, no DB read — this step is a hard-coded
 * page so the wizard's first impression is calm static content.
 */
final class WelcomeStep extends Component
{
    /**
     * Dispatches `wizard.step.completed` to the SetupWizard parent so
     * the parent advances the wizard_progress row + currentStepKey to
     * the next registry step. The event carries no payload — the parent
     * already knows which step is active because it owns
     * `$currentStepKey`.
     */
    public function continue(): void
    {
        $this->dispatch('wizard.step.completed');
    }

    public function render(ViewFactory $views): View
    {
        return $views->make('onboarding::livewire.steps.welcome-step');
    }
}
