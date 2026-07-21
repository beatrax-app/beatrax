<?php

declare(strict_types=1);

namespace Modules\Onboarding\Internal\Http\Livewire\Steps;

use Illuminate\Contracts\View\Factory as ViewFactory;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\On;
use Livewire\Component;

/**
 * @link ../../../../../../.docs/features/onboarding/architecture.md
 */
final class ConnectEmailStep extends Component
{
    // Cleared after the modal dispatches oauth-client-wizard:saved or the
    // user cancels; used by the blade to dim the non-clicked button.
    public ?string $authStartedFor = null;

    public function authorizeProvider(string $provider): void
    {
        if (! in_array($provider, ['gmail', 'microsoft'], strict: true)) {
            return;
        }

        $this->authStartedFor = $provider;
        $this->dispatch('oauth-client-wizard:open', provider: $provider);
    }

    #[On('oauth-client-wizard:saved')]
    public function onAuthSaved(): void
    {
        $this->authStartedFor = null;
        $this->dispatch('wizard.step.completed');
    }

    public function skip(): void
    {
        $this->dispatch('wizard.step.skipped');
    }

    public function render(ViewFactory $views): View
    {
        return $views->make('onboarding::livewire.steps.connect-email-step');
    }
}
