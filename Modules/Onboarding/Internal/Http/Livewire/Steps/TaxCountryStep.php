<?php

declare(strict_types=1);

namespace Modules\Onboarding\Internal\Http\Livewire\Steps;

use Illuminate\Contracts\View\Factory as ViewFactory;
use Illuminate\Contracts\View\View;
use Livewire\Component;
use Modules\Core\Public\Contracts\CurrentUser;
use Modules\Tax\Public\Services\TaxCountrySetup;

/**
 * Optional wizard step — pick a tax country so the per-country deduction
 * categories are seeded before the user first opens the tax cockpit.
 * Mirrors the BudgetsStep shape: a `continue` action that persists the
 * selection (via the Tax module's public TaxCountrySetup surface — the
 * boundary arch tests forbid touching Modules\Tax\Internal from here) and
 * bubbles `wizard.step.completed`, plus a `skip` action that bubbles
 * `wizard.step.skipped`. The parent SetupWizard owns the wizard_progress
 * mutation + the advance.
 *
 * Choosing nothing and continuing is valid — the tax country can always be
 * set later on the /settings page; this step is a convenience, not a gate.
 * On a wizard re-run (?force=1) an already-stored country is preselected.
 */
final class TaxCountryStep extends Component
{
    /** Selected country code, or empty string when nothing is chosen. */
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
            // selectCountry re-validates the code against the allow-list
            // server-side, so an injected/unknown code is silently ignored.
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
