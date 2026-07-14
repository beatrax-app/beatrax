<?php

declare(strict_types=1);

namespace Modules\Mobile\Internal\Http\Livewire;

use Illuminate\Contracts\View\Factory as ViewFactory;
use Illuminate\Contracts\View\View;
use Livewire\Component;
use Modules\Mobile\Internal\Boot\MobileFirstLaunchBootstrap;

/**
 * Mobile first-run welcome screen (`mobile.welcome`, `/mobile/welcome`) —
 * the mobile mirror of `Modules\Desktop\Internal\Http\Livewire\
 * WelcomeScreen`.
 *
 * Renders on a genuinely fresh mobile device (empty `users` table, gated in
 * front of this screen by `MobileEnsureDatabaseReady`) with two CTAs instead
 * of the desktop's single "Get started":
 *
 *   - "Create account" → `/signup` (Phase 12 D-03 gates the signup route to
 *     `User::count() === 0`, so the welcome → signup path is internally
 *     consistent — this reuses the Auth module's signup ceremony AS-IS).
 *   - "Import from another device" → `/mobile/import`
 *     (`MobileImportBootstrap`, Phase 15 import-join), the fresh-device
 *     local-identity bootstrap: creates a minimal local account + app-lock
 *     + sync identity (no epoch mint), then redirects into
 *     `/mobile/pair?mode=import` — closing the deferred item this
 *     docblock previously named ("cross-device key-delivery model
 *     unconfirmed").
 *
 * Once an account exists the device is set up, so any later landing on
 * `/mobile/welcome` — a stale `intended()` URL, a bookmark — must bounce to
 * the dashboard instead of stranding a set-up user on the first-run screen
 * (the `MobileEnsureDatabaseReady` gate exempts `mobile.welcome`, so it
 * cannot perform this bounce itself).
 *
 * Stateless — zero properties, no constructor. `phpstan-strict-rules`
 * forbids a constructor on a Livewire `Component` subclass.
 */
final class MobileWelcomeScreen extends Component
{
    /**
     * Bounce already-set-up devices (a user exists) to the dashboard; only
     * a true fresh install (no users) sees the welcome screen.
     */
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
