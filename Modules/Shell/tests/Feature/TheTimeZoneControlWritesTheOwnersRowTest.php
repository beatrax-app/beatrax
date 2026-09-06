<?php

declare(strict_types=1);

use Livewire\Livewire;
use Modules\Core\Models\User;
use Modules\Core\Public\Services\InstallTimezone;
use Modules\Core\Public\Support\HostTimezone;
use Modules\Shell\Internal\Http\Livewire\SettingsPage;

beforeEach(function (): void {
    $this->processZone = date_default_timezone_get();
    HostTimezone::fake('Pacific/Auckland');
    config(['app.timezone_pinned' => null]);
});

afterEach(function (): void {
    HostTimezone::fake(null);
    date_default_timezone_set($this->processZone);
});

function tzcUser(string $username): User
{
    return User::query()->create([
        'username' => $username,
        'password' => 'fixture-password',
        'period_start_day' => 1,
        'default_currency_view' => 'eur_only',
    ]);
}

// The stored choice, not the resolved zone. Opening on the resolved one would
// show a reader who has chosen nothing as having chosen the zone they happen
// to be sitting in, and there would be no way back to the sentinel.
it('opens on the sentinel while nothing has been chosen', function (): void {
    $user = tzcUser('tzc-unset');

    Livewire::actingAs($user)
        ->test(SettingsPage::class)
        ->assertSet('timezone', InstallTimezone::THIS_MACHINE);
});

it('opens on the stored choice once one has been made', function (): void {
    $user = tzcUser('tzc-stored');
    User::query()->whereKey($user->id)->update(['timezone' => 'Asia/Tokyo']);

    Livewire::actingAs($user)
        ->test(SettingsPage::class)
        ->assertSet('timezone', 'Asia/Tokyo');
});

it('stores the zone the reader picks', function (): void {
    $user = tzcUser('tzc-pick');

    Livewire::actingAs($user)
        ->test(SettingsPage::class)
        ->call('setTimezone', 'Asia/Tokyo')
        ->assertHasNoErrors();

    expect(User::query()->find($user->id)->timezone)->toBe('Asia/Tokyo');
});

it('clears the row again when the reader picks the machine', function (): void {
    $user = tzcUser('tzc-clear');
    User::query()->whereKey($user->id)->update(['timezone' => 'Asia/Tokyo']);

    Livewire::actingAs($user)
        ->test(SettingsPage::class)
        ->call('setTimezone', InstallTimezone::THIS_MACHINE)
        ->assertHasNoErrors();

    expect(User::query()->find($user->id)->timezone)->toBeNull();
});

it('refuses a value that is not a zone and leaves the row alone', function (string $picked): void {
    $user = tzcUser('tzc-refuse-'.md5($picked));
    User::query()->whereKey($user->id)->update(['timezone' => 'Asia/Tokyo']);

    Livewire::actingAs($user)
        ->test(SettingsPage::class)
        ->call('setTimezone', $picked)
        ->assertHasErrors('timezone');

    expect(User::query()->find($user->id)->timezone)->toBe('Asia/Tokyo');
})->with([
    'an offset' => '+02:00',
    'a windows name' => 'W. Europe Standard Time',
    'a zone that does not exist' => 'Europe/Nowhere',
    'nothing at all' => '',
]);

// The sentinel has to name the zone it would fall back to, or "This machine"
// tells the reader nothing about which day they are about to read.
it('names the detected zone on the sentinel option', function (): void {
    $user = tzcUser('tzc-labelled');

    Livewire::actingAs($user)
        ->test(SettingsPage::class)
        ->assertSee('Pacific/Auckland')
        ->assertSee('Europe')
        ->assertSee('Amsterdam');
});
