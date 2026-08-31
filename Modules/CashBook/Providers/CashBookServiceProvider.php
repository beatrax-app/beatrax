<?php

declare(strict_types=1);

namespace Modules\CashBook\Providers;

use Illuminate\Support\ServiceProvider;
use Livewire\LivewireManager;
use Modules\CashBook\Internal\Http\Livewire\CashBookPage;
use Modules\CashBook\Internal\Services\ManualEntryAnchors;
use Modules\Core\Public\Support\LoadsModuleResources;

final class CashBookServiceProvider extends ServiceProvider
{
    use LoadsModuleResources;

    public function register(): void
    {
        $this->app->singleton(ManualEntryAnchors::class);
    }

    public function boot(LivewireManager $livewire): void
    {
        $this->loadModuleResources('cashbook');

        $livewire->component('cashbook.cash-book-page', CashBookPage::class);
    }
}
