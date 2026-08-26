<?php

declare(strict_types=1);

use Illuminate\Contracts\Session\Session;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Modules\Core\Models\User;
use Modules\Core\Public\Support\Lang;
use Modules\Mobile\Internal\Http\Livewire\MobileLockScreen;
use Modules\Mobile\Internal\Http\Livewire\MobilePairingScan;

uses(RefreshDatabase::class);

// The confirm button used to say the device identity was locked and leave the
// user there, with nothing on that screen opening the PIN pad. Redirecting was
// only half of it: the reason lived on a public property of the component being
// navigated away from, and navigate:false is a full page load.

it('carries the reason across to the lock screen', function (): void {
    $user = User::query()->create([
        'username' => 'lockflash-'.bin2hex(random_bytes(4)),
        'password' => bcrypt('account-password'),
        'period_start_day' => 1,
        'default_currency_view' => 'eur_only',
    ]);
    test()->actingAs($user);

    app(Session::class)->flash(
        MobilePairingScan::LOCKED_IDENTITY_FLASH,
        Lang::get('mobile::pairing.errors.identity_locked'),
    );

    Livewire::test(MobileLockScreen::class)
        ->assertSet('flashMessage', Lang::get('mobile::pairing.errors.identity_locked'))
        ->assertSee(Lang::get('mobile::pairing.errors.identity_locked'));
});

it('leaves the lock screen silent when nothing sent the user there', function (): void {
    $user = User::query()->create([
        'username' => 'locksilent-'.bin2hex(random_bytes(4)),
        'password' => bcrypt('account-password'),
        'period_start_day' => 1,
        'default_currency_view' => 'eur_only',
    ]);
    test()->actingAs($user);

    Livewire::test(MobileLockScreen::class)->assertSet('flashMessage', '');
});

it('routes every locked-identity branch to the lock screen', function (): void {
    $component = (string) file_get_contents(
        base_path('Modules/Mobile/Internal/Http/Livewire/MobilePairingScan.php')
    );

    expect($component)->toContain('private function sendToUnlock(')
        ->and($component)->toContain("route('mobile.lock')");

    // The copy must not survive anywhere that does not also open the PIN pad,
    // and the only place it is produced is inside the helper that redirects.
    $occurrences = preg_match_all(
        "/mobile::pairing\.errors\.identity_locked/",
        $component
    );

    expect($occurrences)->toBe(1, 'identity_locked is produced somewhere that does not open the PIN pad');
});

it('keeps a lock route to send them to', function (): void {
    $routes = (string) file_get_contents(base_path('Modules/Mobile/Routes/web.php'));

    expect($routes)->toContain("name('mobile.lock')");
});

it('returns an importing device to the import arm after it unlocks', function (): void {
    $component = (string) file_get_contents(
        base_path('Modules/Mobile/Internal/Http/Livewire/MobilePairingScan.php')
    );

    // Unlocking fell through to redirectToIntendedUrl()'s dashboard default, so a
    // device sent to the PIN pad mid-import came back to a pairing screen that no
    // longer knew it was importing, and therefore offered the typed code arm the
    // import path hides because it cannot succeed there.
    expect($component)->toContain('MobileLockGateway::SESSION_INTENDED_URL')
        ->and($component)->toContain('PairingEntryUrl::importingFrom($urls)');

    $sendToUnlock = substr($component, (int) strpos($component, 'private function sendToUnlock('));
    $sendToUnlock = substr($sendToUnlock, 0, (int) strpos($sendToUnlock, "\n    }"));

    expect(str_contains($sendToUnlock, 'SESSION_INTENDED_URL'))
        ->toBeTrue('sendToUnlock() sends the user to the PIN pad without recording where to return');
});
