<?php

declare(strict_types=1);

use Livewire\Livewire;
use Modules\Core\Models\User;
use Modules\Desktop\Internal\Http\Livewire\WelcomeScreen;

// The gate exempts `desktop.welcome`, so the bounce has to happen in the
// component: a stale intended() URL after an app-lock unlock, the PWA
// start_url or a bookmark can all land a set-up device on the first-run screen.
it('renders the welcome screen on a genuine fresh install (no users)', function (): void {
    Livewire::test(WelcomeScreen::class)
        ->assertNoRedirect()
        ->assertStatus(200);
});

it('redirects an already-set-up device (a user exists) to the dashboard', function (): void {
    User::query()->create([
        'username' => 'welcome-redirect-user',
        'password' => bcrypt('welcome-pass'),
        'period_start_day' => 1,
        'default_currency_view' => 'eur_only',
    ]);

    Livewire::test(WelcomeScreen::class)
        ->assertRedirect(route('dashboard'));
});
