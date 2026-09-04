<?php

declare(strict_types=1);

namespace Modules\Mobile\Internal\Http\Livewire;

use Illuminate\Contracts\View\Factory as ViewFactory;
use Illuminate\Contracts\View\View;
use Livewire\Component;
use Modules\Core\Public\Navigation\Destination;
use Modules\Core\Public\Support\Lang;
use Modules\Mobile\Internal\Boot\MobileFirstLaunchBootstrap;

final class MobileWelcomeScreen extends Component
{
    // Bounces already-set-up devices (a user exists) to the dashboard;
    // only a true fresh install (no users) sees the welcome screen.
    public function mount(MobileFirstLaunchBootstrap $bootstrap): void
    {
        if (! $bootstrap->isFreshInstall()) {
            $this->redirectRoute(Destination::Dashboard->routeName());
        }
    }

    public function render(ViewFactory $views): View
    {
        $view = $views->make('mobile::livewire.mobile-welcome-screen');

        $view->extends('layouts.app', ['title' => Lang::get('mobile::welcome.page_title').' · Beatrax']);

        return $view;
    }
}
