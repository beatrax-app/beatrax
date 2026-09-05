<?php

declare(strict_types=1);

use Livewire\Livewire;
use Modules\Core\Models\User;
use Modules\Notifications\Internal\Delivery\NoSystemNotificationConsent;
use Modules\Notifications\Public\Contracts\SystemNotificationConsent;
use Modules\Notifications\Public\Http\Livewire\NotificationsSettingsSection;
use Modules\Notifications\Tests\Support\RecordingConsent;

// Measured on the Samsung, Android 16, targetSdk 36: the manifest declares
// POST_NOTIFICATIONS and the strip script deliberately keeps it, but nothing
// in the tree ever requested it. `dumpsys package` reports granted=false with
// no USER_SET flag — never prompted, not refused — and `dumpsys notification`
// reports `importance=NONE userSet=false` for the package, which is how the
// platform says "blocked, and the reader never chose that". A full first run
// raised no prompt at any of the nine wizard steps. Every reminder, budget
// nudge and shortfall warning the app posts was being dropped.
//
// The moment to ask is the moment the reader asks to be notified.

beforeEach(function (): void {
    $this->user = User::query()->create([
        'username' => 'consent-'.bin2hex(random_bytes(4)),
        'password' => 'fixture-password-12chars',
        'period_start_day' => 1,
    ]);
    $this->actingAs($this->user);

    $this->consent = new RecordingConsent;
    $this->app->instance(SystemNotificationConsent::class, $this->consent);
});

it('asks the platform for consent when the reader turns a notification on', function (): void {
    Livewire::test(NotificationsSettingsSection::class)
        ->set('remindersEnabled', true)
        ->set('budgetNudgesEnabled', false)
        ->set('savingsPromptsEnabled', false)
        ->call('save')
        ->assertSet('saved', true);

    expect($this->consent->requests)->toBe(1);
});

it('does not ask when every notification is off', function (): void {
    Livewire::test(NotificationsSettingsSection::class)
        ->set('remindersEnabled', false)
        ->set('budgetNudgesEnabled', false)
        ->set('savingsPromptsEnabled', false)
        ->call('save')
        ->assertSet('saved', true);

    expect($this->consent->requests)->toBe(0);
});

it('does not ask when the settings were refused', function (): void {
    Livewire::test(NotificationsSettingsSection::class)
        ->set('remindersEnabled', true)
        ->set('quietHoursFrom', '99:99')
        ->call('save')
        ->assertSet('saved', false);

    expect($this->consent->requests)->toBe(0);
});

it('binds a default that needs no platform prompt', function (): void {
    $this->app->forgetInstance(SystemNotificationConsent::class);

    expect(app(SystemNotificationConsent::class))
        ->toBeInstanceOf(NoSystemNotificationConsent::class);
});
