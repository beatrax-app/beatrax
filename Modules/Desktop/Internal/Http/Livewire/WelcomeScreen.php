<?php

declare(strict_types=1);

namespace Modules\Desktop\Internal\Http\Livewire;

use Illuminate\Contracts\View\Factory as ViewFactory;
use Illuminate\Contracts\View\View;
use Livewire\Component;
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
            $this->redirectRoute('dashboard');
        }
    }

    public function render(ViewFactory $views): View
    {
        $view = $views->make('desktop::welcome');

        /** @phpstan-ignore-next-line method.notFound — registered at runtime by Livewire's SupportPageComponents */
        $view->extends('layouts.app', ['title' => 'Welcome · beatrax']);

        return $view;
    }
}
