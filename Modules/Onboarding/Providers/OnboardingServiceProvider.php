<?php

declare(strict_types=1);

namespace Modules\Onboarding\Providers;

use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Support\ServiceProvider;
use Livewire\LivewireManager;
use Modules\Core\Public\Events\UserInstalled;
use Modules\Core\Public\Support\LoadsModuleResources;
use Modules\Onboarding\Internal\Http\Livewire\SetupWizard;
use Modules\Onboarding\Internal\Http\Livewire\StartingBalanceCard;
use Modules\Onboarding\Internal\Http\Livewire\Steps\BudgetsStep;
use Modules\Onboarding\Internal\Http\Livewire\Steps\ConnectBankStep;
use Modules\Onboarding\Internal\Http\Livewire\Steps\ConnectCardStep;
use Modules\Onboarding\Internal\Http\Livewire\Steps\ConnectEmailStep;
use Modules\Onboarding\Internal\Http\Livewire\Steps\ConnectPaypalStep;
use Modules\Onboarding\Internal\Http\Livewire\Steps\DoneStep;
use Modules\Onboarding\Internal\Http\Livewire\Steps\FirstImportStep;
use Modules\Onboarding\Internal\Http\Livewire\Steps\TaxCountryStep;
use Modules\Onboarding\Internal\Http\Livewire\Steps\WelcomeStep;
use Modules\Onboarding\Internal\Listeners\InitializeWizardProgressOnInstall;
use Modules\Onboarding\Internal\Services\ResumeStepResolver;
use Modules\Onboarding\Internal\Services\WizardProgressInitializer;
use Modules\Onboarding\Internal\Services\WizardStepRegistry;
use Modules\Onboarding\Public\Services\WizardProgressQuery;

final class OnboardingServiceProvider extends ServiceProvider
{
    use LoadsModuleResources;

    public function register(): void
    {
        $this->app->singleton(WizardStepRegistry::class);
        $this->app->singleton(WizardProgressInitializer::class);
        $this->app->singleton(ResumeStepResolver::class);
        $this->app->singleton(WizardProgressQuery::class);
    }

    public function boot(Dispatcher $events, LivewireManager $livewire): void
    {
        $this->loadModuleResources('onboarding');

        $events->listen(UserInstalled::class, InitializeWizardProgressOnInstall::class);

        $livewire->component('onboarding.setup-wizard', SetupWizard::class);
        $livewire->component('onboarding.steps.welcome-step', WelcomeStep::class);
        $livewire->component('onboarding.steps.done-step', DoneStep::class);
        $livewire->component('onboarding.steps.connect-bank-step', ConnectBankStep::class);
        $livewire->component('onboarding.steps.connect-paypal-step', ConnectPaypalStep::class);
        $livewire->component('onboarding.steps.connect-card-step', ConnectCardStep::class);
        $livewire->component('onboarding.steps.connect-email-step', ConnectEmailStep::class);
        $livewire->component('onboarding.steps.first-import-step', FirstImportStep::class);
        $livewire->component('onboarding.steps.budgets-step', BudgetsStep::class);
        $livewire->component('onboarding.steps.tax-country-step', TaxCountryStep::class);
        $livewire->component('onboarding.starting-balance-card', StartingBalanceCard::class);
    }
}
