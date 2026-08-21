<?php

declare(strict_types=1);

namespace Modules\Shell\Providers;

use Illuminate\Support\ServiceProvider;
use Livewire\LivewireManager;
use Modules\Core\Public\Support\LoadsModuleResources;
use Modules\Shell\Internal\Http\Livewire\AppSidebar;
use Modules\Shell\Internal\Http\Livewire\Dashboard;
use Modules\Shell\Internal\Http\Livewire\NetWorthCard;
use Modules\Shell\Internal\Http\Livewire\SettingsPage;
use Modules\Shell\Internal\Http\Livewire\SpendingTrendCard;

final class ShellServiceProvider extends ServiceProvider
{
    use LoadsModuleResources;

    public function boot(LivewireManager $livewire): void
    {
        $this->loadModuleResources('shell');

        // The aliases keep the `core.` prefix they were registered under while
        // these components lived in Core. An alias is rendered into the page as
        // wire:name and pinned by a snapshot, so it is an observable string, not
        // a naming convention — the class behind it moved, the string did not.
        $livewire->component('core.dashboard', Dashboard::class);
        $livewire->component('core.settings-page', SettingsPage::class);
        $livewire->component('core.app-sidebar', AppSidebar::class);
        $livewire->component('core.net-worth-card', NetWorthCard::class);
        $livewire->component('core.spending-trend-card', SpendingTrendCard::class);
    }
}
