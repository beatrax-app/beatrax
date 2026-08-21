<?php

declare(strict_types=1);

namespace Modules\Onboarding\Internal\Http\Livewire\Steps;

use Illuminate\Contracts\View\Factory as ViewFactory;
use Illuminate\Contracts\View\View;
use Livewire\Component;
use Modules\Core\Public\Contracts\CurrentUser;
use Modules\Core\Public\Services\UserCountry;

final class TaxCountryStep extends Component
{
    public string $taxCountryCode = '';

    public function mount(CurrentUser $currentUser, UserCountry $countries): void
    {
        if ($currentUser->isAuthenticated()) {
            $this->taxCountryCode = $countries->current($currentUser->id());
        }
    }

    public function continue(CurrentUser $currentUser, UserCountry $countries): void
    {
        if ($this->taxCountryCode !== '' && $currentUser->isAuthenticated()) {
            // Re-checks the code against the allow-list server-side; an
            // injected one is dropped.
            $countries->store($currentUser->id(), $this->taxCountryCode);
        }

        $this->dispatch('wizard.step.completed');
    }

    public function skip(): void
    {
        $this->dispatch('wizard.step.skipped');
    }

    public function render(UserCountry $countries, ViewFactory $views): View
    {
        return $views->make('onboarding::livewire.steps.tax-country-step', [
            'countries' => $countries->options(),
        ]);
    }
}
