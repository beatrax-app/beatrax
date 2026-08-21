<?php

declare(strict_types=1);

namespace Modules\Onboarding\Internal\Http\Livewire\Steps;

use Illuminate\Contracts\View\Factory as ViewFactory;
use Illuminate\Contracts\View\View;
use Livewire\Component;
use Modules\Core\Public\Contracts\CurrentUser;
use Modules\Core\Public\Services\UserCountry;

final class CountryStep extends Component
{
    public string $countryCode = '';

    public function mount(CurrentUser $currentUser, UserCountry $countries): void
    {
        if ($currentUser->isAuthenticated()) {
            $this->countryCode = $countries->current($currentUser->id());
        }
    }

    public function continue(CurrentUser $currentUser, UserCountry $countries): void
    {
        if ($this->countryCode !== '' && $currentUser->isAuthenticated()) {
            // Re-checks the code against the allow-list server-side; an
            // injected one is dropped.
            $countries->store($currentUser->id(), $this->countryCode);
        }

        $this->dispatch('wizard.step.completed');
    }

    public function skip(): void
    {
        $this->dispatch('wizard.step.skipped');
    }

    public function render(UserCountry $countries, ViewFactory $views): View
    {
        return $views->make('onboarding::livewire.steps.country-step', [
            'countries' => $countries->options(),
        ]);
    }
}
