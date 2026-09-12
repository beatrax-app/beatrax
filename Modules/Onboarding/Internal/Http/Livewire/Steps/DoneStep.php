<?php

declare(strict_types=1);

namespace Modules\Onboarding\Internal\Http\Livewire\Steps;

use Illuminate\Contracts\View\Factory as ViewFactory;
use Illuminate\Contracts\View\View;
use Livewire\Component;

final class DoneStep extends Component
{
    // Bubbled rather than written here, like every other step: the parent owns
    // the wizard_progress mutation, and this is the row nothing ever marked.
    // It stayed pending however often a reader pressed Finish, so the wizard
    // reopened here under the resume banner for the life of the install.
    public function finish(): void
    {
        $this->dispatch('wizard.step.completed');
    }

    public function render(ViewFactory $views): View
    {
        return $views->make('onboarding::livewire.steps.done-step');
    }
}
