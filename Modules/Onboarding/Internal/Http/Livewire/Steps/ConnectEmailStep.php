<?php

declare(strict_types=1);

namespace Modules\Onboarding\Internal\Http\Livewire\Steps;

use Illuminate\Contracts\View\Factory as ViewFactory;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Locked;
use Livewire\Attributes\On;
use Livewire\Component;
use Modules\Core\Public\Services\UserDataPathService;

final class ConnectEmailStep extends Component
{
    public ?string $authStartedFor = null;

    // Nothing on a phone fetches a mailbox, so the heading and lede that told
    // the reader Beatrax would watch their mail hold on the desktop only.
    #[Locked]
    public bool $onPhone = false;

    public function mount(): void
    {
        $this->onPhone = UserDataPathService::platform() !== null;
    }

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
        return $views->make('onboarding::livewire.steps.connect-email-step', ['onPhone' => $this->onPhone]);
    }
}
