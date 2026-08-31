<?php

declare(strict_types=1);

use Illuminate\Database\DatabaseManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Modules\Core\Models\User;
use Modules\Core\Public\Support\Lang;
use Modules\Mobile\Internal\Http\Livewire\SyncCompleteScreen;
use Modules\Mobile\Internal\Http\Livewire\SyncScreen;

uses(RefreshDatabase::class);

// The last screen of setup said "There is no sync button to press". The first
// screen after it is Data & devices, whose only way to move data is a button
// called Sync now — `MobileBackgroundSchedule::impossibleOnDevice()` names
// `mobile.sync-pull` as work no phone schedule can complete, so the tap is not
// a shortcut, it is the whole mechanism.

/** @return list<string> the locale codes Mobile ships copy for */
function setupDoneLocales(): array
{
    $codes = [];

    foreach ((array) glob(base_path('Modules/Mobile/Resources/lang/*/sync_complete.php')) as $file) {
        $codes[] = basename(dirname((string) $file));
    }

    return $codes;
}

function setupDoneUser(string $username): User
{
    return User::query()->create([
        'username' => $username,
        'password' => bcrypt('fixture'),
        'period_start_day' => 1,
        'default_currency_view' => 'eur_only',
    ]);
}

function setupDonePeerFor(User $user): void
{
    app(DatabaseManager::class)->connection()->table('device_registry')->insert([
        'user_id' => $user->id,
        'device_id' => 'setup-done-peer-'.bin2hex(random_bytes(4)),
        'name' => 'Study desktop',
        'ed25519_public_key_hex' => str_repeat('ab', 32),
        'x25519_public_key_hex' => str_repeat('cd', 32),
        'safety_number_words' => 'alpha bravo charlie',
        'is_self' => 0,
        'paired_at' => '2026-08-01T10:00:00Z',
        'confirmed_at' => '2026-08-01T10:01:00Z',
        'last_seen_at' => '2026-08-01T10:01:00Z',
        'created_at' => '2026-08-01T10:00:00Z',
        'updated_at' => '2026-08-01T10:01:00Z',
    ]);
}

it('names, on the last setup screen, the control the next screen actually offers', function (): void {
    $user = setupDoneUser('setup-done-'.bin2hex(random_bytes(4)));
    setupDonePeerFor($user);
    $this->actingAs($user);

    $control = Lang::get('mobile::sync.sync_now');

    expect(Livewire::test(SyncScreen::class)->html())->toContain($control);

    Livewire::test(SyncCompleteScreen::class)
        ->assertSet('syncAction', $control)
        ->assertSee($control)
        ->assertSee(Lang::get('mobile::sync_complete.automatic_body', ['action' => $control]))
        ->assertDontSee('There is no sync button to press');
});

// The two files are what disagreed, so the two files are what this reads. A
// rendered assertion in English alone would leave twenty-five locales free to
// go on denying the button.
it('resolves the button label into the setup screen copy in every language', function (): void {
    $silent = [];

    foreach (setupDoneLocales() as $locale) {
        app('translator')->setLocale($locale);

        $control = Lang::get('mobile::sync.sync_now');
        $lines = [
            'automatic_body' => Lang::get('mobile::sync_complete.automatic_body', ['action' => $control]),
            'relay_body' => Lang::get('mobile::sync_complete.relay_body', ['action' => $control]),
            'no_relay_body' => Lang::get('mobile::sync_complete.no_relay_body', ['action' => $control]),
        ];

        foreach ($lines as $key => $line) {
            if (! str_contains($line, $control)) {
                $silent[] = "{$locale}: {$key}";
            }
        }
    }

    expect($silent)->toBe([], 'These lines promise syncing without naming the tap that performs it: '.implode(', ', $silent).'.');
});

it('promises no landing on the away-from-home lines that a closed app cannot deliver', function (): void {
    $user = setupDoneUser('setup-done-relay-'.bin2hex(random_bytes(4)));
    setupDonePeerFor($user);
    $this->actingAs($user);

    $control = Lang::get('mobile::sync.sync_now');

    Livewire::test(SyncCompleteScreen::class)
        ->set('hasRelay', true)
        ->assertSee(Lang::get('mobile::sync_complete.relay_body', ['action' => $control]))
        ->set('hasRelay', false)
        ->assertSee(Lang::get('mobile::sync_complete.no_relay_body', ['action' => $control]));
});
