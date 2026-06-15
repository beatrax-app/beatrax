<?php

declare(strict_types=1);

namespace Modules\Desktop\Internal\Http\Livewire;

use Illuminate\Contracts\View\Factory as ViewFactory;
use Illuminate\Contracts\View\View;
use Livewire\Component;
use Modules\Desktop\Internal\Native\FirstLaunchBootstrap;

/**
 * Welcome screen (D-22).
 *
 * Renders after the first-launch DB bootstrap finishes on a fresh
 * install — a centered brand mark, the "Welcome to beatrax" heading,
 * and a single emerald "Get started" button that drops the user onto
 * `/signup` (Phase 12 D-03 gates the signup route to `User::count() === 0`,
 * so the welcome page → signup path is internally consistent).
 *
 * The welcome screen is only meaningful on a genuine fresh install. Once an
 * account exists the device is set up, so any later landing on `/welcome` — a
 * stale `intended()` URL after an app-lock unlock, the PWA `start_url`, a
 * bookmarked link — must bounce to the dashboard instead of stranding a
 * set-up user on the first-run screen (the EnsureDatabaseReady gate exempts
 * `desktop.welcome`, so it cannot perform this bounce itself).
 *
 * Stateless — zero properties, no constructor. `phpstan-strict-rules`
 * forbids a constructor on a Livewire `Component` subclass.
 */
final class WelcomeScreen extends Component
{
    /**
     * Bounce already-set-up devices (a user exists) to the dashboard; only a
     * true fresh install (no users) sees the welcome screen.
     */
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
