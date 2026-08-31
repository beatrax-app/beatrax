<?php

declare(strict_types=1);

namespace Modules\Pots\Providers;

use Illuminate\Support\ServiceProvider;
use Livewire\LivewireManager;
use Modules\Core\Public\Support\LoadsModuleResources;
use Modules\Pots\Internal\Http\Livewire\PotsPage;
use Modules\Pots\Internal\Services\PotAllocationLedger;
use Modules\Pots\Internal\Services\PotRowLoader;
use Modules\Pots\Public\Services\PotBalanceQuery;

final class PotsServiceProvider extends ServiceProvider
{
    use LoadsModuleResources;

    public function register(): void
    {
        $this->app->singleton(PotAllocationLedger::class);
        $this->app->singleton(PotRowLoader::class);
        $this->app->singleton(PotBalanceQuery::class);
    }

    public function boot(LivewireManager $livewire): void
    {
        $this->loadModuleResources('pots');

        $livewire->component('pots.pots-page', PotsPage::class);
    }
}
