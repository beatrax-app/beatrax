<?php

declare(strict_types=1);

use Illuminate\Contracts\Session\Session;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Modules\Core\Models\User;
use Modules\Core\Public\Support\Lang;
use Modules\Core\Public\Support\PatternScan;
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

    // "Produced only where it redirects" became "produced in only one place":
    // the line is now also the confirm step's status when the lock takes the
    // identity out from under a live ceremony, and there the Confirm button is
    // the road to the PIN pad — it carries the tap across the unlock.
    $sources = glob(base_path('Modules/Mobile/Internal/Http/Livewire/Concerns/*.php')) ?: [];
    $sources[] = base_path('Modules/Mobile/Internal/Http/Livewire/MobilePairingScan.php');

    $occurrences = 0;
    foreach ($sources as $source) {
        $occurrences += PatternScan::count(
            "/mobile::pairing\.errors\.identity_locked/",
            (string) file_get_contents($source),
        );
    }

    expect($occurrences)->toBe(1, 'identity_locked is produced in more than one place across this screen and its '
        .'concerns, so a branch can show it without anyone having checked that branch offers a way to the PIN pad');

    // And that one place is the helper both roads read it from.
    $notice = (string) file_get_contents(
        base_path('Modules/Mobile/Internal/Http/Livewire/Concerns/ConfirmsAcrossTheLock.php')
    );

    expect($notice)->toContain('private function identityUnavailableNotice(');
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
