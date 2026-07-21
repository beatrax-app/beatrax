<?php

declare(strict_types=1);

namespace Modules\Mobile\Internal\Http\Livewire;

use Illuminate\Contracts\View\Factory as ViewFactory;
use Illuminate\Contracts\View\View;
use Livewire\Component;
use Modules\Mobile\Internal\Boot\MobileFirstLaunchBootstrap;

/**
 * @link ../../../../../.docs/features/mobile/architecture.md
 */
final class MobileWelcomeScreen extends Component
{
    // Bounces already-set-up devices (a user exists) to the dashboard;
    // only a true fresh install (no users) sees the welcome screen.
    public function mount(MobileFirstLaunchBootstrap $bootstrap): void
    {
        if (! $bootstrap->isFreshInstall()) {
            $this->redirectRoute('dashboard');
        }
    }

    public function render(ViewFactory $views): View
    {
        $view = $views->make('mobile::livewire.mobile-welcome-screen');

        /** @phpstan-ignore-next-line method.notFound — registered at runtime by Livewire's SupportPageComponents */
        $view->extends('layouts.app', ['title' => 'Welcome · beatrax']);

        return $view;
    }
}
