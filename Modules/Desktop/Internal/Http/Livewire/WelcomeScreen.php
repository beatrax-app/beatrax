<?php

declare(strict_types=1);

namespace Modules\Desktop\Internal\Http\Livewire;

use Illuminate\Contracts\View\Factory as ViewFactory;
use Illuminate\Contracts\View\View;
use Livewire\Component;
use Modules\Core\Public\Navigation\Destination;
use Modules\Core\Public\Support\Brand;
use Modules\Core\Public\Support\Lang;
use Modules\Desktop\Internal\Native\FirstLaunchBootstrap;

final class WelcomeScreen extends Component
{
    // Only a true fresh install (no users) sees the welcome screen —
    // any later landing here (a stale intended() URL, the PWA
    // start_url, a bookmark) bounces to the dashboard instead of
    // stranding a set-up user on the first-run screen.
    public function mount(FirstLaunchBootstrap $bootstrap): void
    {
        if (! $bootstrap->isFreshInstall()) {
            $this->redirectRoute(Destination::Dashboard->routeName());
        }
    }

    public function render(ViewFactory $views): View
    {
        $view = $views->make('desktop::welcome');

        $view->extends('layouts.app', ['title' => Lang::get('desktop::screens.welcome.page_title').Brand::TITLE_SUFFIX]);

        return $view;
    }
}
