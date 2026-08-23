<?php

declare(strict_types=1);

use Livewire\Livewire;
use Modules\Core\Models\User;
use Modules\Shell\Internal\Http\Livewire\SettingsPage;

// Changing the display language re-requests the page so the layout chrome
// re-renders in the new language. That URL has to be the settings screen: the
// action runs inside a POST to Livewire's update endpoint, so the *current*
// request URL is that endpoint, and a browser sent there answers 405.

beforeEach(function (): void {
    $this->user = User::create([
        'username' => 'locale-switch-user',
        'password' => 'opensesame',
        'period_start_day' => 1,
        'default_currency_view' => 'eur_only',
    ]);
    $this->actingAs($this->user);
});

it('sends the reader back to the settings screen after switching language', function (): void {
    Livewire::test(SettingsPage::class)
        ->call('setLocale', 'nl')
        ->assertRedirect(route('settings'));
});

it('never redirects to the Livewire update endpoint', function (): void {
    $redirect = Livewire::test(SettingsPage::class)
        ->call('setLocale', 'nl')
        ->effects['redirect'] ?? '';

    expect($redirect)->not->toContain('/update')
        ->and($redirect)->not->toContain('livewire');
});
