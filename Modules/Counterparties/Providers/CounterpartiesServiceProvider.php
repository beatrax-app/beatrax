<?php

declare(strict_types=1);

namespace Modules\Counterparties\Providers;

use Illuminate\Support\ServiceProvider;
use Illuminate\View\Compilers\BladeCompiler;
use Livewire\LivewireManager;
use Modules\Core\Public\Support\LoadsModuleResources;
use Modules\Counterparties\Internal\Http\Livewire\CounterpartyIndex;
use Modules\Counterparties\Internal\Http\Livewire\CounterpartyProfile;
use Modules\Counterparties\Internal\Http\Livewire\CounterpartyTriage;
use Modules\Counterparties\Internal\Pipeline\ResolveCounterpartyStage;
use Modules\Counterparties\Internal\Resolver\CounterpartyResolverService;
use Modules\Counterparties\Internal\Resolver\CounterpartySlugResolver;
use Modules\Counterparties\Public\Contracts\CounterpartyResolver;
use Modules\Counterparties\Public\Pipeline\ResolvesCounterparties;

final class CounterpartiesServiceProvider extends ServiceProvider
{
    use LoadsModuleResources;

    public function register(): void
    {
        $this->app->singleton(CounterpartySlugResolver::class);
        $this->app->singleton(CounterpartyResolver::class, CounterpartyResolverService::class);
        $this->app->singleton(ResolvesCounterparties::class, ResolveCounterpartyStage::class);
    }

    public function boot(BladeCompiler $blade, LivewireManager $livewire): void
    {
        $this->loadModuleResources('counterparties');

        $blade->componentNamespace('Modules\\Counterparties\\Resources\\views\\components', 'counterparties');

        $livewire->component('counterparties.index', CounterpartyIndex::class);
        $livewire->component('counterparties.profile', CounterpartyProfile::class);
        $livewire->component('counterparties.triage', CounterpartyTriage::class);
    }
}
