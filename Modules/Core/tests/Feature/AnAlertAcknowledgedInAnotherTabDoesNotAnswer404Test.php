<?php

declare(strict_types=1);

use Livewire\Livewire;
use Modules\Core\Models\User;
use Modules\Core\Public\Http\Livewire\SystemAlertsBanner;

// The banner is mounted on every page, so a stale alert id on a second tab is
// the ordinary case: acknowledge the same warning twice and the second click
// must repaint the banner, not replace the page with a 404.

function alertBannerUser(): User
{
    return User::query()->create([
        'username' => 'alert-banner-'.bin2hex(random_bytes(4)),
        'password' => 'fixture-password-12chars',
        'period_start_day' => 1,
    ]);
}

it('answers an acknowledge on an alert that is gone by re-rendering the banner', function (): void {
    $this->actingAs(alertBannerUser());

    Livewire::test(SystemAlertsBanner::class)
        ->call('acknowledge', 999999)
        ->assertStatus(200);
});

it('answers install and skipVersion on an alert that is gone by re-rendering the banner', function (): void {
    $this->actingAs(alertBannerUser());

    Livewire::test(SystemAlertsBanner::class)
        ->call('install', 999999)
        ->assertStatus(200);

    Livewire::test(SystemAlertsBanner::class)
        ->call('skipVersion', 999999)
        ->assertStatus(200);
});
