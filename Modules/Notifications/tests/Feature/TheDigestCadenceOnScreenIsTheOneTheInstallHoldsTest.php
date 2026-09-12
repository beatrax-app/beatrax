<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Modules\Core\Models\User;
use Modules\Notifications\Public\Http\Livewire\NotificationsSettingsSection;

uses(RefreshDatabase::class);

// A fresh install has no notification_preferences row, so the screen opens on
// the locked defaults, whose digest cadence is weekly. The cadence select
// listed daily first and marked nothing, so a browser drew "Daily" over a
// weekly install — and a reader who wanted daily could not choose it, because
// selecting the option already on screen fires no change event.

function digestCadenceReader(string $username): User
{
    $user = User::query()->create([
        'username' => $username,
        'password' => 'fixture-password',
        'period_start_day' => 1,
        'default_currency_view' => 'eur_only',
    ]);

    test()->actingAs($user);

    return $user;
}

it('opens on the cadence a fresh install actually holds', function (): void {
    digestCadenceReader('digest-fresh');

    $html = Livewire::test(NotificationsSettingsSection::class)
        ->assertSet('digestCadence', 'weekly')
        ->html();

    expect($html)->toContain('<option value="weekly" selected>')
        ->and($html)->toContain('<option value="daily">');
});

it('follows the stored cadence once the reader has chosen one', function (): void {
    digestCadenceReader('digest-chosen');

    $html = Livewire::test(NotificationsSettingsSection::class)
        ->set('digestCadence', 'off')
        ->html();

    expect($html)->toContain('<option value="off" selected>')
        ->and($html)->not->toContain('<option value="weekly" selected>');
});
