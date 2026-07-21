<?php

declare(strict_types=1);

namespace Modules\Onboarding\Internal\Http\Livewire\Steps;

use Illuminate\Contracts\View\Factory as ViewFactory;
use Illuminate\Contracts\View\View;
use Livewire\Component;

/**
 * @link ../../../../../../.docs/features/onboarding/architecture.md
 */
final class WelcomeStep extends Component
{
    public function continue(): void
    {
        $this->dispatch('wizard.step.completed');
    }

    public function render(ViewFactory $views): View
    {
        return $views->make('onboarding::livewire.steps.welcome-step');
    }
}
