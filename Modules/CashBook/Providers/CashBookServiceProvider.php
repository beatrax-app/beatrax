<?php

declare(strict_types=1);

namespace Modules\CashBook\Providers;

use Illuminate\Support\ServiceProvider;
use Livewire\LivewireManager;
use Modules\CashBook\Internal\Actions\RecordManualTransaction;
use Modules\CashBook\Internal\Http\Livewire\CashBookPage;

final class CashBookServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(RecordManualTransaction::class);
    }

    public function boot(LivewireManager $livewire): void
    {
        $this->loadRoutesFrom(__DIR__.'/../Routes/web.php');
        $this->loadViewsFrom(__DIR__.'/../Resources/views', 'cashbook');
        $this->loadTranslationsFrom(__DIR__.'/../Resources/lang', 'cashbook');

        $livewire->component('cashbook.cash-book-page', CashBookPage::class);
    }
}
