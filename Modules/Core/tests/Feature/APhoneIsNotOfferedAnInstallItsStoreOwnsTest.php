<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Modules\Core\Database\Seeders\Demo\DemoSystemAlertsSeeder;
use Modules\Core\Models\SystemAlert;
use Modules\Core\Models\User;
use Modules\Core\Public\Enums\UpdateAlertKind;
use Modules\Core\Public\Http\Livewire\SystemAlertsBanner;

uses(RefreshDatabase::class);

// An iPhone showed "Update available — Beatrax 0.1.0 is ready. It will install
// on next launch." over Install on next launch / Skip this version. Tapping it
// acknowledged the row and installed nothing, because the sole listener for
// UpdateInstallRequested is registered by the Desktop module, which the phone
// does not load — and TriggerUpdateDownload returns early on a phone anyway.

// The inertness is deliberate: nothing may install outside the app stores. What
// was missing is that the control is not offered where it is known to be inert.

function storeUpdateUser(): User
{
    return User::query()->create([
        'username' => 'store-update-'.bin2hex(random_bytes(4)),
        'password' => 'fixture-password-12chars',
        'period_start_day' => 1,
    ]);
}

function storeUpdateAlert(): void
{
    // System-wide, the way RecordUpdateAvailableAlert writes it.
    $alert = new SystemAlert;
    $alert->user_id = null;
    $alert->kind = UpdateAlertKind::Available->value;
    $alert->severity = 'info';
    $alert->message = 'A new release is available.';
    $alert->metadata = ['latestVersion' => '0.1.0'];
    $alert->save();
}

beforeEach(function (): void {
    putenv('NATIVEPHP_PLATFORM');
    $this->actingAs(storeUpdateUser());
    storeUpdateAlert();
});

afterEach(fn () => putenv('NATIVEPHP_PLATFORM'));

it('offers the install on the desktop, where something can perform one', function (): void {
    Livewire::test(SystemAlertsBanner::class)
        ->assertSee('install(', false)
        ->assertSee('skipVersion(', false);
});

it('offers a phone no install, because its store owns the update', function (): void {
    putenv('NATIVEPHP_PLATFORM=ios');

    Livewire::test(SystemAlertsBanner::class)
        ->assertDontSee('install(', false)
        ->assertDontSee('skipVersion(', false);
});

// The promise, not only the button: a phone that still read "It will install on
// next launch" beside a Mark as resolved would be no better off.
it('does not tell a phone the update will install on next launch', function (): void {
    putenv('NATIVEPHP_PLATFORM=ios');

    Livewire::test(SystemAlertsBanner::class)
        ->assertDontSee('0.1.0');
});

it('still shows a phone the alerts it can act on', function (): void {
    $alert = new SystemAlert;
    $alert->user_id = null;
    $alert->kind = 'backup_corrupt';
    $alert->severity = 'critical';
    $alert->message = 'Backup verification failed.';
    $alert->metadata = [];
    $alert->save();

    putenv('NATIVEPHP_PLATFORM=ios');

    Livewire::test(SystemAlertsBanner::class)
        ->assertSee('acknowledge('.$alert->id.')', false);
});

// And the row is not written on a phone in the first place: the demo seeder
// holds "every kind here is one the app can actually raise", which is true of
// the app and was not true of the shell.
it('seeds no update alert on a phone, and still seeds one on the desktop', function (): void {
    $seeder = app(DemoSystemAlertsSeeder::class);
    $users = ['demo-1' => storeUpdateUser()];

    putenv('NATIVEPHP_PLATFORM=ios');
    $seeder->run($users);

    $onPhone = SystemAlert::withoutGlobalScopes()
        ->whereIn('kind', UpdateAlertKind::values())
        ->whereJsonContains('metadata->seed_key', 'update-available-current')
        ->count();

    putenv('NATIVEPHP_PLATFORM');
    $seeder->run($users);

    $onDesktop = SystemAlert::withoutGlobalScopes()
        ->whereIn('kind', UpdateAlertKind::values())
        ->whereJsonContains('metadata->seed_key', 'update-available-current')
        ->count();

    expect($onPhone)->toBe(0, 'a phone was seeded an update alert it can never raise')
        ->and($onDesktop)->toBe(1, 'the desktop lost its update alert too — the skip is too broad');
});
