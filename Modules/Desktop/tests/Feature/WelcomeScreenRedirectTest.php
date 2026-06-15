<?php

declare(strict_types=1);

use Livewire\Livewire;
use Modules\Core\Models\User;
use Modules\Desktop\Internal\Http\Livewire\WelcomeScreen;

/*
 * WelcomeScreen redirect guard.
 *
 * The welcome screen is only meaningful on a genuine fresh install (zero
 * users). Once an account exists the device is set up, so any later landing on
 * `/welcome` — a stale intended() URL after an app-lock unlock, the PWA
 * start_url, a bookmark — must bounce to the dashboard instead of stranding a
 * set-up user on the first-run screen. (The EnsureDatabaseReady gate exempts
 * `desktop.welcome`, so it cannot perform this bounce itself.)
 */

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
