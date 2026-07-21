<?php

declare(strict_types=1);

namespace Modules\Onboarding\Internal\Http\Livewire\Steps;

use Illuminate\Contracts\View\Factory as ViewFactory;
use Illuminate\Contracts\View\View;
use Livewire\Component;
use Modules\Core\Public\Contracts\CurrentUser;
use Modules\Tax\Public\Services\TaxCountrySetup;

/**
 * @link ../../../../../../.docs/features/onboarding/architecture.md
 */
final class TaxCountryStep extends Component
{
    public string $taxCountryCode = '';

    public function mount(CurrentUser $currentUser, TaxCountrySetup $setup): void
    {
        if ($currentUser->isAuthenticated()) {
            $this->taxCountryCode = $setup->currentCountry($currentUser->id());
        }
    }

    public function continue(CurrentUser $currentUser, TaxCountrySetup $setup): void
    {
        if ($this->taxCountryCode !== '' && $currentUser->isAuthenticated()) {
            // Re-validates the code against the allow-list server-side, so
            // an injected/unknown code is silently ignored.
            $setup->selectCountry($currentUser->id(), $this->taxCountryCode);
        }

        $this->dispatch('wizard.step.completed');
    }

    public function skip(): void
    {
        $this->dispatch('wizard.step.skipped');
    }

    public function render(TaxCountrySetup $setup, ViewFactory $views): View
    {
        return $views->make('onboarding::livewire.steps.tax-country-step', [
            'countries' => $setup->availableCountries(),
        ]);
    }
}
