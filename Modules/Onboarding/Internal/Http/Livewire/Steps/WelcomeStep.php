<?php

declare(strict_types=1);

namespace Modules\Onboarding\Internal\Http\Livewire\Steps;

use Illuminate\Contracts\View\Factory as ViewFactory;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Locked;
use Livewire\Component;
use Modules\Core\Public\Services\UserDataPathService;

final class WelcomeStep extends Component
{
    // The inbox pipeline is desktop-only, so the third row of what the wizard
    // promises to set up is true on one platform and not the other.
    #[Locked]
    public bool $onPhone = false;

    public function mount(): void
    {
        $this->onPhone = UserDataPathService::platform() !== null;
    }

    public function continue(): void
    {
        $this->dispatch('wizard.step.completed');
    }

    public function render(ViewFactory $views): View
    {
        return $views->make('onboarding::livewire.steps.welcome-step', ['onPhone' => $this->onPhone]);
    }
}
